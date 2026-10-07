<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\BatchResource;
use App\Http\Resources\InventoryAdjustmentResource;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\ProductResource;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()->orderBy('name')->get();
        $batches = Batch::query()->orderBy('received_at')->get();
        $movements = InventoryMovement::query()->with(['batch:id,number', 'user:id,name'])->latest()->get();
        $adjustments = InventoryAdjustment::query()
            ->with(['requestedBy:id,name', 'reviewedBy:id,name'])
            ->latest()
            ->get();
        $auditLogs = AuditLog::query()->with('user:id,name')->latest()->get();

        return response()->json([
            'products' => ProductResource::collection($products)->resolve($request),
            'batches' => BatchResource::collection($batches)->resolve($request),
            'transactions' => InventoryMovementResource::collection($movements)->resolve($request),
            'adjustments' => InventoryAdjustmentResource::collection($adjustments)->resolve($request),
            'audit' => AuditLogResource::collection($auditLogs)->resolve($request),
            'alerts' => $this->alerts($products, $batches, $adjustments),
        ]);
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, Batch>  $batches
     * @param  Collection<int, InventoryAdjustment>  $adjustments
     * @return array<int, array<string, int|string|null>>
     */
    private function alerts(Collection $products, Collection $batches, Collection $adjustments): array
    {
        $alerts = [];

        foreach ($products as $product) {
            if ($product->stock <= $product->reorder_point) {
                $alerts[] = [
                    'id' => "low-{$product->id}",
                    'type' => 'Low Stock',
                    'product' => $product->name,
                    'detail' => "{$product->stock} {$product->unit} available; reorder point is {$product->reorder_point}",
                    'severity' => 'High',
                    'date' => now()->toIso8601String(),
                ];
            }

            if ($product->capacity !== null && $product->stock >= $product->capacity * 0.9) {
                $alerts[] = [
                    'id' => "cap-{$product->id}",
                    'type' => 'Capacity Threshold',
                    'product' => $product->name,
                    'detail' => "{$product->stock} of {$product->capacity} capacity used",
                    'severity' => 'Medium',
                    'date' => now()->toIso8601String(),
                ];
            }
        }

        foreach ($batches as $batch) {
            if ($batch->expires_at === null) {
                continue;
            }

            $status = $batch->expires_at->isPast() ? 'Expired Stock' : ($batch->expires_at->diffInDays(now()) <= 30 ? 'Expiring Stock' : null);

            if ($status !== null) {
                $alerts[] = [
                    'id' => "exp-{$batch->id}",
                    'type' => $status,
                    'product' => $products->firstWhere('id', $batch->product_id)?->name,
                    'detail' => "{$batch->number} — {$batch->quantity} remaining, expiry {$batch->expires_at->toDateString()}",
                    'severity' => $status === 'Expired Stock' ? 'High' : 'Medium',
                    'date' => now()->toIso8601String(),
                ];
            }
        }

        foreach ($adjustments->where('status', 'Pending') as $adjustment) {
            $alerts[] = [
                'id' => "dis-{$adjustment->id}",
                'type' => 'Inventory Discrepancy',
                'product' => $products->firstWhere('id', $adjustment->product_id)?->name,
                'detail' => "Adjustment {$adjustment->id}: requested quantity {$adjustment->requested_qty}",
                'severity' => 'High',
                'date' => $adjustment->created_at?->toIso8601String(),
            ];
        }

        return $alerts;
    }
}
