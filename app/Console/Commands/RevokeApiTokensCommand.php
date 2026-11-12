<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:revoke-api-tokens
    {email : Email of the user whose tokens to revoke}
    {--name= : Revoke only the tokens with this name}
    {--all : Revoke every token the user has}
')]
#[Description('Revoke a user\'s agent API tokens, one by name or all of them.')]
class RevokeApiTokensCommand extends Command
{
    public function handle(): int
    {
        $name = $this->option('name');
        $all = (bool) $this->option('all');

        if ($all === filled($name)) {
            $this->components->error('Pass exactly one of --name=<token name> or --all.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No user has that email.');

            return self::FAILURE;
        }

        $revoked = $user->tokens()
            ->when(! $all, fn ($query) => $query->where('name', $name))
            ->delete();

        if ($revoked === 0) {
            $this->components->error('No matching tokens to revoke.');

            return self::FAILURE;
        }

        $this->components->info("Revoked {$revoked} token(s) for {$user->email}.");

        return self::SUCCESS;
    }
}
