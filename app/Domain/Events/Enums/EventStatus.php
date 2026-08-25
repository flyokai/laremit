<?php

declare(strict_types=1);

namespace App\Domain\Events\Enums;

/**
 * Per-event outcome reported in the 202 response, one entry per submitted
 * event, in submission order.
 */
enum EventStatus: string
{
    case Accepted = 'accepted';

    /** Same event_id already accepted (this batch or the dedup window). */
    case Duplicate = 'duplicate';

    case Invalid = 'invalid';

    /** Refused by backpressure; retry after the Retry-After header. */
    case Shed = 'shed';
}
