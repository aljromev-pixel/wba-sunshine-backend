<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBatchRequest;
use App\Http\Requests\Api\V1\UpdateBatchRequest;
use App\Http\Resources\BatchResource;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Product;
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
            $batch = Batch::query()->create([
                'product_id' => $product->id,
                'number' => $data['number'],
                'quantity' => $data['quantity'],
                'received_at' => $data['receivedAt'],
                'expires_at' => $data['expiresAt'] ?? null,
            ]);
            $product->increment('stock', $batch->quantity);
            $this->audit($request, 'Batch created', $batch, "{$batch->number} — {$batch->quantity} {$product->unit}");

            return $batch;
        });

        return (new BatchResource($batch))->response()->setStatusCode(201);
    }

    public function update(UpdateBatchRequest $request, Batch $batch): JsonResponse
    {
        $data = $request->validated();
        $batch->update([
            'number' => $data['number'],
            'received_at' => $data['receivedAt'],
            'expires_at' => $data['expiresAt'] ?? null,
        ]);
        $this->audit($request, 'Batch updated', $batch, $batch->number);

        return (new BatchResource($batch->fresh()))->response();
    }

    public function destroy(Request $request, Batch $batch): JsonResponse
    {
        $this->authorizeManagement($request);

        if ($batch->quantity > 0 || $batch->inventoryMovements()->exists()) {
            throw ValidationException::withMessages([
                'batch' => ['Only empty batches without movement history can be deleted.'],
            ]);
        }

        $this->audit($request, 'Batch deleted', $batch, $batch->number);
        $batch->delete();

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
