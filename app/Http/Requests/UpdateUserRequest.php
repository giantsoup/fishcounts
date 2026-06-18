<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)->ignore($this->route('user'))],
            'role' => ['required', Rule::enum(Role::class)],
            'timezone' => ['required', 'timezone'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $target = $this->route('user');

                if (! $target instanceof User || ! $this->user()?->is($target)) {
                    return;
                }

                if ($this->enum('role', Role::class) !== Role::Admin) {
                    $validator->errors()->add('role', 'You cannot remove your own admin role.');
                }

                if (! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', 'You cannot deactivate your own account.');
                }
            },
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
            'is_active' => $this->boolean('is_active'),
        ];
    }
}
