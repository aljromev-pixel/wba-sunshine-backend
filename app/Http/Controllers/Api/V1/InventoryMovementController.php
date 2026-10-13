<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreInventoryMovementRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Support\InventoryUpdates;
use App\Support\WarehouseStock;
use Illuminate\Database\Eloquent\Builder;
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
            User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $fingerprint = WarehouseStock::fingerprint($data);
            if (isset($data['requestId'])) {
                $existing = InventoryMovement::query()->where('user_id', $request->user()->id)->where('request_id', $data['requestId'])->first();
                if ($existing !== null) {
                    WarehouseStock::assertSameRequest($existing->request_fingerprint, $fingerprint);

                    return $existing->load(['batch:id,number', 'user:id,name']);
                }
            }
            WarehouseStock::initializeOpeningBalance($product, $request->user());
            WarehouseStock::assertBalanced($product);
            $batch = $this->resolveBatch($data, $product);
            $quantity = $data['quantity'];
            if ($product->demand_pattern === 'Perishable-Rotating' && $batch->expires_at === null) {
                throw ValidationException::withMessages(['expiresAt' => ['Perishable batches require a verified expiry date before recording movements.']]);
            }
            $increasesStock = in_array($data['type'], ['Stock In', 'Return'], true);
            $transfer = $data['type'] === 'Transfer';
            $before = $product->stock;
            $batchBefore = $batch->quantity;
            if ($batch->expires_at !== null && $batch->expires_at->lt(today())) {
                throw ValidationException::withMessages(['batchId' => ['Expired batches cannot be received, returned, dispatched or transferred as usable stock.']]);
            }

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

            $destination = null;
            if ($transfer) {
                $location = trim($data['destinationLocation']);
                if (strcasecmp($location, trim($batch->location)) === 0) {
                    throw ValidationException::withMessages(['destinationLocation' => ['Choose a different storage location.']]);
                }
                $originId = $batch->origin_batch_id ?? $batch->id;
                $destination = Batch::query()->where('product_id', $product->id)
                    ->where(fn (Builder $query): Builder => $query->where('origin_batch_id', $originId)->orWhere('id', $originId))
                    ->whereRaw('LOWER(location) = ?', [strtolower($location)])
                    ->lockForUpdate()->first();
                if ($destination === null) {
                    $destination = Batch::query()->create([
                        'product_id' => $product->id,
                        'origin_batch_id' => $originId,
                        'number' => 'TRANSFER-'.str()->uuid(),
                        'quantity' => 0,
                        'received_at' => $batch->received_at,
                        'expires_at' => $batch->expires_at,
                        'location' => $location,
                    ]);
                }
                $destination->increment('quantity', $quantity);
            } else {
                $product->increment('stock', $increasesStock ? $quantity : -$quantity);
            }
            $product->increment('stock_version');

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
                'stock_before' => $before,
                'stock_after' => $product->stock,
                'batch_before' => $batchBefore,
                'batch_after' => $batch->quantity,
                'destination_batch_id' => $destination?->id,
                'source_location' => $increasesStock ? null : $batch->location,
                'destination_location' => $destination?->location ?? ($increasesStock ? $batch->location : null),
                'return_condition' => $data['returnCondition'] ?? null,
                'request_id' => $data['requestId'] ?? null,
                'request_fingerprint' => $fingerprint,
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

            InventoryUpdates::movement($movement, $product, $request->user());

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
        if (isset($data['batchNumber']) && Batch::query()->where('number', $data['batchNumber'])->exists()) {
            throw ValidationException::withMessages(['batchNumber' => ['That receiving batch number already exists. Select the existing batch or enter a unique number.']]);
        }

        return Batch::query()->create([
            'product_id' => $product->id,
            'number' => $data['batchNumber'] ?? 'REC-'.str()->uuid(),
            'quantity' => 0,
            'received_at' => now()->toDateString(),
            'expires_at' => $data['expiresAt'] ?? null,
            'location' => $data['location'] ?? 'Main warehouse',
        ]);
    }
}
