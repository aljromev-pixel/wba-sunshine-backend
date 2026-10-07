<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewInventoryAdjustmentRequest;
use App\Http\Requests\Api\V1\StoreInventoryAdjustmentRequest;
use App\Http\Resources\InventoryAdjustmentResource;
use App\Models\AuditLog;
use App\Models\InventoryAdjustment;
use App\Models\Product;
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
            $adjustment = InventoryAdjustment::query()->create([
                'product_id' => $product->id,
                'system_qty' => $product->stock,
                'requested_qty' => $data['requestedQty'],
                'reason' => $data['reason'],
                'requested_by' => $request->user()->id,
            ]);

            $this->writeAudit($request, 'Adjustment requested', $adjustment);

            return $adjustment->fresh(['requestedBy:id,name']);
        });

        return (new InventoryAdjustmentResource($adjustment))->response()->setStatusCode(201);
    }

    public function review(ReviewInventoryAdjustmentRequest $request, InventoryAdjustment $adjustment): JsonResponse
    {
        $adjustment = DB::transaction(function () use ($request, $adjustment): InventoryAdjustment {
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
                Product::query()
                    ->lockForUpdate()
                    ->findOrFail($adjustment->product_id)
                    ->update(['stock' => $adjustment->requested_qty]);
            }

            $adjustment->update([
                'reviewed_by' => $request->user()->id,
                'status' => $approved ? 'Approved' : 'Rejected',
                'reviewed_at' => now(),
            ]);
            $this->writeAudit($request, $approved ? 'Adjustment approved' : 'Adjustment rejected', $adjustment);

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
