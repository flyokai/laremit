<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * The PSP did not give a definitive answer — timeout, connection failure,
 * 5xx. Crucially this is AMBIGUOUS, not "failed": the charge may well have
 * happened. The only safe reactions are retrying with the SAME idempotency
 * key or waiting for the webhook; treating this as a decline is how systems
 * double-charge.
 */
class PspUnavailable extends RuntimeException {}
