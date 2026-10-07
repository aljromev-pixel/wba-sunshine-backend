<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
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
            'timestamp' => $this->created_at?->toIso8601String(),
            'user' => $this->user?->name,
            'department' => $this->department,
            'roleLevel' => $this->role_level,
            'action' => $this->action,
            'module' => $this->module,
            'reference' => $this->reference,
            'description' => $this->description,
        ];
    }
}
