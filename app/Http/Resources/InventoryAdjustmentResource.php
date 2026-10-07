<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryAdjustmentResource extends JsonResource
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
            'productId' => $this->product_id,
            'systemQty' => $this->system_qty,
            'requestedQty' => $this->requested_qty,
            'reason' => $this->reason,
            'requestedById' => $this->requested_by,
            'requestedBy' => $this->requestedBy?->name,
            'reviewedBy' => $this->reviewedBy?->name,
            'status' => $this->status,
            'date' => $this->created_at?->toDateString(),
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
