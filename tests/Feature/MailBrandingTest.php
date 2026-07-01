<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\TestNotification;
use Tests\TestCase;

class MailBrandingTest extends TestCase
{
    public function test_markdown_notifications_use_fishcounts_branding(): void
    {
        $user = new User([
            'name' => 'Preview User',
            'email' => 'preview@example.test',
        ]);

        $html = (string) (new TestNotification)
            ->toMail($user)
            ->render();

        $this->assertStringContainsString('<title>FishCounts</title>', $html);
        $this->assertStringContainsString('/images/fishcounts-logo.png', $html);
        $this->assertStringContainsString('alt="FishCounts"', $html);
        $this->assertStringContainsString('FISH', $html);
        $this->assertStringContainsString('COUNTS', $html);
        $this->assertStringContainsString('TRACK TRENDS.', $html);
        $this->assertStringContainsString('CATCH MORE.', $html);
        $this->assertStringContainsString('GET NOTIFIED.', $html);
        $this->assertStringContainsString('background-color: #f6fbfe', $html);
        $this->assertStringContainsString('color: #102a43', $html);
        $this->assertStringContainsString('color: #00365f', $html);
        $this->assertStringContainsString('color: #0a5f95', $html);
        $this->assertStringContainsString('color: #b61e2e', $html);
        $this->assertStringContainsString("Regards,<br>\nFishCounts", $html);
        $this->assertStringNotContainsString('laravel.com/img/notification-logo', $html);
        $this->assertStringNotContainsString("Regards,<br>\nLaravel", $html);
    }
}
