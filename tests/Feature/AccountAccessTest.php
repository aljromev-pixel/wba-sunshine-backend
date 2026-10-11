<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AccountAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_setup_requires_authentication_and_limits_failed_attempts(): void
    {
        $this->postJson('/api/v1/auth/password', $this->passwordDetails())->assertUnauthorized();
        $user = User::factory()->create(['password' => 'temporary-test-password', 'must_change_password' => true]);
        $token = $user->createToken('test')->plainTextToken;
        $this->freezeTime();
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->withToken($token)->postJson('/api/v1/auth/password', [...$this->passwordDetails(), 'current_password' => 'wrong-password'])
                ->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/password', $this->passwordDetails())->assertTooManyRequests();
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_restricted_account_can_sign_out_without_finishing_password_setup(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $token = $user->createToken('test')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_admin_created_account_must_set_a_private_password_before_workspace_access(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $created = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', $this->profile())
            ->assertCreated()->assertJsonPath('data.isActive', true)->assertJsonPath('data.mustChangePassword', true);
        $user = User::query()->findOrFail($created->json('data.id'));
        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'temporary-test-password'])
            ->assertOk()->assertJsonPath('user.mustChangePassword', true);
        $oldToken = $login->json('token');
        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson('/api/v1/inventory')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->postJson('/api/v1/inventory/movements', [])->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
        $changed = $this->postJson('/api/v1/auth/password', $this->passwordDetails())
            ->assertOk()->assertJsonPath('user.mustChangePassword', false)->assertJsonMissingPath('user.password');
        $this->assertTrue(Hash::check('private-test-password', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($changed->json('token'))->getJson('/api/v1/inventory')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'Password changed', 'reference' => (string) $user->id]);
        $logs = AuditLog::query()->get()->toJson();
        foreach (['temporary-test-password', 'private-test-password', $oldToken, $changed->json('token'), $user->fresh()->password] as $secret) {
            $this->assertStringNotContainsString($secret, $logs);
        }
    }

    /** @param array<string, string> $changes */
    #[DataProvider('invalidPasswords')]
    public function test_invalid_password_changes_preserve_the_password_and_restricted_access(array $changes, string $field): void
    {
        $user = User::factory()->create(['password' => 'temporary-test-password', 'must_change_password' => true]);
        $token = $user->createToken('test')->plainTextToken;
        $originalHash = $user->password;
        $this->withToken($token)->postJson('/api/v1/auth/password', [...$this->passwordDetails(), ...$changes])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidPasswords(): array
    {
        return [
            'incorrect temporary password' => [['current_password' => 'wrong-password'], 'current_password'],
            'missing current password' => [['current_password' => ''], 'current_password'],
            'short new password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'same password' => [['password' => 'temporary-test-password', 'password_confirmation' => 'temporary-test-password'], 'password'],
            'mismatch' => [['password_confirmation' => 'different-private-password'], 'password'],
            'missing confirmation' => [['password_confirmation' => ''], 'password_confirmation'],
        ];
    }

    public function test_disabling_access_revokes_sessions_and_reenabling_requires_a_new_login(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $user = User::factory()->create(['name' => 'Access Member', 'first_name' => 'Access', 'last_name' => 'Member', 'email' => 'access@example.test', 'department' => 'Warehouse', 'role_level' => 'Staff', 'password' => 'temporary-test-password']);
        $token = $user->createToken('test')->plainTextToken;
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/users/'.$user->id, [...$this->profile(), 'password' => '', 'isActive' => false])
            ->assertOk()->assertJsonPath('data.isActive', false);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'User disabled', 'reference' => (string) $user->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/inventory')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'temporary-test-password'])->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/users/'.$user->id, [...$this->profile(), 'password' => '', 'isActive' => true])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'User enabled', 'reference' => (string) $user->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'temporary-test-password'])->assertOk();
    }

    public function test_disabled_accounts_cannot_use_a_preexisting_token_on_any_authenticated_route(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $token = $user->createToken('test')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/inventory')->assertUnauthorized();
        $this->postJson('/api/v1/auth/password', $this->passwordDetails())->assertUnauthorized();
    }

    public function test_admin_cannot_disable_self_or_change_another_admin_access(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $other = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $profile = [...$this->profile(), 'email' => $admin->email, 'department' => 'Administration', 'roleLevel' => 'Manager', 'password' => '', 'isActive' => false];
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/users/'.$admin->id, $profile)->assertUnprocessable()->assertJsonValidationErrors('isActive');
        $this->putJson('/api/v1/users/'.$other->id, [...$profile, 'email' => $other->email])->assertForbidden();
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_admin_password_reset_requires_employee_setup_again_and_revokes_old_tokens(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $user = User::factory()->create(['email' => 'access@example.test']);
        $user->createToken('old');
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/users/'.$user->id, $this->profile())
            ->assertOk()->assertJsonPath('data.mustChangePassword', true);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_password_change_rolls_back_when_audit_storage_fails(): void
    {
        $user = User::factory()->create(['password' => 'temporary-test-password', 'must_change_password' => true]);
        $token = $user->createToken('test')->plainTextToken;
        $originalHash = $user->password;
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(function (): void {
            throw new RuntimeException('Audit storage unavailable.');
        });
        try {
            $this->withToken($token)->postJson('/api/v1/auth/password', $this->passwordDetails())->assertInternalServerError();
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /** @return array<string, string> */
    private function profile(): array
    {
        return ['name' => 'Access', 'lastName' => 'Member', 'email' => 'access@example.test', 'department' => 'Warehouse', 'roleLevel' => 'Staff', 'password' => 'temporary-test-password'];
    }

    /** @return array<string, string> */
    private function passwordDetails(): array
    {
        return ['current_password' => 'temporary-test-password', 'password' => 'private-test-password', 'password_confirmation' => 'private-test-password'];
    }
}
