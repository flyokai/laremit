<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use DomainException;

/**
 * A state change outside the allow-list. Thrown, never silently coerced:
 * an impossible transition reaching this code means an upstream invariant
 * already broke, and the ledger's correctness depends on stopping here.
 */
final class InvalidTransition extends DomainException {}
