<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\PaginatesApiLists;
use Illuminate\Foundation\Http\FormRequest;

class ListTripReportsRequest extends FormRequest
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
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'source' => ['sometimes', 'integer', 'min:1'],
            'boat' => ['sometimes', 'integer', 'min:1'],
            ...$this->paginationRules(),
        ];
    }
}
