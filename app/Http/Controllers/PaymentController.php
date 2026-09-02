<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Exceptions\AlreadySubscribed;
use App\Domain\Billing\Exceptions\PaymentInProgress;
use App\Domain\Billing\Models\PaymentIntent;
use App\Domain\Billing\Payments\CreatePaymentIntent;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/payments — start a subscription purchase; 202, charging is
 * asynchronous. GET /v1/payments/{id} — poll the outcome. Behind
 * EnforceIdempotency: the 202 (payment intent id included) is stored and
 * replayed verbatim for a retried key, so a client that never saw its
 * response can still find its intent.
 */
final class PaymentController
{
    public function store(Request $request, CreatePaymentIntent $createIntent): JsonResponse
    {
        /** @var array{user_id: int, product: string, plan: string} $validated */
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'product' => ['required', 'string', 'exists:products,slug'],
            'plan' => ['required', 'string'],
        ]);

        $product = Product::query()->where('slug', $validated['product'])->firstOrFail();

        $plan = Plan::query()
            ->where('product_id', $product->id)
            ->where('slug', $validated['plan'])
            ->where('active', true)
            ->first();

        if ($plan === null) {
            return response()->json(['error' => 'unknown_plan'], 422);
        }

        $user = User::query()->findOrFail($validated['user_id']);

        try {
            $intent = $createIntent->execute($user, $plan);
        } catch (AlreadySubscribed) {
            return response()->json(['error' => 'already_subscribed'], 409);
        } catch (PaymentInProgress $inProgress) {
            // Another request's charge is in flight for this subscription.
            // Hand back its intent id: the honest move is to poll that one,
            // not to mint a rival charge.
            return response()->json([
                'error' => 'payment_in_progress',
                'payment_intent_id' => $inProgress->paymentIntentId,
            ], 409);
        }

        return response()->json([
            'payment_intent_id' => $intent->id,
            'status' => $intent->status->value,
            'amount_minor' => $intent->amount_minor,
            'currency' => $intent->currency,
            'subscription_id' => $intent->subscription_id,
        ], 202);
    }

    public function show(PaymentIntent $payment): JsonResponse
    {
        return response()->json([
            'payment_intent_id' => $payment->id,
            'status' => $payment->status->value,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'psp_reference' => $payment->psp_reference,
            'subscription_id' => $payment->subscription_id,
            'last_error' => $payment->last_error,
        ]);
    }
}
