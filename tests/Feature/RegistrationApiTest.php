<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('staffDepartments')]
    public function test_registration_issues_a_working_staff_token_for_the_selected_department(string $department): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            ...$this->details(), 'email' => ' NEW@EXAMPLE.TEST ',
            'department' => $department, 'roleLevel' => 'Staff',
        ]);
        $response->assertCreated()
            ->assertJsonPath('user.email', 'new@example.test')
            ->assertJsonPath('user.department', $department)
            ->assertJsonPath('user.roleLevel', 'Staff')
            ->assertJsonMissingPath('user.password');
        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('test-only-password', $user->password));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'department' => $department, 'role_level' => 'Staff']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('user.id', $user->id);
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/products', [])->assertForbidden();
        $this->getJson('/api/v1/inventory')->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function staffDepartments(): array
    {
        return ['warehouse' => ['Warehouse'], 'sales' => ['Sales'], 'purchasing' => ['Purchasing']];
    }

    public function test_duplicate_email_returns_422_and_preserves_the_existing_account(): void
    {
        $existing = User::factory()->create(['email' => 'new@example.test']);
        $this->postJson('/api/v1/auth/register', [...$this->details(), 'email' => 'NEW@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($existing->name, $existing->fresh()->name);
    }

    /** @param array<string, string> $changes */
    #[DataProvider('invalidDetails')]
    public function test_invalid_registration_returns_422_without_creating_an_account(array $changes, string $field): void
    {
        $this->postJson('/api/v1/auth/register', [...$this->details(), ...$changes])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidDetails(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'long name' => [['name' => str_repeat('a', 256)], 'name'],
            'missing email' => [['email' => ''], 'email'],
            'invalid email' => [['email' => 'not-an-email'], 'email'],
            'long email' => [['email' => str_repeat('a', 245).'@example.test'], 'email'],
            'missing password' => [['password' => ''], 'password'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'long password' => [['password' => str_repeat('a', 256), 'password_confirmation' => str_repeat('a', 256)], 'password'],
            'mismatched confirmation' => [['password_confirmation' => 'different-password'], 'password'],
            'missing department' => [['department' => ''], 'department'],
            'unsupported department' => [['department' => 'Support'], 'department'],
            'administration is not staff' => [['department' => 'Administration'], 'department'],
            'supervisor access' => [['roleLevel' => 'Supervisor'], 'roleLevel'],
            'manager access' => [['roleLevel' => 'Manager'], 'roleLevel'],
            'raw manager attribute' => [['role_level' => 'Manager'], 'role_level'],
        ];
    }

    public function test_registration_returns_429_after_five_requests_from_one_ip(): void
    {
        $this->freezeTime();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/register', $this->details())->assertTooManyRequests();
        $this->assertDatabaseCount('users', 0);
    }

    /** @return array<string, string> */
    private function details(): array
    {
        return [
            'name' => 'New Team Member', 'email' => 'new@example.test', 'department' => 'Sales',
            'password' => 'test-only-password', 'password_confirmation' => 'test-only-password',
        ];
    }
}
