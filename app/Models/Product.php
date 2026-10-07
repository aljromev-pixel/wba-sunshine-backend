<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

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
            'reorder_point' => 'integer',
            'capacity' => 'integer',
            'lead_time' => 'integer',
            'monthly_sales' => 'integer',
        ];
    }
}
