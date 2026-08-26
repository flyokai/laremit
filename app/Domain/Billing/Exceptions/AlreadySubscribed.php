<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use DomainException;

final class AlreadySubscribed extends DomainException {}
