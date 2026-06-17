<?php

namespace App\Http\Requests;

class UpdateAlertRuleRequest extends StoreAlertRuleRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('alert_rule')) ?? false;
    }
}
