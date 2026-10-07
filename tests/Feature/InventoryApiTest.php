<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_read_inventory_state_in_frontend_shape(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'name' => 'Air Purifier',
            'stock' => 10,
            'reorder_point' => 20,
        ]);
        $batch = Batch::factory()->for($product)->create([
            'number' => 'AP-2607',
            'quantity' => 10,
        ]);
        $movement = InventoryMovement::factory()
            ->for($product)
            ->for($batch)
            ->for($user)
            ->create(['reference' => 'SO-24091']);
        $adjustment = InventoryAdjustment::factory()
            ->for($product)
            ->for($user, 'requestedBy')
            ->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/inventory');

        $response
            ->assertOk()
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('products.0.reorderPoint', 20)
            ->assertJsonPath('batches.0.productId', $product->id)
            ->assertJsonPath('transactions.0.id', $movement->id)
            ->assertJsonPath('transactions.0.batch', 'AP-2607')
            ->assertJsonPath('adjustments.0.id', $adjustment->id)
            ->assertJsonPath('alerts.0.type', 'Low Stock');
    }

    public function test_inventory_state_requires_authentication(): void
    {
        $this->getJson('/api/v1/inventory')->assertUnauthorized();
    }
}
