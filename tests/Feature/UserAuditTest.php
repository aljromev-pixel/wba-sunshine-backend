<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class UserAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_records_the_new_user_as_actor_without_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            ...$this->profile(), 'password' => 'test-only-password', 'password_confirmation' => 'test-only-password',
        ])->assertCreated();
        $user = User::query()->findOrFail($response->json('user.id'));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id, 'department' => 'Warehouse', 'role_level' => 'Staff',
            'action' => 'Account registered', 'module' => 'Users', 'reference' => (string) $user->id,
        ]);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertStringNotContainsString('test-only-password', AuditLog::query()->firstOrFail()->toJson());
        $this->assertStringNotContainsString($response->json('token'), AuditLog::query()->firstOrFail()->toJson());
        $this->withToken($response->json('token'))->getJson('/api/v1/inventory')
            ->assertOk()->assertJsonPath('audit.0.action', 'Account registered')
            ->assertJsonPath('audit.0.user', 'Audit Staff Member');
    }

    public function test_admin_create_and_update_record_the_actor_target_and_no_password_value(): void
    {
        $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
        $this->actingAs($admin, 'sanctum');
        $response = $this->postJson('/api/v1/users', [...$this->profile(), 'password' => 'test-only-password'])->assertCreated();
        $targetId = $response->json('data.id');
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id, 'action' => 'User created', 'module' => 'Users', 'reference' => (string) $targetId,
        ]);
        $this->putJson("/api/v1/users/{$targetId}", [
            ...$this->profile(), 'name' => 'Updated Staff', 'password' => 'changed-test-password',
        ])->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id, 'action' => 'User updated', 'reference' => (string) $targetId,
            'description' => 'Updated Staff Member (audit@example.test)',
        ]);
        $this->assertDatabaseCount('audit_logs', 2);
        $logs = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('test-only-password', $logs);
        $this->assertStringNotContainsString('changed-test-password', $logs);
        $this->assertStringNotContainsString(User::query()->findOrFail($targetId)->password, $logs);
        $this->getJson('/api/v1/inventory')->assertOk()->assertJsonCount(2, 'audit');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_account_creation_rolls_back_when_the_audit_record_cannot_be_saved(bool $adminCreated): void
    {
        if ($adminCreated) {
            $admin = User::factory()->create(['department' => 'Administration', 'role_level' => 'Manager']);
            $this->actingAs($admin, 'sanctum');
        }
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(function (): void {
            throw new RuntimeException('Audit storage unavailable.');
        });
        try {
            $this->postJson($adminCreated ? '/api/v1/users' : '/api/v1/auth/register', [
                ...$this->profile(), 'password' => 'test-only-password', 'password_confirmation' => 'test-only-password',
            ])->assertInternalServerError();
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertDatabaseMissing('users', ['email' => 'audit@example.test']);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /** @return array<string, string> */
    private function profile(): array
    {
        return ['name' => 'Audit Staff', 'lastName' => 'Member', 'email' => 'audit@example.test', 'department' => 'Warehouse', 'roleLevel' => 'Staff'];
    }
}
