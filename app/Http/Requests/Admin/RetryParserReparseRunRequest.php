<?php

namespace App\Http\Requests\Admin;

use App\Models\ParserReparseRun;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RetryParserReparseRunRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $run = $this->route('parserReparseRun');

        return $run instanceof ParserReparseRun
            && ($this->user()?->can('update', $run) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}
