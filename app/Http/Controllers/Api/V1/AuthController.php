<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SupabaseAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $email = strtolower($request->string('email')->trim()->toString());
        $provider = app(SupabaseAuth::class);
        $session = $provider->enabled() ? $provider->signIn($email, $request->string('password')->toString()) : null;
        $user = $session !== null
            ? User::query()->where('auth_user_id', $session['user']['id'])->first()
            : User::query()->where('email', $email)->first();

        if (! $user || ! $user->is_active || ($session === null && (! is_string($user->password) || ! Hash::check($request->string('password')->toString(), $user->password)))) {
            if ($session !== null) {
                $provider->signOut($session['access_token']);
            }
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $portal = $request->route('portal', 'staff');
        if ($user->loginPortal() !== $portal) {
            if ($session !== null) {
                $provider->signOut($session['access_token']);
            }

            return response()->json([
                'message' => $portal === 'admin' ? 'This account must use Staff sign in.' : 'This account must use Admin sign in.',
                'code' => 'LOGIN_PORTAL_MISMATCH',
            ], 403);
        }
        if ($session !== null && $user->email !== $email) {
            abort_if(User::withTrashed()->where('email', $email)->whereKeyNot($user->id)->exists(), 503, 'The authentication email mapping needs administrator review.');
            DB::transaction(function () use ($user, $email): void {
                $user->update(['email' => $email]);
                AuditLog::query()->create([
                    'user_id' => $user->id, 'department' => $user->department, 'role_level' => $user->role_level,
                    'action' => 'Authentication email synchronized', 'module' => 'Users', 'reference' => (string) $user->id,
                    'description' => 'The profile email was synchronized from a successful Supabase credential verification; identity and inventory links were preserved.',
                ]);
            });
        }
        $token = $session !== null ? $provider->issueSession($user, $session) : $user->createToken('frontend', ['portal:'.$portal])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $provider = app(SupabaseAuth::class);
        if ($provider->enabled()) {
            $user = DB::transaction(function () use ($request, $provider): User {
                $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
                abort_unless($user->is_active, 401);
                $provider->changePassword($request->bearerToken(), $request->validated('current_password'), $request->validated('password'));
                $user->update(['password' => null, 'must_change_password' => false]);
                $user->tokens()->delete();
                AuditLog::query()->create([
                    'user_id' => $user->id, 'department' => $user->department, 'role_level' => $user->role_level,
                    'action' => 'Password changed', 'module' => 'Users', 'reference' => (string) $user->id,
                    'description' => 'Supabase password changed; previous application sessions revoked.',
                ]);

                return $user;
            });
            try {
                $session = $provider->signIn($user->email, $request->validated('password'));
                abort_unless($session['user']['id'] === $user->auth_user_id, 503);

                return response()->json(['token' => $provider->issueSession($user, $session), 'user' => new UserResource($user)]);
            } catch (\Throwable) {
                return response()->json(['message' => 'Your password was changed and previous sessions were revoked. Sign in again with your new password.', 'passwordChanged' => true], 503);
            }
        }

        return DB::transaction(function () use ($request): JsonResponse {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->is_active, 401);
            if (! is_string($user->password) || ! Hash::check($request->validated('current_password'), $user->password)) {
                throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
            }
            $user->update(['password' => $request->validated('password'), 'must_change_password' => false]);
            $user->tokens()->delete();
            AuditLog::query()->create([
                'user_id' => $user->id,
                'department' => $user->department,
                'role_level' => $user->role_level,
                'action' => 'Password changed',
                'module' => 'Users',
                'reference' => (string) $user->id,
                'description' => "{$user->name} set their own password; previous sessions revoked.",
            ]);

            return response()->json([
                'token' => $user->createToken('frontend', ['portal:'.$user->loginPortal()])->plainTextToken,
                'user' => new UserResource($user),
            ]);
        });
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $provider = app(SupabaseAuth::class);
        $request->user()->currentAccessToken()?->delete();
        if ($provider->enabled()) {
            $provider->signOut($request->bearerToken());
        }

        return response()->json(status: 204);
    }
}
