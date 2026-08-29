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
use App\MockStores\Apple\MockAppStore;
use App\MockStores\Jobs\DeliverStoreNotification;
use App\Support\Jws\Jws;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

function appStore(): MockAppStore
{
    return app(MockAppStore::class);
}

function appleProductId(string $product = 'edtech', string $plan = 'monthly'): string
{
    return StoreProductId::of($product, $plan);
}

function storeRow(string $identifier): ?Subscription
{
    return Subscription::query()->where('store_original_transaction_id', $identifier)->first();
}

function hasAccess(User $user, string $product = 'edtech'): bool
{
    return (bool) test()->getJson(
        sprintf('/v1/entitlements?user_id=%d&product=%s', $user->id, $product),
        ['Authorization' => 'Bearer test-billing-token'],
    )->json('has_access');
}

beforeEach(function (): void {
    Queue::fake([DeliverStoreNotification::class, DeliverPspWebhook::class]);

    $product = Product::factory()->create(['slug' => 'edtech']);
    $this->plan = Plan::factory()->for($product)->create(['slug' => 'monthly']);
    $this->user = User::factory()->create();
});

it('creates an active subscription for the linked user from a signed SUBSCRIBED notification', function (): void {
    $purchase = appStore()->purchase(appleProductId(), $this->user->app_account_token);

    $responses = drainStoreNotifications();

    expect($responses)->toHaveCount(1);
    $responses[0]->assertOk()->assertJsonPath('duplicate', false);

    $subscription = storeRow($purchase->identifier);

    expect($subscription)->not->toBeNull()
        ->and($subscription?->user_id)->toBe($this->user->id)
        ->and($subscription?->plan_id)->toBe($this->plan->id)
        ->and($subscription?->store)->toBe(Store::Apple)
        ->and($subscription?->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription?->current_period_end?->isAfter(CarbonImmutable::now()->addDays(29)))->toBeTrue()
        ->and($subscription?->last_event_at?->equalTo($purchase->event_at))->toBeTrue()
        ->and(hasAccess($this->user))->toBeTrue();

    $stored = WebhookEvent::query()->sole();

    expect($stored->provider)->toBe(Store::Apple)
        ->and($stored->type)->toBe('SUBSCRIBED/INITIAL_BUY')
        ->and($stored->status)->toBe(WebhookEventStatus::Processed)
        ->and($stored->outcome)->toBe('applied');
});

it('rejects a payload signed with a key that is not the pinned one', function (): void {
    $stranger = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($stranger, $pem);

    $forged = Jws::encode([], [
        'notificationType' => 'SUBSCRIBED',
        'notificationUUID' => 'forged',
        'signedDate' => (int) (microtime(true) * 1000),
        'data' => [],
    ], (string) $pem);

    deliverAppleNotification(json_encode(['signedPayload' => $forged], JSON_THROW_ON_ERROR))
        ->assertStatus(401)
        ->assertJsonPath('error', 'invalid_signature');

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0);
});

it('rejects bodies that are not a signed payload, and fails closed without a pinned key', function (): void {
    deliverAppleNotification('{}')->assertStatus(400)->assertJsonPath('error', 'malformed_payload');
    deliverAppleNotification('nope')->assertStatus(400)->assertJsonPath('error', 'malformed_json');

    config()->set('billing.stores.apple.public_key', null);
    deliverAppleNotification('{"signedPayload":"a.b.c"}')->assertStatus(503);
});

it('dedupes on notificationUUID', function (): void {
    appStore()->purchase(appleProductId(), $this->user->app_account_token);

    /** @var DeliverStoreNotification $job */
    $job = Queue::pushed(DeliverStoreNotification::class)->first();

    deliverAppleNotification($job->body)->assertOk()->assertJsonPath('duplicate', false);
    deliverAppleNotification($job->body)->assertOk()->assertJsonPath('duplicate', true);

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1);
});

it('extends the period on DID_RENEW', function (): void {
    $purchase = appStore()->purchase(appleProductId(), $this->user->app_account_token);
    drainStoreNotifications();

    appStore()->act($purchase->identifier, 'renew');
    drainStoreNotifications();

    $subscription = storeRow($purchase->identifier);

    expect($subscription?->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription?->current_period_end?->isAfter(CarbonImmutable::now()->addDays(59)))->toBeTrue()
        ->and(WebhookEvent::query()->where('type', 'DID_RENEW')->sole()->outcome)->toBe('applied');
});

it('treats auto-renew off as canceled with access to the end of the paid period, and reinstates on re-enable', function (): void {
    $purchase = appStore()->purchase(appleProductId(), $this->user->app_account_token);
    drainStoreNotifications();

    appStore()->act($purchase->identifier, 'cancel');
    drainStoreNotifications();

    $subscription = storeRow($purchase->identifier);

    expect($subscription?->status)->toBe(SubscriptionStatus::Canceled)
        ->and($subscription?->canceled_at)->not->toBeNull()
        ->and(hasAccess($this->user))->toBeTrue();

    appStore()->act($purchase->identifier, 'resume');
    drainStoreNotifications();

    expect(storeRow($purchase->identifier)?->status)->toBe(SubscriptionStatus::Active)
        ->and(storeRow($purchase->identifier)?->canceled_at)->toBeNull();
});

it('rejects a stale notification that arrives after a newer one', function (): void {
    $purchase = appStore()->purchase(appleProductId(), $this->user->app_account_token);
    appStore()->act($purchase->identifier, 'cancel');

    // Reordered on the wire: the cancellation lands before the purchase.
    drainStoreNotifications(static fn (array $jobs): array => array_reverse($jobs));

    expect(storeRow($purchase->identifier)?->status)->toBe(SubscriptionStatus::Canceled)
        ->and(WebhookEvent::query()->where('type', 'like', 'DID_CHANGE_RENEWAL_STATUS%')->sole()->outcome)->toBe('applied')
        ->and(WebhookEvent::query()->where('type', 'SUBSCRIBED/INITIAL_BUY')->sole()->outcome)->toBe('stale');
});

it('maps a grace period to past_due with access, then expiry to no access', function (): void {
    $purchase = appStore()->purchase(appleProductId(), $this->user->app_account_token);
    drainStoreNotifications();

    appStore()->act($purchase->identifier, 'fail_payment');
    drainStoreNotifications();

    expect(storeRow($purchase->identifier)?->status)->toBe(SubscriptionStatus::PastDue)
        ->and(hasAccess($this->user))->toBeTrue();

    appStore()->act($purchase->identifier, 'expire');
    drainStoreNotifications();

    expect(storeRow($purchase->identifier)?->status)->toBe(SubscriptionStatus::Expired)
        ->and(hasAccess($this->user))->toBeFalse();
});

it('revokes on REFUND and on REVOKE', function (string $action): void {
    $purchase = appStore()->purchase(appleProductId(), $this->user->app_account_token);
    drainStoreNotifications();

    appStore()->act($purchase->identifier, $action);
    drainStoreNotifications();

    $subscription = storeRow($purchase->identifier);

    expect($subscription?->status)->toBe(SubscriptionStatus::Revoked)
        ->and($subscription?->revoked_at)->not->toBeNull()
        ->and($subscription?->withinCurrentPeriod())->toBeTrue()
        ->and(hasAccess($this->user))->toBeFalse();
})->with(['refund', 'revoke']);

it('discards a Sandbox notification when the app expects Production', function (): void {
    config()->set('billing.stores.environment', 'Production');

    appStore()->purchase(appleProductId(), $this->user->app_account_token);
    drainStoreNotifications()[0]->assertOk();

    expect(WebhookEvent::query()->sole()->status)->toBe(WebhookEventStatus::Discarded)
        ->and(WebhookEvent::query()->sole()->outcome)->toBe('wrong_environment')
        ->and(Subscription::query()->count())->toBe(0);
});

it('discards a purchase of a product that is not in the catalog', function (): void {
    appStore()->purchase('com.laremit.nothing.monthly', $this->user->app_account_token);
    drainStoreNotifications();

    expect(WebhookEvent::query()->sole()->outcome)->toBe('unknown_product')
        ->and(Subscription::query()->count())->toBe(0);
});

it('holds an unlinked purchase until a client restores it', function (): void {
    $purchase = appStore()->purchase(appleProductId(), null);
    drainStoreNotifications();

    expect(WebhookEvent::query()->sole()->outcome)->toBe('unknown_user')
        ->and(Subscription::query()->count())->toBe(0);

    $this->postJson('/v1/iap/apple/sync', [
        'user_id' => $this->user->id,
        'identifier' => $purchase->identifier,
    ], ['Authorization' => 'Bearer test-billing-token'])
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('has_access', true);

    expect(storeRow($purchase->identifier)?->user_id)->toBe($this->user->id);
});

it('creates the row from a later notification when the first one was lost', function (): void {
    $purchase = appStore()->purchase(appleProductId(), $this->user->app_account_token);
    Queue::fake([DeliverStoreNotification::class, DeliverPspWebhook::class]); // the SUBSCRIBED delivery is gone

    appStore()->act($purchase->identifier, 'renew');
    drainStoreNotifications();

    expect(storeRow($purchase->identifier)?->status)->toBe(SubscriptionStatus::Active)
        ->and(hasAccess($this->user))->toBeTrue();
});

it('resubscribes on the same store identity after expiry, on the same row', function (): void {
    $purchase = appStore()->purchase(appleProductId(), $this->user->app_account_token);
    drainStoreNotifications();
    appStore()->act($purchase->identifier, 'expire');
    drainStoreNotifications();

    expect(storeRow($purchase->identifier)?->status)->toBe(SubscriptionStatus::Expired);

    // Apple keeps the originalTransactionId across a resubscribe.
    appStore()->act($purchase->identifier, 'renew');
    drainStoreNotifications();

    expect(storeRow($purchase->identifier)?->status)->toBe(SubscriptionStatus::Active)
        ->and(Subscription::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(hasAccess($this->user))->toBeTrue();
});
