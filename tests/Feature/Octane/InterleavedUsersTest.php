<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * The interleaved-user leak test (Module 10, ADR-008): the real Octane
 * Worker, in a child process, serving two users' entitlement requests
 * alternately — see tests/Support/Octane/InterleaveHarness.php for the rig.
 *
 * Two runs, and both matter. The scoped() run pins the invariant every
 * long-lived worker must hold: no response ever carries another user's
 * data. The --leak run pins the rig itself: with the planted
 * warm-plus-singleton binding (OCTANE_DEMO_CROSS_REQUEST_LEAK) the
 * invariant MUST fail — a leak detector that has never detected a leak is
 * an assertion about nothing.
 */

/**
 * @return array{leak_demo: bool, exchanges: non-empty-list<array{requested: int, returned: int|null, has_access: bool|null, status: int}>}
 */
function runInterleavedWorker(bool $leak): array
{
    $process = new Process(
        array_merge(
            [PHP_BINARY, 'tests/Support/Octane/interleave-runner.php'],
            $leak ? ['--leak'] : [],
        ),
        base_path(),
        timeout: 120,
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue(
        'interleave runner failed: '.$process->getErrorOutput().$process->getOutput(),
    );

    /** @var array{leak_demo: bool, exchanges: non-empty-list<array{requested: int, returned: int|null, has_access: bool|null, status: int}>} $result */
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($result['exchanges'])->toHaveCount(10);

    return $result;
}

test('interleaved users never see each other\'s data through the octane worker', function (): void {
    $result = runInterleavedWorker(leak: false);

    foreach ($result['exchanges'] as $exchange) {
        expect($exchange['status'])->toBe(200)
            ->and($exchange['returned'])->toBe($exchange['requested']);
    }

    // Two users, opposite entitlements: the traffic shape that makes a leak
    // impossible to miss. The subscribed user is seeded first.
    $subscribed = $result['exchanges'][0]['requested'];

    foreach ($result['exchanges'] as $exchange) {
        expect($exchange['has_access'])->toBe($exchange['requested'] === $subscribed);
    }
});

test('the planted warm singleton leaks the first user to everyone — and this test catches it', function (): void {
    $result = runInterleavedWorker(leak: true);

    $first = $result['exchanges'][0];
    expect($first['returned'])->toBe($first['requested']);

    // Every request after the first is answered as the first user: identity
    // and entitlement both. The unsubscribed user being told has_access=true
    // is the concrete harm — a cross-request state leak IS an access bug.
    foreach (array_slice($result['exchanges'], 1) as $exchange) {
        expect($exchange['returned'])->toBe($first['requested'])
            ->and($exchange['has_access'])->toBeTrue();
    }

    $leaked = array_filter(
        $result['exchanges'],
        static fn (array $exchange): bool => $exchange['returned'] !== $exchange['requested'],
    );

    expect($leaked)->not->toBeEmpty();
});
