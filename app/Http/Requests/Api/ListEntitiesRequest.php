<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\PaginatesApiLists;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query for listing boats, species, or trip types: an optional name-or-alias search and paging.
 */
class ListEntitiesRequest extends FormRequest
{
    use PaginatesApiLists;

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
            ...$this->paginationRules(),
        ];
    }
}
