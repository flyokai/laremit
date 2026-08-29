<?php

declare(strict_types=1);

namespace App\MockStores\Google;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Stores\Google\GoogleNotification;
use App\MockStores\MockStoresSettings;
use App\MockStores\Models\StoreSubscription;
use App\MockStores\NotificationScheduler;
use App\MockStores\StoreClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

/**
 * The pretend Play Store. Every change emits a Real-Time Developer
 * Notification through a Pub/Sub-shaped push and is answerable through
 * the Play Developer API stand-in (subscriptionsV2Get). Local tooling only.
 *
 * Statuses are Google's own SUBSCRIPTION_STATE_* words. A purchase token
 * is NOT a stable identity: resubscribing mints a new token whose record
 * points at the old one via linkedPurchaseToken.
 */
final readonly class MockPlayStore
{
    private const GRACE_DAYS = 7;

    public function __construct(
        private MockStoresSettings $settings,
        private NotificationScheduler $scheduler,
    ) {}

    public function purchase(
        string $productId,
        ?string $obfuscatedExternalAccountId,
        int $periodDays = 30,
        ?string $linkedPurchaseToken = null,
    ): StoreSubscription {
        $now = self::now();

        if ($linkedPurchaseToken !== null) {
            $old = $this->find($linkedPurchaseToken);

            if ($old === null) {
                throw new InvalidArgumentException("No Play purchase [{$linkedPurchaseToken}] to link to.");
            }

            // The store invalidates the old token the moment the new one exists.
            $old->update(['status' => 'SUBSCRIPTION_STATE_EXPIRED', 'period_end' => $now, 'event_at' => $now]);
            $obfuscatedExternalAccountId ??= $old->app_account_token;
        }

        $subscription = StoreSubscription::query()->create([
            'store' => Store::Google->value,
            'identifier' => Str::random(24).'.AO-J1O'.Str::random(16),
            'linked_identifier' => $linkedPurchaseToken,
            'product_id' => $productId,
            'app_account_token' => $obfuscatedExternalAccountId,
            'status' => 'SUBSCRIPTION_STATE_ACTIVE',
            'auto_renew' => true,
            'period_start' => $now,
            'period_end' => $now->addDays($periodDays),
            'period_days' => $periodDays,
            'environment' => $this->settings->environment(),
            'acknowledged' => false,
            'event_at' => $now,
        ]);

        $this->notify($subscription, GoogleNotification::PURCHASED);

        return $subscription;
    }

    /**
     * @param  string  $action  one of: renew, cancel, restart, on_hold, grace, recover, expire, revoke, pause
     */
    public function act(string $token, string $action): StoreSubscription
    {
        $subscription = $this->find($token);

        if ($subscription === null) {
            throw new InvalidArgumentException("No Play purchase [{$token}].");
        }

        $now = self::now();
        $length = $subscription->period_days;

        [$changes, $type] = match ($action) {
            'renew' => [
                ['status' => 'SUBSCRIPTION_STATE_ACTIVE', 'period_start' => $subscription->period_end->max($now), 'period_end' => $subscription->period_end->max($now)->addDays($length)],
                GoogleNotification::RENEWED,
            ],
            'cancel' => [['status' => 'SUBSCRIPTION_STATE_CANCELED', 'auto_renew' => false], GoogleNotification::CANCELED],
            'restart' => [['status' => 'SUBSCRIPTION_STATE_ACTIVE', 'auto_renew' => true], GoogleNotification::RESTARTED],
            // Grace: Google extends expiry while it retries, access continues.
            'grace' => [['status' => 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD', 'period_end' => $now->addDays(self::GRACE_DAYS)], GoogleNotification::IN_GRACE_PERIOD],
            // On hold: retries continue, access does not.
            'on_hold' => [['status' => 'SUBSCRIPTION_STATE_ON_HOLD', 'period_end' => $now], GoogleNotification::ON_HOLD],
            'recover' => [
                ['status' => 'SUBSCRIPTION_STATE_ACTIVE', 'period_start' => $now, 'period_end' => $now->addDays($length)],
                GoogleNotification::RECOVERED,
            ],
            'pause' => [['status' => 'SUBSCRIPTION_STATE_PAUSED', 'period_end' => $now], GoogleNotification::PAUSED],
            'expire' => [['status' => 'SUBSCRIPTION_STATE_EXPIRED', 'period_end' => $now], GoogleNotification::EXPIRED],
            'revoke' => [['status' => 'SUBSCRIPTION_STATE_EXPIRED', 'period_end' => $now, 'revoked_at' => $now], GoogleNotification::REVOKED],
            default => throw new InvalidArgumentException("Unknown Play action [{$action}]."),
        };

        $subscription->update($changes + ['event_at' => $now]);

        $this->notify($subscription->refresh(), $type);

        return $subscription;
    }

    public function find(string $token): ?StoreSubscription
    {
        return StoreSubscription::query()
            ->where('store', Store::Google->value)
            ->where('identifier', $token)
            ->first();
    }

    /**
     * Play Developer API stand-in: purchases.subscriptionsv2.get.
     *
     * @return array<string, mixed>|null
     */
    public function subscriptionsV2Get(string $token): ?array
    {
        $subscription = $this->find($token);

        if ($subscription === null) {
            return null;
        }

        $purchase = [
            'kind' => 'androidpublisher#subscriptionPurchaseV2',
            'regionCode' => 'US',
            'startTime' => $subscription->period_start->toISOString(),
            'subscriptionState' => $subscription->status,
            'latestOrderId' => 'GPA.'.$subscription->id.'-'.self::millis($subscription->period_start),
            'acknowledgementState' => $subscription->acknowledged ? 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED' : 'ACKNOWLEDGEMENT_STATE_PENDING',
            'lineItems' => [[
                'productId' => $subscription->product_id,
                'expiryTime' => $subscription->period_end->toISOString(),
                'autoRenewingPlan' => ['autoRenewEnabled' => $subscription->auto_renew],
            ]],
        ];

        if ($subscription->app_account_token !== null) {
            $purchase['externalAccountIdentifiers'] = ['obfuscatedExternalAccountId' => $subscription->app_account_token];
        }

        if ($subscription->linked_identifier !== null) {
            $purchase['linkedPurchaseToken'] = $subscription->linked_identifier;
        }

        if ($subscription->environment !== 'Production') {
            $purchase['testPurchase'] = new stdClass;
        }

        return $purchase;
    }

    /** Idempotent, like the real acknowledge call. */
    public function acknowledge(string $token): bool
    {
        $subscription = $this->find($token);

        if ($subscription === null) {
            return false;
        }

        $subscription->update(['acknowledged' => true]);

        return true;
    }

    /**
     * Wrap an RTDN in a Pub/Sub push envelope and queue its delivery.
     *
     * @return string the Pub/Sub messageId
     */
    public function notify(StoreSubscription $subscription, int $type): string
    {
        $messageId = (string) random_int(1_000_000_000_000, 9_999_999_999_999);

        $rtdn = [
            'version' => '1.0',
            'packageName' => $this->settings->googlePackageName(),
            'eventTimeMillis' => (string) self::millis($subscription->event_at),
            'subscriptionNotification' => [
                'version' => '1.0',
                'notificationType' => $type,
                'purchaseToken' => $subscription->identifier,
                'subscriptionId' => $subscription->product_id,
            ],
        ];

        $envelope = [
            'message' => [
                'data' => base64_encode(json_encode($rtdn, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'messageId' => $messageId,
                'publishTime' => CarbonImmutable::now()->toISOString(),
                'attributes' => new stdClass,
            ],
            'subscription' => 'projects/laremit-mock/subscriptions/rtdn-push',
        ];

        $body = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->scheduler->schedule(Store::Google, $body, $messageId);

        return $messageId;
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
