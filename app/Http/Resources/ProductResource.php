<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'category' => $this->category,
            'stock' => $this->stock,
            'reorderPoint' => $this->reorder_point,
            'capacity' => $this->capacity,
            'supplier' => $this->supplier,
            'leadTime' => $this->lead_time,
            'unit' => $this->unit,
            'monthlySales' => $this->monthly_sales,
        ];
    }
}
