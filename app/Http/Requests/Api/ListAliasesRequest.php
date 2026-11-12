<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\PaginatesApiLists;
use Illuminate\Foundation\Http\FormRequest;

class ListAliasesRequest extends FormRequest
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
            ...$this->paginationRules(),
        ];
    }
}
