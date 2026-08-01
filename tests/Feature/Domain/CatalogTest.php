<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\BillingInterval;
use App\Domain\Catalog\Models\Plan;
use App\Domain\Catalog\Models\Product;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\QueryException;

it('casts a plan interval to the enum and the amount to an integer', function (): void {
    $plan = Plan::factory()->yearly()->create();

    expect($plan->fresh()->interval)->toBe(BillingInterval::Year)
        ->and($plan->fresh()->amount_minor)->toBe(9_900)
        ->and($plan->fresh()->amount_minor)->toBeInt();
});

it('refuses two plans with the same slug under one product', function (): void {
    $product = Product::factory()->create();

    Plan::factory()->for($product)->create(['slug' => 'monthly']);

    expect(fn () => Plan::factory()->for($product)->create(['slug' => 'monthly']))
        ->toThrow(QueryException::class);
});

it('allows the same plan slug across different products', function (): void {
    Plan::factory()->for(Product::factory()->create())->create(['slug' => 'monthly']);
    Plan::factory()->for(Product::factory()->create())->create(['slug' => 'monthly']);

    expect(Plan::query()->where('slug', 'monthly')->count())->toBe(2);
});

it('seeds the three products idempotently', function (): void {
    $this->seed(CatalogSeeder::class);
    $this->seed(CatalogSeeder::class);

    expect(Product::query()->count())->toBe(3)
        ->and(Product::query()->pluck('slug')->all())->toEqualCanonicalizing(['edtech', 'vpn', 'ai-tutor'])
        ->and(Plan::query()->count())->toBe(5);
});
