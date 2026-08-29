<?php

declare(strict_types=1);

use App\Domain\Billing\Stores\StoreProductId;

it('maps catalog slugs to store product ids and back', function (): void {
    expect(StoreProductId::of('edtech', 'monthly'))->toBe('com.laremit.edtech.monthly')
        ->and(StoreProductId::parse('com.laremit.edtech.monthly'))->toBe(['product' => 'edtech', 'plan' => 'monthly'])
        ->and(StoreProductId::parse('com.laremit.ai-tutor.yearly'))->toBe(['product' => 'ai-tutor', 'plan' => 'yearly']);
});

it('refuses ids that are not ours', function (): void {
    expect(StoreProductId::parse('com.other.edtech.monthly'))->toBeNull()
        ->and(StoreProductId::parse('com.laremit.edtech'))->toBeNull()
        ->and(StoreProductId::parse('com.laremit..monthly'))->toBeNull()
        ->and(StoreProductId::parse('com.laremit.edtech.'))->toBeNull();
});
