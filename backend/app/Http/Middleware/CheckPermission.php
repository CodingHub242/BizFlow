<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request,Closure $next,string $permission): Response 
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        setPermissionsTeamId($user->tenant_id);

        if (!$user->hasPermissionTo($permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}