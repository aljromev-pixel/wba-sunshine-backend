<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBatchRequest extends FormRequest
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
            'productId' => ['required', 'integer', Rule::exists('products', 'id')],
            'number' => ['required', 'string', 'max:255', Rule::unique('batches', 'number')],
            'location' => ['sometimes', 'required', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'receivedAt' => ['required', 'date'],
            'expiresAt' => ['nullable', 'date', 'after_or_equal:receivedAt', ...($this->integer('quantity') > 0 ? ['after_or_equal:'.today()->toDateString()] : [])],
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
