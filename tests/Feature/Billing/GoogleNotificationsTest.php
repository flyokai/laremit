<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\WebhookEventStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\WebhookEvent;
use App\Domain\Billing\Stores\StoreProductId;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\MockPsp\Jobs\DeliverPspWebhook;
use App\MockStores\Google\MockPlayStore;
use App\MockStores\Jobs\DeliverStoreNotification;
use Illuminate\Support\Facades\Queue;

function playStore(): MockPlayStore
{
    return app(MockPlayStore::class);
}

/** @return array<string, mixed> */
function rtdnEnvelope(string $purchaseToken, int $type, ?string $messageId = null): array
{
    return [
        'message' => [
            'data' => base64_encode(json_encode([
                'version' => '1.0',
                'packageName' => 'com.laremit.app',
                'eventTimeMillis' => (string) (int) (microtime(true) * 1000),
                'subscriptionNotification' => [
                    'version' => '1.0',
                    'notificationType' => $type,
                    'purchaseToken' => $purchaseToken,
                    'subscriptionId' => 'com.laremit.edtech.monthly',
                ],
            ], JSON_THROW_ON_ERROR)),
            'messageId' => $messageId ?? (string) random_int(1, PHP_INT_MAX),
            'publishTime' => now()->toISOString(),
        ],
        'subscription' => 'projects/x/subscriptions/y',
    ];
}

beforeEach(function (): void {
    Queue::fake([DeliverStoreNotification::class, DeliverPspWebhook::class]);

    $product = Product::factory()->create(['slug' => 'edtech']);
    $this->plan = Plan::factory()->for($product)->create(['slug' => 'monthly']);
    $this->user = User::factory()->create();
    $this->productId = StoreProductId::of('edtech', 'monthly');
});

it('re-fetches the purchase, activates the subscription and acknowledges it on SUBSCRIPTION_PURCHASED', function (): void {
    $purchase = playStore()->purchase($this->productId, $this->user->app_account_token);

    drainStoreNotifications()[0]->assertOk();

    $subscription = storeRow($purchase->identifier);

    expect($subscription?->store)->toBe(Store::Google)
        ->and($subscription?->user_id)->toBe($this->user->id)
        ->and($subscription?->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription?->last_event_at?->equalTo($purchase->event_at))->toBeTrue()
        ->and(hasAccess($this->user))->toBeTrue()
        ->and($purchase->refresh()->acknowledged)->toBeTrue();

    $stored = WebhookEvent::query()->sole();

    expect($stored->provider)->toBe(Store::Google)
        ->and($stored->type)->toBe('SUBSCRIPTION_PURCHASED')
        ->and($stored->outcome)->toBe('applied');
});

it('rejects a wrong or missing verification token, and fails closed without one', function (): void {
    $body = json_encode(rtdnEnvelope('tok', 4), JSON_THROW_ON_ERROR);

    deliverGoogleNotification($body, 'wrong')->assertStatus(401);
    $this->call('POST', '/v1/iap/google/notifications', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);

    config()->set('billing.stores.google.pubsub_token', null);
    deliverGoogleNotification($body, 'anything')->assertStatus(503);

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('rejects a malformed envelope', function (): void {
    deliverGoogleNotification('{}')->assertStatus(400);
    deliverGoogleNotification('{"message":{"messageId":"1","publishTime":"2026-01-01T00:00:00Z","data":"!!!"}}')->assertStatus(400);
});

it('dedupes on the Pub/Sub messageId', function (): void {
    playStore()->purchase($this->productId, $this->user->app_account_token);

    /** @var DeliverStoreNotification $job */
    $job = Queue::pushed(DeliverStoreNotification::class)->first();

    deliverGoogleNotification($job->body)->assertJsonPath('duplicate', false);
    deliverGoogleNotification($job->body)->assertJsonPath('duplicate', true);

    expect(WebhookEvent::query()->count())->toBe(1);
});

it('treats a forged notification for a token the store does not know as harmless', function (): void {
    deliverGoogleNotification(json_encode(rtdnEnvelope('not-a-real-token', 4), JSON_THROW_ON_ERROR))->assertOk();

    $stored = WebhookEvent::query()->sole();

    expect($stored->status)->toBe(WebhookEventStatus::Discarded)
        ->and($stored->outcome)->toBe('unknown_purchase')
        ->and(Subscription::query()->count())->toBe(0);
});

it('acknowledges and ignores test notifications', function (): void {
    $envelope = rtdnEnvelope('x', 4);
    $envelope['message']['data'] = base64_encode(json_encode([
        'version' => '1.0',
        'packageName' => 'com.laremit.app',
        'eventTimeMillis' => '1',
        'testNotification' => ['version' => '1.0'],
    ], JSON_THROW_ON_ERROR));

    deliverGoogleNotification(json_encode($envelope, JSON_THROW_ON_ERROR))->assertOk();

    expect(WebhookEvent::query()->sole()->outcome)->toBe('ignored')
        ->and(WebhookEvent::query()->sole()->type)->toBe('TEST_NOTIFICATION');
});

it('mirrors the store through cancel, restart, grace, hold, recovery and expiry', function (): void {
    $purchase = playStore()->purchase($this->productId, $this->user->app_account_token);
    drainStoreNotifications();

    $expectations = [
        'cancel' => [SubscriptionStatus::Canceled, true],
        'restart' => [SubscriptionStatus::Active, true],
        'grace' => [SubscriptionStatus::PastDue, true],
        'on_hold' => [SubscriptionStatus::Paused, false],
        'recover' => [SubscriptionStatus::Active, true],
        'pause' => [SubscriptionStatus::Paused, false],
        'expire' => [SubscriptionStatus::Expired, false],
    ];

    foreach ($expectations as $action => [$status, $access]) {
        playStore()->act($purchase->identifier, $action);
        drainStoreNotifications();

        expect(storeRow($purchase->identifier)?->status)->toBe($status, "after {$action}")
            ->and(hasAccess($this->user))->toBe($access, "access after {$action}");
    }
});

it('marks REVOKED as revoked even though the store merely says expired', function (): void {
    $purchase = playStore()->purchase($this->productId, $this->user->app_account_token);
    drainStoreNotifications();

    playStore()->act($purchase->identifier, 'revoke');
    drainStoreNotifications();

    $subscription = storeRow($purchase->identifier);

    expect($subscription?->status)->toBe(SubscriptionStatus::Revoked)
        ->and($subscription?->revoked_at)->not->toBeNull()
        ->and(hasAccess($this->user))->toBeFalse();
});

it('skips the re-fetch for a notification older than the watermark', function (): void {
    $purchase = playStore()->purchase($this->productId, $this->user->app_account_token);
    playStore()->act($purchase->identifier, 'cancel');

    drainStoreNotifications(static fn (array $jobs): array => array_reverse($jobs));

    expect(storeRow($purchase->identifier)?->status)->toBe(SubscriptionStatus::Canceled)
        ->and(WebhookEvent::query()->where('type', 'SUBSCRIPTION_PURCHASED')->sole()->outcome)->toBe('stale');
});

it('follows linkedPurchaseToken on resubscribe and keeps one row per identity', function (): void {
    $first = playStore()->purchase($this->productId, $this->user->app_account_token);
    drainStoreNotifications();
    playStore()->act($first->identifier, 'expire');
    drainStoreNotifications();

    expect(storeRow($first->identifier)?->status)->toBe(SubscriptionStatus::Expired);

    $second = playStore()->purchase($this->productId, null, 30, $first->identifier);
    drainStoreNotifications();

    expect(Subscription::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(storeRow($first->identifier))->toBeNull()
        ->and(storeRow($second->identifier)?->status)->toBe(SubscriptionStatus::Active)
        ->and(hasAccess($this->user))->toBeTrue();
});
