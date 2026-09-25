<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmployeeRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmployeeRoleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_be_assigned_a_role_within_the_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();


        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $employee = Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'employee_number' => 'EMP-00001',
        ]);

        setPermissionsTeamId($tenant->id);

        $service = app(EmployeeRoleService::class);

        $service->assign(
            employee: $employee,
            role: 'Salesperson',
            tenantId: $tenant->id,
        );

        setPermissionsTeamId($tenant->id);

        $this->assertTrue(
            $user->hasRole('Salesperson')
        );
    }

    public function test_employee_from_another_tenant_cannot_receive_role(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userB = User::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $employeeB = Employee::create([
            'tenant_id' => $tenantB->id,
            'user_id' => $userB->id,
            'employee_number' => 'EMP-00002',
        ]);

        
        $service = app(EmployeeRoleService::class);

        $this->expectException(ValidationException::class);

        $service->assign(
            employee: $employeeB,
            role: 'Salesperson',
            tenantId: $tenantA->id,
        );
    }
}