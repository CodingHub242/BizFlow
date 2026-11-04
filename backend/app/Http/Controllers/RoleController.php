<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Services\RoleService;
use Spatie\Permission\Models\Role;
use Database\Seeders\BizFlowRolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function store(StoreRoleRequest $request,RoleService $roleService): JsonResponse 
    {
        $user = $request->user();

        abort_unless(
            $user && $user->tenant_id,
            403,
            'A valid tenant context is required.'
        );
        
        $tenantId = (int) $user->tenant_id;

        setPermissionsTeamId($tenantId);

        abort_unless(
            $user->hasPermissionTo('roles.create', 'web'),
            403,
            'You do not have permission to create roles.',
        );

        $validated = $request->validated();

        $role = $roleService->create(
            tenantId: $tenantId,
            name: $validated['name'],
            permissions: $validated['permissions'],
        );

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully.',
            'data' => $this->formatRole($role),
        ], 201);
    }

    public function permissionCatalog(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user && $user->tenant_id,
            403,
            'A valid tenant context is required.'
        );

        $tenantId = (int) $user->tenant_id;

        setPermissionsTeamId($tenantId);

        abort_unless(
            $user->hasPermissionTo('roles.view', 'web'),
            403,
            'You do not have permission to view roles and permissions.',
        );

        $groups = [
            'customers' => 'Customers',
            'sales' => 'Sales',
            'products' => 'Products & Services',
            'inventory' => 'Inventory',
            'branches' => 'Branches',
            'expenses' => 'Expenses',
            'invoices' => 'Invoices',
            'reports' => 'Reports',
            'employees' => 'Employees',
            'roles' => 'Roles & Permissions',
            'migration' => 'Migration Center',
            'categories' => 'Categories',
            'settings' => 'Business Settings',
        ];

        $permissions = collect(BizFlowRolePermissionSeeder::PERMISSIONS)
            ->map(fn (string $name) => [
                'name' => $name,
                'module' => explode('.', $name, 2)[0],
            ])
            ->groupBy('module')
            ->map(fn ($items, $module) => [
                'key' => $module,
                'label' => $groups[$module] ?? ucfirst($module),
                'permissions' => $items->values(),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    public function index(Request $request): JsonResponse
	{
	    $user = $request->user();

        abort_unless(
            $user && $user->tenant_id,
            403,
            'A valid tenant context is required.'
        );

	    $tenantId = (int) $user->tenant_id;
	
	    setPermissionsTeamId($tenantId);
	
	    abort_unless(
	        $user->hasPermissionTo('roles.view', 'web'),
	        403,
	        'You do not have permission to view roles.'
	    );
	
	   $roles = Role::query()
            ->where('tenant_id', $tenantId)
            ->where('guard_name', 'web')
            ->with(['permissions:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => $this->formatRole($role));
	
	    return response()->json([
	        'success' => true,
	        'data' => $roles,
	    ]);
	}

    public function updatePermissions(
    Request $request,
    int $roleId,
    RoleService $roleService
): JsonResponse {

    $validated = $request->validate([
        'permissions' => ['present', 'array'],
        'permissions.*' => ['required', 'string', 'distinct'],
    ]);
    
    $user = $request->user();

    abort_unless(
            $user && $user->tenant_id,
            403,
            'A valid tenant context is required.'
        );
        
    $tenantId = (int) $user->tenant_id;

    setPermissionsTeamId($tenantId);

    abort_unless(
        $user->hasPermissionTo('roles.update', 'web'),
        403,
        'You do not have permission to update role permissions.'
    );

    $validated = $request->validate([
        'permissions' => ['present', 'array'],
        'permissions.*' => ['required', 'string', 'distinct'],
    ]);

    // Only tenant-owned roles can be modified.
    $role = Role::query()
        ->where('tenant_id', $tenantId)
        ->where('guard_name', 'web')
        ->findOrFail($roleId);

    $role = $roleService->assignPermissions(
        $role,
        $tenantId,
        $validated['permissions'],
    );

    return response()->json([
        'success' => true,
        'message' => 'Role permissions updated successfully.',
        'data' => $this->formatRole($role),
    ]);
}

private function formatRole(Role $role): array
{
    return [
        'id' => $role->id,
        'tenant_id' => $role->tenant_id,
        'name' => $role->name,
        'guard_name' => $role->guard_name,
        'permissions' => $role->permissions
            ->pluck('name')
            ->values(),
        'created_at' => $role->created_at,
    ];
}
}