<?php

declare(strict_types=1);

namespace App\Domain\Events\Contracts;

use App\Domain\Events\Support\Envelope;

/**
 * One consumer group's processing stage.
 *
 * apply() receives a batch and MUST be idempotent: delivery is at-least-once,
 * and after a crash the whole batch is redelivered — including the part that
 * was already applied. Each implementation buys idempotency its own way
 * (INSERT IGNORE, commutative PFADD/SETBIT, reacted-markers); none of them
 * may assume first delivery.
 */
interface Consumer
{
    /**
     * @param  list<Envelope>  $envelopes
     */
    public function apply(array $envelopes): void;
}
