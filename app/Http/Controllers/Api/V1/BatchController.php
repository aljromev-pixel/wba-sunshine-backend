<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBatchRequest;
use App\Http\Requests\Api\V1\UpdateBatchRequest;
use App\Http\Resources\BatchResource;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Support\WarehouseStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return BatchResource::collection(Batch::query()->oldest('received_at')->get())->response();
    }

    public function store(StoreBatchRequest $request): JsonResponse
    {
        $batch = DB::transaction(function () use ($request): Batch {
            $data = $request->validated();
            $product = Product::query()->lockForUpdate()->findOrFail($data['productId']);
            WarehouseStock::initializeOpeningBalance($product, $request->user());
            WarehouseStock::assertBalanced($product);
            $before = $product->stock;
            $batch = Batch::query()->create([
                'product_id' => $product->id,
                'number' => $data['number'],
                'quantity' => $data['quantity'],
                'received_at' => $data['receivedAt'],
                'expires_at' => $data['expiresAt'] ?? null,
                'location' => $data['location'] ?? 'Main warehouse',
            ]);
            $product->increment('stock', $batch->quantity);
            $product->increment('stock_version');
            if ($batch->quantity > 0) {
                InventoryMovement::query()->create([
                    'product_id' => $product->id,
                    'batch_id' => $batch->id,
                    'user_id' => $request->user()->id,
                    'type' => 'Stock In',
                    'quantity' => $batch->quantity,
                    'reference' => 'Batch receipt '.$batch->number,
                    'stock_before' => $before,
                    'stock_after' => $product->stock,
                    'batch_before' => 0,
                    'batch_after' => $batch->quantity,
                    'destination_location' => $batch->location,
                ]);
            }
            $this->audit($request, 'Batch created', $batch, "{$batch->number} — {$batch->quantity} {$product->unit}");

            return $batch;
        });

        return (new BatchResource($batch))->response()->setStatusCode(201);
    }

    public function update(UpdateBatchRequest $request, Batch $batch): JsonResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($request, $batch, $data): void {
            $product = Product::query()->lockForUpdate()->findOrFail($batch->product_id);
            $batch->update([
                'number' => $data['number'],
                'received_at' => $data['receivedAt'],
                'expires_at' => $data['expiresAt'] ?? null,
            ]);
            $originId = $batch->origin_batch_id ?? $batch->id;
            $product->batches()->where(fn (Builder $query): Builder => $query->where('id', $originId)->orWhere('origin_batch_id', $originId))
                ->update(['received_at' => $data['receivedAt'], 'expires_at' => $data['expiresAt'] ?? null]);
            $product->increment('stock_version');
            $this->audit($request, 'Batch updated', $batch, $batch->number);
        });

        return (new BatchResource($batch->fresh()))->response();
    }

    public function destroy(Request $request, Batch $batch): JsonResponse
    {
        $this->authorizeManagement($request);

        DB::transaction(function () use ($request, $batch): void {
            $product = Product::query()->lockForUpdate()->findOrFail($batch->product_id);
            $batch = Batch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->quantity > 0 || $batch->inventoryMovements()->exists() || InventoryMovement::query()->where('destination_batch_id', $batch->id)->exists()) {
                throw ValidationException::withMessages([
                    'batch' => ['Only empty batches without movement history can be deleted.'],
                ]);
            }

            $this->audit($request, 'Batch deleted', $batch, $batch->number);
            $batch->delete();
            $product->increment('stock_version');
        });

        return response()->json(status: 204);
    }

    private function audit(Request $request, string $action, Batch $batch, string $description): void
    {
        AuditLog::query()->create([
            'user_id' => $request->user()->id,
            'department' => $request->user()->department,
            'role_level' => $request->user()->role_level,
            'action' => $action,
            'module' => 'Batches',
            'reference' => (string) $batch->id,
            'description' => $description,
        ]);
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless(in_array("{$request->user()?->department}:{$request->user()?->role_level}", [
            'Warehouse:Supervisor',
            'Warehouse:Manager',
            'Administration:Manager',
        ], true), 403);
    }
}
