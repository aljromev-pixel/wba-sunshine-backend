<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_database_seed_does_not_create_known_password_accounts(): void
    {
        $this->seed();
        $this->seed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_created_and_updated_emails_use_the_login_canonical_form(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $profile = ['name' => 'Mara', 'lastName' => 'Santos', 'email' => '  Mara@Example.Test  ', 'password' => 'private-test-password', 'department' => 'Warehouse', 'roleLevel' => 'Staff'];
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', $profile)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('users', ['id' => $id, 'email' => 'mara@example.test']);
        $this->postJson('/api/v1/users', [...$profile, 'email' => 'MARA@EXAMPLE.TEST'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->putJson("/api/v1/users/{$id}", [...$profile, 'email' => '  MARA.NEW@EXAMPLE.TEST  '])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $id, 'email' => 'mara.new@example.test']);
        $this->postJson('/api/v1/auth/login', ['email' => 'MARA.NEW@EXAMPLE.TEST', 'password' => 'private-test-password'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_product_quantities_above_postgresql_integer_range_are_validation_errors(): void
    {
        $manager = User::factory()->create(['role_level' => 'Manager']);
        $profile = ['sku' => 'BOUNDARY-AC', 'name' => 'Warehouse air conditioner', 'category' => 'Air Conditioners', 'stock' => 0, 'reorderPoint' => 0, 'capacity' => null, 'leadTime' => 1, 'unit' => 'units', 'monthlySales' => 0];
        $this->actingAs($manager, 'sanctum');
        foreach (['stock', 'reorderPoint', 'capacity', 'monthlySales'] as $field) {
            $this->postJson('/api/v1/products', [...$profile, $field => 2147483648])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_oversized_movement_batch_and_adjustment_quantities_do_not_write(): void
    {
        $manager = User::factory()->create(['role_level' => 'Manager']);
        $product = Product::factory()->create(['stock' => 10]);
        $batch = Batch::factory()->for($product)->create(['quantity' => 10]);
        $this->actingAs($manager, 'sanctum');
        $this->postJson('/api/v1/inventory/movements', ['productId' => $product->id, 'batchId' => $batch->id, 'type' => 'Stock In', 'quantity' => 2147483648, 'reference' => 'Boundary receipt'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson('/api/v1/batches', ['productId' => $product->id, 'number' => 'BOUNDARY-BATCH', 'quantity' => 2147483648, 'receivedAt' => today()->toDateString(), 'location' => 'Receiving'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson('/api/v1/inventory/adjustments', ['productId' => $product->id, 'requestedQty' => 2147483648, 'reason' => 'Verified count'])->assertUnprocessable()->assertJsonValidationErrors('requestedQty');
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(10, $batch->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_adjustments', 0);
        $this->assertDatabaseCount('batches', 1);
    }

    public function test_receipts_reject_computed_overflow_and_roll_back_new_batches(): void
    {
        $manager = User::factory()->create(['role_level' => 'Manager']);
        $product = Product::factory()->create(['stock' => 2147483647]);
        $batch = Batch::factory()->for($product)->create(['quantity' => 2147483647]);
        $this->actingAs($manager, 'sanctum');
        $this->postJson('/api/v1/inventory/movements', ['productId' => $product->id, 'type' => 'Stock In', 'quantity' => 1, 'batchNumber' => 'OVERFLOW-RECEIPT', 'location' => 'Receiving', 'reference' => 'Boundary receipt'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson('/api/v1/batches', ['productId' => $product->id, 'number' => 'OVERFLOW-BATCH', 'quantity' => 1, 'receivedAt' => today()->toDateString(), 'location' => 'Receiving'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(2147483647, $product->fresh()->stock);
        $this->assertSame(2147483647, $batch->fresh()->quantity);
        $this->assertDatabaseCount('batches', 1);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_default_movement_factory_uses_a_batch_of_the_same_product(): void
    {
        $movement = InventoryMovement::factory()->create();
        $this->assertSame($movement->product_id, $movement->batch->product_id);
    }

    public function test_postgresql_rejects_cross_product_batches_even_outside_the_api(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Composite PostgreSQL constraints require the PostgreSQL CI database.');
        }
        $movement = InventoryMovement::factory()->create();
        $otherProduct = Product::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('inventory_movements')->where('id', $movement->id)->update(['product_id' => $otherProduct->id]));
    }

    public function test_postgresql_rejects_negative_stock_even_outside_the_api(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL CHECK constraints require the PostgreSQL CI database.');
        }
        $product = Product::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('products')->where('id', $product->id)->update(['stock' => -1]));
    }
}
