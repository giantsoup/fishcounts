<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ListAliasesRequest extends FormRequest
{
    /**
     * Access is decided by the agent-api middleware group and the route's ability.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListParserErrorsRequest::MAX_PER_PAGE],
        ];
    }
}
