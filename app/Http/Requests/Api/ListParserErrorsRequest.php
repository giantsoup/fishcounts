<?php

namespace App\Http\Requests\Api;

use App\Enums\ParserDiagnosticType;
use App\Http\Requests\Api\Concerns\PaginatesApiLists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListParserErrorsRequest extends FormRequest
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
            'status' => ['sometimes', Rule::in(['open', 'resolved', 'all'])],
            'kind' => ['sometimes', Rule::in(['alias', 'structural'])],
            'type' => ['sometimes', Rule::in(ParserDiagnosticType::storedErrorTypes())],
            'source' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            ...$this->paginationRules(),
        ];
    }
}
