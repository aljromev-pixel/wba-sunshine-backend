<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
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
            'batch_id' => Batch::factory(),
            'user_id' => User::factory(),
            'type' => fake()->randomElement(['Stock In', 'Stock Out', 'Transfer', 'Return']),
            'quantity' => fake()->numberBetween(1, 25),
            'reference' => fake()->bothify('REF-####'),
            'status' => 'Completed',
        ];
    }
}
