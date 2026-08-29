<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Entitlements\Entitlements;
use App\Domain\Billing\Enums\Store;
use App\Domain\Billing\Exceptions\StoreUnavailable;
use App\Domain\Billing\Stores\StoreClient;
use App\Domain\Billing\Stores\StoreSubscriptionProjector;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/iap/{store}/sync — "restore purchases". The app says "this
 * user owns store subscription X". We believe none of it: we ask the
 * store for X, check the store's own account link agrees (or is absent),
 * and project what the STORE said. The device never grants itself
 * anything; it only tells us where to look. This is also how a purchase
 * whose SUBSCRIBED notification was lost, or that was made before the
 * user logged in, becomes a subscription at all.
 */
final class IapSyncController
{
    public function __invoke(
        Request $request,
        string $store,
        StoreClient $client,
        StoreSubscriptionProjector $projector,
        Entitlements $entitlements,
    ): JsonResponse {
        $which = Store::tryFrom($store);

        if ($which === null || ! $which->isStoreAuthoritative()) {
            return response()->json(['error' => 'unknown_store'], 404);
        }

        /** @var array{user_id: int, identifier: string} $validated */
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'identifier' => ['required', 'string', 'max:128'],
        ]);

        $user = User::query()->findOrFail($validated['user_id']);

        try {
            $snapshot = $client->fetchSubscription($which, $validated['identifier'], CarbonImmutable::now(), 'RESTORE');
        } catch (StoreUnavailable $e) {
            return response()->json(['error' => 'store_unavailable', 'detail' => $e->getMessage()], 503);
        }

        if ($snapshot === null) {
            return response()->json(['error' => 'unknown_purchase'], 404);
        }

        if ($snapshot->appAccountToken !== null && $snapshot->appAccountToken !== $user->app_account_token) {
            return response()->json(['error' => 'owned_by_another_account'], 409);
        }

        $result = $projector->project($snapshot->withAppAccountToken($user->app_account_token));

        if (! $result->isApplicable() || $result->subscription === null) {
            return response()->json(['error' => $result->verdict], 422);
        }

        $subscription = $result->subscription;

        return response()->json([
            'subscription_id' => $subscription->id,
            'status' => $subscription->status->value,
            'verdict' => $result->verdict,
            'has_access' => $entitlements->hasAccessTo($user->id, $subscription->product->slug),
        ]);
    }
}
