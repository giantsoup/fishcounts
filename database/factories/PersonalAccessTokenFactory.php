<?php

namespace Database\Factories;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Builds token rows directly, for tests that need a shape MintApiToken never produces
 * (no hint, a non-User owner, an expired row). Its secret is unknown, so these tokens
 * cannot authenticate; mint through App\Actions\ApiTokens\MintApiToken for that.
 *
 * @extends Factory<PersonalAccessToken>
 */
class PersonalAccessTokenFactory extends Factory
{
    /**
     * Define the model's default state: a read-only token for a new admin, expiring in 90 days.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tokenable_type' => (new User)->getMorphClass(),
            'tokenable_id' => User::factory()->admin(),
            'name' => fake()->unique()->slug(2),
            'token' => hash('sha256', Str::random(48)),
            'abilities' => ['read'],
            'expires_at' => now()->addDays(90),
        ];
    }

    public function forTokenable(Model $tokenable): static
    {
        return $this->state(fn (array $attributes): array => [
            'tokenable_type' => $tokenable->getMorphClass(),
            'tokenable_id' => $tokenable->getKey(),
        ]);
    }

    public function createdBy(User $creator): static
    {
        return $this->state(fn (array $attributes): array => ['created_by_user_id' => $creator->getKey()]);
    }

    public function withHint(string $hint): static
    {
        return $this->state(fn (array $attributes): array => ['token_hint' => $hint]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => ['expires_at' => now()->subDay()]);
    }
}
