<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth('platform')->check()) {
            return redirect()->route('platform.login');
        }

        return $next($request);
    }
}