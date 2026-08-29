<?php

declare(strict_types=1);

namespace App\Domain\Billing\Stores;

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Exceptions\StoreUnavailable;
use App\Domain\Billing\Stores\Apple\AppleNotificationParser;
use App\Domain\Billing\Stores\Google\GooglePurchaseMapper;
use App\Support\Jws\Jws;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Real-HTTP store client against the mock stores' API stand-ins. Route
 * shapes mirror the real ones closely enough that swapping the base URL
 * (and adding the real auth: an ES256 JWT for Apple, a service-account
 * OAuth2 token for Google) is the production delta.
 */
final readonly class HttpStoreClient implements StoreClient
{
    public function __construct(
        private Http $http,
        private AppleNotificationParser $apple,
        private string $baseUrl,
        private float $timeoutSeconds,
        private string $googlePackageName,
    ) {}

    public function fetchSubscription(Store $store, string $identifier, CarbonImmutable $eventAt, ?string $notificationType = null): ?StoreSubscriptionSnapshot
    {
        return match ($store) {
            Store::Apple => $this->fetchApple($identifier, $notificationType),
            Store::Google => $this->fetchGoogle($identifier, $eventAt, $notificationType),
            Store::Psp => null,
        };
    }

    public function acknowledge(Store $store, string $identifier): void
    {
        if ($store !== Store::Google) {
            return;
        }

        $response = $this->send(fn (PendingRequest $request): Response => $request->post($this->googlePath($identifier).'/acknowledge'));

        if (! $response->successful() && $response->status() !== 404) {
            throw new StoreUnavailable("Play API acknowledge answered {$response->status()}.");
        }
    }

    public function needsAcknowledgement(Store $store, string $identifier): bool
    {
        if ($store !== Store::Google) {
            return false;
        }

        $response = $this->send(fn (PendingRequest $request): Response => $request->get($this->googlePath($identifier)));

        return $response->successful() && GooglePurchaseMapper::acknowledgementPending($this->body($response));
    }

    private function fetchApple(string $originalTransactionId, ?string $notificationType): ?StoreSubscriptionSnapshot
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get("/apple/inApps/v1/subscriptions/{$originalTransactionId}"));

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new StoreUnavailable("App Store Server API answered {$response->status()}.");
        }

        $body = $this->body($response);
        $transactionJws = $body['signedTransactionInfo'] ?? null;
        $renewalJws = $body['signedRenewalInfo'] ?? null;
        $environment = $body['environment'] ?? null;

        if (! is_string($transactionJws) || ! is_string($renewalJws) || ! is_string($environment)) {
            throw new StoreUnavailable('App Store Server API answered without signed subscription info.');
        }

        // The store's clock is the signedDate inside the signed data; read
        // it unverified only to stamp the snapshot — the parser verifies
        // every byte before anything is believed.
        $signedDate = Jws::peekPayload($transactionJws)['signedDate'] ?? null;
        $eventAt = is_int($signedDate) ? CarbonImmutable::createFromTimestampMs($signedDate, 'UTC') : CarbonImmutable::now();

        return $this->apple->snapshotFromSignedInfo($transactionJws, $renewalJws, $environment, $eventAt, $notificationType);
    }

    private function fetchGoogle(string $purchaseToken, CarbonImmutable $eventAt, ?string $notificationType): ?StoreSubscriptionSnapshot
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get($this->googlePath($purchaseToken)));

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new StoreUnavailable("Play Developer API answered {$response->status()}.");
        }

        return GooglePurchaseMapper::snapshot($this->body($response), $purchaseToken, $eventAt, $notificationType);
    }

    private function googlePath(string $purchaseToken): string
    {
        return "/google/androidpublisher/v3/applications/{$this->googlePackageName}/purchases/subscriptionsv2/tokens/{$purchaseToken}";
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        try {
            return $call(
                $this->http
                    ->baseUrl($this->baseUrl)
                    ->timeout($this->timeoutSeconds)
                    ->connectTimeout(min(2.0, $this->timeoutSeconds))
                    ->acceptJson(),
            );
        } catch (ConnectionException $e) {
            throw new StoreUnavailable("Store gave no answer: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
