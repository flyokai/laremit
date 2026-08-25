<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Static bearer token for the ingest edge — fail closed: no configured
 * token means no ingestion, in every environment. Runs before the gzip
 * middleware so unauthenticated bodies are never even decompressed.
 */
final class AuthenticateIngest
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('events.ingest_token');
        $given = $request->bearerToken();

        if (! is_string($expected) || $expected === '' || ! is_string($given) || ! hash_equals($expected, $given)) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        return $next($request);
    }
}
