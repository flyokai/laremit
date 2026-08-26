<?php

declare(strict_types=1);

namespace App\MockPsp;

/**
 * What the mock PSP answers: an HTTP status and a JSON body, stored
 * verbatim per idempotency key so replays are byte-identical.
 */
final readonly class PspResponse
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public int $status,
        public array $body,
    ) {}
}
