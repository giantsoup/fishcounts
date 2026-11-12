<?php

namespace App\Http\Requests\Api;

class StartReparseRunRequest extends PreviewReparseRunRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'fingerprint' => ['required', 'string', 'size:64']];
    }
}
