<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('PROD-######')),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'unit' => fake()->randomElement(['unit', 'kg', 'box', 'hour']),
            'price' => fake()->randomFloat(2, 0, 9999.99),
            'currency' => 'CAD',
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }
}
