<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreInventoryMovementRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryMovementController extends Controller
{
    public function store(StoreInventoryMovementRequest $request): JsonResponse
    {
        $movement = DB::transaction(function () use ($request): InventoryMovement {
            $data = $request->validated();
            $product = Product::query()->lockForUpdate()->findOrFail($data['productId']);
            $batch = $this->resolveBatch($data, $product);
            $quantity = $data['quantity'];
            $increasesStock = in_array($data['type'], ['Stock In', 'Return'], true);

            if (! $increasesStock && $product->stock < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['The requested quantity exceeds available stock.'],
                ]);
            }

            if ($batch !== null && ! $increasesStock && $batch->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['The requested quantity exceeds the selected batch quantity.'],
                ]);
            }

            $product->increment('stock', $increasesStock ? $quantity : -$quantity);

            if ($batch !== null) {
                $batch->increment('quantity', $increasesStock ? $quantity : -$quantity);
            }

            $movement = InventoryMovement::query()->create([
                'product_id' => $product->id,
                'batch_id' => $batch?->id,
                'user_id' => $request->user()->id,
                'type' => $data['type'],
                'quantity' => $quantity,
                'reference' => $data['reference'] ?? null,
            ]);

            AuditLog::query()->create([
                'user_id' => $request->user()->id,
                'department' => $request->user()->department,
                'role_level' => $request->user()->role_level,
                'action' => $data['type'],
                'module' => 'Inventory',
                'reference' => (string) $movement->id,
                'description' => "{$product->name} — {$quantity} {$product->unit}",
            ]);

            return $movement->load(['batch:id,number', 'user:id,name']);
        });

        return (new InventoryMovementResource($movement))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveBatch(array $data, Product $product): ?Batch
    {
        $batchId = $data['batchId'] ?? null;

        if ($batchId !== null) {
            $batch = Batch::query()->lockForUpdate()->findOrFail($batchId);

            if ($batch->product_id !== $product->id) {
                throw ValidationException::withMessages([
                    'batchId' => ['The selected batch does not belong to the product.'],
                ]);
            }

            return $batch;
        }

        if ($data['type'] !== 'Stock In') {
            throw ValidationException::withMessages([
                'batchId' => ['A batch is required for this movement type.'],
            ]);
        }

        return Batch::query()->create([
            'product_id' => $product->id,
            'number' => $data['batchNumber'] ?? 'REC-'.now()->format('YmdHisv'),
            'quantity' => 0,
            'received_at' => now()->toDateString(),
            'expires_at' => $data['expiresAt'] ?? null,
        ]);
    }
}
