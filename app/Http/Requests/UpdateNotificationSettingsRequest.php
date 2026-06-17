<?php

namespace App\Http\Requests;

use App\Models\NotificationDestination;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', NotificationDestination::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email_enabled' => ['sometimes', 'boolean'],
            'discord_enabled' => ['sometimes', 'boolean'],
            'discord_webhook_url' => ['nullable', 'url', 'starts_with:https://discord.com/api/webhooks/,https://discordapp.com/api/webhooks/'],
        ];
    }
}
