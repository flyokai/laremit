<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Events\Ingestion\Ingestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * POST /v1/events — the ingest edge.
 *
 * Auth and envelope validation only, then hand off to the buffer and answer
 * 202: acceptance means "durably buffered", never "processed". JSON parsing
 * is explicit rather than via $request->json() so the gzip middleware's
 * content swap can never race a cached parse.
 */
final class IngestController
{
    public const MAX_BATCH = 500;

    public function __invoke(Request $request, Ingestor $ingestor): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return response()->json(['error' => 'malformed_json', 'detail' => $e->getMessage()], 400);
        }

        $events = is_array($body) ? ($body['events'] ?? null) : null;

        if (! is_array($events) || ! array_is_list($events)) {
            return response()->json(['error' => 'invalid_shape', 'detail' => 'Body must be {"events": [...]}.'], 422);
        }

        if ($events === []) {
            return response()->json(['error' => 'empty_batch'], 422);
        }

        if (count($events) > self::MAX_BATCH) {
            return response()->json(['error' => 'batch_too_large', 'max' => self::MAX_BATCH], 422);
        }

        $result = $ingestor->ingest($events);

        if ($result->rejected) {
            return response()
                ->json(['error' => 'over_capacity', 'retry_after' => $result->retryAfterSeconds], 429)
                ->header('Retry-After', (string) $result->retryAfterSeconds);
        }

        $response = response()->json($result->payload(), 202);

        // Partial shedding still returns 202 — but tell well-behaved clients
        // when to bring the shed events back.
        if ($result->shed > 0) {
            $response->header('Retry-After', (string) $result->retryAfterSeconds);
        }

        return $response;
    }
}
