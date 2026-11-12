<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query for listing boats, species, or trip types: an optional name-or-alias search and paging.
 */
class ListEntitiesRequest extends FormRequest
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
            'search' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListParserErrorsRequest::MAX_PER_PAGE],
        ];
    }
}
