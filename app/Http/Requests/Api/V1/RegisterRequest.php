<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'lastName' => ['nullable', 'string', 'max:255'],
            'fullName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(12)],
            'department' => ['required', 'string', Rule::in(['Warehouse', 'Sales', 'Purchasing'])],
            'roleLevel' => ['sometimes', 'string', Rule::in(['Staff'])],
            'role_level' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $firstName = $this->input('name');
        $lastName = $this->input('lastName');
        $this->merge([
            'email' => strtolower($this->string('email')->trim()->toString()),
            'fullName' => is_string($firstName) && ($lastName === null || is_string($lastName))
                ? trim($firstName.' '.$lastName)
                : null,
        ]);
    }
}
