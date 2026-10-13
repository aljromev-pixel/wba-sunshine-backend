<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SupabaseAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupabaseAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.credential_provider' => 'supabase', 'services.supabase.url' => 'https://auth.example.test', 'services.supabase.publishable_key' => 'publishable-test-only', 'services.supabase.service_role_key' => 'service-test-only']);
        Http::preventStrayRequests();
    }

    #[TestWith(['Administration', 'Manager', 'admin'])]
    #[TestWith(['Warehouse', 'Staff', 'staff'])]
    #[TestWith(['Purchasing', 'Manager', 'staff'])]
    public function test_supabase_sessions_are_identity_verified_and_bound_to_one_portal(string $department, string $role, string $portal): void
    {
        $user = User::factory()->create(['auth_user_id' => (string) Str::uuid(), 'department' => $department, 'role_level' => $role, 'password' => 'local-copy-must-not-authenticate']);
        $token = 'supabase-test-session-'.Str::uuid();
        Http::fake([
            '*/token?grant_type=password' => Http::response(['access_token' => $token, 'refresh_token' => 'private-refresh-test-only', 'expires_in' => 3600, 'user' => ['id' => $user->auth_user_id]], 200),
            '*/logout?scope=local' => Http::response(null, 204),
            '*/user' => Http::response(['id' => $user->auth_user_id, 'user_metadata' => ['role' => 'Admin']], 200),
        ]);
        $credentials = ['email' => $user->email, 'password' => 'supabase-password'];
        $correct = $portal === 'admin' ? '/api/v1/auth/admin/login' : '/api/v1/auth/login';
        $wrong = $portal === 'admin' ? '/api/v1/auth/login' : '/api/v1/auth/admin/login';
        $this->postJson($wrong, $credentials)->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $login = $this->postJson($correct, $credentials)->assertOk()->assertJsonPath('token', $token)->assertJsonMissingPath('refresh_token')->assertJsonMissingPath('user.password');
        $this->withToken($login->json('token'))->getJson('/api/v1/auth/'.$portal.'/me')->assertOk()->assertJsonPath('user.authUserId', $user->auth_user_id);
        $this->getJson('/api/v1/'.($portal === 'admin' ? 'staff' : 'admin').'/overview')->assertForbidden();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_supabase_failure_never_falls_back_to_a_matching_local_password(): void
    {
        $user = User::factory()->create(['auth_user_id' => (string) Str::uuid(), 'password' => 'local-test-password']);
        Http::fake(['*/token?grant_type=password' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'local-test-password'])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_provider_outage_is_retryable_without_logging_out_an_existing_session(): void
    {
        $user = User::factory()->create(['auth_user_id' => (string) Str::uuid()]);
        $token = app(SupabaseAuth::class)->issueSession($user, ['access_token' => 'provider-outage-token', 'expires_in' => 3600]);
        Http::fake(['*/user' => Http::failedConnection()]);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertServiceUnavailable();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_a_direct_supabase_token_without_an_approved_portal_session_cannot_access_the_api(): void
    {
        Http::fake();
        $this->withToken('unregistered-provider-jwt')->getJson('/api/v1/inventory')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_identity_mismatch_revokes_the_registered_session(): void
    {
        $user = User::factory()->create(['auth_user_id' => (string) Str::uuid()]);
        $token = app(SupabaseAuth::class)->issueSession($user, ['access_token' => 'identity-mismatch-token', 'expires_in' => 3600]);
        Http::fake(['*/user' => Http::response(['id' => (string) Str::uuid()], 200)]);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[TestWith(['banned_until'])]
    #[TestWith(['deleted_at'])]
    public function test_provider_disabled_identity_cannot_keep_an_existing_application_session(string $field): void
    {
        $user = User::factory()->create(['auth_user_id' => (string) Str::uuid()]);
        $token = app(SupabaseAuth::class)->issueSession($user, ['access_token' => 'disabled-identity-token', 'expires_in' => 3600]);
        Http::fake(['*/user' => Http::response(['id' => $user->auth_user_id, $field => now()->addDay()->toISOString()])]);
        $this->withToken($token)->getJson('/api/v1/inventory')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unassigned_supabase_identity_is_rejected_without_auto_creating_a_profile(): void
    {
        Http::fake([
            '*/token?grant_type=password' => Http::response(['access_token' => 'unassigned-token', 'expires_in' => 3600, 'user' => ['id' => (string) Str::uuid(), 'user_metadata' => ['department' => 'Administration', 'role' => 'Admin']]], 200),
            '*/logout?scope=local' => Http::response(null, 204),
        ]);
        $this->postJson('/api/v1/auth/admin/login', ['email' => 'unassigned@example.test', 'password' => 'private-test-password'])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expired_provider_tokens_cannot_reach_protected_data(): void
    {
        $user = User::factory()->create(['auth_user_id' => (string) Str::uuid()]);
        $token = app(SupabaseAuth::class)->issueSession($user, ['access_token' => 'expired-provider-token', 'expires_in' => 1]);
        $this->travel(2)->seconds();
        Http::fake();
        $this->withToken($token)->getJson('/api/v1/inventory')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_administrator_creates_a_linked_staff_account_without_a_local_password_copy(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager', 'auth_user_id' => (string) Str::uuid()]);
        $token = app(SupabaseAuth::class)->issueSession($admin, ['access_token' => 'admin-create-token', 'expires_in' => 3600]);
        Http::fake(function ($request) use ($admin) {
            if ($request->method() === 'GET') {
                return Http::response(['id' => $admin->auth_user_id]);
            }

            return Http::response(['id' => $request['id'], 'email' => $request['email']]);
        });
        $response = $this->withToken($token)->postJson('/api/v1/users', [
            'name' => 'Mika', 'lastName' => 'Santos', 'email' => 'mika.santos@example.test',
            'password' => 'private-test-password', 'department' => 'Warehouse', 'roleLevel' => 'Staff',
        ])->assertCreated()->assertJsonPath('data.loginPortal', 'staff')->assertJsonPath('data.mustChangePassword', true);
        $staff = User::findOrFail($response->json('data.id'));
        $this->assertNotNull($staff->auth_user_id);
        $this->assertNull($staff->password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'User created', 'reference' => (string) $staff->id]);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request['password'] === 'private-test-password' && ! isset($request['password_hash']));
    }

    public function test_failed_provider_account_creation_rolls_back_the_profile_and_audit(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager', 'auth_user_id' => (string) Str::uuid()]);
        $token = app(SupabaseAuth::class)->issueSession($admin, ['access_token' => 'admin-create-failure', 'expires_in' => 3600]);
        Http::fake(['*/user' => Http::response(['id' => $admin->auth_user_id]), '*/admin/users' => Http::response([], 500)]);
        $this->withToken($token)->postJson('/api/v1/users', [
            'name' => 'Mika', 'lastName' => 'Santos', 'email' => 'mika.santos@example.test',
            'password' => 'private-test-password', 'department' => 'Warehouse', 'roleLevel' => 'Staff',
        ])->assertServiceUnavailable();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_password_change_is_saved_when_the_subsequent_sign_in_fails(): void
    {
        $user = User::factory()->create(['auth_user_id' => (string) Str::uuid(), 'must_change_password' => true]);
        $token = app(SupabaseAuth::class)->issueSession($user, ['access_token' => 'password-change-token', 'expires_in' => 3600]);
        Http::fake(function ($request) use ($user) {
            if ($request->method() === 'GET') {
                return Http::response(['id' => $user->auth_user_id]);
            }

            return $request->method() === 'PUT' ? Http::response(['id' => $user->auth_user_id]) : Http::response([], 500);
        });
        $this->withToken($token)->postJson('/api/v1/auth/password', [
            'current_password' => 'old-test-password', 'password' => 'new-test-password', 'password_confirmation' => 'new-test-password',
        ])->assertServiceUnavailable()->assertJsonPath('passwordChanged', true);
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertNull($user->fresh()->password);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Password changed']);
    }
}
