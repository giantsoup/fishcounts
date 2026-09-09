<?php

namespace App\Http\Requests\Admin;

use App\Models\ParserReparseRun;
use App\Models\ScrapeSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewParserReparseRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ParserReparseRun::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_id' => ['nullable', 'integer', Rule::exists(ScrapeSource::class, 'id')],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
        ];
    }
}
