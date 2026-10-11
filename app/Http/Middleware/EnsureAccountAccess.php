<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        if ($access !== 'password' && $request->user()->must_change_password) {
            return response()->json(['message' => 'Set your own password before accessing the workspace.', 'code' => 'PASSWORD_CHANGE_REQUIRED'], 403);
        }

        return $next($request);
    }
}
