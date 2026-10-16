<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantPermissionTest extends TestCase
{
    use RefreshDatabase;

  
public function test_roles_are_isolated_between_tenants(): void
{
    $tenantA = Tenant::create([
        'name' => 'Business A',
        'slug' => 'business-a',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Business B',
        'slug' => 'business-b',
    ]);

    $userA = User::create([
        'tenant_id' => $tenantA->id,
        'name' => 'User A',
        'email' => 'usera@example.com',
        'password' => bcrypt('password'),
    ]);

    $userB = User::create([
        'tenant_id' => $tenantB->id,
        'name' => 'User B',
        'email' => 'userb@example.com',
        'password' => bcrypt('password'),
    ]);

    $registrar = app(PermissionRegistrar::class);

    // Create the Accountant role for tenant A.
    $registrar->setPermissionsTeamId($tenantA->id);

    $roleA = Role::query()->firstOrCreate([
        'tenant_id' => $tenantA->id,
        'name' => 'Accountant',
        'guard_name' => 'web',
    ]);

    $userA->assignRole($roleA);

    $this->assertTrue($userA->fresh()->hasRole('Accountant'));

    // Create the same role name for tenant B.
    $registrar->setPermissionsTeamId($tenantB->id);

    Role::query()->firstOrCreate([
        'tenant_id' => $tenantB->id,
        'name' => 'Accountant',
        'guard_name' => 'web',
    ]);

    // Tenant B's user must not inherit tenant A's role assignment.
    $this->assertFalse($userB->fresh()->hasRole('Accountant'));

    // Clear the team context to avoid affecting subsequent tests.
    $registrar->setPermissionsTeamId(null);
}

}