<?php

namespace App\Http\Controllers;

use App\Enums\NotificationChannel;
use App\Http\Requests\UpdateNotificationSettingsRequest;
use App\Models\NotificationDestination;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class NotificationSettingsController extends Controller
{
    public function edit(): View
    {
        $this->authorize('viewAny', NotificationDestination::class);

        return view('notification-settings.edit', [
            'destinations' => auth()->user()->notificationDestinations()->get(),
        ]);
    }

    public function update(UpdateNotificationSettingsRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($request->boolean('email_enabled')) {
            $user->notificationDestinations()->updateOrCreate(
                ['channel' => NotificationChannel::Email, 'name' => 'Primary email'],
                ['destination' => $user->email, 'is_enabled' => true, 'verified_at' => $user->email_verified_at],
            );
        }

        if ($request->filled('discord_webhook_url')) {
            $user->notificationDestinations()->updateOrCreate(
                ['channel' => NotificationChannel::Discord, 'name' => 'Discord webhook'],
                ['destination' => $request->string('discord_webhook_url')->toString(), 'is_enabled' => $request->boolean('discord_enabled')],
            );
        }

        return redirect()->route('notification-settings.edit')->with('status', 'Notification settings updated.');
    }
}
