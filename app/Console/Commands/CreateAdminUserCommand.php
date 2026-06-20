<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

#[Signature('fish:create-admin
    {email? : Admin email address}
    {--name=Fish Counts Admin : Display name for the admin user}
    {--password= : Plain-text password. Omit to be prompted securely.}
    {--force : Allow creating an admin when one already exists}
')]
#[Description('Create the initial active admin user.')]
class CreateAdminUserCommand extends Command
{
    public function handle(): int
    {
        if ($this->adminExists() && ! $this->option('force')) {
            $this->components->error('An admin user already exists. Use --force only if you intentionally need another admin.');

            return self::FAILURE;
        }

        try {
            $name = $this->resolveName();
            $email = $this->resolveEmail();
            $password = $this->resolvePassword();
        } catch (ValidationException $exception) {
            $this->writeValidationErrors($exception);

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => Role::Admin,
            'timezone' => 'America/Los_Angeles',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->components->info("Admin user created for {$user->email}.");

        return self::SUCCESS;
    }

    private function adminExists(): bool
    {
        return User::query()
            ->where('role', Role::Admin)
            ->exists();
    }

    /**
     * @throws ValidationException
     */
    private function resolveName(): string
    {
        $name = trim((string) $this->option('name'));

        return $this->validatedValue('name', $name, $this->nameRules());
    }

    /**
     * @throws ValidationException
     */
    private function resolveEmail(): string
    {
        $email = $this->argument('email');

        if (is_string($email) && $email !== '') {
            return $this->validatedEmail($email);
        }

        if (! $this->input->isInteractive()) {
            throw ValidationException::withMessages([
                'email' => 'The email argument is required when running non-interactively.',
            ]);
        }

        return $this->promptForEmail();
    }

    /**
     * @throws ValidationException
     */
    private function resolvePassword(): string
    {
        $password = $this->option('password');

        if (is_string($password) && $password !== '') {
            return $this->validatedPassword($password);
        }

        if (! $this->input->isInteractive()) {
            throw ValidationException::withMessages([
                'password' => 'The password option is required when running non-interactively.',
            ]);
        }

        return $this->promptForPassword();
    }

    private function promptForEmail(): string
    {
        while (true) {
            $email = $this->ask('Admin email');

            try {
                return $this->validatedEmail((string) $email);
            } catch (ValidationException $exception) {
                $this->writeValidationErrors($exception);
            }
        }
    }

    private function promptForPassword(): string
    {
        while (true) {
            $password = $this->secret('Admin password');

            try {
                return $this->validatedPassword((string) $password);
            } catch (ValidationException $exception) {
                $this->writeValidationErrors($exception);
            }
        }
    }

    /**
     * @throws ValidationException
     */
    private function validatedEmail(string $email): string
    {
        return $this->validatedValue('email', Str::lower(trim($email)), $this->emailRules());
    }

    /**
     * @throws ValidationException
     */
    private function validatedPassword(string $password): string
    {
        return $this->validatedValue('password', $password, $this->passwordRules());
    }

    /**
     * @param  array<int, mixed>  $rules
     *
     * @throws ValidationException
     */
    private function validatedValue(string $field, string $value, array $rules): string
    {
        $validated = Validator::make(
            [$field => $value],
            [$field => $rules],
        )->validate();

        return (string) $validated[$field];
    }

    /**
     * @return array<int, mixed>
     */
    private function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * @return array<int, mixed>
     */
    private function emailRules(): array
    {
        return ['required', 'string', 'email:rfc', 'max:255', Rule::unique(User::class, 'email')];
    }

    /**
     * @return array<int, mixed>
     */
    private function passwordRules(): array
    {
        return ['required', Password::defaults()];
    }

    private function writeValidationErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                $this->components->error($message);
            }
        }
    }
}
