<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SupabaseAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupabaseUserImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.supabase.url' => 'https://auth.example.test', 'services.supabase.publishable_key' => 'publishable-test-only', 'services.supabase.service_role_key' => 'service-test-only']);
        Http::preventStrayRequests();
    }

    public function test_dry_run_and_unconfirmed_execution_never_send_credentials(): void
    {
        User::factory()->create();
        Http::fake();
        $this->artisan('app:import-supabase-users')->assertSuccessful();
        $this->artisan('app:import-supabase-users', ['--execute' => true])->assertFailed();
        $this->assertNull(User::first()->auth_user_id);
        Http::assertNothingSent();
    }

    public function test_import_preserves_the_hash_and_legacy_id_and_resumes_without_duplicates(): void
    {
        $user = User::factory()->create();
        $hash = $user->getRawOriginal('password');
        $uuid = app(SupabaseAuth::class)->importId($user);
        $identity = ['id' => $uuid, 'email' => $user->email, 'app_metadata' => ['wba_legacy_user_id' => (string) $user->id, 'wba_import_password_fingerprint' => hash('sha256', $hash)]];
        Http::fake([
            '*/admin/users/'.$uuid => Http::sequence()->push([], 404)->push($identity),
            '*/admin/users' => Http::response($identity, 200),
        ]);
        $options = ['--execute' => true, '--backup-confirmed' => true, '--confirm-managed-emails' => true];
        $this->artisan('app:import-supabase-users', $options)->assertSuccessful();
        $this->artisan('app:import-supabase-users', $options)->assertSuccessful();
        $this->assertSame($uuid, $user->fresh()->auth_user_id);
        $this->assertSame($hash, $user->fresh()->getRawOriginal('password'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request['password_hash'] === $hash && ! isset($request['password']) && $request['id'] === $uuid);
    }

    public function test_import_refuses_an_existing_identity_without_trusted_provenance(): void
    {
        $user = User::factory()->create();
        $uuid = app(SupabaseAuth::class)->importId($user);
        Http::fake(['*/admin/users/'.$uuid => Http::response(['id' => $uuid, 'email' => $user->email, 'user_metadata' => ['wba_legacy_user_id' => $user->id]])]);
        $this->artisan('app:import-supabase-users', ['--execute' => true, '--backup-confirmed' => true, '--confirm-managed-emails' => true])->assertFailed();
        $this->assertNull($user->fresh()->auth_user_id);
        $this->assertDatabaseCount('audit_logs', 0);
        Http::assertSentCount(1);
    }

    public function test_changed_password_during_a_partial_import_requires_reconciliation(): void
    {
        $user = User::factory()->create(['password' => 'new-test-password']);
        $uuid = app(SupabaseAuth::class)->importId($user);
        Http::fake(['*/admin/users/'.$uuid => Http::response([
            'id' => $uuid, 'email' => $user->email,
            'app_metadata' => ['wba_legacy_user_id' => (string) $user->id, 'wba_import_password_fingerprint' => 'old-import-fingerprint'],
        ])]);
        $this->artisan('app:import-supabase-users', ['--execute' => true, '--backup-confirmed' => true, '--confirm-managed-emails' => true])->assertFailed();
        $this->assertNull($user->fresh()->auth_user_id);
        $this->assertDatabaseCount('audit_logs', 0);
        Http::assertSentCount(1);
    }

    public function test_linked_profiles_cannot_change_legacy_credentials_before_cutover(): void
    {
        $user = User::factory()->create(['password' => 'original-test-password']);
        $user->forceFill(['auth_user_id' => app(SupabaseAuth::class)->importId($user)])->save();
        $hash = $user->password;
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/password', [
            'current_password' => 'original-test-password', 'password' => 'new-test-password', 'password_confirmation' => 'new-test-password',
        ])->assertConflict();
        $this->assertSame($hash, $user->fresh()->password);
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/users/'.$user->id, [
            'name' => 'Mika', 'lastName' => 'Santos', 'email' => $user->email, 'password' => 'new-test-password',
            'department' => 'Warehouse', 'roleLevel' => 'Staff',
        ])->assertConflict();
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_finalization_requires_supabase_mode_and_removes_legacy_credentials_and_sessions(): void
    {
        $user = User::factory()->create();
        $uuid = app(SupabaseAuth::class)->importId($user);
        $user->forceFill(['auth_user_id' => $uuid])->save();
        $user->createToken('old-session');
        $options = ['--execute' => true, '--backup-confirmed' => true, '--confirm-managed-emails' => true, '--finalize' => true];
        Http::fake(['*/admin/users/'.$uuid => Http::response(['id' => $uuid, 'email' => $user->email, 'app_metadata' => ['wba_legacy_user_id' => (string) $user->id]])]);
        $this->artisan('app:import-supabase-users', $options)->assertFailed();
        $this->assertNotNull($user->fresh()->password);
        config(['auth.credential_provider' => 'supabase']);
        $this->artisan('app:import-supabase-users', $options)->assertSuccessful();
        $this->assertNull($user->fresh()->password);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Supabase authentication finalized']);
    }
}
