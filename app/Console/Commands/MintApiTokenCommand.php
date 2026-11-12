<?php

namespace App\Console\Commands;

use App\Enums\ApiAbility;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

#[Signature('fish:mint-api-token
    {email : Email of the active admin account the token acts as}
    {--name=agent : Token name, used to revoke it later}
    {--ability=* : Ability to grant (read, aliases, entities, reparse, errors). Repeat for several.}
    {--days=90 : Days until the token expires}
')]
#[Description('Mint an agent API token for an admin user and print it once.')]
class MintApiTokenCommand extends Command
{
    public function handle(): int
    {
        $validator = Validator::make([
            'name' => $this->option('name'),
            'abilities' => $this->option('ability'),
            'days' => $this->option('days'),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['distinct', Rule::enum(ApiAbility::class)],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null || ! $user->is_active || ! $user->isAdmin()) {
            $this->components->error('API tokens can only be minted for an existing, active admin user.');

            return self::FAILURE;
        }

        $options = $validator->validated();
        $expiresAt = now()->addDays((int) $options['days']);
        $token = $user->createToken($options['name'], $options['abilities'], $expiresAt);

        $this->components->info(sprintf(
            'Token "%s" minted for %s with [%s], expiring %s. It is shown only once:',
            $options['name'],
            $user->email,
            implode(', ', $options['abilities']),
            $expiresAt->toDateTimeString(),
        ));
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
