<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Exceptions\StoreUnavailable;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Stores\StoreClient;
use App\Domain\Billing\Stores\StoreProductId;
use App\Domain\Billing\Stores\StoreSubscriptionSnapshot;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\MockPsp\Jobs\DeliverPspWebhook;
use App\MockStores\Apple\MockAppStore;
use App\MockStores\Google\MockPlayStore;
use App\MockStores\Jobs\DeliverStoreNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/** @return array<string, string> */
function billingHeaders(): array
{
    return ['Authorization' => 'Bearer test-billing-token'];
}

beforeEach(function (): void {
    Queue::fake([DeliverStoreNotification::class, DeliverPspWebhook::class]);

    $product = Product::factory()->create(['slug' => 'edtech']);
    Plan::factory()->for($product)->create(['slug' => 'monthly']);
    $this->user = User::factory()->create();
    $this->productId = StoreProductId::of('edtech', 'monthly');
});

it('never grants from the claim: an identifier the store does not know is a 404', function (string $store): void {
    $this->postJson("/v1/iap/{$store}/sync", ['user_id' => $this->user->id, 'identifier' => 'made-up'], billingHeaders())
        ->assertStatus(404)
        ->assertJsonPath('error', 'unknown_purchase');

    expect(Subscription::query()->count())->toBe(0);
})->with(['apple', 'google']);

it('links an unlinked store purchase to the caller and grants access', function (): void {
    $purchase = app(MockPlayStore::class)->purchase($this->productId, null);

    $this->postJson('/v1/iap/google/sync', ['user_id' => $this->user->id, 'identifier' => $purchase->identifier], billingHeaders())
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('verdict', 'applied')
        ->assertJsonPath('has_access', true);

    $subscription = Subscription::query()->sole();

    expect($subscription->user_id)->toBe($this->user->id)
        ->and($subscription->store)->toBe(Store::Google);

    // Idempotent: restoring again confirms, changes nothing.
    $this->postJson('/v1/iap/google/sync', ['user_id' => $this->user->id, 'identifier' => $purchase->identifier], billingHeaders())
        ->assertOk()
        ->assertJsonPath('verdict', 'confirmed');
});

it('refuses to attach a purchase the store links to another account', function (): void {
    $owner = User::factory()->create();
    $purchase = app(MockAppStore::class)->purchase($this->productId, $owner->app_account_token);

    $this->postJson('/v1/iap/apple/sync', ['user_id' => $this->user->id, 'identifier' => $purchase->identifier], billingHeaders())
        ->assertStatus(409)
        ->assertJsonPath('error', 'owned_by_another_account');

    expect(Subscription::query()->count())->toBe(0);
});

it('reports the store being down instead of guessing', function (): void {
    app()->instance(StoreClient::class, new class implements StoreClient
    {
        public function fetchSubscription(Store $store, string $identifier, CarbonImmutable $eventAt, ?string $notificationType = null): ?StoreSubscriptionSnapshot
        {
            throw new StoreUnavailable('App Store Server API answered 503.');
        }

        public function acknowledge(Store $store, string $identifier): void {}

        public function needsAcknowledgement(Store $store, string $identifier): bool
        {
            return false;
        }
    });

    $this->postJson('/v1/iap/apple/sync', ['user_id' => $this->user->id, 'identifier' => '2000'], billingHeaders())
        ->assertStatus(503)
        ->assertJsonPath('error', 'store_unavailable');
});

it('requires the billing token and a known store', function (): void {
    $this->postJson('/v1/iap/apple/sync', ['user_id' => $this->user->id, 'identifier' => 'x'])->assertStatus(401);
    $this->postJson('/v1/iap/psp/sync', ['user_id' => $this->user->id, 'identifier' => 'x'], billingHeaders())->assertStatus(404);
    $this->postJson('/v1/iap/apple/sync', ['user_id' => 999999, 'identifier' => 'x'], billingHeaders())->assertStatus(422);
});
