<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BatchResource extends JsonResource
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
            'number' => $this->number,
            'quantity' => $this->quantity,
            'receivedAt' => $this->received_at?->toDateString(),
            'expiresAt' => $this->expires_at?->toDateString(),
        ];
    }
}
