<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Entitlements\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /v1/entitlements?user_id=&product= — the one entitlement answer,
 * served by the one entitlement function.
 */
final class EntitlementController
{
    public function __invoke(Request $request, Entitlements $entitlements): JsonResponse
    {
        /** @var array{user_id: int|string, product: string} $validated */
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'product' => ['required', 'string'],
        ]);

        // Query-string values validate as integers but arrive as strings.
        $userId = (int) $validated['user_id'];

        return response()->json([
            'user_id' => $userId,
            'product' => $validated['product'],
            'has_access' => $entitlements->hasAccessTo($userId, $validated['product']),
        ]);
    }
}
