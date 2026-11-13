<?php

namespace App\Models;

use Database\Factories\PersonalAccessTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Agent API token. Only the SHA-256 hash of the secret is stored; the plaintext is shown
 * once when it is minted. `created_by_user_id` and `token_hint` are written by
 * App\Actions\ApiTokens\MintApiToken through forceFill and are deliberately not fillable.
 *
 * @property int|null $created_by_user_id
 * @property string|null $token_hint
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /** @use HasFactory<PersonalAccessTokenFactory> */
    use HasFactory;

    /** Number of mask characters shown, fixed so the mask never reveals the real token length. */
    public const MASK_LENGTH = 12;

    public const MASK_CHARACTER = '•';

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The token as the UI may show it after the one-time reveal: the configured prefix, a
     * fixed-width mask, and the stored last-four hint. Never derived from the secret.
     */
    public function maskedToken(): string
    {
        return $this->maskedPrefix().($this->token_hint ?? '');
    }

    /**
     * The prefix and mask without the hint, for rendering the hint separately.
     */
    public function maskedPrefix(): string
    {
        return config('sanctum.token_prefix', '').str_repeat(self::MASK_CHARACTER, self::MASK_LENGTH);
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->effectiveExpiresAt();

        return $expiresAt !== null && $expiresAt->isPast();
    }

    /**
     * The earlier of the token's own expiry and Sanctum's global expiration backstop.
     */
    public function effectiveExpiresAt(): ?Carbon
    {
        $backstopMinutes = config('sanctum.expiration');
        $backstop = $backstopMinutes ? $this->created_at?->copy()->addMinutes((int) $backstopMinutes) : null;

        return collect([$this->expires_at, $backstop])->filter()->sort()->first();
    }

    public function isOwnedByUser(): bool
    {
        return $this->tokenable_type === (new User)->getMorphClass();
    }
}
