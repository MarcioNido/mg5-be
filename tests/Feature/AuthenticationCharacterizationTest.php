<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_issue_a_token_that_authenticates_subsequent_requests(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $token = $this->postJson('/api/auth/token', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com')
            ->json('token');

        $this->withToken($token)
            ->getJson('/api/auth/my-account')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_invalid_credentials_and_missing_tokens_are_rejected(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $this->postJson('/api/auth/token', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->getJson('/api/auth/my-account')->assertUnauthorized();
    }
}
