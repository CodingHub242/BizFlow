<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Spatie\Permission\Models\Role;
use App\Services\Tenant\TenantProvisioningService;
use Database\Seeders\BizFlowRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeApiTest extends TestCase
{
    use RefreshDatabase;

    

protected function setUp(): void
{
    parent::setUp();

    $this->seed(BizFlowRolePermissionSeeder::class);
}

   
private function assignRole(
    User $user,
    string $roleName = 'Administrator'
): void {
    $tenantId = $user->tenant_id;

    $provisioning = app(TenantProvisioningService::class);
    $provisioning->provision(
        $user->tenant()->firstOrFail()
    );

    setPermissionsTeamId($tenantId);

    $role = Role::query()
        ->where('tenant_id', $tenantId)
        ->where('name', $roleName)
        ->where('guard_name', 'web')
        ->firstOrFail();

    $user->assignRole($role);

    $this->assertTrue(
    $user->fresh()->hasPermissionTo('employees.create', 'web'),
    "Administrator role for tenant {$tenantId} lacks employees.create."
);
}


    public function test_authenticated_user_can_create_employee_but_cannot_cross_tenant_boundaries(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $this->assignRole($userA);

        $userB = User::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $this->assignRole($userB);

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $branchB = Branch::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        // Normal employee creation.
        $response = $this
            ->actingAs($userA)
            ->postJson('/api/employees', [
                'user_id' => $userA->id,
                'branch_id' => $branchA->id,
                'employee_number' => 'EMP-00001',
                'phone' => '+233201234567',
                'job_title' => 'Salesperson',
                'employment_status' => 'active',
                'hired_at' => '2026-09-25',
            ]);

        $response
            ->assertCreated()
            ->assertJson([
                'success' => true,
                'message' => 'Employee created successfully.',
                'data' => [
                    'tenant_id' => $tenantA->id,
                    'user_id' => $userA->id,
                    'branch_id' => $branchA->id,
                    'employee_number' => 'EMP-00001',
                    'job_title' => 'Salesperson',
                    'employment_status' => 'active',
                ],
            ]);

        $this->assertDatabaseHas('employees', [
            'tenant_id' => $tenantA->id,
            'user_id' => $userA->id,
            'branch_id' => $branchA->id,
            'employee_number' => 'EMP-00001',
        ]);

        // Tenant A must not be able to create an employee
        // using Tenant B's user.
        $response = $this
            ->actingAs($userA)
            ->postJson('/api/employees', [
                'user_id' => $userB->id,
                'branch_id' => $branchA->id,
                'employee_number' => 'EMP-00002',
            ]);

        $response->assertStatus(422);

        $response->assertJsonValidationErrors([
            'user_id',
        ]);

        // Tenant A must not be able to use Tenant B's branch.
        $anotherUserA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $response = $this
            ->actingAs($userA)
            ->postJson('/api/employees', [
                'user_id' => $anotherUserA->id,
                'branch_id' => $branchB->id,
                'employee_number' => 'EMP-00003',
            ]);

        $response->assertStatus(422);

        $response->assertJsonValidationErrors([
            'branch_id',
        ]);

        // Only the legitimate employee should exist.
        $this->assertDatabaseCount('employees', 1);

        $this->assertSame(
            $tenantA->id,
            Employee::first()->tenant_id
        );
    }

    public function test_user_without_employee_create_permission_cannot_create_employee(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->actingAs($user)
        ->postJson('/api/employees', [
            'name' => 'Unauthorized Employee',
            'email' => 'unauthorized.employee@example.com',
            'phone' => '0244000000',
            'employee_number' => 'EMP-UNAUTHORIZED-001',
            'job_title' => 'Salesperson',
            'employment_status' => 'active',
            'hired_at' => '2026-09-25',
        ])
        ->assertStatus(403);

    $this->assertDatabaseMissing('employees', [
        'tenant_id' => $tenant->id,
        'employee_number' => 'EMP-UNAUTHORIZED-001',
    ]);
}
}