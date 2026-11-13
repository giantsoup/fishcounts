<?php

namespace App\Http\Requests;

use App\Models\PersonalAccessToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Replaces a token with a new secret, optionally with new abilities and expiry. The name
 * and owner carry over, so name uniqueness is not checked again.
 */
class RotateApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('rotate', $this->route('apiToken')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return StoreApiTokenRequest::abilityAndExpiryRules();
    }

    public function apiToken(): PersonalAccessToken
    {
        return $this->route('apiToken');
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
