<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBatchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->canManageInventory();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:255', Rule::unique('batches', 'number')->ignore($this->route('batch'))],
            'receivedAt' => ['required', 'date'],
            'expiresAt' => ['nullable', 'date', 'after_or_equal:receivedAt'],
        ];
    }

    private function canManageInventory(): bool
    {
        return in_array("{$this->user()?->department}:{$this->user()?->role_level}", [
            'Warehouse:Supervisor',
            'Warehouse:Manager',
            'Administration:Manager',
        ], true);
    }
}
