<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Stores\Apple\AppleJwsVerifier;
use App\MockPsp\Jobs\DeliverPspWebhook;
use App\MockStores\Jobs\DeliverStoreNotification;
use App\MockStores\Models\StoreSubscription;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake([DeliverStoreNotification::class, DeliverPspWebhook::class]);
});

/** @return array<string, mixed> */
function decodeApple(DeliverStoreNotification $job): array
{
    $body = json_decode($job->body, true, 8, JSON_THROW_ON_ERROR);

    return app(AppleJwsVerifier::class)->decode($body['signedPayload']);
}

/** @return array<string, mixed> */
function decodeRtdn(DeliverStoreNotification $job): array
{
    $envelope = json_decode($job->body, true, 8, JSON_THROW_ON_ERROR);

    return json_decode(base64_decode($envelope['message']['data'], true) ?: '{}', true, 8, JSON_THROW_ON_ERROR);
}

it('sells an App Store subscription and signs a SUBSCRIBED notification as JWS', function (): void {
    $response = $this->postJson('/mock-stores/apple/purchases', [
        'product_id' => 'com.laremit.edtech.monthly',
        'app_account_token' => '11111111-1111-4111-8111-111111111111',
    ])->assertStatus(201)->assertJsonPath('status', 'active');

    /** @var DeliverStoreNotification $job */
    $job = Queue::pushed(DeliverStoreNotification::class)->sole();
    $payload = decodeApple($job);

    expect($job->store)->toBe(Store::Apple)
        ->and($payload['notificationType'])->toBe('SUBSCRIBED')
        ->and($payload['subtype'])->toBe('INITIAL_BUY')
        ->and($payload['notificationUUID'])->toBe($job->eventId)
        ->and($payload['data']['environment'])->toBe('Sandbox');

    $transaction = app(AppleJwsVerifier::class)->decode($payload['data']['signedTransactionInfo']);

    expect($transaction['originalTransactionId'])->toBe($response->json('original_transaction_id'))
        ->and($transaction['appAccountToken'])->toBe('11111111-1111-4111-8111-111111111111')
        ->and($transaction['productId'])->toBe('com.laremit.edtech.monthly');
});

it('answers the App Store Server API stand-in with signed state, and 404 for strangers', function (): void {
    $id = $this->postJson('/mock-stores/apple/purchases', ['product_id' => 'com.laremit.vpn.monthly'])->json('original_transaction_id');

    $this->postJson("/mock-stores/apple/subscriptions/{$id}/cancel")->assertOk()->assertJsonPath('auto_renew', false);

    $signed = $this->getJson("/mock-stores/apple/inApps/v1/subscriptions/{$id}")->assertOk();
    $renewal = app(AppleJwsVerifier::class)->decode($signed->json('signedRenewalInfo'));

    expect($renewal['autoRenewStatus'])->toBe(0)
        ->and($renewal['originalTransactionId'])->toBe($id);

    $this->getJson('/mock-stores/apple/inApps/v1/subscriptions/2000000000000000')->assertStatus(404);
    $this->postJson("/mock-stores/apple/subscriptions/{$id}/teleport")->assertStatus(404);
});

it('sells a Play subscription, pushes a Pub/Sub-shaped RTDN, and serves subscriptionsv2', function (): void {
    $response = $this->postJson('/mock-stores/google/purchases', [
        'product_id' => 'com.laremit.edtech.monthly',
        'obfuscated_external_account_id' => 'acct-1',
    ])->assertStatus(201)->assertJsonPath('acknowledged', false);

    $token = $response->json('purchase_token');

    /** @var DeliverStoreNotification $job */
    $job = Queue::pushed(DeliverStoreNotification::class)->sole();
    $rtdn = decodeRtdn($job);

    expect($job->store)->toBe(Store::Google)
        ->and($rtdn['subscriptionNotification']['notificationType'])->toBe(4)
        ->and($rtdn['subscriptionNotification']['purchaseToken'])->toBe($token)
        ->and($rtdn['packageName'])->toBe('com.laremit.app');

    $purchase = $this->getJson("/mock-stores/google/androidpublisher/v3/applications/com.laremit.app/purchases/subscriptionsv2/tokens/{$token}")
        ->assertOk()
        ->assertJsonPath('subscriptionState', 'SUBSCRIPTION_STATE_ACTIVE')
        ->assertJsonPath('acknowledgementState', 'ACKNOWLEDGEMENT_STATE_PENDING')
        ->assertJsonPath('externalAccountIdentifiers.obfuscatedExternalAccountId', 'acct-1')
        ->assertJsonPath('lineItems.0.productId', 'com.laremit.edtech.monthly');

    expect($purchase->json('testPurchase'))->not->toBeNull();

    $this->postJson("/mock-stores/google/androidpublisher/v3/applications/com.laremit.app/purchases/subscriptionsv2/tokens/{$token}/acknowledge")->assertOk();
    $this->getJson("/mock-stores/google/androidpublisher/v3/applications/com.laremit.app/purchases/subscriptionsv2/tokens/{$token}")
        ->assertJsonPath('acknowledgementState', 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED');

    $this->getJson('/mock-stores/google/androidpublisher/v3/applications/com.laremit.app/purchases/subscriptionsv2/tokens/nope')->assertStatus(404);
});

it('links a resubscribe to the old token and expires the old purchase', function (): void {
    $old = $this->postJson('/mock-stores/google/purchases', ['product_id' => 'com.laremit.edtech.monthly', 'obfuscated_external_account_id' => 'acct-2'])->json('purchase_token');

    $new = $this->postJson('/mock-stores/google/purchases', ['product_id' => 'com.laremit.edtech.monthly', 'linked_purchase_token' => $old])
        ->assertStatus(201)
        ->assertJsonPath('linked_purchase_token', $old)
        ->assertJsonPath('obfuscated_external_account_id', 'acct-2')
        ->json('purchase_token');

    $this->getJson("/mock-stores/google/androidpublisher/v3/applications/com.laremit.app/purchases/subscriptionsv2/tokens/{$old}")
        ->assertJsonPath('subscriptionState', 'SUBSCRIPTION_STATE_EXPIRED');
    $this->getJson("/mock-stores/google/androidpublisher/v3/applications/com.laremit.app/purchases/subscriptionsv2/tokens/{$new}")
        ->assertJsonPath('linkedPurchaseToken', $old);
});

it('stamps every change with a strictly increasing store clock', function (): void {
    $id = $this->postJson('/mock-stores/apple/purchases', ['product_id' => 'com.laremit.edtech.monthly'])->json('original_transaction_id');
    $first = StoreSubscription::query()->sole()->event_at;

    $this->postJson("/mock-stores/apple/subscriptions/{$id}/cancel");
    $second = StoreSubscription::query()->sole()->event_at;

    $this->postJson("/mock-stores/apple/subscriptions/{$id}/resume");
    $third = StoreSubscription::query()->sole()->event_at;

    expect($second->isAfter($first))->toBeTrue()
        ->and($third->isAfter($second))->toBeTrue();
});

it('exposes delivery knobs and drops notifications when told to', function (): void {
    $configured = $this->postJson('/mock-stores/config', ['delivery' => ['drop_rate' => 1.0]])
        ->assertOk()
        ->assertJsonMissingPath('apple.signing_key');

    expect($configured->json('delivery.drop_rate'))->toEqual(1.0);

    $this->postJson('/mock-stores/apple/purchases', ['product_id' => 'com.laremit.edtech.monthly'])->assertStatus(201);

    Queue::assertNothingPushed();

    $this->deleteJson('/mock-stores/config')->assertJsonPath('delivery.drop_rate', 0);
});
