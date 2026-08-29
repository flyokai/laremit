<?php

declare(strict_types=1);

namespace App\Support\Jws;

use RuntimeException;

/** A JWS that cannot be trusted: malformed, wrong algorithm, or bad signature. */
final class JwsException extends RuntimeException {}
