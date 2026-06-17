<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\Factory as HttpFactory;

class DiscordWebhookSender
{
    public function __construct(private readonly HttpFactory $http) {}

    /** @param array<string, mixed> $payload */
    public function send(string $webhookUrl, array $payload): void
    {
        $this->http
            ->timeout(10)
            ->connectTimeout(5)
            ->retry([250, 1000], throw: false)
            ->post($webhookUrl, $payload)
            ->throw();
    }
}
