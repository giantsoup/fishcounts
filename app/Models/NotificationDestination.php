<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class NotificationDestination extends Model
{
    protected $attributes = [
        'is_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'destination' => 'encrypted',
            'is_enabled' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
