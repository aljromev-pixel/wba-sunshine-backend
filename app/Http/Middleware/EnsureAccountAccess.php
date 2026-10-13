<?php

namespace App\Http\Middleware;

use App\Support\SupabaseAuth;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $access = 'workspace'): Response
    {
        if (! $request->user()?->is_active) {
            return response()->json(['message' => 'Your account is disabled. Contact your administrator.'], 401);
        }
        $user = $request->user();
        $token = $user->currentAccessToken();
        $provider = app(SupabaseAuth::class);
        $portals = $token instanceof PersonalAccessToken ? array_values(array_intersect($token->abilities ?? [], ['portal:admin', 'portal:staff'])) : [];
        if (($portals !== [] && $portals !== ['portal:'.$user->loginPortal()]) || ($provider->enabled() && (! $token instanceof PersonalAccessToken || $portals === [] || ! in_array('provider:supabase', $token->abilities ?? [], true) || ! $user->auth_user_id))) {
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }
            abort(401, 'Your login portal changed. Sign in through the correct portal.');
        }
        if ($provider->enabled()) {
            $identity = $provider->identity($request->bearerToken() ?? '');
            if (($identity['id'] ?? null) !== $user->auth_user_id) {
                if ($token instanceof PersonalAccessToken) {
                    $token->delete();
                }
                abort(401, 'Your Supabase session is no longer valid. Please sign in again.');
            }
        }
        if ($access !== 'password' && $request->user()->must_change_password) {
            return response()->json(['message' => 'Set your own password before accessing the workspace.', 'code' => 'PASSWORD_CHANGE_REQUIRED'], 403);
        }

        return $next($request);
    }
}
