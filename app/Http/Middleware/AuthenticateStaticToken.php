<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token gate parameterized by the config key holding the expected
 * token (e.g. `billing.api_token`). Fail closed: unconfigured means 401 in
 * every environment. The single-static-token model itself is tech-debt #7.
 */
final class AuthenticateStaticToken
{
    public function handle(Request $request, Closure $next, string $configKey): Response
    {
        $expected = config($configKey);
        $given = $request->bearerToken();

        if (! is_string($expected) || $expected === '' || ! is_string($given) || ! hash_equals($expected, $given)) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        return $next($request);
    }
}
