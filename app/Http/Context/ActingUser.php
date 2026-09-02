<?php

declare(strict_types=1);

namespace App\Http\Context;

use LogicException;

/**
 * The user a billing request acts for. Auth on this surface is a
 * per-surface token (tech-debt #7), so the acting user is not auth()'s
 * user — it is the validated `user_id` the endpoint operates on, resolved
 * once and read back by everything downstream in the same request.
 *
 * That "same request" is the load-bearing phrase, and it is a container
 * decision, not a property of this class: the binding in
 * AppServiceProvider is scoped(), which Octane flushes between requests.
 * Bound singleton() and pre-resolved at worker boot — what the
 * OCTANE_DEMO_CROSS_REQUEST_LEAK flag deliberately does — the first
 * request's user is served to every user that worker ever sees. The
 * interleaved-user test (tests/Feature/Octane) exists to catch exactly
 * that; ADR-008 has the audit this class is the demonstration piece for.
 */
final class ActingUser
{
    private ?int $userId = null;

    /**
     * First write wins: within one request lifecycle the acting user is
     * immutable, so later layers can trust what earlier layers resolved.
     * Under FPM "one lifecycle" and "one process" coincide and this memo
     * is unobservable — which is precisely why the pattern survives code
     * review and then leaks under a long-lived worker.
     */
    public function actFor(int $userId): void
    {
        $this->userId ??= $userId;
    }

    public function id(): int
    {
        if ($this->userId === null) {
            throw new LogicException('No acting user has been resolved for this request.');
        }

        return $this->userId;
    }
}
