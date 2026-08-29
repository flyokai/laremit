<?php

declare(strict_types=1);

namespace App\MockStores\Http;

use App\MockStores\MockStoresSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Runtime knobs for the mock stores' delivery behaviour (delay, duplicates, drops). */
final class MockStoresConfigController
{
    public function show(MockStoresSettings $settings): JsonResponse
    {
        return response()->json(self::redact($settings->all()));
    }

    public function configure(Request $request, MockStoresSettings $settings): JsonResponse
    {
        /** @var array<string, mixed> $overrides */
        $overrides = $request->validate([
            'delivery' => ['sometimes', 'array'],
            'delivery.delay_seconds' => ['sometimes', 'array', 'size:2'],
            'delivery.delay_seconds.*' => ['integer', 'min:0', 'max:60'],
            'delivery.duplicate_rate' => ['sometimes', 'numeric', 'between:0,1'],
            'delivery.drop_rate' => ['sometimes', 'numeric', 'between:0,1'],
        ]);

        $settings->override($overrides);

        return response()->json(self::redact($settings->all()));
    }

    public function reset(MockStoresSettings $settings): JsonResponse
    {
        $settings->reset();

        return response()->json(self::redact($settings->all()));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function redact(array $settings): array
    {
        if (is_array($settings['apple'] ?? null)) {
            unset($settings['apple']['signing_key']);
        }

        if (is_array($settings['google'] ?? null)) {
            unset($settings['google']['pubsub_token']);
        }

        return $settings;
    }
}
