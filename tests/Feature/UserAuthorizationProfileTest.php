<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAuthorizationProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_responses_include_the_frontend_authorization_profile(): void
    {
        $user = User::factory()->create([
            'department' => 'Administration',
            'role_level' => 'Manager',
        ]);
        $token = $user->createToken('frontend');

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.department', 'Administration')
            ->assertJsonPath('user.roleLevel', 'Manager');
    }
}
