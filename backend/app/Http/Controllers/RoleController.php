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
            'data' => [
                'id' => $role->id,
                'tenant_id' => $role->tenant_id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'permissions' => $role->permissions->pluck('name')->values(),
                'created_at' => $role->created_at,
            ],
        ], 201);
    }

    public function permissionCatalog(Request $request): JsonResponse
    {
        $user = $request->user();
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