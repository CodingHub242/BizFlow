<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class BizFlowRolePermissionSeeder extends Seeder
{
    public const DEFAULT_ROLES = [
        'Owner',
        'Administrator',
        'Manager',
        'Salesperson',
        'Cashier',
        'Inventory Officer',
        'Accountant',
    ];

    public const PERMISSIONS = [
        // Customers
        'customers.view',
        'customers.create',
        'customers.update',
        'customers.delete',

        // Sales
        'sales.view',
        'sales.create',
        'sales.update',
        'sales.delete',

        // Products / Services
        'products.view',
        'products.create',
        'products.update',
        'products.delete',
        'products.change_cost',

        // Inventory
        'inventory.view',
        'inventory.receive',
        'inventory.adjust',
        'inventory.transfer',

        // Expenses
        'expenses.view',
        'expenses.create',
        'expenses.update',
        'expenses.delete',

        // Invoices
        'invoices.view',
        'invoices.create',
        'invoices.update',
        'invoices.cancel',
        'invoices.record_payment',

        // Reports
        'reports.view',
        'reports.view_profit',

        // Employees
        'employees.view',
        'employees.create',
        'employees.update',
        'employees.manage_roles',

        // Roles
        'roles.view',
        'roles.create',
        'roles.update',
        'roles.delete',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function provisionTenantRoles(int $tenantId): void
    {
        setPermissionsTeamId($tenantId);

        foreach (self::DEFAULT_ROLES as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }
    }
}