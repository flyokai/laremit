<?php

declare(strict_types=1);

namespace App\MockStores\Http;

use App\MockStores\Google\MockPlayStore;
use App\MockStores\Models\StoreSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The pretend Play Store: a console for driving purchases, and the Play
 * Developer API stand-in (subscriptionsv2 get + acknowledge).
 */
final class MockGoogleController
{
    public function purchase(Request $request, MockPlayStore $store): JsonResponse
    {
        /** @var array{product_id: string, obfuscated_external_account_id?: string|null, period_days?: int, linked_purchase_token?: string|null} $validated */
        $validated = $request->validate([
            'product_id' => ['required', 'string', 'max:64'],
            'obfuscated_external_account_id' => ['nullable', 'string', 'max:64'],
            'period_days' => ['sometimes', 'integer', 'min:1', 'max:400'],
            'linked_purchase_token' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $subscription = $store->purchase(
                $validated['product_id'],
                $validated['obfuscated_external_account_id'] ?? null,
                $validated['period_days'] ?? 30,
                $validated['linked_purchase_token'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response()->json(self::describe($subscription), 201);
    }

    public function act(MockPlayStore $store, string $token, string $action): JsonResponse
    {
        try {
            $subscription = $store->act($token, str_replace('-', '_', $action));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response()->json(self::describe($subscription));
    }

    /** Play Developer API stand-in: purchases.subscriptionsv2.get. */
    public function subscription(MockPlayStore $store, string $package, string $token): JsonResponse
    {
        $purchase = $store->subscriptionsV2Get($token);

        return $purchase === null
            ? response()->json(['error' => ['code' => 404, 'message' => 'The purchase token was not found.']], 404)
            : response()->json($purchase);
    }

    public function acknowledge(MockPlayStore $store, string $package, string $token): JsonResponse
    {
        return $store->acknowledge($token)
            ? response()->json([], 200)
            : response()->json(['error' => ['code' => 404, 'message' => 'The purchase token was not found.']], 404);
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(StoreSubscription $subscription): array
    {
        return [
            'purchase_token' => $subscription->identifier,
            'linked_purchase_token' => $subscription->linked_identifier,
            'product_id' => $subscription->product_id,
            'obfuscated_external_account_id' => $subscription->app_account_token,
            'subscription_state' => $subscription->status,
            'auto_renew' => $subscription->auto_renew,
            'acknowledged' => $subscription->acknowledged,
            'period_start' => $subscription->period_start->toISOString(),
            'period_end' => $subscription->period_end->toISOString(),
            'event_at' => $subscription->event_at->toISOString(),
        ];
    }
}
