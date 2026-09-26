<?php

namespace Tests\Unit;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantRoleProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_tenant_provisions_all_default_roles(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Business',
            'slug' => 'test-business',
            'email' => 'test@example.com',
            'status' => 'active',
        ]);

        $this->assertSame(
            7,
            Role::query()
                ->where('tenant_id', $tenant->id)
                ->count()
        );

        foreach ([
            'Owner',
            'Administrator',
            'Manager',
            'Salesperson',
            'Cashier',
            'Inventory Officer',
            'Accountant',
        ] as $role) {
            $this->assertDatabaseHas('roles', [
                'tenant_id' => $tenant->id,
                'name' => $role,
                'guard_name' => 'web',
            ]);
        }
    }
}