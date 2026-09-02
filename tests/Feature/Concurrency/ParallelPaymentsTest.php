<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Plan;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\ConcurrencyHarness;

/*
 * Module 9's answer to "how do you test concurrency in PHP": assert
 * invariants, not interleavings. These requests are real parallel HTTP
 * against a real MySQL — you cannot script which of the 20 wins, and the
 * test never tries; it asserts what must be true no matter who won.
 *
 * Everything here runs against the harness's throwaway stack, never the
 * sqlite test database, so each test seeds a fresh user + product and scopes
 * every count to them.
 */

/** @return array{user_id: int, product: string, plan: string} */
function seedPurchasable(ConcurrencyHarness $harness): array
{
    $suffix = strtolower(Str::random(8));

    $userId = $harness->insert('users', User::factory()->raw(['email' => "parallel-{$suffix}@example.test"]));
    $productId = $harness->insert('products', Product::factory()->raw(['slug' => "product-{$suffix}"]));
    $harness->insert('plans', Plan::factory()->raw(['product_id' => $productId, 'amount_minor' => 1500]));

    return ['user_id' => $userId, 'product' => "product-{$suffix}", 'plan' => 'monthly'];
}

/**
 * @param  list<string>  $keys  one entry per request
 * @return list<ResponseInterface>
 */
function burst(ConcurrencyHarness $harness, array $body, array $keys): array
{
    $client = $harness->client();

    $promises = array_map(fn (string $key) => $client->postAsync('/v1/payments', [
        'json' => $body,
        'headers' => [
            'Authorization' => 'Bearer test-billing-token',
            'Idempotency-Key' => $key,
        ],
    ]), $keys);

    return array_values(Utils::unwrap($promises));
}

it('serves 20 parallel requests with one idempotency key as exactly one charge', function (): void {
    $harness = ConcurrencyHarness::boot();

    if ($harness === null) {
        $this->markTestSkipped('MySQL is not reachable; is the compose stack up?');
    }

    $body = seedPurchasable($harness);
    $key = (string) Str::uuid();

    $responses = burst($harness, $body, array_fill(0, 20, $key));

    // Every response is a truthful one of exactly two things: the (possibly
    // replayed) 202, or 409-in-progress with Retry-After. Nothing 5xx'd,
    // and precisely ONE request did the work.
    $statuses = array_map(fn (ResponseInterface $r): int => $r->getStatusCode(), $responses);
    $fresh = array_filter($responses, fn (ResponseInterface $r): bool => $r->getStatusCode() === 202 && ! $r->hasHeader('Idempotency-Replayed'));

    expect(array_unique(array_diff($statuses, [202, 409])))->toBe([])
        ->and(count($fresh))->toBe(1);

    // All 202 bodies, replayed or not, name the same intent.
    $intentIds = [];
    foreach ($responses as $response) {
        if ($response->getStatusCode() === 202) {
            $decoded = json_decode((string) $response->getBody(), true);
            $intentIds[] = is_array($decoded) ? $decoded['payment_intent_id'] : null;
        }
    }

    expect(array_unique($intentIds))->toHaveCount(1);

    // The invariants — one intent, one subscription, one movement of money,
    // one balanced ledger pair — regardless of interleaving.
    $userId = $body['user_id'];
    expect($harness->scalar("SELECT COUNT(*) FROM payment_intents WHERE user_id = {$userId}"))->toBe(1)
        ->and($harness->scalar("SELECT COUNT(*) FROM subscriptions WHERE user_id = {$userId}"))->toBe(1)
        ->and($harness->scalar("SELECT COUNT(*) FROM psp_charges WHERE idempotency_key IN (SELECT psp_idempotency_key FROM payment_intents WHERE user_id = {$userId})"))->toBe(1)
        ->and($harness->scalar("SELECT COUNT(*) FROM payment_intents WHERE user_id = {$userId} AND status = 'succeeded'"))->toBe(1)
        ->and($harness->scalar("SELECT COUNT(*) FROM ledger_entries WHERE reference_id IN (SELECT id FROM payment_intents WHERE user_id = {$userId})"))->toBe(2)
        ->and($harness->scalar("SELECT COALESCE(SUM(amount_minor), -1) FROM ledger_entries WHERE reference_id IN (SELECT id FROM payment_intents WHERE user_id = {$userId})"))->toBe(0);

    // A latecomer with the same key gets the stored answer, marked as such.
    $replay = $harness->client()->post('/v1/payments', [
        'json' => $body,
        'headers' => ['Authorization' => 'Bearer test-billing-token', 'Idempotency-Key' => $key],
    ]);

    $replayBody = json_decode((string) $replay->getBody(), true);

    expect($replay->getStatusCode())->toBe(202)
        ->and($replay->getHeaderLine('Idempotency-Replayed'))->toBe('true')
        ->and(is_array($replayBody) ? $replayBody['payment_intent_id'] : null)->toBe($intentIds[0]);
})->group('concurrency');

it('moves money once for 20 parallel requests even when every key differs', function (): void {
    $harness = ConcurrencyHarness::boot();

    if ($harness === null) {
        $this->markTestSkipped('MySQL is not reachable; is the compose stack up?');
    }

    $body = seedPurchasable($harness);

    // Distinct keys mean the idempotency middleware waves everyone through:
    // the one-live-subscription rule is all that stands between a
    // double-click storm and a double charge.
    $responses = burst($harness, $body, array_map(fn (): string => (string) Str::uuid(), range(1, 20)));

    $statuses = array_map(fn (ResponseInterface $r): int => $r->getStatusCode(), $responses);

    // Exactly one purchase goes through; every rival is refused cleanly
    // (409 already_subscribed / payment_in_progress) — no 5xx leaks out.
    expect(array_unique(array_diff($statuses, [202, 409])))->toBe([])
        ->and(count(array_keys($statuses, 202, true)))->toBe(1);

    $userId = $body['user_id'];
    expect($harness->scalar("SELECT COUNT(*) FROM psp_charges WHERE idempotency_key IN (SELECT psp_idempotency_key FROM payment_intents WHERE user_id = {$userId})"))->toBe(1)
        ->and($harness->scalar("SELECT COUNT(*) FROM payment_intents WHERE user_id = {$userId} AND status = 'succeeded'"))->toBe(1)
        ->and($harness->scalar("SELECT COUNT(*) FROM subscriptions WHERE user_id = {$userId}"))->toBe(1)
        ->and($harness->scalar("SELECT COUNT(*) FROM ledger_entries WHERE reference_id IN (SELECT id FROM payment_intents WHERE user_id = {$userId})"))->toBe(2);
})->group('concurrency');
