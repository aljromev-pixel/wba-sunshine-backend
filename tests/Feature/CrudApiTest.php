<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CrudApiTest extends TestCase
{
    use RefreshDatabase;

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

        $this->assertDatabaseMissing('products', ['id' => $productId]);
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
