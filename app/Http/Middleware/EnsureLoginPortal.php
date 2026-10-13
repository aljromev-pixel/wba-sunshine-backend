<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLoginPortal
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $portal): Response
    {
        abort_unless($request->user()?->loginPortal() === $portal, 403, 'This page belongs to the other login portal.');

        return $next($request);
    }
}
