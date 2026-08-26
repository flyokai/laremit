<?php

declare(strict_types=1);

use App\Domain\Billing\Jobs\ChargeJob;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Identity\Models\User;
use App\Http\Middleware\EnforceIdempotency;
use App\MockPsp\Jobs\DeliverPspWebhook;
use App\Support\Idempotency\IdempotencyRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

/** @return array{user_id: int, product: string, plan: string} */
function idemPaymentBody(): array
{
    $plan = Plan::query()->firstOr(fn () => Plan::factory()->create());
    $user = User::query()->firstOr(fn () => User::factory()->create());

    return ['user_id' => $user->id, 'product' => $plan->product->slug, 'plan' => $plan->slug];
}

/** @return array<string, string> */
function idemHeaders(string $key): array
{
    return ['Authorization' => 'Bearer test-billing-token', 'Idempotency-Key' => $key];
}

beforeEach(function (): void {
    Queue::fake([ChargeJob::class, DeliverPspWebhook::class]);
});

it('requires the Idempotency-Key header', function (): void {
    $this->postJson('/v1/payments', idemPaymentBody(), idemHeaders(''))
        ->assertStatus(400)
        ->assertJsonPath('error', 'missing_idempotency_key');

    $this->postJson('/v1/payments', idemPaymentBody(), idemHeaders(str_repeat('k', 129)))
        ->assertStatus(400);
});

it('replays the stored response for a retried key, creating nothing twice', function (): void {
    $body = idemPaymentBody();

    $first = $this->postJson('/v1/payments', $body, idemHeaders('key-replay'))->assertStatus(202);
    $second = $this->postJson('/v1/payments', $body, idemHeaders('key-replay'))->assertStatus(202);

    expect($second->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and($second->getContent())->toBe($first->getContent())
        ->and(PaymentIntent::query()->count())->toBe(1);
});

it('rejects the same key with a different request body', function (): void {
    $body = idemPaymentBody();

    $this->postJson('/v1/payments', $body, idemHeaders('key-reused'))->assertStatus(202);

    $other = $body;
    $other['plan'] = 'yearly';

    $this->postJson('/v1/payments', $other, idemHeaders('key-reused'))
        ->assertStatus(422)
        ->assertJsonPath('error', 'idempotency_key_reused');
});

it('answers 409 with Retry-After while the original request is still running', function (): void {
    $body = idemPaymentBody();
    $hash = hash('sha256', 'POST|v1/payments|'.json_encode($body));

    IdempotencyRecord::query()->create([
        'key' => 'key-running',
        'request_hash' => $hash,
        'status' => IdempotencyRecord::STATUS_RUNNING,
        'locked_at' => CarbonImmutable::now(),
    ]);

    $this->postJson('/v1/payments', $body, idemHeaders('key-running'))
        ->assertStatus(409)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error', 'request_in_progress');
});

it('takes over a stale running claim from a crashed request', function (): void {
    $body = idemPaymentBody();
    $hash = hash('sha256', 'POST|v1/payments|'.json_encode($body));

    IdempotencyRecord::query()->create([
        'key' => 'key-stale',
        'request_hash' => $hash,
        'status' => IdempotencyRecord::STATUS_RUNNING,
        'locked_at' => CarbonImmutable::now()->subSeconds((int) config('billing.idempotency.lock_seconds') + 5),
    ]);

    $this->postJson('/v1/payments', $body, idemHeaders('key-stale'))->assertStatus(202);

    expect(IdempotencyRecord::query()->where('key', 'key-stale')->value('status'))
        ->toBe(IdempotencyRecord::STATUS_COMPLETED);
});

it('stores client-error responses for replay too', function (): void {
    $body = idemPaymentBody();
    $body['plan'] = 'no-such-plan';

    $this->postJson('/v1/payments', $body, idemHeaders('key-422'))->assertStatus(422);

    $this->postJson('/v1/payments', $body, idemHeaders('key-422'))
        ->assertStatus(422)
        ->assertHeader('Idempotency-Replayed', 'true');
});

it('releases the claim on a 5xx so the client can retry', function (): void {
    Route::post('/idem-boom', function () {
        return response()->json(['error' => 'boom'], 503);
    })->middleware(EnforceIdempotency::class);

    $this->postJson('/idem-boom', [], ['Idempotency-Key' => 'key-5xx'])->assertStatus(503);

    expect(IdempotencyRecord::query()->where('key', 'key-5xx')->exists())->toBeFalse();
});

it('releases the claim when the handler throws', function (): void {
    Route::post('/idem-throw', function (): never {
        throw new RuntimeException('kaboom');
    })->middleware(EnforceIdempotency::class);

    $this->postJson('/idem-throw', [], ['Idempotency-Key' => 'key-throw'])->assertStatus(500);

    expect(IdempotencyRecord::query()->where('key', 'key-throw')->exists())->toBeFalse();
});

it('prunes records past the retention window', function (): void {
    IdempotencyRecord::query()->create([
        'key' => 'key-old',
        'request_hash' => 'h',
        'status' => IdempotencyRecord::STATUS_COMPLETED,
        'locked_at' => CarbonImmutable::now()->subDays(3),
    ]);
    IdempotencyRecord::query()->where('key', 'key-old')
        ->update(['created_at' => CarbonImmutable::now()->subDays(3)]);

    $this->artisan('model:prune', ['--model' => [IdempotencyRecord::class]])->assertSuccessful();

    expect(IdempotencyRecord::query()->where('key', 'key-old')->exists())->toBeFalse();
});
