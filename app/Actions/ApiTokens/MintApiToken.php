<?php

namespace App\Actions\ApiTokens;

use App\Enums\ApiAbility;
use App\Models\PersonalAccessToken;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Mints an agent API token. Shared by fish:mint-api-token and the admin UI so the
 * active-admin and expiry rules live in one place. Name uniqueness is a UI rule and is
 * enforced by its form requests, not here.
 */
class MintApiToken
{
    /** Longest lifetime a token may have, matching the sanctum.expiration backstop. */
    public const MAX_DAYS = 365;

    /**
     * @param  list<string>  $abilities  ApiAbility values.
     *
     * @throws ValidationException when the owner is not an active admin, or the abilities or expiry are invalid.
     */
    public function handle(User $owner, string $name, array $abilities, DateTimeInterface $expiresAt, ?User $creator = null): NewAccessToken
    {
        if (! $owner->is_active || ! $owner->isAdmin()) {
            throw ValidationException::withMessages(['owner_id' => 'API tokens can only be minted for an existing, active admin user.']);
        }

        if ($abilities === [] || array_diff($abilities, array_column(ApiAbility::cases(), 'value')) !== []) {
            throw ValidationException::withMessages(['abilities' => 'Choose at least one valid ability.']);
        }

        if ($expiresAt <= now() || $expiresAt > now()->addDays(self::MAX_DAYS)->addMinute()) {
            throw ValidationException::withMessages(['expires_in_days' => 'Tokens must expire within '.self::MAX_DAYS.' days.']);
        }

        return DB::transaction(function () use ($owner, $name, $abilities, $expiresAt, $creator): NewAccessToken {
            $newToken = $owner->createToken($name, array_values(array_unique($abilities)), $expiresAt);

            /** @var PersonalAccessToken $accessToken */
            $accessToken = $newToken->accessToken;
            $accessToken->forceFill([
                'created_by_user_id' => $creator?->getKey(),
                'token_hint' => substr($newToken->plainTextToken, -4),
            ])->save();

            return $newToken;
        });
    }
}
