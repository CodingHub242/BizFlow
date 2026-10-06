<?php

namespace App\Http\Middleware;

use App\TenantStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsApproved
{
    public function handle(Request $request,Closure $next): Response 
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Authentication is required.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $tenant = $user->tenant;

        if (!$tenant) {
            return response()->json([
                'message' => 'No business account is associated with this user.',
                'code' => 'TENANT_NOT_FOUND',
            ], 403);
        }

        return match ($tenant->status) {
            TenantStatus::APPROVED => $next($request),

            TenantStatus::PENDING => response()->json([
                'message' => 'Your business registration is awaiting approval.',
                'code' => 'TENANT_PENDING',
            ], 403),

            TenantStatus::REJECTED => response()->json([
                'message' => 'Your business registration was not approved.',
                'code' => 'TENANT_REJECTED',
            ], 403),

            TenantStatus::SUSPENDED => response()->json([
                'message' => 'Your business account has been suspended.',
                'code' => 'TENANT_SUSPENDED',
            ], 403),
        };
    }
}