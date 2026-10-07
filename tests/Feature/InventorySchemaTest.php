<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventorySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_records_persist_with_their_domain_relationships(): void
    {
        $product = Product::factory()->create(['stock' => 35]);
        $user = User::factory()->create();
        $batch = Batch::factory()->for($product)->create(['quantity' => 35]);
        $movement = InventoryMovement::factory()
            ->for($product)
            ->for($batch)
            ->for($user)
            ->create(['quantity' => 5]);
        $adjustment = InventoryAdjustment::factory()
            ->for($product)
            ->for($user, 'requestedBy')
            ->create();
        $auditLog = AuditLog::factory()->for($user)->create();

        $this->assertModelExists($product);
        $this->assertSame(35, $product->stock);
        $this->assertTrue($product->batches->contains($batch));
        $this->assertSame($product->id, $movement->product->id);
        $this->assertSame($batch->id, $movement->batch->id);
        $this->assertSame($user->id, $movement->user->id);
        $this->assertSame($user->id, $adjustment->requestedBy->id);
        $this->assertTrue($user->auditLogs->contains($auditLog));
    }
}
