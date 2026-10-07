<?php

namespace Database\Factories;

use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryAdjustment>
 */
class InventoryAdjustmentFactory extends Factory
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
            'system_qty' => 20,
            'requested_qty' => 18,
            'reason' => fake()->sentence(),
            'requested_by' => User::factory(),
            'reviewed_by' => null,
            'status' => 'Pending',
            'reviewed_at' => null,
        ];
    }
}
