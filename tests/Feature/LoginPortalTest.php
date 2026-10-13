<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LoginPortalTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['Administration', 'Manager', 'admin'])]
    #[TestWith(['Warehouse', 'Staff', 'staff'])]
    #[TestWith(['Warehouse', 'Supervisor', 'staff'])]
    #[TestWith(['Warehouse', 'Manager', 'staff'])]
    #[TestWith(['Sales', 'Staff', 'staff'])]
    #[TestWith(['Sales', 'Supervisor', 'staff'])]
    #[TestWith(['Purchasing', 'Staff', 'staff'])]
    #[TestWith(['Purchasing', 'Manager', 'staff'])]
    public function test_accounts_can_use_only_their_assigned_login_portal(string $department, string $role, string $portal): void
    {
        $user = User::factory()->create(['department' => $department, 'role_level' => $role, 'password' => 'private-test-password']);
        $correct = $portal === 'admin' ? '/api/v1/auth/admin/login' : '/api/v1/auth/login';
        $wrong = $portal === 'admin' ? '/api/v1/auth/login' : '/api/v1/auth/admin/login';
        $credentials = ['email' => $user->email, 'password' => 'private-test-password'];
        $this->postJson($wrong, $credentials)->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $login = $this->postJson($correct, $credentials)->assertOk()->assertJsonPath('user.loginPortal', $portal);
        $this->withToken($login->json('token'))->getJson('/api/v1/auth/'.$portal.'/me')->assertOk();
        $this->getJson('/api/v1/'.($portal === 'admin' ? 'staff' : 'admin').'/overview')->assertForbidden();
    }

    public function test_a_portal_bound_session_is_revoked_after_an_account_type_change(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager', 'password' => 'private-test-password']);
        $token = $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => 'private-test-password'])->assertOk()->json('token');
        $admin->update(['department' => 'Warehouse', 'role_level' => 'Staff']);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_bad_credentials_do_not_expose_a_valid_account_portal(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager', 'password' => 'private-test-password']);
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'incorrect-password'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
