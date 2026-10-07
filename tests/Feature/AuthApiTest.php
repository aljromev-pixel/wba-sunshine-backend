<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_issues_a_token_and_returns_the_safe_user_payload(): void
    {
        $user = User::factory()->create([
            'email' => 'staff@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@example.com',
            'password' => 'correct-password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', $user->name)
            ->assertJsonPath('user.email', 'staff@example.com')
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_rejects_invalid_credentials_with_a_validation_error(): void
    {
        User::factory()->create([
            'email' => 'staff@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@example.com',
            'password' => 'incorrect-password',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email' => 'The provided credentials are incorrect.',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_authenticated_users_can_read_their_profile_and_log_out_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('frontend');

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.password');

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_protected_authentication_endpoints_reject_missing_tokens(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }
}
