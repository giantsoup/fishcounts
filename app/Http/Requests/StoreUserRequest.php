<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)],
            'role' => ['required', Rule::enum(Role::class)],
            'timezone' => ['required', 'timezone'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{name: string, email: string, role: Role, timezone: string, is_active: bool} */
    public function userAttributes(): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $this->enum('role', Role::class),
            'timezone' => $validated['timezone'],
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
