<?php

declare(strict_types=1);

namespace App\Domain\Events\Enums;

/**
 * Backpressure class of an event, declared by the producer in the envelope.
 *
 * Under load the pipeline sheds analytics before it sheds anything that a
 * domain reaction depends on. Producers that lie and mark telemetry as
 * operational are a governance problem, not a validation one.
 */
enum Priority: string
{
    /** Something downstream acts on: a reaction job, a billing signal. */
    case Operational = 'operational';

    /** Telemetry. Losing it under pressure costs a data point, not money. */
    case Analytics = 'analytics';

    public function sheddable(): bool
    {
        return $this === self::Analytics;
    }
}
