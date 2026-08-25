<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

class BootstrapAdministratorCommand extends Command
{
    protected $signature = 'mg5:bootstrap-admin';

    protected $description = 'Interactively create or recover the local administrator';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Administrator bootstrap requires an interactive terminal with hidden input.');

            return self::FAILURE;
        }

        try {
            $name = trim((string) $this->ask('Administrator name'));
            $email = Str::lower(trim((string) $this->ask('Administrator email')));

            $identityValidator = Validator::make(compact('name', 'email'), [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email:rfc', 'max:255'],
            ]);

            if ($identityValidator->fails()) {
                $this->error($identityValidator->errors()->first());

                return self::FAILURE;
            }

            $tenants = Tenant::query()->whereIn('slug', ['personal', 'clinic'])->get()->keyBy('slug');
            if ($tenants->count() !== 2 || ! $tenants->has('personal') || ! $tenants->has('clinic')) {
                $this->error('Required Personal and Clinic tenants are missing. Run the tenant seeder first.');

                return self::FAILURE;
            }

            $updatingExisting = User::query()->where('email', $email)->exists();
            if ($updatingExisting && ! $this->confirm('A user with that email already exists. Update its name, password, and required memberships?', false)) {
                $this->error('Administrator bootstrap cancelled; the existing user was not changed.');

                return self::FAILURE;
            }

            $password = $this->secret('Password', false);
            $passwordConfirmation = $this->secret('Confirm password', false);

            if (! is_string($password) || ! is_string($passwordConfirmation)) {
                $this->error('Secure password input is unavailable.');

                return self::FAILURE;
            }

            $passwordValidator = Validator::make([
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ], [
                'password' => [
                    'required',
                    'string',
                    'confirmed',
                    Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
                ],
            ]);

            if ($passwordValidator->fails()) {
                $this->error($passwordValidator->errors()->first());

                return self::FAILURE;
            }

            DB::transaction(function () use ($email, $name, $password, $tenants, $updatingExisting): void {
                $user = User::query()->where('email', $email)->lockForUpdate()->first();

                if ($user !== null && ! $updatingExisting) {
                    throw new \RuntimeException('A user was created concurrently. Run the command again.');
                }

                if ($user === null) {
                    $user = new User;
                    $user->email = $email;
                }

                $user->name = $name;
                $user->password = Hash::make($password);
                $user->save();
                $user->tenants()->syncWithoutDetaching($tenants->pluck('id')->all());
            });

            $this->info($updatingExisting
                ? 'Administrator updated and attached to Personal and Clinic.'
                : 'Administrator created and attached to Personal and Clinic.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Administrator bootstrap failed; no administrator changes were saved.');

            return self::FAILURE;
        }
    }
}
