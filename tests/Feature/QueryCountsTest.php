<?php

declare(strict_types=1);

use App\Domain\Billing\Jobs\ChargeJob;
use App\Domain\Billing\Jobs\ProcessWebhookEvent;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Events\Contracts\EventBuffer;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeEventBuffer;

/*
 * Query budgets for the hottest paths (Module 9): an N+1 lands as a diff in
 * an exact number, not as a p99 regression three weeks later in production.
 * Each budget is itemized where it is asserted — when one of these fails,
 * the fix is to re-justify the new number line by line, not to bump it.
 *
 * expectsDatabaseQueryCount() starts counting when called, so every test
 * arranges its world first and states its budget just before acting.
 */

/** @return array<string, mixed> */
function countedEvent(): array
{
    return [
        'event_id' => (string) Str::uuid(),
        'type' => 'video.watched',
        'schema_version' => 2,
        'occurred_at' => now()->toISOString(),
        'user_id' => 42,
        'product' => 'edtech',
        'priority' => 'analytics',
        'payload' => ['position_ms' => 1000],
    ];
}

it('ingests any batch size with ZERO database queries', function (): void {
    // The buffer seam is swapped exactly as the endpoint tests do; the real
    // RedisEventBuffer takes only a Redis factory, so it could not query
    // MySQL if it wanted to. What this pins down is the request path around
    // it: auth, gzip, envelope validation, dedup and shedding all stay off
    // the database — at batch size 1 and at 200 alike.
    $small = ['events' => [countedEvent()]];
    $large = ['events' => array_map(fn (): array => countedEvent(), range(1, 200))];
    $this->app->instance(EventBuffer::class, new FakeEventBuffer);

    $this->expectsDatabaseQueryCount(0);

    $this->postJson('/v1/events', $small, ['Authorization' => 'Bearer test-token'])->assertStatus(202);
    $this->postJson('/v1/events', $large, ['Authorization' => 'Bearer test-token'])->assertStatus(202);
});

it('answers an entitlement check in exactly 2 queries', function (): void {
    $plan = Plan::factory()->create();
    $user = User::factory()->create();
    $product = $plan->product->slug;

    // 1. product slug -> id (selects only `id`, no hydrated row)
    // 2. one EXISTS over subscriptions encoding the whole access rule —
    //    grants-access states OR canceled-but-still-in-period
    // Per request, twice asserted: the second call must not load anything
    // the first cached away.
    $this->expectsDatabaseQueryCount(4);

    foreach (range(1, 2) as $call) {
        $this->getJson(
            sprintf('/v1/entitlements?user_id=%d&product=%s', $user->id, $product),
            ['Authorization' => 'Bearer test-billing-token'],
        )->assertOk();
    }
});

it('starts a payment in exactly 11 queries', function (): void {
    Queue::fake([ChargeJob::class]);
    $plan = Plan::factory()->create(['amount_minor' => 1500]);
    $user = User::factory()->create();
    $product = $plan->product->slug;

    //  1. idempotency claim INSERT (the atomic at-most-once gate)
    //  2. validation exists: users.id
    //  3. validation exists: products.slug
    //  4. load product by slug
    //  5. load plan by (product, slug, active)
    //  6. load user
    //  7. user row FOR UPDATE (the purchase serialization anchor)
    //  8. subscription lookup FOR UPDATE (the one-live-subscription rule)
    //  9. INSERT subscription (incomplete)
    // 10. INSERT payment_intent
    // 11. idempotency record completed UPDATE (stored 202 for replays)
    $this->expectsDatabaseQueryCount(11);

    $this->postJson('/v1/payments', [
        'user_id' => $user->id,
        'product' => $product,
        'plan' => $plan->slug,
    ], [
        'Authorization' => 'Bearer test-billing-token',
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertStatus(202);
});

it('takes a webhook delivery — and its duplicate — in exactly 2 queries each', function (): void {
    Queue::fake([ProcessWebhookEvent::class]);
    $intent = PaymentIntent::factory()->processing()->create();
    $event = pspChargeEvent($intent);

    // Per delivery (the handler itself is queued work, faked out here):
    // 1. INSERT OR IGNORE into webhook_events (persist raw, dedup on the
    //    provider event id unique key)
    // 2. re-select by (provider, provider_event_id) — dispatch decides on
    //    "status is pending", never on "just inserted"
    // A redelivery costs the same two queries and nothing more: dedup work
    // must not scale with duplicate volume.
    $this->expectsDatabaseQueryCount(4);

    deliverPspWebhook($event)->assertOk()->assertJsonPath('duplicate', false);
    deliverPspWebhook($event)->assertOk()->assertJsonPath('duplicate', true);
});
