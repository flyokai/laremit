<?php

declare(strict_types=1);

namespace App\MockStores\Apple;

use App\Domain\Billing\Enums\Store;
use App\MockStores\MockStoresSettings;
use App\MockStores\Models\StoreSubscription;
use App\MockStores\NotificationScheduler;
use App\MockStores\StoreClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The pretend App Store. Owns the subscription's lifecycle the way Apple
 * does — every change bumps its clock (event_at), emits an App Store
 * Server Notification V2 signed as JWS, and is answerable through the
 * Server API stand-in (signedSubscription). Local tooling only.
 *
 * Statuses are Apple's shape, not ours: `active`, `grace` (billing retry
 * inside a grace period), `expired`, `revoked`; auto-renew is a separate
 * flag, as it is in the real renewal info.
 */
final readonly class MockAppStore
{
    private const GRACE_DAYS = 16;

    public function __construct(
        private MockStoresSettings $settings,
        private AppleSigner $signer,
        private NotificationScheduler $scheduler,
    ) {}

    public function purchase(string $productId, ?string $appAccountToken, int $periodDays = 30): StoreSubscription
    {
        $now = self::now();

        $subscription = StoreSubscription::query()->create([
            'store' => Store::Apple->value,
            'identifier' => '2'.str_pad((string) random_int(0, 999_999_999_999_999), 15, '0', STR_PAD_LEFT),
            'product_id' => $productId,
            'app_account_token' => $appAccountToken,
            'status' => 'active',
            'auto_renew' => true,
            'period_start' => $now,
            'period_end' => $now->addDays($periodDays),
            'period_days' => $periodDays,
            'environment' => $this->settings->environment(),
            'acknowledged' => true,
            'event_at' => $now,
        ]);

        $this->notify($subscription, 'SUBSCRIBED', 'INITIAL_BUY');

        return $subscription;
    }

    /**
     * @param  string  $action  one of: renew, cancel, resume, expire, fail_payment, recover, refund, revoke
     */
    public function act(string $identifier, string $action): StoreSubscription
    {
        $subscription = $this->find($identifier);

        if ($subscription === null) {
            throw new InvalidArgumentException("No App Store subscription [{$identifier}].");
        }

        $now = self::now();
        $wasInGrace = $subscription->status === 'grace';

        [$changes, $type, $subtype] = match ($action) {
            'renew' => [
                ['status' => 'active', 'period_start' => $subscription->period_end->max($now), 'period_end' => $subscription->period_end->max($now)->addDays($subscription->period_days)],
                'DID_RENEW', $wasInGrace ? 'BILLING_RECOVERY' : null,
            ],
            'cancel' => [['auto_renew' => false], 'DID_CHANGE_RENEWAL_STATUS', 'AUTO_RENEW_DISABLED'],
            'resume' => [['auto_renew' => true], 'DID_CHANGE_RENEWAL_STATUS', 'AUTO_RENEW_ENABLED'],
            'expire' => [['status' => 'expired', 'period_end' => $now], 'EXPIRED', $wasInGrace ? 'BILLING_RETRY' : 'VOLUNTARY'],
            'fail_payment' => [['status' => 'grace', 'period_end' => $now], 'DID_FAIL_TO_PAY', 'GRACE_PERIOD'],
            'recover' => [
                ['status' => 'active', 'period_start' => $now, 'period_end' => $now->addDays($subscription->period_days)],
                'DID_RENEW', 'BILLING_RECOVERY',
            ],
            'refund' => [['status' => 'revoked', 'revoked_at' => $now], 'REFUND', null],
            'revoke' => [['status' => 'revoked', 'revoked_at' => $now], 'REVOKE', null],
            default => throw new InvalidArgumentException("Unknown App Store action [{$action}]."),
        };

        $subscription->update($changes + ['event_at' => $now]);

        $this->notify($subscription->refresh(), $type, $subtype);

        return $subscription;
    }

    public function find(string $identifier): ?StoreSubscription
    {
        return StoreSubscription::query()
            ->where('store', Store::Apple->value)
            ->where('identifier', $identifier)
            ->first();
    }

    /**
     * App Store Server API stand-in: the current signed state.
     *
     * @return array{signedTransactionInfo: string, signedRenewalInfo: string, environment: string}|null
     */
    public function signedSubscription(string $identifier): ?array
    {
        $subscription = $this->find($identifier);

        if ($subscription === null) {
            return null;
        }

        $signedDate = self::now();

        return [
            'signedTransactionInfo' => $this->signer->sign($this->transactionInfo($subscription, $signedDate)),
            'signedRenewalInfo' => $this->signer->sign($this->renewalInfo($subscription, $signedDate)),
            'environment' => $subscription->environment,
        ];
    }

    /**
     * Build and queue one ASSN v2 notification. signedDate is the store's
     * clock for the change — the same instant stamped on the record.
     *
     * @return string the notificationUUID
     */
    public function notify(StoreSubscription $subscription, string $type, ?string $subtype): string
    {
        $uuid = (string) Str::uuid();
        $signedDate = $subscription->event_at;

        $payload = [
            'notificationType' => $type,
            'notificationUUID' => $uuid,
            'notificationVersion' => '2.0',
            'signedDate' => self::millis($signedDate),
            'data' => [
                'appAppleId' => 1_234_567_890,
                'bundleId' => $this->settings->appleBundleId(),
                'bundleVersion' => '1',
                'environment' => $subscription->environment,
                'signedTransactionInfo' => $this->signer->sign($this->transactionInfo($subscription, $signedDate)),
                'signedRenewalInfo' => $this->signer->sign($this->renewalInfo($subscription, $signedDate)),
            ],
        ];

        if ($subtype !== null) {
            $payload['subtype'] = $subtype;
        }

        $body = json_encode(['signedPayload' => $this->signer->sign($payload)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->scheduler->schedule(Store::Apple, $body, $uuid);

        return $uuid;
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionInfo(StoreSubscription $subscription, CarbonImmutable $signedDate): array
    {
        $info = [
            'transactionId' => $subscription->identifier.'-'.self::millis($subscription->period_start),
            'originalTransactionId' => $subscription->identifier,
            'bundleId' => $this->settings->appleBundleId(),
            'productId' => $subscription->product_id,
            'subscriptionGroupIdentifier' => '20000001',
            'purchaseDate' => self::millis($subscription->period_start),
            'originalPurchaseDate' => self::millis($subscription->created_at ?? $subscription->period_start),
            'expiresDate' => self::millis($subscription->period_end),
            'quantity' => 1,
            'type' => 'Auto-Renewable Subscription',
            'inAppOwnershipType' => 'PURCHASED',
            'environment' => $subscription->environment,
            'signedDate' => self::millis($signedDate),
        ];

        if ($subscription->app_account_token !== null) {
            $info['appAccountToken'] = $subscription->app_account_token;
        }

        if ($subscription->revoked_at !== null) {
            $info['revocationDate'] = self::millis($subscription->revoked_at);
            $info['revocationReason'] = 0;
        }

        return $info;
    }

    /**
     * @return array<string, mixed>
     */
    private function renewalInfo(StoreSubscription $subscription, CarbonImmutable $signedDate): array
    {
        $info = [
            'originalTransactionId' => $subscription->identifier,
            'autoRenewProductId' => $subscription->product_id,
            'productId' => $subscription->product_id,
            'autoRenewStatus' => $subscription->auto_renew ? 1 : 0,
            'environment' => $subscription->environment,
            'signedDate' => self::millis($signedDate),
            'recentSubscriptionStartDate' => self::millis($subscription->period_start),
        ];

        if ($subscription->status === 'grace') {
            $info['isInBillingRetryPeriod'] = true;
            $info['gracePeriodExpiresDate'] = self::millis($subscription->period_end->addDays(self::GRACE_DAYS));
            $info['expirationIntent'] = 2;
        }

        if ($subscription->status === 'expired') {
            $info['expirationIntent'] = $subscription->auto_renew ? 2 : 1;
        }

        return $info;
    }

    private static function now(): CarbonImmutable
    {
        return StoreClock::now();
    }

    private static function millis(CarbonImmutable $at): int
    {
        return (int) $at->getPreciseTimestamp(3);
    }
}
