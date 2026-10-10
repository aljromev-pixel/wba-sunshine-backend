<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->canManageUsers();
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
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'password' => ['nullable', 'string', Password::min(8)],
            'department' => ['required', 'string', Rule::in(['Warehouse', 'Sales', 'Purchasing', 'Administration'])],
            'roleLevel' => ['required', 'string', Rule::in(User::ROLE_LEVELS_BY_DEPARTMENT[$this->string('department')->toString()] ?? [])],
        ];
    }

    private function canManageUsers(): bool
    {
        return $this->user()?->department === 'Administration' && $this->user()?->role_level === 'Manager';
    }
}
