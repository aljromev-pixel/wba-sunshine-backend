<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewInventoryAdjustmentRequest;
use App\Http\Requests\Api\V1\StoreInventoryAdjustmentRequest;
use App\Http\Resources\InventoryAdjustmentResource;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Support\InventoryUpdates;
use App\Support\WarehouseStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryAdjustmentController extends Controller
{
    public function store(StoreInventoryAdjustmentRequest $request): JsonResponse
    {
        $adjustment = DB::transaction(function () use ($request): InventoryAdjustment {
            $data = $request->validated();
            $product = Product::query()->lockForUpdate()->findOrFail($data['productId']);
            User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $fingerprint = WarehouseStock::fingerprint($data);
            if (isset($data['requestId'])) {
                $existing = InventoryAdjustment::query()->where('requested_by', $request->user()->id)->where('request_id', $data['requestId'])->first();
                if ($existing !== null) {
                    WarehouseStock::assertSameRequest($existing->request_fingerprint, $fingerprint);

                    return $existing->load(['requestedBy:id,name']);
                }
            }
            if (isset($data['expectedStockVersion']) && (int) $data['expectedStockVersion'] !== $product->stock_version) {
                throw ValidationException::withMessages(['productId' => ['Stock changed while you were counting. Refresh inventory and recount before submitting.']]);
            }
            $openingBatch = WarehouseStock::initializeOpeningBalance($product, $request->user());
            if (! $product->batches()->exists()) {
                $openingBatch = Batch::query()->create([
                    'product_id' => $product->id, 'number' => 'COUNT-'.str()->uuid(), 'quantity' => 0,
                    'received_at' => today()->toDateString(), 'location' => 'Main warehouse',
                ]);
                $product->increment('stock_version');
            }
            $batches = $product->batches()->orderBy('id')->lockForUpdate()->get();
            $counts = $data['batchCounts'] ?? ($batches->count() === 1 ? [['batchId' => $batches->first()->id, 'quantity' => $data['requestedQty']]] : []);
            foreach ($counts as &$count) {
                $count['batchId'] = (int) $count['batchId'];
                $count['quantity'] = (int) $count['quantity'];
                if ($count['batchId'] === 0 && $openingBatch !== null) {
                    $count['batchId'] = $openingBatch->id;
                }
            }
            unset($count);
            $ids = collect($counts)->pluck('batchId')->sort()->values()->all();
            if ($ids !== $batches->pluck('id')->sort()->values()->all() || collect($counts)->sum('quantity') !== (int) $data['requestedQty']) {
                throw ValidationException::withMessages(['batchCounts' => ['Provide a verified quantity for every batch; their sum must equal the verified product total.']]);
            }
            $adjustment = InventoryAdjustment::query()->create([
                'product_id' => $product->id,
                'system_qty' => $product->stock,
                'requested_qty' => $data['requestedQty'],
                'reason' => $data['reason'],
                'requested_by' => $request->user()->id,
                'stock_version' => $product->stock_version,
                'batch_counts' => $counts,
                'request_id' => $data['requestId'] ?? null,
                'request_fingerprint' => $fingerprint,
            ]);

            $this->writeAudit($request, 'Adjustment requested', $adjustment);
            InventoryUpdates::adjustment($adjustment, $product, $request->user());

            return $adjustment->fresh(['requestedBy:id,name']);
        });

        return (new InventoryAdjustmentResource($adjustment))->response()->setStatusCode(201);
    }

    public function review(ReviewInventoryAdjustmentRequest $request, InventoryAdjustment $adjustment): JsonResponse
    {
        $adjustment = DB::transaction(function () use ($request, $adjustment): InventoryAdjustment {
            $product = Product::query()->lockForUpdate()->findOrFail($adjustment->product_id);
            $adjustment = InventoryAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);

            if ($adjustment->status !== 'Pending') {
                throw ValidationException::withMessages([
                    'adjustment' => ['This request is no longer pending.'],
                ]);
            }

            if ($adjustment->requested_by === $request->user()->id) {
                throw ValidationException::withMessages([
                    'adjustment' => ['You cannot review your own adjustment request.'],
                ]);
            }

            $approved = $request->boolean('approved');

            if ($approved) {
                $batches = $product->batches()->orderBy('id')->lockForUpdate()->get();
                $counts = collect($adjustment->batch_counts ?? []);
                if ($adjustment->stock_version === null || $adjustment->stock_version !== $product->stock_version || $adjustment->system_qty !== $product->stock || $counts->pluck('batchId')->sort()->values()->all() !== $batches->pluck('id')->sort()->values()->all()) {
                    throw ValidationException::withMessages(['adjustment' => ['Stock or batch allocation changed since this count. Reject this request and submit a fresh count.']]);
                }
                $before = $product->stock;
                foreach ($batches as $batch) {
                    $batch->update(['quantity' => $counts->firstWhere('batchId', $batch->id)['quantity']]);
                }
                $product->update(['stock' => $adjustment->requested_qty, 'stock_version' => $product->stock_version + 1]);
                WarehouseStock::assertBalanced($product);
                $movement = InventoryMovement::query()->create([
                    'product_id' => $product->id, 'user_id' => $request->user()->id,
                    'type' => 'Adjustment', 'quantity' => abs($product->stock - $before),
                    'reference' => 'Approved adjustment '.$adjustment->id,
                    'stock_before' => $before, 'stock_after' => $product->stock,
                ]);
                InventoryUpdates::movement($movement, $product, $request->user());
            }

            $adjustment->update([
                'reviewed_by' => $request->user()->id,
                'status' => $approved ? 'Approved' : 'Rejected',
                'reviewed_at' => now(),
            ]);
            $this->writeAudit($request, $approved ? 'Adjustment approved' : 'Adjustment rejected', $adjustment);
            InventoryUpdates::adjustment($adjustment, $product, $request->user());

            return $adjustment->load(['requestedBy:id,name', 'reviewedBy:id,name']);
        });

        return (new InventoryAdjustmentResource($adjustment))->response();
    }

    private function writeAudit(
        StoreInventoryAdjustmentRequest|ReviewInventoryAdjustmentRequest $request,
        string $action,
        InventoryAdjustment $adjustment,
    ): void {
        AuditLog::query()->create([
            'user_id' => $request->user()->id,
            'department' => $request->user()->department,
            'role_level' => $request->user()->role_level,
            'action' => $action,
            'module' => 'Adjustments',
            'reference' => (string) $adjustment->id,
            'description' => $adjustment->reason,
        ]);
    }
}
