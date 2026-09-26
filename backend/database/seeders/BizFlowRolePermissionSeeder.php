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

        // Migration Center
        'migration.view',
        'migration.create',
        'migration.import',
    ];

    public const ROLE_PERMISSIONS = [
    'Owner' => [
        'customers.view',
        'customers.create',
        'customers.update',
        'customers.delete',

        'sales.view',
        'sales.create',
        'sales.update',
        'sales.delete',

        'products.view',
        'products.create',
        'products.update',
        'products.delete',
        'products.change_cost',

        'inventory.view',
        'inventory.receive',
        'inventory.adjust',
        'inventory.transfer',

        'expenses.view',
        'expenses.create',
        'expenses.update',
        'expenses.delete',

        'invoices.view',
        'invoices.create',
        'invoices.update',
        'invoices.cancel',
        'invoices.record_payment',

        'reports.view',
        'reports.view_profit',

        'employees.view',
        'employees.create',
        'employees.update',
        'employees.manage_roles',

        'roles.view',
        'roles.create',
        'roles.update',
        'roles.delete',

        'migration.view',
        'migration.create',
        'migration.import',
    ],

    'Administrator' => [
        'customers.view',
        'customers.create',
        'customers.update',
        'customers.delete',

        'sales.view',
        'sales.create',
        'sales.update',
        'sales.delete',

        'products.view',
        'products.create',
        'products.update',
        'products.delete',
        'products.change_cost',

        'inventory.view',
        'inventory.receive',
        'inventory.adjust',
        'inventory.transfer',

        'expenses.view',
        'expenses.create',
        'expenses.update',
        'expenses.delete',

        'invoices.view',
        'invoices.create',
        'invoices.update',
        'invoices.cancel',
        'invoices.record_payment',

        'reports.view',
        'reports.view_profit',

        'employees.view',
        'employees.create',
        'employees.update',
        'employees.manage_roles',

        'roles.view',
        'roles.create',
        'roles.update',
        'roles.delete',

        'migration.view',
        'migration.create',
        'migration.import',
    ],

    'Manager' => [
        'customers.view',
        'customers.create',
        'customers.update',

        'sales.view',
        'sales.create',
        'sales.update',

        'products.view',
        'products.create',
        'products.update',

        'inventory.view',
        'inventory.receive',
        'inventory.adjust',
        'inventory.transfer',

        'expenses.view',
        'expenses.create',
        'expenses.update',

        'invoices.view',
        'invoices.create',
        'invoices.update',
        'invoices.record_payment',

        'reports.view',
        'reports.view_profit',

        'employees.view',
        'employees.create',
        'employees.update',
    ],

    'Salesperson' => [
        'customers.view',
        'customers.create',
        'customers.update',

        'sales.view',
        'sales.create',
        'sales.update',

        'products.view',

        'inventory.view',

        'invoices.view',
        'invoices.create',
        'invoices.record_payment',
    ],

    'Cashier' => [
        'customers.view',
        'customers.create',
        'customers.update',

        'sales.view',
        'sales.create',

        'products.view',

        'inventory.view',

        'invoices.view',
        'invoices.create',
        'invoices.record_payment',
    ],

    'Inventory Officer' => [
        'customers.view',

        'products.view',
        'products.create',
        'products.update',

        'inventory.view',
        'inventory.receive',
        'inventory.adjust',
        'inventory.transfer',

        'sales.view',

        'invoices.view',
    ],

    'Accountant' => [
        'customers.view',

        'sales.view',

        'products.view',

        'inventory.view',

        'expenses.view',
        'expenses.create',
        'expenses.update',
        'expenses.delete',

        'invoices.view',
        'invoices.create',
        'invoices.update',
        'invoices.record_payment',

        'reports.view',
        'reports.view_profit',

        'migration.view',
        'migration.create',
        'migration.import',
    ],
];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

   public function provisionTenantRoles(int $tenantId): void
{
    foreach (self::PERMISSIONS as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    setPermissionsTeamId($tenantId);

    foreach (self::DEFAULT_ROLES as $roleName) {
        $role = Role::findOrCreate($roleName, 'web');

        $role->syncPermissions(
            self::ROLE_PERMISSIONS[$roleName] ?? []
        );
    }
}
}