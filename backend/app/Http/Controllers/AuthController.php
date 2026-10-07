<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\TenantStatus;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'],'password' => ['required', 'string'],]);

        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $tenant = Tenant::query()->find($user->tenant_id);

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Business account could not be found.',
                'code' => 'TENANT_NOT_FOUND',
            ], 403);
        }

        if ($tenant->status === TenantStatus::PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Your business is awaiting platform approval.',
                'code' => 'TENANT_PENDING',
            ], 403);
        }

        if ($tenant->status === TenantStatus::REJECTED) {
            return response()->json([
                'success' => false,
                'message' => 'Your business registration was rejected.',
                'code' => 'TENANT_REJECTED',
            ], 403);
        }

        if ($tenant->status === TenantStatus::SUSPENDED) {
            return response()->json([
                'success' => false,
                'message' => 'Your business account has been suspended.',
                'code' => 'TENANT_SUSPENDED',
            ], 403);
        }

        $token = $user->createToken('BizFlow Web');

        $roles = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->pluck('roles.name');

        foreach($roles as $role){}

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                //get the role of the user from model has roles and sanctum
                'role' => $role,
                'tenant_id' => $user->tenant_id,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logout successful.',
        ]);
    }
}