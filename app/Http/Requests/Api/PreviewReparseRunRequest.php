<?php

namespace App\Http\Requests\Api;

use App\Models\ScrapeSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewReparseRunRequest extends FormRequest
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
            'source' => ['sometimes', 'integer', Rule::exists(ScrapeSource::class, 'id')],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
        ];
    }

    /**
     * The planner's scope arguments, in the order ParserReparsePlanner::preview() takes them.
     *
     * @return array{0: ?int, 1: ?string, 2: ?string}
     */
    public function scope(): array
    {
        $validated = $this->validated();

        return [
            isset($validated['source']) ? (int) $validated['source'] : null,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        ];
    }
}
