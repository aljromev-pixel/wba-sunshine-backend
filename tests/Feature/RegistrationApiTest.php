<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_unavailable_and_creates_nothing(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'New', 'lastName' => 'Member', 'email' => 'new@example.test',
            'password' => 'test-only-password', 'password_confirmation' => 'test-only-password',
            'department' => 'Warehouse', 'roleLevel' => 'Staff',
        ])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_authenticated_users_cannot_reopen_public_registration(): void
    {
        $user = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/register', [])->assertNotFound();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
