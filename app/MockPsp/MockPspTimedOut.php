<?php

declare(strict_types=1);

namespace App\MockPsp;

use RuntimeException;

/**
 * The mock PSP decided this request "times out" at the caller. It carries
 * the true outcome — which may include a charge that DID happen — so each
 * transport can render the ambiguity its own way: the HTTP controller
 * sleeps past the client timeout and answers into the void; the loopback
 * client converts it straight into PspTimedOut.
 */
final class MockPspTimedOut extends RuntimeException
{
    public function __construct(public readonly PspResponse $truth)
    {
        parent::__construct('Mock PSP simulated a timeout.');
    }
}
