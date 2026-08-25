<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transparent gzip request bodies for the ingest endpoint. A 500-event JSON
 * batch compresses roughly 10:1, and mobile SDK batching is exactly the
 * client this endpoint exists for.
 */
final class DecodesGzipRequests
{
    /**
     * Decompression ceiling. gzip tops out near 1000:1, so a small hostile
     * body can try to balloon; past this we stop inflating and the truncated
     * JSON fails parsing with a 400 downstream.
     */
    private const MAX_DECODED_BYTES = 26_214_400; // 25 MiB

    public function handle(Request $request, Closure $next): Response
    {
        $encoding = strtolower(trim((string) $request->headers->get('Content-Encoding', '')));

        if ($encoding === '' || $encoding === 'identity') {
            return $next($request);
        }

        if ($encoding !== 'gzip') {
            return response()->json(['error' => 'unsupported_content_encoding', 'supported' => ['gzip']], 415);
        }

        $decoded = @gzdecode($request->getContent(), self::MAX_DECODED_BYTES);

        if ($decoded === false) {
            return response()->json(['error' => 'malformed_gzip'], 400);
        }

        // Rebuild the request around the decoded body. The header goes from
        // the server bag because initialize() re-derives the header bag from
        // it — remove it there or it comes back.
        $request->server->remove('HTTP_CONTENT_ENCODING');

        $request->initialize(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
            $decoded,
        );

        return $next($request);
    }
}
