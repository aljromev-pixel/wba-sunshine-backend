<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
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
            'sku' => ['required', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($this->route('product'))],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'stock' => ['required', 'integer', 'min:0'],
            'reorderPoint' => ['required', 'integer', 'min:0'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'leadTime' => ['required', 'integer', 'min:0', 'max:365'],
            'unit' => ['required', 'string', 'max:100'],
            'monthlySales' => ['required', 'integer', 'min:0'],
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
