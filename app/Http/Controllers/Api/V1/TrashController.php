<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Product;
use App\Models\User;
use App\Support\InventoryPermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TrashController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:products,batches,users'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $model = $this->model($request, $data['type']);
        $records = $model::onlyTrashed()->orderByDesc('deleted_at')->orderByDesc('id')->paginate(25);
        $actors = User::withTrashed()->whereIn('id', $records->pluck('deleted_by')->filter())->get(['id', 'name'])->keyBy('id');
        $records->setCollection($records->getCollection()->map(fn (Model $record): array => [
            'id' => $record->id, 'type' => $data['type'], 'name' => $record->name ?? $record->number,
            'reference' => $record->sku ?? $record->number ?? $record->email,
            'deletedAt' => $record->deleted_at->toIso8601String(),
            'deletedBy' => $actors->get($record->deleted_by)?->name ?? 'Not recorded',
        ]));

        return response()->json($records);
    }

    public function restore(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->model($request, $type);
        DB::transaction(function () use ($request, $model, $type, $id): void {
            $record = $model::onlyTrashed()->findOrFail($id);
            if ($type === 'batches') {
                $product = Product::query()->lockForUpdate()->find($record->product_id);
                if ($product === null) {
                    throw ValidationException::withMessages(['record' => ['Restore the parent product first.']]);
                }
                $product->increment('stock_version');
            }
            $record = $model::onlyTrashed()->lockForUpdate()->findOrFail($id);
            abort_if($type === 'users' && $record->department === 'Administration' && $record->role_level === 'Manager', 403);
            $record->restore();
            $record->forceFill(['deleted_by' => null])->save();
            AuditLog::query()->create([
                'user_id' => $request->user()->id, 'department' => $request->user()->department,
                'role_level' => $request->user()->role_level, 'action' => 'Record restored',
                'module' => ucfirst($type), 'reference' => (string) $record->id,
                'description' => 'Restored '.($record->name ?? $record->number).($type === 'users' ? '; account remains disabled until an administrator enables it.' : ''),
            ]);
        });

        return response()->json(['message' => $type === 'users' ? 'Account restored. Enable it from User Management when ready.' : 'Record restored.']);
    }

    /** @return class-string<Product>|class-string<Batch>|class-string<User> */
    private function model(Request $request, string $type): string
    {
        $model = match ($type) {
            'products' => Product::class, 'batches' => Batch::class, 'users' => User::class, default => abort(404)
        };
        $role = $request->user()->department.':'.$request->user()->role_level;
        abort_unless($type === 'users' ? $role === 'Administration:Manager' : InventoryPermissions::canDeleteInventory($request->user()), 403);

        return $model;
    }
}
