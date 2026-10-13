<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class SupabaseAuth
{
    public function enabled(): bool
    {
        abort_unless(in_array(config('auth.credential_provider'), ['laravel', 'supabase'], true), 503, 'The authentication provider is not configured correctly.');

        return config('auth.credential_provider') === 'supabase';
    }

    /** @return array<string, mixed> */
    public function signIn(string $email, string $password, string $field = 'email'): array
    {
        $response = $this->send(fn (): Response => $this->client()->post('token?grant_type=password', ['email' => $email, 'password' => $password]));
        if (in_array($response->status(), [400, 401, 422], true)) {
            throw ValidationException::withMessages([$field => ['The provided credentials are incorrect.']]);
        }
        $this->requireSuccess($response);
        $session = $response->json();
        abort_unless(is_array($session) && is_string($session['access_token'] ?? null) && is_string($session['user']['id'] ?? null) && Str::isUuid($session['user']['id']) && is_numeric($session['expires_in'] ?? null) && (int) $session['expires_in'] > 0, 503, 'The authentication provider returned an invalid session.');

        return $session;
    }

    /** @return array<string, mixed>|null */
    public function identity(string $token): ?array
    {
        $response = $this->send(fn (): Response => $this->client(token: $token)->get('user'));
        if (in_array($response->status(), [401, 403], true)) {
            return null;
        }
        $this->requireSuccess($response);
        $identity = $response->json();
        abort_unless(is_array($identity) && is_string($identity['id'] ?? null) && Str::isUuid($identity['id']), 503, 'The authentication provider returned an invalid account.');
        if (! empty($identity['deleted_at'])) {
            return null;
        }
        if (! empty($identity['banned_until'])) {
            try {
                if (Carbon::parse($identity['banned_until'])->isFuture()) {
                    return null;
                }
            } catch (\Throwable) {
                abort(503, 'The authentication provider returned an invalid account status.');
            }
        }

        return $identity;
    }

    public function signOut(string $token, string $scope = 'local'): void
    {
        $response = $this->send(fn (): Response => $this->client(token: $token)->post('logout?scope='.$scope));
        if (! in_array($response->status(), [401, 403], true)) {
            $this->requireSuccess($response);
        }
    }

    /** @param array<string, mixed> $session */
    public function issueSession(User $user, array $session): string
    {
        $token = $session['access_token'];
        $user->tokens()->create([
            'name' => 'supabase-'.$user->loginPortal(),
            'token' => hash('sha256', $token),
            'abilities' => ['portal:'.$user->loginPortal(), 'provider:supabase'],
            'expires_at' => now()->addSeconds(min((int) $session['expires_in'], 86400)),
        ]);

        return $token;
    }

    public function changePassword(User $user, string $token, string $currentPassword, string $password): void
    {
        $verification = $this->signIn($user->email, $currentPassword, 'current_password');
        try {
            abort_unless($verification['user']['id'] === $user->auth_user_id, 503, 'The authentication account mapping changed.');
        } finally {
            $this->signOut($verification['access_token']);
        }
        $response = $this->send(fn (): Response => $this->client(token: $token)->put('user', ['current_password' => $currentPassword, 'password' => $password]));
        if (in_array($response->status(), [400, 401, 422], true)) {
            throw ValidationException::withMessages(['current_password' => ['The current password could not be verified, or the new password does not meet the authentication policy.']]);
        }
        $this->requireSuccess($response);
    }

    public function importId(User $user): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, rtrim((string) config('services.supabase.url'), '/').'/wba/users/'.$user->id)->toString();
    }

    /** @return array<string, mixed>|null */
    public function account(string $id): ?array
    {
        abort_unless(Str::isUuid($id), 503, 'Invalid authentication account mapping.');
        $response = $this->send(fn (): Response => $this->client(admin: true)->get('admin/users/'.$id));
        if ($response->notFound()) {
            return null;
        }
        $this->requireSuccess($response);

        $account = $this->accountPayload($response);
        abort_unless($account['id'] === $id, 503, 'The authentication provider returned a conflicting account mapping.');

        return $account;
    }

    /** @return array<string, mixed> */
    public function createAccount(User $user, ?string $password = null, bool $importHash = false): array
    {
        $payload = [
            'id' => $this->importId($user),
            'email' => $user->email,
            'email_confirm' => true,
            'app_metadata' => ['wba_legacy_user_id' => (string) $user->id],
            'ban_duration' => $user->is_active && ! $user->trashed() ? 'none' : '876000h',
        ];
        if ($importHash) {
            $payload['password_hash'] = $user->getRawOriginal('password');
            $payload['app_metadata']['wba_import_password_fingerprint'] = hash('sha256', $payload['password_hash']);
        } else {
            $payload['password'] = $password;
        }
        $response = $this->send(fn (): Response => $this->client(admin: true)->post('admin/users', $payload));
        if (in_array($response->status(), [400, 409, 422], true)) {
            throw ValidationException::withMessages(['email' => ['Supabase could not create this account. Check for an existing email account or password-policy mismatch; no existing identity was linked automatically.']]);
        }
        $this->requireSuccess($response);

        $account = $this->accountPayload($response);
        abort_unless($account['id'] === $payload['id'] && strtolower(trim($account['email'] ?? '')) === $user->email, 503, 'The authentication provider returned a conflicting account mapping.');

        return $account;
    }

    /** @param array<string, mixed> $attributes */
    public function updateAccount(User $user, array $attributes): void
    {
        abort_unless(Str::isUuid($user->auth_user_id ?? ''), 503, 'This account has not been migrated to Supabase Auth.');
        $payload = ['email' => $attributes['email'], 'email_confirm' => true, 'ban_duration' => ($attributes['is_active'] ?? false) ? 'none' : '876000h'];
        if (is_string($attributes['password'] ?? null) && $attributes['password'] !== '') {
            $payload['password'] = $attributes['password'];
        }
        $response = $this->send(fn (): Response => $this->client(admin: true)->put('admin/users/'.$user->auth_user_id, $payload));
        if (in_array($response->status(), [400, 409, 422], true)) {
            throw ValidationException::withMessages(['email' => ['The authentication provider rejected the email or password change.']]);
        }
        $this->requireSuccess($response);
    }

    private function client(bool $admin = false, ?string $token = null): PendingRequest
    {
        $url = rtrim((string) config('services.supabase.url'), '/');
        $key = (string) ($admin ? (config('services.supabase.secret_key') ?: config('services.supabase.service_role_key')) : config('services.supabase.publishable_key'));
        abort_unless(str_starts_with($url, 'https://') && $key !== '', 503, 'Supabase authentication is not configured. Contact your administrator.');

        $client = Http::baseUrl($url.'/auth/v1/')->acceptJson()->asJson()->withHeaders(['apikey' => $key])->connectTimeout(5)->timeout(15);

        return $token !== null || ! str_starts_with($key, 'sb_') ? $client->withToken($token ?? $key) : $client;
    }

    /** @param callable(): Response $operation */
    private function send(callable $operation): Response
    {
        try {
            return $operation();
        } catch (ConnectionException) {
            abort(503, 'The authentication provider is temporarily unavailable. Please retry.');
        }
    }

    private function requireSuccess(Response $response): void
    {
        abort_if($response->status() === 429, 429, 'Too many authentication attempts. Please wait and retry.');
        abort_unless($response->successful(), 503, 'The authentication provider is temporarily unavailable. Please retry.');
    }

    /** @return array<string, mixed> */
    private function accountPayload(Response $response): array
    {
        $account = $response->json('user') ?? $response->json();
        abort_unless(is_array($account) && is_string($account['id'] ?? null) && Str::isUuid($account['id']), 503, 'The authentication provider returned an invalid account.');

        return $account;
    }
}
