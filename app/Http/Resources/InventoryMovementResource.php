<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
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
            'type' => $this->type,
            'productId' => $this->product_id,
            'quantity' => $this->quantity,
            'batch' => $this->batch?->number,
            'date' => $this->created_at?->toIso8601String(),
            'user' => $this->user?->name,
            'reference' => $this->reference,
            'status' => $this->status,
        ];
    }
}
