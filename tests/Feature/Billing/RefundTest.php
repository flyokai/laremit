<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\LedgerAccount;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\WebhookEventStatus;
use App\Domain\Billing\Jobs\ProcessWebhookEvent;
use App\Domain\Billing\Ledger\Ledger;
use App\Domain\Billing\Models\LedgerEntry;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Models\WebhookEvent;
use Illuminate\Support\Str;

/** A succeeded intent with an active subscription, settled through the real webhook path. */
function settledIntent(int $amount = 1499): PaymentIntent
{
    $intent = PaymentIntent::factory()->processing()->create();
    PaymentIntent::query()->whereKey($intent->id)->update(['amount_minor' => $amount]);
    $intent->refresh();

    deliverPspWebhook(pspChargeEvent($intent))->assertOk();

    return $intent->refresh();
}

function refundsBalance(string $currency = 'USD'): int
{
    return app(Ledger::class)->balance(LedgerAccount::Refunds, $currency)->minor;
}

it('books a full refund as a balanced reversal and revokes access', function (): void {
    $intent = settledIntent(1499);
    $refund = pspRefundEvent($intent);

    deliverPspWebhook($refund)->assertOk();

    $ledger = app(Ledger::class);

    expect(LedgerEntry::query()->count())->toBe(4)
        ->and($ledger->trialBalance()['balanced'])->toBeTrue()
        ->and($ledger->balance(LedgerAccount::PspCash, 'USD')->minor)->toBe(0)
        ->and($ledger->balance(LedgerAccount::Revenue, 'USD')->minor)->toBe(-1499)
        ->and(refundsBalance())->toBe(1499)
        ->and($intent->refresh()->refunded_minor)->toBe(1499)
        ->and($intent->isFullyRefunded())->toBeTrue()
        ->and($intent->subscription->status)->toBe(SubscriptionStatus::Revoked)
        ->and($intent->subscription->revoked_at)->not->toBeNull()
        ->and(WebhookEvent::query()->where('provider_event_id', $refund['event_id'])->sole()->outcome)->toBe('applied');

    $this->getJson(sprintf('/v1/entitlements?user_id=%d&product=%s', $intent->user_id, $intent->subscription->product->slug), ['Authorization' => 'Bearer test-billing-token'])
        ->assertJsonPath('has_access', false);
});

it('keeps access on a partial refund', function (): void {
    $intent = settledIntent(1499);

    deliverPspWebhook(pspRefundEvent($intent, 500))->assertOk();

    expect(LedgerEntry::query()->count())->toBe(4)
        ->and(refundsBalance())->toBe(500)
        ->and($intent->refresh()->refunded_minor)->toBe(500)
        ->and($intent->subscription->status)->toBe(SubscriptionStatus::Active);
});

it('revokes once partial refunds add up to the whole charge', function (): void {
    $intent = settledIntent(1499);

    deliverPspWebhook(pspRefundEvent($intent, 500))->assertOk();
    deliverPspWebhook(pspRefundEvent($intent, 999))->assertOk();

    expect(LedgerEntry::query()->count())->toBe(6)
        ->and(refundsBalance())->toBe(1499)
        ->and($intent->refresh()->subscription->status)->toBe(SubscriptionStatus::Revoked);
});

it('absorbs a duplicate refund delivered under a new event id', function (): void {
    $intent = settledIntent(1499);
    $refundId = 're_'.Str::ulid();

    deliverPspWebhook(pspRefundEvent($intent, 500, $refundId))->assertOk();
    $again = pspRefundEvent($intent, 500, $refundId);
    deliverPspWebhook($again)->assertOk();

    expect(LedgerEntry::query()->count())->toBe(4)
        ->and($intent->refresh()->refunded_minor)->toBe(500)
        ->and(WebhookEvent::query()->where('provider_event_id', $again['event_id'])->sole()->outcome)->toBe('duplicate');
});

it('waits for the charge outcome when the refund arrives first', function (): void {
    $intent = PaymentIntent::factory()->processing()->create();
    $chargeId = 'ch_'.Str::ulid();

    // Reordered: the refund outruns the success it refunds.
    $refund = pspRefundEvent($intent, null, null, $chargeId);
    deliverPspWebhook($refund)->assertOk();

    $row = WebhookEvent::query()->where('provider_event_id', $refund['event_id'])->sole();

    expect($row->status)->toBe(WebhookEventStatus::Pending)
        ->and($row->outcome)->toBe('out_of_order')
        ->and(LedgerEntry::query()->count())->toBe(0);

    deliverPspWebhook(pspChargeEvent($intent, 'charge.succeeded', $chargeId))->assertOk();

    // The queue's release-and-retry, run by hand.
    ProcessWebhookEvent::dispatchSync($row->id);

    expect($row->refresh()->status)->toBe(WebhookEventStatus::Processed)
        ->and($row->outcome)->toBe('applied')
        ->and(LedgerEntry::query()->count())->toBe(4)
        ->and($intent->refresh()->subscription->status)->toBe(SubscriptionStatus::Revoked);
});

it('refuses a refund that names a different charge than the intent settled with', function (): void {
    $intent = settledIntent(1499);
    $refund = pspRefundEvent($intent, null, null, 'ch_someone_else');

    deliverPspWebhook($refund)->assertOk();

    expect(LedgerEntry::query()->count())->toBe(2)
        ->and($intent->refresh()->refunded_minor)->toBe(0)
        ->and($intent->subscription->status)->toBe(SubscriptionStatus::Active)
        ->and(WebhookEvent::query()->where('provider_event_id', $refund['event_id'])->sole()->outcome)->toBe('conflict');
});

it('ignores a refund for an intent it does not know', function (): void {
    $intent = settledIntent(1499);
    $refund = pspRefundEvent($intent);
    $refund['data']['metadata']['payment_intent_id'] = 424242;

    deliverPspWebhook($refund)->assertOk();

    expect(LedgerEntry::query()->count())->toBe(2)
        ->and(WebhookEvent::query()->where('provider_event_id', $refund['event_id'])->sole()->outcome)->toBe('unknown_intent');
});

it('books what the provider reports even when it exceeds the charge, capping only the projection', function (): void {
    $intent = settledIntent(1499);

    deliverPspWebhook(pspRefundEvent($intent, 2000))->assertOk();

    expect(refundsBalance())->toBe(2000)
        ->and($intent->refresh()->refunded_minor)->toBe(1499)
        ->and($intent->subscription->status)->toBe(SubscriptionStatus::Revoked);
});
