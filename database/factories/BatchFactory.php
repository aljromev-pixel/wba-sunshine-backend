<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'number' => fake()->unique()->bothify('BATCH-####'),
            'quantity' => fake()->numberBetween(1, 100),
            'received_at' => fake()->dateTimeBetween('-90 days', 'today'),
            'expires_at' => fake()->optional()->dateTimeBetween('tomorrow', '+2 years'),
        ];
    }
}
