<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\User;
use App\Support\InventoryNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrashAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleted_product_is_hidden_restorable_and_audited(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create(['stock' => 0]);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();
        $this->assertSoftDeleted($product);
        $this->getJson('/api/v1/inventory')->assertOk()->assertJsonCount(0, 'products');
        $this->getJson('/api/v1/trash?type=products')->assertOk()->assertJsonPath('data.0.name', $product->name)->assertJsonPath('data.0.deletedBy', $admin->name);
        $this->postJson("/api/v1/trash/products/{$product->id}/restore")->assertOk();
        $this->assertNotSoftDeleted($product);
        $this->getJson('/api/v1/trash?type=products')->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'Record restored', 'reference' => (string) $product->id]);
        $this->postJson("/api/v1/trash/products/{$product->id}/restore")->assertNotFound();
    }

    public function test_trash_permissions_cannot_be_bypassed_by_changing_record_type(): void
    {
        $this->getJson('/api/v1/trash?type=products')->assertUnauthorized();
        $product = Product::factory()->create(['stock' => 0]);
        $product->delete();
        $staff = User::factory()->create();
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/trash?type=products')->assertForbidden();
        $this->postJson("/api/v1/trash/products/{$product->id}/restore")->assertForbidden();
        $manager = User::factory()->create(['department' => 'Warehouse', 'role_level' => 'Manager']);
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/trash?type=products')->assertOk();
        $this->getJson('/api/v1/trash?type=users')->assertForbidden();
        $this->postJson('/api/v1/trash/users/123/restore')->assertForbidden();
        $this->postJson('/api/v1/trash/audit/123/restore')->assertNotFound();
    }

    public function test_restoring_a_user_does_not_restore_access_or_tokens(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $token = $user->createToken('old-session')->accessToken;
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$user->id}")->assertNoContent();
        $this->assertSoftDeleted($user);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
        $this->postJson("/api/v1/trash/users/{$user->id}/restore")->assertOk();
        $this->assertNotSoftDeleted($user);
        $this->assertFalse($user->fresh()->is_active);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
    }

    public function test_empty_batch_restoration_requires_its_parent_and_does_not_change_stock(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create(['stock' => 0]);
        $batch = Batch::factory()->for($product)->create(['quantity' => 0]);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/batches/{$batch->id}")->assertNoContent();
        $this->assertSoftDeleted($batch);
        $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();
        $this->postJson("/api/v1/trash/batches/{$batch->id}/restore")->assertUnprocessable();
        $this->postJson("/api/v1/trash/products/{$product->id}/restore")->assertOk();
        $this->postJson("/api/v1/trash/batches/{$batch->id}/restore")->assertOk();
        $this->assertNotSoftDeleted($batch);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(2, $product->fresh()->stock_version);
    }

    public function test_stocked_products_cannot_be_hidden_in_trash(): void
    {
        $product = Product::factory()->create(['stock' => 4]);
        $this->actingAs($this->admin(), 'sanctum')->deleteJson("/api/v1/products/{$product->id}")->assertUnprocessable();
        $this->assertNotSoftDeleted($product);
    }

    public function test_usable_stock_excludes_expired_batches_and_matched_counts_are_not_discrepancies(): void
    {
        $this->freezeTime();
        $user = $this->admin();
        $product = Product::factory()->create(['stock' => 10, 'reorder_point' => 4]);
        Batch::factory()->for($product)->create(['quantity' => 7, 'expires_at' => today()->subDay()]);
        Batch::factory()->for($product)->create(['quantity' => 3, 'expires_at' => today()]);
        InventoryAdjustment::factory()->for($product)->create(['requested_by' => $user->id, 'system_qty' => 10, 'requested_qty' => 10, 'status' => 'Pending']);
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/inventory')->assertOk()->assertJsonPath('products.0.stock', 10)->assertJsonPath('products.0.usableStock', 3);
        $alerts = collect($response->json('alerts'));
        $this->assertTrue($alerts->contains('type', 'Low Stock'));
        $this->assertFalse($alerts->contains('type', 'Inventory Discrepancy'));
    }

    public function test_movement_notifications_persist_without_duplicates_and_failed_writes_do_not_notify(): void
    {
        $actor = $this->admin();
        $observer = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);
        $product = Product::factory()->create(['stock' => 10]);
        $batch = Batch::factory()->for($product)->create(['quantity' => 10, 'expires_at' => null]);
        $payload = ['type' => 'Stock Out', 'productId' => $product->id, 'batchId' => $batch->id, 'quantity' => 2, 'reference' => 'QA dispatch', 'requestId' => (string) Str::uuid()];
        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/inventory/movements', $payload)->assertCreated();
        $this->postJson('/api/v1/inventory/movements', $payload)->assertCreated();
        $this->assertSame(1, $observer->notifications()->count());
        $this->assertSame(0, $inactive->notifications()->count());
        $this->assertDatabaseCount('notifications', 2);
        $this->postJson('/api/v1/inventory/movements', [...$payload, 'requestId' => (string) Str::uuid(), 'quantity' => 999])->assertUnprocessable();
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_notification_read_state_is_account_scoped_and_survives_refresh(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owner->notify(new InventoryNotice(['title' => 'Stock update', 'message' => 'QA', 'link' => '/alerts']));
        $id = $owner->notifications()->firstOrFail()->id;
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/notifications')->assertJsonPath('unreadCount', 1);
        $this->postJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('unreadCount', 0);
        $this->getJson('/api/v1/inventory')->assertJsonPath('notificationSummary.unreadCount', 0);
        $this->postJson('/api/v1/notifications/read-all')->assertOk();
    }

    public function test_adjustment_notifications_only_reach_requester_and_authorized_reviewers(): void
    {
        $staff = User::factory()->create();
        $reviewer = $this->admin();
        $sales = User::factory()->create(['department' => 'Sales', 'role_level' => 'Staff']);
        $product = Product::factory()->create(['stock' => 10]);
        Batch::factory()->for($product)->create(['quantity' => 10]);
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/inventory/adjustments', ['productId' => $product->id, 'requestedQty' => 8, 'reason' => 'Physical count'])->assertCreated();
        $this->assertSame(1, $staff->notifications()->count());
        $this->assertSame(1, $reviewer->notifications()->count());
        $this->assertSame(0, $sales->notifications()->count());
        $reviewer->update(['department' => 'Sales', 'role_level' => 'Staff']);
        $this->actingAs($reviewer->fresh(), 'sanctum')->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unreadCount', 0);
    }

    private function admin(): User
    {
        return User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
    }

    public function test_sales_history_and_seasonal_profiles_persist_and_reject_invalid_months(): void
    {
        $this->freezeTime();
        $payload = ['sku' => 'HISTORY-1', 'name' => 'Planning test', 'category' => 'Air Conditioners', 'stock' => 0, 'reorderPoint' => 2, 'leadTime' => 7, 'monthlySales' => 10, 'unit' => 'units', 'demandPattern' => 'Seasonal-Critical', 'monthlySalesHistory' => [['month' => today()->subMonthNoOverflow()->format('Y-m'), 'quantity' => 60]], 'seasonalProfile' => [['month' => 6, 'multiplier' => 2]]];
        $id = $this->actingAs($this->admin(), 'sanctum')->postJson('/api/v1/products', $payload)->assertCreated()->assertJsonPath('data.demandPattern', 'Seasonal-Critical')->json('data.id');
        $this->getJson('/api/v1/inventory')->assertJsonPath('products.0.monthlySalesHistory.0.quantity', 60)->assertJsonPath('products.0.seasonalProfile.0.multiplier', 2);
        $this->putJson("/api/v1/products/{$id}", [...$payload, 'monthlySalesHistory' => [['month' => today()->format('Y-m'), 'quantity' => 99]]])->assertUnprocessable()->assertJsonValidationErrors('monthlySalesHistory.0.month');
        $this->putJson("/api/v1/products/{$id}", [...$payload, 'seasonalProfile' => [['month' => 6, 'multiplier' => 2], ['month' => 6, 'multiplier' => 3]]])->assertUnprocessable()->assertJsonValidationErrors('seasonalProfile.0.month');
        $this->putJson("/api/v1/products/{$id}", [...$payload, 'demandPattern' => 'Unknown'])->assertUnprocessable()->assertJsonValidationErrors('demandPattern');
    }

    public function test_perishable_receipts_require_verified_expiry_and_cannot_have_undated_opening_stock(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create(['stock' => 0, 'demand_pattern' => 'Perishable-Rotating']);
        $payload = ['productId' => $product->id, 'quantity' => 2, 'reference' => 'QA receipt', 'type' => 'Stock In'];
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/inventory/movements', $payload)->assertUnprocessable()->assertJsonValidationErrors('expiresAt');
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertDatabaseCount('batches', 0);
        $this->postJson('/api/v1/inventory/movements', [...$payload, 'expiresAt' => today()->addMonths(9)->toDateString()])->assertCreated();
        $this->postJson('/api/v1/batches', ['productId' => $product->id, 'number' => 'UNDATED', 'quantity' => 1, 'receivedAt' => today()->toDateString()])->assertUnprocessable();
        $this->postJson('/api/v1/products', ['sku' => 'UNDATED-OPENING', 'name' => 'Filter', 'category' => 'Filters', 'stock' => 3, 'reorderPoint' => 0, 'leadTime' => 1, 'monthlySales' => 0, 'unit' => 'units', 'demandPattern' => 'Perishable-Rotating'])->assertUnprocessable()->assertJsonValidationErrors('stock');
    }
}
