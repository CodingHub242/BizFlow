<?php

namespace Tests\Feature;

use App\EmploymentStatus;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_domain_enforces_relationships_tenant_isolation_and_uniqueness(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $employee = Employee::create([
            'tenant_id' => $tenantA->id,
            'user_id' => $userA->id,
            'branch_id' => $branchA->id,
            'employee_number' => 'EMP-00001',
            'phone' => '+233201234567',
            'job_title' => 'Salesperson',
            'employment_status' => EmploymentStatus::ACTIVE,
            'hired_at' => '2026-09-25',
        ]);

        // Core employee data.
        $this->assertSame($tenantA->id, $employee->tenant_id);
        $this->assertSame($userA->id, $employee->user_id);
        $this->assertSame($branchA->id, $employee->branch_id);
        $this->assertSame('EMP-00001', $employee->employee_number);
        $this->assertSame('Salesperson', $employee->job_title);

        // Enum/date casts.
        $this->assertSame(
            EmploymentStatus::ACTIVE,
            $employee->employment_status
        );

        $this->assertSame(
            '2026-09-25',
            $employee->hired_at->toDateString()
        );

        // Relationships.
        $this->assertTrue($employee->tenant->is($tenantA));
        $this->assertTrue($employee->user->is($userA));
        $this->assertTrue($employee->branch->is($branchA));

        $this->assertTrue(
            $tenantA->employees->contains($employee)
        );

        $this->assertTrue(
            $branchA->employees->contains($employee)
        );

        $this->assertTrue(
            $userA->employee->is($employee)
        );

        // Another tenant may use the same employee number.
        $userB = User::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $employeeB = Employee::create([
            'tenant_id' => $tenantB->id,
            'user_id' => $userB->id,
            'employee_number' => 'EMP-00001',
            'employment_status' => EmploymentStatus::ACTIVE,
        ]);

        $this->assertSame('EMP-00001', $employeeB->employee_number);
        $this->assertSame($tenantB->id, $employeeB->tenant_id);

        // Same tenant cannot reuse an employee number.
        $duplicateNumberUser = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        try {
            Employee::create([
                'tenant_id' => $tenantA->id,
                'user_id' => $duplicateNumberUser->id,
                'employee_number' => 'EMP-00001',
                'employment_status' => EmploymentStatus::ACTIVE,
            ]);

            $this->fail(
                'Duplicate employee number was allowed within the same tenant.'
            );
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        // Same user cannot have two employee records in the same tenant.
        try {
            Employee::create([
                'tenant_id' => $tenantA->id,
                'user_id' => $userA->id,
                'employee_number' => 'EMP-00002',
                'employment_status' => EmploymentStatus::ACTIVE,
            ]);

            $this->fail(
                'The same user was allowed multiple employee records within the tenant.'
            );
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}