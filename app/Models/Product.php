<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'sku',
    'name',
    'category',
    'stock',
    'reorder_point',
    'capacity',
    'supplier',
    'lead_time',
    'unit',
    'monthly_sales',
    'stock_version',
    'demand_pattern',
    'monthly_sales_history',
    'seasonal_profile',
])]
class Product extends Model
{
    public const DEMAND_PATTERNS = ['Seasonal-Critical', 'Perishable-Rotating', 'Stable-Demand', 'Traceable-High Accuracy'];

    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    public function usableStock(): int
    {
        $quantity = $this->usable_stock ?? $this->batches()->where(function (Builder $query): void {
            $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', today());
        })->sum('quantity');

        return min($this->stock, (int) $quantity);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(InventoryAdjustment::class);
    }

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'stock_version' => 'integer',
            'reorder_point' => 'integer',
            'capacity' => 'integer',
            'lead_time' => 'integer',
            'monthly_sales' => 'integer',
            'monthly_sales_history' => 'array',
            'seasonal_profile' => 'array',
        ];
    }
}
