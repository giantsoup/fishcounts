<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('fish:create-admin {email} {--name=Fish Counts Admin} {--password=}')]
#[Description('Create or update an active admin user.')]
class CreateAdminUserCommand extends Command
{
    public function handle(): int
    {
        $password = $this->option('password') ?: str()->password(16);

        User::query()->updateOrCreate(
            ['email' => $this->argument('email')],
            [
                'name' => $this->option('name'),
                'password' => Hash::make($password),
                'role' => Role::Admin,
                'timezone' => 'America/Los_Angeles',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $this->info('Admin user is ready.');

        if (! $this->option('password')) {
            $this->warn("Generated password: {$password}");
        }

        return self::SUCCESS;
    }
}
