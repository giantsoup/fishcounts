<?php

namespace App\Http\Requests\Admin;

class StartParserReparseRunRequest extends PreviewParserReparseRunRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'fingerprint' => ['required', 'string', 'size:64']];
    }
}
