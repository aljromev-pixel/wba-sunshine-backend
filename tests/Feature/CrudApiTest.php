<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Product;
use App\Models\User;
use App\Support\InventoryPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CrudApiTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['Warehouse', 'Staff', false, false])]
    #[TestWith(['Warehouse', 'Supervisor', true, true])]
    #[TestWith(['Warehouse', 'Manager', true, true])]
    #[TestWith(['Sales', 'Staff', false, false])]
    #[TestWith(['Sales', 'Supervisor', false, false])]
    #[TestWith(['Purchasing', 'Staff', false, false])]
    #[TestWith(['Purchasing', 'Manager', true, false])]
    #[TestWith(['Administration', 'Manager', true, true])]
    public function test_catalogue_maintenance_and_deletion_follow_job_responsibilities(string $department, string $roleLevel, bool $maintain, bool $delete): void
    {
        $user = User::factory()->make(['department' => $department, 'role_level' => $roleLevel]);

        $this->assertSame($maintain, InventoryPermissions::canMaintainCatalogue($user));
        $this->assertSame($delete, InventoryPermissions::canDeleteInventory($user));
    }

    public function test_purchasing_manager_can_create_products_and_receive_batches_with_a_ledger_entry(): void
    {
        $manager = User::factory()->create(['department' => 'Purchasing', 'role_level' => 'Manager']);
        $productId = $this->actingAs($manager, 'sanctum')->postJson('/api/v1/products', $this->productPayload())
            ->assertCreated()->json('data.id');

        $this->postJson('/api/v1/batches', [
            'productId' => $productId, 'number' => 'PURCHASING-RECEIPT', 'quantity' => 7,
            'receivedAt' => '2026-10-01', 'location' => 'Receiving rack',
        ])->assertCreated()->assertJsonPath('data.quantity', 7);

        $this->assertDatabaseHas('products', ['id' => $productId, 'stock' => 7]);
        $this->assertDatabaseHas('inventory_movements', ['product_id' => $productId, 'user_id' => $manager->id, 'type' => 'Stock In', 'quantity' => 7]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $manager->id, 'action' => 'Batch created']);
    }

    public function test_purchasing_manager_can_edit_details_without_overwriting_stock_or_location(): void
    {
        $manager = User::factory()->create(['department' => 'Purchasing', 'role_level' => 'Manager']);
        $product = Product::factory()->create(['stock' => 7]);
        $batch = Batch::factory()->for($product)->create(['quantity' => 7, 'location' => 'Rack A', 'received_at' => '2026-10-01', 'expires_at' => null]);

        $this->actingAs($manager, 'sanctum')->putJson("/api/v1/products/{$product->id}", [...$this->productPayload(), 'name' => 'Panasonic Air Purifier', 'stock' => 999])
            ->assertOk()->assertJsonPath('data.name', 'Panasonic Air Purifier')->assertJsonPath('data.stock', 7);
        $this->putJson("/api/v1/batches/{$batch->id}", [
            'number' => 'CORRECTED-RECEIPT', 'receivedAt' => '2026-10-02', 'expiresAt' => '2027-10-02',
            'quantity' => 999, 'location' => 'Unrecorded transfer',
        ])->assertOk()->assertJsonPath('data.number', 'CORRECTED-RECEIPT')->assertJsonPath('data.quantity', 7)->assertJsonPath('data.location', 'Rack A');

        $this->assertDatabaseHas('batches', ['id' => $batch->id, 'number' => 'CORRECTED-RECEIPT']);
        $this->assertSame('2027-10-02', $batch->fresh()->expires_at->toDateString());
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $manager->id, 'action' => 'Batch updated']);
    }

    public function test_purchasing_manager_cannot_delete_or_restore_inventory_or_manage_users(): void
    {
        $manager = User::factory()->create(['department' => 'Purchasing', 'role_level' => 'Manager']);
        $product = Product::factory()->create(['stock' => 0]);
        $batch = Batch::factory()->for($product)->create(['quantity' => 0]);

        $this->actingAs($manager, 'sanctum')->deleteJson("/api/v1/batches/{$batch->id}")->assertForbidden();
        $this->deleteJson("/api/v1/products/{$product->id}")->assertForbidden();
        $this->getJson('/api/v1/trash?type=products')->assertForbidden();
        $this->postJson("/api/v1/trash/products/{$product->id}/restore")->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();

        $this->assertNotSoftDeleted($product);
        $this->assertNotSoftDeleted($batch);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_purchasing_staff_cannot_update_batches_or_create_batch_receipts(): void
    {
        $staff = User::factory()->create(['department' => 'Purchasing', 'role_level' => 'Staff']);
        $product = Product::factory()->create(['stock' => 0]);
        $batch = Batch::factory()->for($product)->create(['quantity' => 0]);

        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/batches', [
            'productId' => $product->id, 'number' => 'FORBIDDEN', 'quantity' => 5, 'receivedAt' => '2026-10-01',
        ])->assertForbidden();
        $this->putJson("/api/v1/batches/{$batch->id}", ['number' => 'FORBIDDEN', 'receivedAt' => '2026-10-01'])->assertForbidden();

        $this->assertDatabaseCount('batches', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_warehouse_manager_can_create_update_and_delete_a_product_without_history(): void
    {
        $manager = User::factory()->create(['department' => 'Warehouse', 'role_level' => 'Manager']);
        $payload = $this->productPayload();

        $create = $this->actingAs($manager, 'sanctum')->postJson('/api/v1/products', $payload);
        $create->assertCreated()->assertJsonPath('data.sku', 'CRUD-001');
        $productId = $create->json('data.id');

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/v1/products/{$productId}", [...$payload, 'name' => 'Updated Air Purifier'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Air Purifier');

        $this->actingAs($manager, 'sanctum')->deleteJson("/api/v1/products/{$productId}")->assertNoContent();

        $this->assertSoftDeleted('products', ['id' => $productId]);
        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_staff_cannot_create_products(): void
    {
        $staff = User::factory()->create(['department' => 'Warehouse', 'role_level' => 'Staff']);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/products', $this->productPayload())
            ->assertForbidden();
    }

    public function test_batch_creation_increases_its_product_stock(): void
    {
        $manager = User::factory()->create(['department' => 'Warehouse', 'role_level' => 'Manager']);
        $product = Product::factory()->create(['stock' => 5]);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/batches', [
                'productId' => $product->id,
                'number' => 'BATCH-CRUD-01',
                'quantity' => 7,
                'receivedAt' => '2026-10-08',
                'expiresAt' => '2027-10-08',
            ])
            ->assertCreated()
            ->assertJsonPath('data.productId', $product->id)
            ->assertJsonPath('data.quantity', 7);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 12]);
        $this->assertDatabaseHas('batches', ['number' => 'BATCH-CRUD-01', 'quantity' => 7]);
    }

    public function test_administration_manager_can_manage_users_but_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);

        $create = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'New Warehouse Staff',
            'lastName' => 'Member',
            'email' => 'new.staff@example.com',
            'password' => 'password123',
            'department' => 'Warehouse',
            'roleLevel' => 'Staff',
        ]);
        $create->assertCreated()->assertJsonPath('data.roleLevel', 'Staff');
        $userId = $create->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/users/{$userId}", [
                'name' => 'New Warehouse Supervisor',
                'email' => 'new.staff@example.com',
                'department' => 'Warehouse',
                'roleLevel' => 'Supervisor',
            ])
            ->assertOk()
            ->assertJsonPath('data.roleLevel', 'Supervisor');

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$userId}")->assertNoContent();
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$admin->id}")->assertUnprocessable();
    }

    public function test_non_administrator_cannot_manage_users(): void
    {
        $manager = User::factory()->create(['department' => 'Warehouse', 'role_level' => 'Manager']);

        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/users')->assertForbidden();
    }

    #[TestWith(['Sales', 'Manager'])]
    #[TestWith(['Purchasing', 'Supervisor'])]
    #[TestWith(['Administration', 'Staff'])]
    #[TestWith(['Administration', 'Supervisor'])]
    public function test_unsupported_role_cannot_be_created(string $department, string $roleLevel): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Unsupported User',
            'email' => 'unsupported@example.com',
            'password' => 'password123',
            'department' => $department,
            'roleLevel' => $roleLevel,
        ])->assertUnprocessable()->assertJsonValidationErrors(['roleLevel']);

        $this->assertDatabaseMissing('users', ['email' => 'unsupported@example.com']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[TestWith(['Sales', 'Manager'])]
    #[TestWith(['Purchasing', 'Supervisor'])]
    #[TestWith(['Administration', 'Staff'])]
    #[TestWith(['Administration', 'Supervisor'])]
    public function test_unsupported_role_cannot_be_assigned_on_update(string $department, string $roleLevel): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $staff = User::factory()->create(['department' => 'Warehouse', 'role_level' => 'Staff']);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$staff->id}", [
            'name' => $staff->name,
            'email' => $staff->email,
            'department' => $department,
            'roleLevel' => $roleLevel,
        ])->assertUnprocessable()->assertJsonValidationErrors(['roleLevel']);

        $this->assertDatabaseHas('users', ['id' => $staff->id, 'department' => 'Warehouse', 'role_level' => 'Staff']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /** @return array<string, int|string|null> */
    private function productPayload(): array
    {
        return [
            'sku' => 'CRUD-001',
            'name' => 'CRUD Air Purifier',
            'category' => 'Air Quality',
            'stock' => 0,
            'reorderPoint' => 10,
            'capacity' => 100,
            'supplier' => 'Test Supplier',
            'leadTime' => 7,
            'unit' => 'units',
            'monthlySales' => 10,
        ];
    }
}
