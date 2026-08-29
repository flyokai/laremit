<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * The app store's API gave no usable answer (timeout, 5xx, transport
 * failure). Like PspUnavailable this is ambiguity, not a statement about
 * the subscription: the caller retries later, and never changes local
 * state on the strength of it.
 */
final class StoreUnavailable extends RuntimeException {}
