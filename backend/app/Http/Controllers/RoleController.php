<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function store(StoreRoleRequest $request,RoleService $roleService): JsonResponse 
    {
        $user = $request->user();

        setPermissionsTeamId($user->tenant_id);

        abort_unless(
            $user->hasPermissionTo('roles.create'),
            403,
            'You do not have permission to create roles.'
        );

        $role = $roleService->create(
            tenantId: $user->tenant_id,
            name: $request->validated('name'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully.',
            'data' => [
                'id' => $role->id,
                'tenant_id' => $role->tenant_id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'created_at' => $role->created_at,
            ],
        ], 201);
    }
}