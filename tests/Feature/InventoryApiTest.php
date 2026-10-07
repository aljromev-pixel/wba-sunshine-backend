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

    public function test_stock_out_updates_inventory_and_records_an_audit_event(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);
        $batch = Batch::factory()->for($product)->create(['quantity' => 10]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/inventory/movements', [
            'type' => 'Stock Out',
            'productId' => $product->id,
            'batchId' => $batch->id,
            'quantity' => 4,
            'reference' => 'SO-24091',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.type', 'Stock Out')
            ->assertJsonPath('data.productId', $product->id)
            ->assertJsonPath('data.batch', $batch->number);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 6]);
        $this->assertDatabaseHas('batches', ['id' => $batch->id, 'quantity' => 6]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'user_id' => $user->id,
            'reference' => 'SO-24091',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'Stock Out',
            'module' => 'Inventory',
        ]);
    }

    public function test_stock_out_rejects_a_quantity_above_available_batch_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);
        $batch = Batch::factory()->for($product)->create(['quantity' => 3]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'type' => 'Stock Out',
                'productId' => $product->id,
                'batchId' => $batch->id,
                'quantity' => 4,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'quantity' => 'The requested quantity exceeds the selected batch quantity.',
            ]);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
        $this->assertDatabaseHas('batches', ['id' => $batch->id, 'quantity' => 3]);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_manager_can_approve_an_inventory_adjustment_and_update_stock(): void
    {
        $staff = User::factory()->create();
        $manager = User::factory()->create(['role_level' => 'Manager']);
        $product = Product::factory()->create(['stock' => 10]);

        $adjustmentResponse = $this->actingAs($staff, 'sanctum')->postJson('/api/v1/inventory/adjustments', [
            'productId' => $product->id,
            'requestedQty' => 7,
            'reason' => 'Cycle count variance',
        ]);

        $adjustmentResponse->assertCreated()->assertJsonPath('data.status', 'Pending');
        $adjustmentId = $adjustmentResponse->json('data.id');

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/inventory/adjustments/{$adjustmentId}/review", ['approved' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'Approved')
            ->assertJsonPath('data.reviewedBy', $manager->name);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 7]);
        $this->assertDatabaseHas('inventory_adjustments', [
            'id' => $adjustmentId,
            'status' => 'Approved',
            'reviewed_by' => $manager->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Adjustment approved', 'user_id' => $manager->id]);
    }

    public function test_staff_cannot_review_an_inventory_adjustment(): void
    {
        $staff = User::factory()->create(['role_level' => 'Staff']);
        $adjustment = InventoryAdjustment::factory()->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/inventory/adjustments/{$adjustment->id}/review", ['approved' => true])
            ->assertForbidden();
    }

    public function test_manager_cannot_review_their_own_inventory_adjustment(): void
    {
        $manager = User::factory()->create(['role_level' => 'Manager']);
        $adjustment = InventoryAdjustment::factory()->for($manager, 'requestedBy')->create();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/inventory/adjustments/{$adjustment->id}/review", ['approved' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'adjustment' => 'You cannot review your own adjustment request.',
            ]);

        $this->assertDatabaseHas('inventory_adjustments', [
            'id' => $adjustment->id,
            'status' => 'Pending',
            'reviewed_by' => null,
        ]);
    }
}
