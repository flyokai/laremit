<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Idempotency\IdempotencyRecord;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Inbound idempotency (ADR-004, layer 1): at-most-once semantics for
 * unsafe endpoints, keyed by the client's Idempotency-Key header.
 *
 * The atomic claim is an INSERT into a unique key — not read-then-write, so
 * two concurrent duplicates race on the constraint and exactly one runs.
 * The loser sees `running` and gets 409 + Retry-After; once the winner
 * finishes, replays get its stored response verbatim with
 * Idempotency-Replayed: true. The same key with a different body is a 422 —
 * key reuse is a client bug worth failing loudly.
 *
 * 5xx responses and exceptions release the claim: a failed attempt must
 * stay retryable with the same key. A `running` claim older than the lock
 * window (crashed worker mid-request) is taken over via a guarded UPDATE.
 */
final class EnforceIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $maxLength = (int) config('billing.idempotency.max_key_length');

        if (! is_string($key) || $key === '' || strlen($key) > $maxLength) {
            return response()->json([
                'error' => 'missing_idempotency_key',
                'detail' => "Provide an Idempotency-Key header of at most {$maxLength} characters.",
            ], 400);
        }

        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        if (! $this->claim($key, $hash)) {
            $existing = $this->handleExistingClaim($key, $hash);

            if ($existing !== null) {
                return $existing;
            }
            // null: a stale claim was taken over — this request now owns it.
        }

        return $this->runAndStore($request, $next, $key);
    }

    private function claim(string $key, string $hash): bool
    {
        try {
            IdempotencyRecord::query()->create([
                'key' => $key,
                'request_hash' => $hash,
                'status' => IdempotencyRecord::STATUS_RUNNING,
                'locked_at' => CarbonImmutable::now(),
            ]);
        } catch (QueryException) {
            return false; // key already claimed — by history or by a race
        }

        return true;
    }

    private function handleExistingClaim(string $key, string $hash): ?Response
    {
        $record = IdempotencyRecord::query()->where('key', $key)->firstOrFail();

        if ($record->request_hash !== $hash) {
            return response()->json([
                'error' => 'idempotency_key_reused',
                'detail' => 'This Idempotency-Key was already used with a different request.',
            ], 422);
        }

        if ($record->status === IdempotencyRecord::STATUS_COMPLETED) {
            return response((string) $record->response_body, (int) $record->response_status)
                ->header('Content-Type', 'application/json')
                ->header('Idempotency-Replayed', 'true');
        }

        $lockSeconds = (int) config('billing.idempotency.lock_seconds');

        // A running claim older than the lock window belongs to a request
        // that died mid-flight. The guarded UPDATE is the takeover: under
        // concurrency exactly one retry wins it, the rest still see 409.
        $tookOver = IdempotencyRecord::query()
            ->where('key', $key)
            ->where('status', IdempotencyRecord::STATUS_RUNNING)
            ->where('locked_at', '<', CarbonImmutable::now()->subSeconds($lockSeconds))
            ->update(['locked_at' => CarbonImmutable::now()]);

        if ($tookOver === 1) {
            return null;
        }

        return response()->json([
            'error' => 'request_in_progress',
            'detail' => 'The original request with this Idempotency-Key is still running.',
        ], 409)->header('Retry-After', (string) $lockSeconds);
    }

    private function runAndStore(Request $request, Closure $next, string $key): Response
    {
        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $e) {
            $this->release($key);

            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            $this->release($key);

            return $response;
        }

        $body = (string) $response->getContent();

        if (strlen($body) > (int) config('billing.idempotency.max_body_bytes')) {
            // A response too big to replay must not poison the key.
            $this->release($key);

            return $response;
        }

        IdempotencyRecord::query()->where('key', $key)->update([
            'status' => IdempotencyRecord::STATUS_COMPLETED,
            'response_status' => $response->getStatusCode(),
            'response_body' => $body,
        ]);

        return $response;
    }

    private function release(string $key): void
    {
        IdempotencyRecord::query()->where('key', $key)->delete();
    }
}
