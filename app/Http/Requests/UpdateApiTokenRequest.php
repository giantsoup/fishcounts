<?php

namespace App\Http\Requests;

use App\Models\PersonalAccessToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Renames a token. Abilities, owner, and expiry are immutable; changing them means rotating.
 */
class UpdateApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->apiToken()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $token = $this->apiToken();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                StoreApiTokenRequest::uniqueNameFor((int) $token->tokenable_id)->ignore($token->getKey()),
            ],
        ];
    }

    public function apiToken(): PersonalAccessToken
    {
        return $this->route('apiToken');
    }
}
