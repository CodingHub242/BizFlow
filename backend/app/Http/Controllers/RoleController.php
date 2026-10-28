<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Services\RoleService;
use Spatie\Permission\Models\Role;
use Illuminate\Http\Request;
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

    public function index(Request $request): JsonResponse
	{
	    $user = $request->user();
	    $tenantId = (int) $user->tenant_id;
	
	    setPermissionsTeamId($tenantId);
	
	    abort_unless(
	        $user->hasPermissionTo('roles.view', 'web'),
	        403,
	        'You do not have permission to view roles.'
	    );
	
	    $roles = Role::query()
	        ->where('guard_name', 'web')
	        ->where(function ($query) use ($tenantId) {
	            $query->whereNull('tenant_id')
	                ->orWhere('tenant_id', $tenantId);
	        })
	        ->orderBy('name')
	        ->get([
	            'id',
	            'tenant_id',
	            'name',
	            'guard_name',
	            'created_at',
	        ]);
	
	    return response()->json([
	        'success' => true,
	        'data' => $roles,
	    ]);
	}
}