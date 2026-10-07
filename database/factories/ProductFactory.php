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
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->bothify('SKU-####'),
            'name' => fake()->words(3, true),
            'category' => fake()->randomElement(['Cooling', 'Electrical', 'Filtration']),
            'stock' => fake()->numberBetween(0, 100),
            'reorder_point' => fake()->numberBetween(5, 25),
            'capacity' => fake()->numberBetween(100, 500),
            'supplier' => fake()->company(),
            'lead_time' => fake()->numberBetween(1, 14),
            'unit' => 'units',
            'monthly_sales' => fake()->numberBetween(0, 100),
        ];
    }
}
