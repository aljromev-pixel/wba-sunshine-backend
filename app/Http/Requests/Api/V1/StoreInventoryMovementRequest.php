<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Support\InventoryPermissions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryMovementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && InventoryPermissions::canCreateMovement($user, $this->string('type')->toString());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['Stock In', 'Stock Out', 'Transfer', 'Return'])],
            'productId' => ['required', 'integer', Rule::exists('products', 'id')],
            'batchId' => ['nullable', 'integer', Rule::exists('batches', 'id')],
            'batchNumber' => ['nullable', 'string', 'max:255'],
            'expiresAt' => ['nullable', 'date'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:255'],
            'location' => ['sometimes', 'required', 'string', 'max:100'],
            'destinationLocation' => ['required_if:type,Transfer', 'nullable', 'string', 'max:100'],
            'returnCondition' => ['required_if:type,Return', 'nullable', Rule::in(['Resaleable'])],
            'requestId' => ['sometimes', 'required', 'uuid'],
        ];
    }
}
