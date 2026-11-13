<?php

namespace App\Models;

use App\Enums\Role;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'email_verified_at', 'password', 'role', 'timezone', 'is_active', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * API tokens only ever belong to active admins. Deleting the account, deactivating it, or
     * removing its admin role revokes every token, so a later reactivation or re-promotion does
     * not quietly bring an old token back to life.
     */
    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged(['is_active', 'role']) && (! $user->is_active || ! $user->isAdmin())) {
                $user->tokens()->delete();
            }
        });

        static::deleting(function (User $user): void {
            $user->tokens()->delete();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * The only kind of account an agent API token may belong to or be managed by.
     */
    public function isActiveAdmin(): bool
    {
        return $this->is_active && $this->isAdmin();
    }

    /** @return HasMany<AlertRule, $this> */
    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }

    /** @return HasMany<NotificationDestination, $this> */
    public function notificationDestinations(): HasMany
    {
        return $this->hasMany(NotificationDestination::class);
    }

    /** @return HasMany<ParserDiagnosticReviewAction, $this> */
    public function parserDiagnosticReviewActions(): HasMany
    {
        return $this->hasMany(ParserDiagnosticReviewAction::class, 'actor_user_id');
    }
}
