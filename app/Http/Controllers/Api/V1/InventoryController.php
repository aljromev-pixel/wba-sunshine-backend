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
use App\Support\InventoryPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()->withSum('batches', 'quantity')->orderBy('name')->get();
        $batches = Batch::query()->orderBy('received_at')->get();
        [$movements, $movementMeta] = $this->firstHistoryPage($request, 'transactions');
        [$adjustments, $adjustmentMeta] = $this->firstHistoryPage($request, 'adjustments');
        [$auditLogs, $auditMeta] = InventoryPermissions::canViewAudit($request->user())
            ? $this->firstHistoryPage($request, 'audit')
            : [collect(), ['total' => 0, 'throughId' => 0, 'nextPage' => null]];
        $pending = $this->historyQuery($request, 'adjustments')->where('id', '<=', $adjustmentMeta['throughId'])->where('status', 'Pending')->get();
        $adjustments = $adjustments->concat($pending)->unique('id')->values();

        return response()->json([
            'products' => ProductResource::collection($products)->resolve($request),
            'batches' => BatchResource::collection($batches)->resolve($request),
            'transactions' => InventoryMovementResource::collection($movements)->resolve($request),
            'adjustments' => InventoryAdjustmentResource::collection($adjustments)->resolve($request),
            'audit' => AuditLogResource::collection($auditLogs)->resolve($request),
            'alerts' => $this->alerts($products, $batches, $pending),
            'history' => ['transactions' => $movementMeta, 'adjustments' => $adjustmentMeta, 'audit' => $auditMeta],
            'loadedAt' => now()->toIso8601String(),
            'warehouseTimezone' => config('app.timezone'),
        ]);
    }

    public function history(Request $request, string $section): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'throughId' => ['required', 'integer', 'min:0'],
            'productId' => [$section === 'transactions' ? 'sometimes' : 'prohibited', 'integer', 'min:1'],
        ]);
        $resource = match ($section) {
            'transactions' => InventoryMovementResource::class,
            'adjustments' => InventoryAdjustmentResource::class,
            'audit' => AuditLogResource::class,
            default => abort(404),
        };
        $query = $this->historyQuery($request, $section)->where('id', '<=', $data['throughId']);
        if (isset($data['productId'])) {
            $query->where('product_id', $data['productId']);
        }
        $page = $query->paginate(100);

        return $resource::collection($page)->response();
    }

    /** @return array{Collection, array{total: int, throughId: int, nextPage: ?int}} */
    private function firstHistoryPage(Request $request, string $section): array
    {
        $query = $this->historyQuery($request, $section);
        $throughId = (int) (clone $query)->max('id');
        $query->where('id', '<=', $throughId);
        $total = (clone $query)->count();

        return [$query->limit(100)->get(), ['total' => $total, 'throughId' => $throughId, 'nextPage' => $total > 100 ? 2 : null]];
    }

    private function historyQuery(Request $request, string $section): Builder
    {
        if ($section === 'transactions') {
            return InventoryMovement::query()->with(['batch:id,number', 'user:id,name'])->orderByDesc('id');
        }
        if ($section === 'audit') {
            abort_unless(InventoryPermissions::canViewAudit($request->user()), 403);

            return AuditLog::query()->with('user:id,name')->orderByDesc('id');
        }
        $query = InventoryAdjustment::query()->with(['requestedBy:id,name', 'reviewedBy:id,name'])->orderByDesc('id');
        if (! InventoryPermissions::canReviewAdjustment($request->user())) {
            $query->where('requested_by', $request->user()->id);
        }

        return $query;
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

            if ($product->capacity !== null && $product->capacity > 0 && $product->stock >= $product->capacity * 0.9) {
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
            if ($batch->expires_at === null || $batch->quantity <= 0) {
                continue;
            }

            $days = today()->diffInDays($batch->expires_at->startOfDay());
            $status = $days < 0 ? 'Expired Stock' : ($days <= 30 ? 'Expiring Stock' : null);

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
