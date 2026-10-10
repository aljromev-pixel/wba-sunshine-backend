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

    public function test_public_registration_creates_sales_staff_and_allows_sign_in_without_privilege_escalation(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            ...$this->details(), 'email' => ' NEW@EXAMPLE.TEST ',
            'department' => 'Administration', 'roleLevel' => 'Manager', 'role_level' => 'Manager',
        ]);
        $response->assertCreated()
            ->assertJsonPath('user.email', 'new@example.test')
            ->assertJsonPath('user.department', 'Sales')
            ->assertJsonPath('user.roleLevel', 'Staff')
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('token');
        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('test-only-password', $user->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'new@example.test', 'password' => 'test-only-password',
        ])->assertOk();
        $this->withToken($login->json('token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('user.id', $user->id);
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/products', [])->assertForbidden();
        $this->postJson('/api/v1/inventory/adjustments', [])->assertForbidden();
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
            'name' => 'New Team Member', 'email' => 'new@example.test',
            'password' => 'test-only-password', 'password_confirmation' => 'test-only-password',
        ];
    }
}
