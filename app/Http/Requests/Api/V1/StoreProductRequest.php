<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
            'sku' => ['required', 'string', 'max:255', Rule::unique('products', 'sku')],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'stock' => ['required', 'integer', 'min:0', 'max:2147483647', ...($this->input('demandPattern') === 'Perishable-Rotating' ? ['max:0'] : [])],
            'reorderPoint' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'leadTime' => ['required', 'integer', 'min:0', 'max:365'],
            'unit' => ['required', 'string', 'max:100'],
            'monthlySales' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'demandPattern' => ['sometimes', 'required', Rule::in(Product::DEMAND_PATTERNS)],
            'monthlySalesHistory' => ['sometimes', 'array', 'max:60'],
            'monthlySalesHistory.*' => ['required', 'array:month,quantity'],
            'monthlySalesHistory.*.month' => ['required', 'date_format:Y-m', 'before:'.today()->startOfMonth()->toDateString(), 'distinct'],
            'monthlySalesHistory.*.quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'seasonalProfile' => ['sometimes', 'array', 'max:12'],
            'seasonalProfile.*' => ['required', 'array:month,multiplier'],
            'seasonalProfile.*.month' => ['required', 'integer', 'min:1', 'max:12', 'distinct'],
            'seasonalProfile.*.multiplier' => ['required', 'numeric', 'min:0', 'max:10'],
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
