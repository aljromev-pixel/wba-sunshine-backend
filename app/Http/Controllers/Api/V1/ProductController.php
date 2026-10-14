<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\AuditLog;
use App\Models\Product;
use App\Support\InventoryPermissions;
use App\Support\WarehouseStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ProductResource::collection(Product::query()->orderBy('name')->get())->response();
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = DB::transaction(function () use ($request): Product {
            $product = Product::query()->create($this->attributes($request->validated()));
            WarehouseStock::initializeOpeningBalance($product, $request->user());
            $this->audit($request, 'Product created', $product, $product->name);

            return $product;
        });

        return (new ProductResource($product->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        DB::transaction(function () use ($request, $product): void {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $data = $request->validated();
            if (($data['demandPattern'] ?? $product->demand_pattern) === 'Perishable-Rotating' && $product->batches()->where('quantity', '>', 0)->whereNull('expires_at')->exists()) {
                throw ValidationException::withMessages(['demandPattern' => ['Record verified expiry dates for stocked batches before classifying this product as perishable.']]);
            }
            $product->update($this->attributes($data));
            $this->audit($request, 'Product updated', $product, $product->name);
        });

        return (new ProductResource($product->fresh()))->response();
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeManagement($request);

        DB::transaction(function () use ($request, $product): void {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            if ($product->stock > 0 || $product->batches()->exists() || $product->movements()->exists() || $product->adjustments()->exists()) {
                throw ValidationException::withMessages([
                    'product' => ['Products with inventory history cannot be deleted.'],
                ]);
            }

            $this->audit($request, 'Product deleted', $product, $product->name);
            $product->forceFill(['deleted_by' => $request->user()->id])->save();
            $product->delete();
        });

        return response()->json(status: 204);
    }

    /** @param array<string, mixed> $data */
    private function attributes(array $data): array
    {
        $attributes = [
            'sku' => $data['sku'],
            'name' => $data['name'],
            'category' => $data['category'],
            'reorder_point' => $data['reorderPoint'],
            'capacity' => $data['capacity'] ?? null,
            'supplier' => $data['supplier'] ?? null,
            'lead_time' => $data['leadTime'],
            'unit' => $data['unit'],
            'monthly_sales' => $data['monthlySales'],
        ];
        if (array_key_exists('stock', $data)) {
            $attributes['stock'] = $data['stock'];
        }
        foreach (['demandPattern' => 'demand_pattern', 'monthlySalesHistory' => 'monthly_sales_history', 'seasonalProfile' => 'seasonal_profile'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        return $attributes;
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
        abort_unless(InventoryPermissions::canDeleteInventory($request->user()), 403);
    }
}
