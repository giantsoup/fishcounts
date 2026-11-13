<?php

namespace App\Actions\ApiTokens;

use App\Models\PersonalAccessToken;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Replaces a token with a new one of the same name and owner. The old token is locked,
 * re-read, and deleted in the same transaction, so two concurrent rotations cannot both
 * succeed and the old secret stops working the moment the new one exists.
 */
class RotateApiToken
{
    public function __construct(private MintApiToken $mintApiToken) {}

    /**
     * @param  list<string>  $abilities
     * @return array{token: NewAccessToken, previous: array{id: int, abilities: list<string>, expires_at: string|null}}
     */
    public function handle(PersonalAccessToken $token, array $abilities, DateTimeInterface $expiresAt, User $actor): array
    {
        return DB::transaction(function () use ($token, $abilities, $expiresAt, $actor): array {
            $current = PersonalAccessToken::query()->lockForUpdate()->findOrFail($token->getKey());

            /** @var User $owner */
            $owner = $current->tokenable;

            $previous = [
                'id' => $current->getKey(),
                'abilities' => $current->abilities ?? [],
                'expires_at' => $current->expires_at?->toIso8601String(),
            ];

            $newToken = $this->mintApiToken->handle($owner, $current->name, $abilities, $expiresAt, $actor);

            $current->delete();

            return ['token' => $newToken, 'previous' => $previous];
        });
    }
}
