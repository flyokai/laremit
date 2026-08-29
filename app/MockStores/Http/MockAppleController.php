<?php

declare(strict_types=1);

namespace App\MockStores\Http;

use App\MockStores\Apple\MockAppStore;
use App\MockStores\Models\StoreSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The pretend App Store's two faces: a sandbox console for driving a
 * subscription through its life (purchases, actions), and the App Store
 * Server API stand-in Laremit re-fetches truth from.
 */
final class MockAppleController
{
    public function purchase(Request $request, MockAppStore $store): JsonResponse
    {
        /** @var array{product_id: string, app_account_token?: string|null, period_days?: int} $validated */
        $validated = $request->validate([
            'product_id' => ['required', 'string', 'max:64'],
            'app_account_token' => ['nullable', 'uuid'],
            'period_days' => ['sometimes', 'integer', 'min:1', 'max:400'],
        ]);

        $subscription = $store->purchase($validated['product_id'], $validated['app_account_token'] ?? null, $validated['period_days'] ?? 30);

        return response()->json(self::describe($subscription), 201);
    }

    public function act(MockAppStore $store, string $id, string $action): JsonResponse
    {
        try {
            $subscription = $store->act($id, str_replace('-', '_', $action));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response()->json(self::describe($subscription));
    }

    /** App Store Server API stand-in: GET /inApps/v1/subscriptions/{originalTransactionId}. */
    public function subscription(MockAppStore $store, string $id): JsonResponse
    {
        $signed = $store->signedSubscription($id);

        return $signed === null
            ? response()->json(['errorCode' => 4040010, 'errorMessage' => 'Original transaction id not found.'], 404)
            : response()->json($signed);
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(StoreSubscription $subscription): array
    {
        return [
            'original_transaction_id' => $subscription->identifier,
            'product_id' => $subscription->product_id,
            'app_account_token' => $subscription->app_account_token,
            'status' => $subscription->status,
            'auto_renew' => $subscription->auto_renew,
            'period_start' => $subscription->period_start->toISOString(),
            'period_end' => $subscription->period_end->toISOString(),
            'environment' => $subscription->environment,
            'event_at' => $subscription->event_at->toISOString(),
        ];
    }
}
