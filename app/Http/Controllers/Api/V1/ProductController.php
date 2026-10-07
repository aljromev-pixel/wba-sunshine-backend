<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\AuditLog;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ProductResource::collection(Product::query()->orderBy('name')->get())->response();
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::query()->create($this->attributes($request->validated()));
        $this->audit($request, 'Product created', $product, $product->name);

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product->update($this->attributes($request->validated()));
        $this->audit($request, 'Product updated', $product, $product->name);

        return (new ProductResource($product->fresh()))->response();
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeManagement($request);

        if ($product->batches()->exists() || $product->movements()->exists() || $product->adjustments()->exists()) {
            throw ValidationException::withMessages([
                'product' => ['Products with inventory history cannot be deleted.'],
            ]);
        }

        $this->audit($request, 'Product deleted', $product, $product->name);
        $product->delete();

        return response()->json(status: 204);
    }

    /** @param array<string, mixed> $data */
    private function attributes(array $data): array
    {
        return [
            'sku' => $data['sku'],
            'name' => $data['name'],
            'category' => $data['category'],
            'stock' => $data['stock'],
            'reorder_point' => $data['reorderPoint'],
            'capacity' => $data['capacity'] ?? null,
            'supplier' => $data['supplier'] ?? null,
            'lead_time' => $data['leadTime'],
            'unit' => $data['unit'],
            'monthly_sales' => $data['monthlySales'],
        ];
    }

    private function audit(Request $request, string $action, Product $product, string $description): void
    {
        AuditLog::query()->create([
            'user_id' => $request->user()->id,
            'department' => $request->user()->department,
            'role_level' => $request->user()->role_level,
            'action' => $action,
            'module' => 'Products',
            'reference' => (string) $product->id,
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
