<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class BootstrapAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Strong!Pilot123';

    public function test_database_seeding_creates_only_the_two_complete_category_baselines(): void
    {
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(['clinic', 'personal'], Tenant::query()->orderBy('slug')->pluck('slug')->all());

        $personal = Tenant::query()->where('slug', 'personal')->sole();
        $clinic = Tenant::query()->where('slug', 'clinic')->sole();

        $this->assertSame(39, $personal->execute(fn (): int => Category::query()->count()));
        $this->assertSame(53, $clinic->execute(fn (): int => Category::query()->count()));
    }

    public function test_it_creates_a_hashed_administrator_with_both_memberships(): void
    {
        $this->bootstrap()
            ->expectsOutput('Administrator created and attached to Personal and Clinic.')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertExitCode(Command::SUCCESS);

        $user = User::query()->sole();

        $this->assertSame('administrator@example.test', $user->email);
        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertEqualsCanonicalizing(
            ['clinic', 'personal'],
            $user->tenants()->pluck('slug')->all()
        );
        $this->assertSame(2, $user->tenants()->count());
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->artisan('mg5:bootstrap-admin')
            ->expectsQuestion('Administrator name', 'Pilot Administrator')
            ->expectsQuestion('Administrator email', 'not-an-email')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_rejects_a_weak_password(): void
    {
        $this->bootstrap(password: 'weak-password', confirmation: 'weak-password')
            ->doesntExpectOutputToContain('weak-password')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_rejects_mismatched_password_confirmation(): void
    {
        $this->bootstrap(confirmation: 'Different!Pilot123')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_to_run_when_a_required_tenant_is_missing(): void
    {
        Tenant::query()->where('slug', 'clinic')->update(['slug' => 'clinic-missing']);

        $this->artisan('mg5:bootstrap-admin')
            ->expectsQuestion('Administrator name', 'Pilot Administrator')
            ->expectsQuestion('Administrator email', 'administrator@example.test')
            ->expectsOutput('Required Personal and Clinic tenants are missing. Run the tenant seeder first.')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_non_interactive_execution(): void
    {
        $this->artisan('mg5:bootstrap-admin', ['--no-interaction' => true])
            ->expectsOutput('Administrator bootstrap requires an interactive terminal with hidden input.')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_user_is_unchanged_when_update_is_not_confirmed(): void
    {
        $user = User::factory()->create([
            'name' => 'Existing User',
            'email' => 'administrator@example.test',
            'password' => Hash::make('Original!Pilot123'),
        ]);

        $this->artisan('mg5:bootstrap-admin')
            ->expectsQuestion('Administrator name', 'Changed Name')
            ->expectsQuestion('Administrator email', 'ADMINISTRATOR@EXAMPLE.TEST')
            ->expectsConfirmation('A user with that email already exists. Update its name, password, and required memberships?', 'no')
            ->assertExitCode(Command::FAILURE);

        $user->refresh();
        $this->assertSame('Existing User', $user->name);
        $this->assertTrue(Hash::check('Original!Pilot123', $user->password));
    }

    public function test_confirmed_existing_user_is_updated_without_duplicate_memberships(): void
    {
        $user = User::factory()->create([
            'name' => 'Existing User',
            'email' => 'administrator@example.test',
            'password' => Hash::make('Original!Pilot123'),
        ]);
        $user->tenants()->syncWithoutDetaching(Tenant::query()->pluck('id'));

        $this->bootstrap(existing: true)
            ->expectsOutput('Administrator updated and attached to Personal and Clinic.')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertExitCode(Command::SUCCESS);

        $user->refresh();
        $this->assertSame('Pilot Administrator', $user->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertSame(2, $user->tenants()->count());
        $this->assertDatabaseCount('tenant_user', 2);
    }

    private function bootstrap(
        string $password = self::PASSWORD,
        string $confirmation = self::PASSWORD,
        bool $existing = false,
    ): PendingCommand {
        $command = $this->artisan('mg5:bootstrap-admin')
            ->expectsQuestion('Administrator name', ' Pilot Administrator ')
            ->expectsQuestion('Administrator email', ' ADMINISTRATOR@EXAMPLE.TEST ');

        if ($existing) {
            $command->expectsConfirmation(
                'A user with that email already exists. Update its name, password, and required memberships?',
                'yes'
            );
        }

        return $command
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $confirmation);
    }
}
