<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Support\InventoryPermissions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryAdjustmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && InventoryPermissions::canRequestAdjustment($user);
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
            'requestedQty' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
