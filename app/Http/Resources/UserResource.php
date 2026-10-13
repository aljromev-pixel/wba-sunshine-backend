<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'authUserId' => $this->auth_user_id,
            'loginPortal' => $this->loginPortal(),
            'name' => $this->name,
            'firstName' => $this->first_name ?? $this->name,
            'lastName' => $this->last_name ?? '',
            'email' => $this->email,
            'department' => $this->department,
            'roleLevel' => $this->role_level,
            'isActive' => $this->is_active,
            'mustChangePassword' => $this->must_change_password,
        ];
    }
}
