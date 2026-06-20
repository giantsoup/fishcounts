<?php

namespace App\Http\Requests;

use App\Models\BackfillRun;
use App\Support\DateInputNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBackfillRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', BackfillRun::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'from_date' => DateInputNormalizer::toIsoDate($this->input('from_date')),
            'to_date' => DateInputNormalizer::toIsoDate($this->input('to_date')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2026-01-01', 'before_or_equal:to_date'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date', 'before_or_equal:today'],
            'source_ids' => ['required', 'array', 'min:1'],
            'source_ids.*' => ['integer', Rule::exists('scrape_sources', 'id')->where('is_enabled', true)],
            'batch_size_days' => ['required', 'integer', 'min:1', 'max:31'],
        ];
    }
}
