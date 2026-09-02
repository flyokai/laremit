<?php

declare(strict_types=1);

use App\Domain\Billing\BillingServiceProvider;
use App\Domain\Billing\Models\LedgerEntry;
use App\Domain\Billing\Stores\LoopbackStoreClient;
use App\Domain\Outbox\Models\OutboxMessage;
use Illuminate\Contracts\Queue\ShouldQueue;

/*
 * Architecture as executable rules (Module 9): a convention that lives in a
 * code-review checklist decays; one that fails the build doesn't. Every rule
 * here states a boundary the codebase actually honours — the ignores are the
 * documented, deliberate exceptions, not leaks.
 */

arch()->preset()->php();

// sha1 in OutboxMessage derives a deterministic UUIDv5-style event id (not a
// password, not a token); the mock PSP and stores roll non-crypto dice for
// chaos rates by design — they are the adversarial test double.
arch()->preset()->security()->ignoring([
    'App\MockPsp',
    'App\MockStores',
    OutboxMessage::class,
]);

arch('all production code declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

// The domain never reaches into the HTTP layer: requests flow inward only.
arch('domain does not depend on the HTTP layer')
    ->expect('App\Domain')
    ->not->toUse('App\Http');

// The mocks are the other side of the wire. Production domain code talks to
// them only through the PspClient/StoreClient contracts; the two loopback
// adapters and the provider that binds them are the composition points where
// the dev-mode bridge is allowed to exist.
arch('domain touches the mock PSP and stores only at the loopback seam')
    ->expect('App\Domain')
    ->not->toUse(['App\MockPsp', 'App\MockStores'])
    ->ignoring([BillingServiceProvider::class, LoopbackStoreClient::class]);

// And the mocks never reach back into our state. They may share wire-level
// vocabulary — the contracts they implement, Money, the Store enum, the
// signature helper — but a mock that read or wrote our tables, drove our
// state machine, or booked our ledger would stop being the other side of the
// wire and start being a backdoor around it.
arch('the mocks share contracts with the domain, never state')
    ->expect(['App\MockPsp', 'App\MockStores'])
    ->not->toUse([
        'App\Domain\Billing\Models',
        'App\Domain\Billing\Ledger',
        'App\Domain\Billing\Payments',
        'App\Domain\Billing\Subscriptions',
        'App\Domain\Billing\Entitlements',
        'App\Domain\Billing\Reconciliation',
        'App\Domain\Catalog',
        'App\Domain\Events',
        'App\Domain\Identity',
        'App\Domain\Outbox',
    ]);

arch('models never do HTTP')
    ->expect('App')
    ->classes()
    ->extending(Illuminate\Database\Eloquent\Model::class)
    ->not->toUse(['Illuminate\Support\Facades\Http', 'Illuminate\Http\Client']);

arch('every job is queueable')
    ->expect([
        'App\Domain\Billing\Jobs',
        'App\Support\Queue\Jobs',
        'App\MockPsp\Jobs',
        'App\MockStores\Jobs',
    ])
    ->toImplement(ShouldQueue::class);

// Append-only means one writer: ledger rows are created by the Ledger service
// alone. The model, its enum, and the reconciliation sweep that READS entries
// to compare books are the only other legitimate references.
arch('only the ledger module touches ledger entries')
    ->expect('App')
    ->not->toUse(LedgerEntry::class)
    ->ignoring([
        'App\Domain\Billing\Ledger',
        'App\Domain\Billing\Models',
        'App\Domain\Billing\Enums',
        'App\Domain\Billing\Reconciliation',
    ]);

// Money moves by value: an accidentally mutable amount is a ledger bug
// waiting to happen.
arch('money is immutable')
    ->expect('App\Domain\Billing\Money\Money')
    ->toBeFinal()
    ->toBeReadonly();
