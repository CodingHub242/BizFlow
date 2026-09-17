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

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantA->id);

        $roleA = Role::create([
            'name' => 'Manager',
            'guard_name' => 'web',
        ]);

        $userA->assignRole($roleA);

        $this->assertTrue($userA->hasRole('Manager'));

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantB->id);

        $this->assertFalse($userB->hasRole('Manager'));
    }
}