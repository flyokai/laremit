<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Entitlements\Entitlements;
use App\Http\Context\ActingUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /v1/entitlements?user_id=&product= — the one entitlement answer,
 * served by the one entitlement function.
 */
final class EntitlementController
{
    public function __invoke(Request $request, ActingUser $acting, Entitlements $entitlements): JsonResponse
    {
        /** @var array{user_id: int|string, product: string} $validated */
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'product' => ['required', 'string'],
        ]);

        // Query-string values validate as integers but arrive as strings.
        // The answer is computed for $acting->id(), not the raw input: the
        // context object is what downstream layers would see, so it is the
        // thing the interleaved-user leak test must observe (ADR-008).
        $acting->actFor((int) $validated['user_id']);
        $userId = $acting->id();

        return response()->json([
            'user_id' => $userId,
            'product' => $validated['product'],
            'has_access' => $entitlements->hasAccessTo($userId, $validated['product']),
        ]);
    }
}
