<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'department' => 'Warehouse',
            'role_level' => 'Staff',
            'action' => 'Stock movement recorded',
            'module' => 'Inventory',
            'reference' => fake()->bothify('REF-####'),
            'description' => fake()->sentence(),
        ];
    }
}
