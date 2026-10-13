<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class WarehouseStock
{
    public static function initializeOpeningBalance(Product $product, User $user): ?Batch
    {
        $allocated = (int) $product->batches()->sum('quantity');
        $quantity = $product->stock - $allocated;
        if ($quantity <= 0) {
            return null;
        }
        $batch = Batch::query()->create([
            'product_id' => $product->id,
            'number' => 'OPEN-'.$product->id.'-'.str()->uuid(),
            'quantity' => $quantity,
            'received_at' => today()->toDateString(),
            'location' => 'Main warehouse',
        ]);
        $product->increment('stock_version');
        InventoryMovement::query()->create([
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'user_id' => $user->id,
            'type' => 'Opening Balance',
            'quantity' => $quantity,
            'reference' => 'Opening allocation: previously unallocated stock',
            'stock_before' => $allocated,
            'stock_after' => $product->stock,
            'batch_before' => 0,
            'batch_after' => $quantity,
            'destination_location' => $batch->location,
        ]);
        AuditLog::query()->create([
            'user_id' => $user->id,
            'department' => $user->department,
            'role_level' => $user->role_level,
            'action' => 'Opening balance allocated',
            'module' => 'Inventory',
            'reference' => (string) $product->id,
            'description' => "{$quantity} existing units allocated to {$batch->number}; product stock remains {$product->stock}.",
        ]);

        return $batch;
    }

    public static function assertBalanced(Product $product): void
    {
        if ((int) $product->batches()->sum('quantity') !== $product->stock) {
            throw ValidationException::withMessages([
                'productId' => ['Batch quantities do not match product stock. Submit verified batch counts for adjustment review before recording movements.'],
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    public static function fingerprint(array $data): string
    {
        unset($data['requestId']);
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function assertSameRequest(?string $fingerprint, string $expected): void
    {
        if ($fingerprint !== $expected) {
            throw ValidationException::withMessages(['requestId' => ['This request identifier was already used with different details.']]);
        }
    }
}
