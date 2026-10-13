<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryNotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'title' => $this->data['title'] ?? 'Inventory update',
            'message' => $this->data['message'] ?? '', 'severity' => $this->data['severity'] ?? 'Info',
            'link' => $this->data['link'] ?? '/alerts', 'date' => $this->created_at?->toIso8601String(),
            'readAt' => $this->read_at?->toIso8601String(),
        ];
    }
}
