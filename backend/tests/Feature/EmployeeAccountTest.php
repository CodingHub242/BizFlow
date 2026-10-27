<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenant\TenantProvisioningService;
use Database\Seeders\BizFlowRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BizFlowRolePermissionSeeder::class);
    }

    private function administratorFor(Tenant $tenant): User
    {
        app(TenantProvisioningService::class)->provision($tenant);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        setPermissionsTeamId($tenant->id);

        $role = \Spatie\Permission\Models\Role::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Administrator')
            ->where('guard_name', 'web')
            ->firstOrFail();

        $user->assignRole($role);

        return $user;
    }

    public function test_administrator_can_create_employee_account(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->administratorFor($tenant);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $response = $this->actingAs($admin)->postJson(
            '/api/employees/accounts',
            [
                'name' => 'Ama Mensah',
                'email' => 'ama@example.com',
                'password' => 'StrongPassword!2026',
                'branch_id' => $branch->id,
                'employee_number' => 'EMP-1001',
                'job_title' => 'Salesperson',
                'employment_status' => 'active',
                'hired_at' => '2026-10-01',
            ]
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'ama@example.com')
            ->assertJsonPath('data.employee_number', 'EMP-1001');

        $this->assertDatabaseHas('users', [
            'tenant_id' => $tenant->id,
            'email' => 'ama@example.com',
        ]);

        $this->assertDatabaseHas('employees', [
            'tenant_id' => $tenant->id,
            'employee_number' => 'EMP-1001',
        ]);

        $user = User::where('email', 'ama@example.com')->firstOrFail();

        $this->assertTrue(
            Hash::check('StrongPassword!2026', $user->password)
        );
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->administratorFor($tenant);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'existing@example.com',
        ]);

        $response = $this->actingAs($admin)->postJson(
            '/api/employees/accounts',
            [
                'name' => 'Duplicate User',
                'email' => 'existing@example.com',
                'password' => 'StrongPassword!2026',
                'employee_number' => 'EMP-1002',
            ]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseMissing('employees', [
            'tenant_id' => $tenant->id,
            'employee_number' => 'EMP-1002',
        ]);
    }

    public function test_employee_account_cannot_use_another_tenants_branch(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $admin = $this->administratorFor($tenantA);

        $branchB = Branch::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $response = $this->actingAs($admin)->postJson(
            '/api/employees/accounts',
            [
                'name' => 'Cross Tenant User',
                'email' => 'cross-tenant@example.com',
                'password' => 'StrongPassword!2026',
                'branch_id' => $branchB->id,
                'employee_number' => 'EMP-1003',
            ]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id']);

        $this->assertDatabaseMissing('users', [
            'email' => 'cross-tenant@example.com',
        ]);
    }

public function test_employee_can_be_created_without_assigning_a_role(): void
{
    $tenant = Tenant::factory()->create();
    $admin = $this->administratorFor($tenant);

    $response = $this->actingAs($admin)->postJson(
        '/api/employees/accounts',
        [
            'name' => 'Ama Mensah',
            'email' => 'ama-no-role@example.com',
            'password' => 'StrongPassword!2026',
            'employee_number' => 'EMP-2001',
        ]
    );

    $response->assertCreated();

    $this->assertDatabaseHas('employees', [
        'tenant_id' => $tenant->id,
        'employee_number' => 'EMP-2001',
    ]);
}

public function test_owner_role_cannot_be_assigned_through_employee_creation(): void
{
    $tenant = Tenant::factory()->create();
    $admin = $this->administratorFor($tenant);

    $response = $this->actingAs($admin)->postJson(
        '/api/employees/accounts',
        [
            'name' => 'New Employee',
            'email' => 'new-owner@example.com',
            'password' => 'StrongPassword!2026',
            'employee_number' => 'EMP-2002',
            'role' => 'Owner',
        ]
    );

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);

    $this->assertDatabaseMissing('users', [
        'email' => 'new-owner@example.com',
    ]);
}

public function test_administrator_role_cannot_be_assigned_through_employee_creation(): void
{
    $tenant = Tenant::factory()->create();
    $admin = $this->administratorFor($tenant);

    $response = $this->actingAs($admin)->postJson(
        '/api/employees/accounts',
        [
            'name' => 'New Administrator',
            'email' => 'new-admin@example.com',
            'password' => 'StrongPassword!2026',
            'employee_number' => 'EMP-2003',
            'role' => 'Administrator',
        ]
    );

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);

    $this->assertDatabaseMissing('users', [
        'email' => 'new-admin@example.com',
    ]);
}

public function test_employee_creation_rolls_back_if_role_assignment_fails(): void
{
    $tenant = Tenant::factory()->create();
    $admin = $this->administratorFor($tenant);

    $usersBefore = User::where('tenant_id', $tenant->id)->count();
    $employeesBefore = Employee::where('tenant_id', $tenant->id)->count();

    $this->actingAs($admin)->postJson(
        '/api/employees/accounts',
        [
            'name' => 'Rollback Employee',
            'email' => 'rollback@example.com',
            'password' => 'StrongPassword!2026',
            'employee_number' => 'EMP-2004',
            'role' => 'Nonexistent Role',
        ]
    )->assertUnprocessable();

    $this->assertSame(
        $usersBefore,
        User::where('tenant_id', $tenant->id)->count()
    );

    $this->assertSame(
        $employeesBefore,
        Employee::where('tenant_id', $tenant->id)->count()
    );
}

}
