<?php

namespace App\Http\Requests\Api;

use App\Enums\ParserDiagnosticType;
use App\Models\ParserError;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListParserErrorsRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 100;

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
            'type' => ['sometimes', Rule::in(self::errorTypes())],
            'source' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * @return list<string>
     */
    private static function errorTypes(): array
    {
        return array_values(array_unique([
            ...ParserError::ALIAS_ERROR_TYPES,
            ...array_column(ParserDiagnosticType::cases(), 'value'),
        ]));
    }
}
