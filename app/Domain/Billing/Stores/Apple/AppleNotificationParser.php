<?php

declare(strict_types=1);

namespace App\Domain\Billing\Stores\Apple;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Stores\StoreSubscriptionSnapshot;
use App\Support\Jws\JwsException;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * ASSN v2 -> StoreSubscriptionSnapshot.
 *
 * The status is derived from the signed transaction and renewal info —
 * the STATE Apple attached — never from the notification type. Types are
 * deltas ("this just happened"); state is absolute, and only absolute
 * state can be safely dropped when it arrives stale (ADR-005). The
 * notification type survives as a label for the record.
 */
final readonly class AppleNotificationParser
{
    public function __construct(private AppleJwsVerifier $verifier) {}

    /**
     * @throws JwsException when any of the three signatures fails
     * @throws InvalidArgumentException when the verified payload is not shaped like ASSN v2
     */
    public function parse(string $signedPayload): AppleNotification
    {
        $outer = $this->verifier->decode($signedPayload);

        $uuid = $outer['notificationUUID'] ?? null;
        $type = $outer['notificationType'] ?? null;
        $subtype = $outer['subtype'] ?? null;
        $signedDate = $outer['signedDate'] ?? null;
        $data = $outer['data'] ?? null;

        if (! is_string($uuid) || ! is_string($type) || ! is_int($signedDate) || ! is_array($data)) {
            throw new InvalidArgumentException('ASSN payload is missing notificationUUID, notificationType, signedDate or data.');
        }

        $transactionJws = $data['signedTransactionInfo'] ?? null;
        $renewalJws = $data['signedRenewalInfo'] ?? null;
        $environment = $data['environment'] ?? null;

        if (! is_string($transactionJws) || ! is_string($renewalJws) || ! is_string($environment)) {
            throw new InvalidArgumentException('ASSN data is missing signedTransactionInfo, signedRenewalInfo or environment.');
        }

        $label = is_string($subtype) && $subtype !== '' ? "{$type}/{$subtype}" : $type;
        $eventAt = self::millis($signedDate);

        return new AppleNotification(
            $uuid,
            $label,
            $eventAt,
            $this->snapshotFromSignedInfo($transactionJws, $renewalJws, $environment, $eventAt, $label),
        );
    }

    /**
     * The shared translation, used by the notification path and by the App
     * Store Server API responses reconciliation and restore re-fetch.
     *
     * @throws JwsException
     * @throws InvalidArgumentException
     */
    public function snapshotFromSignedInfo(
        string $transactionJws,
        string $renewalJws,
        string $environment,
        CarbonImmutable $eventAt,
        ?string $notificationType = null,
    ): StoreSubscriptionSnapshot {
        $transaction = $this->verifier->decode($transactionJws);
        $renewal = $this->verifier->decode($renewalJws);

        $originalTransactionId = $transaction['originalTransactionId'] ?? null;
        $productId = $transaction['productId'] ?? null;
        $purchaseDate = $transaction['purchaseDate'] ?? null;
        $expiresDate = $transaction['expiresDate'] ?? null;

        if (! is_string($originalTransactionId) || ! is_string($productId) || ! is_int($purchaseDate) || ! is_int($expiresDate)) {
            throw new InvalidArgumentException('Signed transaction info is missing originalTransactionId, productId, purchaseDate or expiresDate.');
        }

        if (($renewal['originalTransactionId'] ?? null) !== $originalTransactionId) {
            throw new InvalidArgumentException('Signed renewal info belongs to a different transaction.');
        }

        $appAccountToken = $transaction['appAccountToken'] ?? null;
        $revocationDate = $transaction['revocationDate'] ?? null;
        $autoRenew = ($renewal['autoRenewStatus'] ?? 1) === 1;
        $inBillingRetry = ($renewal['isInBillingRetryPeriod'] ?? false) === true;
        $graceUntil = $renewal['gracePeriodExpiresDate'] ?? null;

        $periodStart = self::millis($purchaseDate);
        $periodEnd = self::millis($expiresDate);

        [$status, $storeStatus] = match (true) {
            is_int($revocationDate) => [SubscriptionStatus::Revoked, 'revoked'],
            // Past expiry: still inside a grace window keeps access; billing
            // retry without one does not; neither retrying nor renewing is
            // simply expired.
            $periodEnd->lte($eventAt) && $inBillingRetry && is_int($graceUntil) && self::millis($graceUntil)->isAfter($eventAt) => [SubscriptionStatus::PastDue, 'grace_period'],
            $periodEnd->lte($eventAt) && $inBillingRetry => [SubscriptionStatus::Paused, 'billing_retry'],
            $periodEnd->lte($eventAt) => [SubscriptionStatus::Expired, 'expired'],
            ! $autoRenew => [SubscriptionStatus::Canceled, 'auto_renew_off'],
            default => [SubscriptionStatus::Active, 'active'],
        };

        return new StoreSubscriptionSnapshot(
            store: Store::Apple,
            storeIdentifier: $originalTransactionId,
            linkedIdentifier: null,
            appAccountToken: is_string($appAccountToken) && $appAccountToken !== '' ? $appAccountToken : null,
            storeProductId: $productId,
            environment: $environment,
            status: $status,
            storeStatus: $storeStatus,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            autoRenew: $autoRenew,
            eventAt: $eventAt,
            notificationType: $notificationType,
        );
    }

    /** Apple timestamps are integer milliseconds since the epoch. */
    private static function millis(int $millis): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampMs($millis, 'UTC');
    }
}
