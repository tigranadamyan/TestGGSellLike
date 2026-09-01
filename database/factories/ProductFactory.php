<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sku' => strtoupper(fake()->unique()->bothify('??_####')),
            'name' => fake()->words(3, true),
            'type' => fake()->randomElement(['game_key', 'software_key', 'gift_card']),
            'price' => fake()->randomFloat(2, 100, 10000),
            'currency' => 'RUB',
        ];
    }
}
