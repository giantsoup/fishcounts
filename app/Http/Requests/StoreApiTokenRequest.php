<?php

namespace App\Http\Requests;

use App\Enums\ApiAbility;
use App\Enums\Role;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

class StoreApiTokenRequest extends FormRequest
{
    /** Lifetimes the admin UI offers, in days. */
    public const EXPIRY_OPTIONS = [7, 30, 90, 180, 365];

    public const DEFAULT_EXPIRY_DAYS = 90;

    public function authorize(): bool
    {
        return $this->user()?->can('create', PersonalAccessToken::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', self::uniqueNameFor($this->integer('owner_id'))],
            'owner_id' => ['required', 'integer', self::activeAdmin()],
            ...self::abilityAndExpiryRules(),
        ];
    }

    /** @return array<string, mixed> */
    public static function abilityAndExpiryRules(): array
    {
        return [
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['distinct', Rule::enum(ApiAbility::class)],
            'expires_in_days' => ['required', 'integer', Rule::in(self::EXPIRY_OPTIONS)],
        ];
    }

    public static function uniqueNameFor(int $ownerId): Unique
    {
        return Rule::unique('personal_access_tokens', 'name')
            ->where('tokenable_type', (new User)->getMorphClass())
            ->where('tokenable_id', $ownerId);
    }

    public static function activeAdmin(): Exists
    {
        return Rule::exists('users', 'id')
            ->where('is_active', true)
            ->where('role', Role::Admin->value);
    }

    public function owner(): User
    {
        return User::query()->findOrFail($this->validated('owner_id'));
    }

    /** @return list<string> */
    public function abilities(): array
    {
        return array_values($this->validated('abilities'));
    }

    public function expiresAt(): Carbon
    {
        return now()->addDays((int) $this->validated('expires_in_days'));
    }
}
