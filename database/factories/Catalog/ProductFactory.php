<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /** @var class-string<Product> */
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // words(…, asText: true) always returns a string, but Faker types it
        // array|string; the branch narrows without a cast.
        $words = fake()->unique()->words(2, true);
        $name = is_string($words) ? $words : implode(' ', $words);

        return [
            'slug' => Str::slug($name),
            'name' => Str::title($name),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
