<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\PspClient;
use App\Domain\Billing\Enums\PaymentIntentStatus;
use App\Domain\Billing\Exceptions\PspUnavailable;
use App\Domain\Billing\Jobs\ChargeJob;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Money\Money;
use App\Domain\Billing\Psp\ChargeResult;
use App\Domain\Billing\Psp\RemoteCharge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

/**
 * The PSP circuit breaker, exercised through the real middleware pipeline
 * (dispatchSync runs job middleware). The stub PSP is a call counter that
 * is always down; the claim under test is that the breaker stops the
 * hammering — not the jobs, and not the PSP's own error responses.
 */
final class AlwaysDownPsp implements PspClient
{
    public int $calls = 0;

    public function charge(string $idempotencyKey, Money $amount, array $metadata): ChargeResult
    {
        $this->calls++;

        throw new PspUnavailable('connection refused');
    }

    public function listCharges(CarbonImmutable $since): array
    {
        return [];
    }

    public function findCharge(string $idempotencyKey): ?RemoteCharge
    {
        return null;
    }
}

it('opens after ten connectivity failures and releases further jobs untried', function (): void {
    $psp = new AlwaysDownPsp;
    app()->instance(PspClient::class, $psp);

    $intent = PaymentIntent::factory()->create();

    // Eleven charge attempts against a dead PSP. The breaker swallows each
    // PspUnavailable and releases the job (which is why none of these
    // throw); from the eleventh on, the circuit is open and the job is
    // released before the PSP client is ever invoked.
    foreach (range(1, 11) as $i) {
        Bus::dispatchSync(new ChargeJob($intent->id));
    }

    expect($psp->calls)->toBe(10)
        // And the intent tells the truth throughout: the charge is neither
        // confirmed nor denied, so it stays processing for the webhook or
        // reconciliation to settle — never failed by an outage.
        ->and($intent->refresh()->status)->toBe(PaymentIntentStatus::Processing);
});

it('does not let one lane\'s breaker park the other\'s jobs', function (): void {
    // The PSP breaker is keyed 'psp'; the store breaker 'stores'. Trip the
    // PSP one hard, then verify a fresh charge attempt is still released by
    // the SAME key — while the RateLimiter shows no hits against the store
    // key. Independent dependencies, independent circuits (ADR-007).
    $psp = new AlwaysDownPsp;
    app()->instance(PspClient::class, $psp);

    $intent = PaymentIntent::factory()->create();

    foreach (range(1, 12) as $i) {
        Bus::dispatchSync(new ChargeJob($intent->id));
    }

    /** @var Illuminate\Cache\RateLimiter $limiter */
    $limiter = app(Illuminate\Cache\RateLimiter::class);

    expect($limiter->attempts('laravel_throttles_exceptions:psp'))->toBeGreaterThanOrEqual(10)
        ->and($limiter->attempts('laravel_throttles_exceptions:stores'))->toBe(0);
});
