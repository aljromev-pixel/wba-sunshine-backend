<?php

namespace App\Support;

use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

class InventoryUpdates
{
    public static function notifications(User $user): MorphMany
    {
        $query = $user->notifications();
        if (! InventoryPermissions::canReviewAdjustment($user)) {
            $query->where(fn (Builder $records): Builder => $records->whereNull('data->adjustmentId')->orWhere('data->requestedById', $user->id));
        }

        return $query;
    }

    public static function movement(InventoryMovement $movement, Product $product, User $actor): void
    {
        $usable = $product->usableStock();
        $important = $usable <= $product->reorder_point;
        $locations = $movement->type === 'Transfer' ? " From {$movement->source_location} to {$movement->destination_location}; warehouse total unchanged." : '';
        $details = [
            'title' => "{$movement->type}: {$product->name}",
            'message' => "{$actor->name} recorded {$movement->quantity} {$product->unit}. On hand: {$product->stock}; usable: {$usable}.".$locations.($important ? ' Replenishment review needed.' : ''),
            'severity' => $important ? 'High' : 'Info', 'productId' => $product->id,
            'movementId' => $movement->id, 'link' => '/inventory/'.$product->id,
        ];
        User::query()->where('is_active', true)->chunkById(100, function (Collection $users) use ($details): void {
            foreach ($users as $user) {
                $user->notify(new InventoryNotice($details));
            }
        });
    }

    public static function adjustment(InventoryAdjustment $adjustment, Product $product, User $actor): void
    {
        $details = [
            'title' => "Adjustment {$adjustment->status}: {$product->name}",
            'message' => "{$actor->name}: count {$adjustment->requested_qty}, recorded stock {$adjustment->system_qty}. Request #{$adjustment->id}.",
            'severity' => $adjustment->requested_qty !== $adjustment->system_qty ? 'High' : 'Info',
            'productId' => $product->id, 'adjustmentId' => $adjustment->id, 'link' => '/adjustments',
            'requestedById' => $adjustment->requested_by,
        ];
        User::query()->where('is_active', true)->chunkById(100, function (Collection $users) use ($details, $adjustment): void {
            foreach ($users as $user) {
                if ($user->id === $adjustment->requested_by || InventoryPermissions::canReviewAdjustment($user)) {
                    $user->notify(new InventoryNotice($details));
                }
            }
        });
    }
}
