<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WarehouseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_details_cannot_overwrite_stock_and_opening_stock_is_allocated(): void
    {
        $admin = $this->administrator();
        $payload = ['sku' => 'OPEN-TEST', 'name' => 'Test appliance', 'category' => 'Air Quality', 'stock' => 10, 'reorderPoint' => 2, 'leadTime' => 7, 'monthlySales' => 5, 'unit' => 'units'];
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/products', $payload)->assertCreated();
        $id = $response->json('data.id');
        $product = Product::query()->findOrFail($id);
        $this->assertSame(10, (int) $product->batches()->sum('quantity'));
        $this->assertDatabaseHas('inventory_movements', ['product_id' => $id, 'type' => 'Opening Balance', 'stock_before' => 0, 'stock_after' => 10]);
        $this->putJson("/api/v1/products/{$id}", [...$payload, 'name' => 'Updated details', 'stock' => 999])->assertOk()->assertJsonPath('data.stock', 10);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_internal_transfer_preserves_total_and_records_both_locations(): void
    {
        $admin = $this->administrator();
        [$product, $batch] = $this->stock();
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/inventory/movements', $this->movement($product, $batch, ['type' => 'Transfer', 'destinationLocation' => 'Rack B']))
            ->assertCreated()->assertJsonPath('data.stockBefore', 10)->assertJsonPath('data.stockAfter', 10)
            ->assertJsonPath('data.sourceLocation', 'Main warehouse')->assertJsonPath('data.destinationLocation', 'Rack B');
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(8, $batch->fresh()->quantity);
        $this->assertSame(2, Batch::query()->findOrFail($response->json('data.destinationBatchId'))->quantity);
        $this->assertSame(10, (int) $product->batches()->sum('quantity'));
        $this->postJson('/api/v1/inventory/movements', $this->movement($product, $batch, ['type' => 'Transfer', 'destinationLocation' => 'Main warehouse']))->assertUnprocessable()->assertJsonValidationErrors('destinationLocation');
    }

    public function test_retrying_a_movement_is_idempotent_and_payload_changes_are_rejected(): void
    {
        $admin = $this->administrator();
        [$product, $batch] = $this->stock();
        $payload = $this->movement($product, $batch, ['requestId' => (string) Str::uuid()]);
        $first = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/inventory/movements', $payload)->assertCreated();
        $this->postJson('/api/v1/inventory/movements', $payload)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->postJson('/api/v1/inventory/movements', [...$payload, 'quantity' => 3])->assertUnprocessable()->assertJsonValidationErrors('requestId');
        $this->assertSame(8, $batch->fresh()->quantity);
    }

    public function test_batch_counts_update_batches_and_product_atomically(): void
    {
        $staff = User::factory()->create();
        $admin = $this->administrator();
        [$product, $batch] = $this->stock(15);
        $other = Batch::factory()->for($product)->create(['quantity' => 5]);
        $batch->update(['quantity' => 10]);
        $response = $this->actingAs($staff, 'sanctum')->postJson('/api/v1/inventory/adjustments', [
            'productId' => $product->id, 'requestedQty' => 12, 'reason' => 'Physical count',
            'batchCounts' => [['batchId' => $batch->id, 'quantity' => 8], ['batchId' => $other->id, 'quantity' => 4]],
        ])->assertCreated();
        $id = $response->json('data.id');
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/inventory/adjustments/{$id}/review", ['approved' => true])->assertOk();
        $this->assertSame(12, $product->fresh()->stock);
        $this->assertSame(8, $batch->fresh()->quantity);
        $this->assertSame(4, $other->fresh()->quantity);
        $this->assertSame(12, (int) $product->batches()->sum('quantity'));
        $this->assertDatabaseHas('inventory_movements', ['type' => 'Adjustment', 'stock_before' => 15, 'stock_after' => 12]);
    }

    public function test_later_movement_prevents_stale_adjustment_approval(): void
    {
        $staff = User::factory()->create();
        $admin = $this->administrator();
        [$product, $batch] = $this->stock();
        $id = $this->actingAs($staff, 'sanctum')->postJson('/api/v1/inventory/adjustments', ['productId' => $product->id, 'requestedQty' => 7, 'reason' => 'Count before dispatch'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory/movements', $this->movement($product, $batch))->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/inventory/adjustments/{$id}/review", ['approved' => true])->assertUnprocessable()->assertJsonValidationErrors('adjustment');
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_adjustments', ['id' => $id, 'status' => 'Pending']);
        $this->postJson("/api/v1/inventory/adjustments/{$id}/review", ['approved' => false])->assertOk();
    }

    public function test_adjustment_retries_do_not_duplicate_requests_and_missing_batch_counts_fail(): void
    {
        $staff = User::factory()->create();
        [$product, $batch] = $this->stock();
        $payload = ['productId' => $product->id, 'requestedQty' => 7, 'reason' => 'Physical count', 'requestId' => (string) Str::uuid()];
        $first = $this->actingAs($staff, 'sanctum')->postJson('/api/v1/inventory/adjustments', $payload)->assertCreated();
        $this->postJson('/api/v1/inventory/adjustments', $payload)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('inventory_adjustments', 1);
        Batch::factory()->for($product)->create(['quantity' => 0]);
        $this->postJson('/api/v1/inventory/adjustments', ['productId' => $product->id, 'requestedQty' => 7, 'reason' => 'Missing allocations'])->assertUnprocessable()->assertJsonValidationErrors('batchCounts');
    }

    #[TestWith(['Stock Out'])]
    #[TestWith(['Stock In'])]
    #[TestWith(['Transfer'])]
    #[TestWith(['Return'])]
    public function test_expired_batches_cannot_be_used_by_direct_api_calls(string $type): void
    {
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));
        $admin = $this->administrator();
        [$product, $batch] = $this->stock();
        $batch->update(['expires_at' => today()->subDay()]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/inventory/movements', $this->movement($product, $batch, ['type' => $type, 'destinationLocation' => 'Rack B', 'returnCondition' => 'Resaleable']))->assertUnprocessable()->assertJsonValidationErrors('batchId');
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_expiry_alerts_match_calendar_boundaries_and_ignore_depleted_batches(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));
        $staff = User::factory()->create();
        $product = Product::factory()->create(['stock' => 100, 'reorder_point' => 0, 'capacity' => null]);
        foreach ([-1, 0, 30, 31] as $days) {
            Batch::factory()->for($product)->create(['quantity' => 1, 'expires_at' => today()->addDays($days)]);
        }
        Batch::factory()->for($product)->create(['quantity' => 0, 'expires_at' => today()->subDay()]);
        $alerts = $this->actingAs($staff, 'sanctum')->getJson('/api/v1/inventory')->assertOk()->json('alerts');
        $this->assertCount(3, $alerts);
        $this->assertSame(1, collect($alerts)->where('type', 'Expired Stock')->count());
        $this->assertSame(2, collect($alerts)->where('type', 'Expiring Stock')->count());
    }

    #[TestWith(['Warehouse', 'Staff', false])]
    #[TestWith(['Sales', 'Staff', false])]
    #[TestWith(['Purchasing', 'Staff', false])]
    #[TestWith(['Warehouse', 'Supervisor', true])]
    #[TestWith(['Warehouse', 'Manager', true])]
    #[TestWith(['Sales', 'Supervisor', true])]
    #[TestWith(['Purchasing', 'Manager', true])]
    #[TestWith(['Administration', 'Manager', true])]
    public function test_audit_data_is_filtered_by_backend_permissions(string $department, string $level, bool $allowed): void
    {
        $user = User::factory()->create(['department' => $department, 'role_level' => $level]);
        AuditLog::factory()->create();
        $audit = $this->actingAs($user, 'sanctum')->getJson('/api/v1/inventory')->assertOk()->json('audit');
        $this->assertCount($allowed ? 1 : 0, $audit);
    }

    public function test_only_administrator_cannot_remove_their_own_admin_role(): void
    {
        $admin = $this->administrator();
        $payload = ['name' => 'Owner', 'lastName' => 'Member', 'email' => $admin->email, 'department' => 'Warehouse', 'roleLevel' => 'Staff'];
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$admin->id}", $payload)->assertUnprocessable()->assertJsonValidationErrors('roleLevel');
        $this->assertSame('Administration', $admin->fresh()->department);
        $this->administrator();
        $this->putJson("/api/v1/users/{$admin->id}", $payload)->assertOk();
    }

    public function test_api_tokens_expire_after_the_configured_shift(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('frontend')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->travel(13)->hours();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_transfer_fragments_keep_the_same_receiving_and_expiry_dates(): void
    {
        $admin = $this->administrator();
        [$product, $batch] = $this->stock();
        $transfer = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/inventory/movements', $this->movement($product, $batch, ['type' => 'Transfer', 'destinationLocation' => 'Rack B']))->assertCreated();
        $expiry = today()->addYear()->toDateString();
        $this->putJson("/api/v1/batches/{$batch->id}", ['number' => $batch->number, 'receivedAt' => $batch->received_at->toDateString(), 'expiresAt' => $expiry])->assertOk();
        $this->assertSame($expiry, Batch::query()->findOrFail($transfer->json('data.destinationBatchId'))->expires_at->toDateString());
    }

    public function test_legacy_unallocated_stock_is_counted_and_reconciled_without_losing_existing_batches(): void
    {
        $staff = User::factory()->create();
        $admin = $this->administrator();
        [$product, $batch] = $this->stock();
        $batch->update(['quantity' => 5]);
        $count = $this->actingAs($staff, 'sanctum')->postJson('/api/v1/inventory/adjustments', [
            'productId' => $product->id, 'requestedQty' => 7, 'reason' => 'Verified old opening stock',
            'batchCounts' => [['batchId' => $batch->id, 'quantity' => 3], ['batchId' => 0, 'quantity' => 4]],
        ])->assertCreated();
        $this->assertSame(10, $product->fresh()->stock);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/inventory/adjustments/'.$count->json('data.id').'/review', ['approved' => true])->assertOk();
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(3, $batch->fresh()->quantity);
        $this->assertSame(7, (int) $product->batches()->sum('quantity'));
    }

    public function test_a_count_is_stale_even_when_movements_restore_the_same_total(): void
    {
        $staff = User::factory()->create();
        $admin = $this->administrator();
        [$product, $batch] = $this->stock();
        $id = $this->actingAs($staff, 'sanctum')->postJson('/api/v1/inventory/adjustments', ['productId' => $product->id, 'requestedQty' => 7, 'reason' => 'Count before movements'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory/movements', $this->movement($product, $batch))->assertCreated();
        $this->postJson('/api/v1/inventory/movements', $this->movement($product, $batch, ['type' => 'Stock In']))->assertCreated();
        $this->assertSame(10, $product->fresh()->stock);
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/inventory/adjustments/{$id}/review", ['approved' => true])->assertUnprocessable();
        $this->assertSame(10, $batch->fresh()->quantity);
    }

    public function test_batch_creation_cannot_bypass_expired_stock_receipt_rejection(): void
    {
        $admin = $this->administrator();
        $product = Product::factory()->create(['stock' => 0]);
        $payload = ['productId' => $product->id, 'number' => 'EXPIRED-RECEIPT', 'quantity' => 3, 'receivedAt' => today()->subDays(2)->toDateString(), 'expiresAt' => today()->subDay()->toDateString()];
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/batches', $payload)->assertUnprocessable()->assertJsonValidationErrors('expiresAt');
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertDatabaseCount('batches', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->postJson('/api/v1/batches', [...$payload, 'quantity' => 0])->assertCreated();
        $this->postJson('/api/v1/batches', [...$payload, 'number' => 'TODAY-RECEIPT', 'expiresAt' => today()->toDateString()])->assertCreated();
        $this->assertSame(3, $product->fresh()->stock);
    }

    private function administrator(): User
    {
        return User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
    }

    public function test_history_is_paged_without_exposing_audit_or_losing_snapshot_boundaries(): void
    {
        $staff = User::factory()->create();
        [$product, $batch] = $this->stock();
        InventoryMovement::factory()->count(105)->for($product)->for($batch)->for($staff)->create();
        $snapshot = $this->actingAs($staff, 'sanctum')->getJson('/api/v1/inventory')->assertOk();
        $snapshot->assertJsonCount(100, 'transactions')->assertJsonPath('history.transactions.total', 105)->assertJsonPath('history.transactions.nextPage', 2);
        $throughId = $snapshot->json('history.transactions.throughId');
        InventoryMovement::factory()->for($product)->for($batch)->for($staff)->create();
        $this->getJson("/api/v1/inventory/history/transactions?page=2&throughId={$throughId}")
            ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 105);
        $this->getJson('/api/v1/inventory/history/audit?throughId=0')->assertForbidden();
        $this->getJson('/api/v1/inventory/history/unknown?throughId=0')->assertNotFound();
    }

    public function test_count_cannot_omit_a_batch_or_reuse_a_pre_movement_snapshot(): void
    {
        $staff = User::factory()->create();
        [$product, $batch] = $this->stock();
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/inventory/movements', $this->movement($product, $batch))->assertCreated();
        $this->postJson('/api/v1/inventory/adjustments', ['productId' => $product->id, 'requestedQty' => 7, 'reason' => 'Old count', 'expectedStockVersion' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('productId');
        $this->assertDatabaseCount('inventory_adjustments', 0);
    }

    /** @return array{Product, Batch} */
    private function stock(int $quantity = 10): array
    {
        $product = Product::factory()->create(['stock' => $quantity]);

        return [$product, Batch::factory()->for($product)->create(['quantity' => $quantity, 'expires_at' => null])];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function movement(Product $product, Batch $batch, array $overrides = []): array
    {
        return [...['productId' => $product->id, 'batchId' => $batch->id, 'type' => 'Stock Out', 'quantity' => 2, 'reference' => 'QA dispatch'], ...$overrides];
    }
}
