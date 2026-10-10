<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_store_separate_names_and_edit_staff_without_exposing_or_changing_passwords(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $create = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            ...$this->profile(), 'password' => 'test-only-password',
        ])->assertCreated()->assertJsonPath('data.name', 'Alex Rivera')
            ->assertJsonPath('data.firstName', 'Alex')->assertJsonPath('data.lastName', 'Rivera')
            ->assertJsonMissingPath('data.password');
        $staff = User::query()->findOrFail($create->json('data.id'));
        $originalHash = $staff->password;
        $this->putJson("/api/v1/users/{$staff->id}", [...$this->profile(), 'lastName' => 'Santos'])
            ->assertOk()->assertJsonPath('data.name', 'Alex Santos');
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'name' => 'Alex Santos', 'first_name' => 'Alex', 'last_name' => 'Santos']);
        $this->assertSame($originalHash, $staff->fresh()->password);
        $this->getJson('/api/v1/users')->assertOk()->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.1.password');
    }

    public function test_an_admin_cannot_update_another_administrators_profile_credentials_or_role_with_403(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $other = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $original = $other->fresh()->getAttributes();
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$other->id}", [
            ...$this->profile(), 'password' => 'changed-password',
        ])->assertForbidden();
        $this->assertSame($original, $other->fresh()->getAttributes());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_an_admin_cannot_delete_another_administrators_account_with_403(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $other = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $other->createToken('other-session');
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$other->id}")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $other->id]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_resetting_a_staff_password_hashes_it_and_revokes_existing_sessions(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $staff = User::factory()->create(['email' => 'alex@example.test']);
        $token = $staff->createToken('old-session');
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$staff->id}", [
            ...$this->profile(), 'password' => 'changed-password',
        ])->assertOk()->assertJsonMissingPath('data.password');
        $this->assertTrue(Hash::check('changed-password', $staff->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_admin_can_change_their_own_password_and_keep_only_the_current_session(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $current = $admin->createToken('current-session');
        $other = $admin->createToken('other-session');
        $this->withToken($current->plainTextToken)->putJson("/api/v1/users/{$admin->id}", [
            ...$this->profile(), 'email' => $admin->email, 'department' => 'Administration', 'roleLevel' => 'Manager', 'password' => 'changed-password',
        ])->assertOk();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->assertTrue(Hash::check('changed-password', $admin->fresh()->password));
    }

    public function test_names_that_exceed_the_combined_storage_limit_return_422(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            ...$this->profile(), 'name' => str_repeat('a', 200), 'lastName' => str_repeat('b', 100), 'password' => 'test-only-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('fullName');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_malformed_names_return_validation_errors_instead_of_server_errors(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            ...$this->profile(), 'lastName' => ['invalid'], 'password' => 'test-only-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('lastName');
    }

    public function test_admin_cannot_create_an_account_without_a_last_name(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            ...$this->profile(), 'lastName' => '', 'password' => 'test-only-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('lastName');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_existing_full_names_remain_readable_without_guessing_a_surname(): void
    {
        $user = User::factory()->create(['name' => 'Mary Ann De la Cruz']);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('user.name', 'Mary Ann De la Cruz')
            ->assertJsonPath('user.firstName', 'Mary Ann De la Cruz')->assertJsonPath('user.lastName', '');
    }

    /** @return array<string, string> */
    private function profile(): array
    {
        return ['name' => 'Alex', 'lastName' => 'Rivera', 'email' => 'alex@example.test', 'department' => 'Sales', 'roleLevel' => 'Staff'];
    }
}
