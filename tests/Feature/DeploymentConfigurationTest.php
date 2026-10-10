<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeploymentConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_admin_is_hashed_and_repeat_bootstrap_preserves_password(): void
    {
        config(['services.bootstrap_admin' => ['name' => 'Deployment Administrator', 'email' => 'ADMIN@example.test', 'password' => 'test-bootstrap-password']]);
        $this->artisan('app:bootstrap-admin')->assertSuccessful();
        $admin = User::query()->where('email', 'admin@example.test')->sole();
        $this->assertSame('Administration', $admin->department);
        $this->assertSame('Manager', $admin->role_level);
        $this->assertTrue(Hash::check('test-bootstrap-password', $admin->password));
        config(['services.bootstrap_admin.password' => 'different-test-password']);
        $this->artisan('app:bootstrap-admin')->assertSuccessful();
        $this->assertTrue(Hash::check('test-bootstrap-password', $admin->fresh()->password));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_bootstrap_rejects_missing_credentials(): void
    {
        config(['services.bootstrap_admin' => ['name' => null, 'email' => null, 'password' => null]]);
        $this->artisan('app:bootstrap-admin')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_bootstrap_does_not_promote_existing_staff(): void
    {
        $user = User::factory()->create(['department' => 'Sales', 'role_level' => 'Staff']);
        config(['services.bootstrap_admin' => ['name' => 'Admin', 'email' => $user->email, 'password' => 'test-bootstrap-password']]);
        $this->artisan('app:bootstrap-admin')->assertFailed();
        $this->assertSame('Staff', $user->fresh()->role_level);
    }

    public function test_bootstrap_cannot_create_a_second_admin(): void
    {
        User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        config(['services.bootstrap_admin' => ['name' => 'Admin', 'email' => 'second@example.test', 'password' => 'test-bootstrap-password']]);
        $this->artisan('app:bootstrap-admin')->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_cors_allows_configured_frontend_preflight(): void
    {
        config(['cors.allowed_origins' => ['https://frontend.example.test']]);
        $this->withHeaders([
            'Origin' => 'https://frontend.example.test',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/v1/auth/login')->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://frontend.example.test');
    }

    public function test_cors_rejects_unlisted_origin(): void
    {
        config(['cors.allowed_origins' => ['https://frontend.example.test']]);
        $this->withHeaders([
            'Origin' => 'https://other.example.test',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/v1/auth/login')->assertHeader('Access-Control-Allow-Origin', 'https://frontend.example.test');
    }
}
