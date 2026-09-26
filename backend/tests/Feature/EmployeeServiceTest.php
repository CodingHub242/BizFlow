<?php

namespace Tests\Feature;

use App\EmploymentStatus;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmployeeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_service_enforces_tenant_boundaries_and_creates_employee(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $userB = User::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $branchB = Branch::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $service = app(EmployeeService::class);

        $employee = $service->create(
            tenantId: $tenantA->id,
            userId: $userA->id,
            branchId: $branchA->id,
            employeeNumber: 'EMP-00001',
            phone: '+233201234567',
            jobTitle: 'Salesperson',
            employmentStatus: EmploymentStatus::ACTIVE,
            hiredAt: '2026-09-25',
        );

        $this->assertInstanceOf(Employee::class, $employee);
        $this->assertSame($tenantA->id, $employee->tenant_id);
        $this->assertSame($userA->id, $employee->user_id);
        $this->assertSame($branchA->id, $employee->branch_id);

        $this->assertThrowsValidation(
            fn () => $service->create(
                tenantId: $tenantA->id,
                userId: $userB->id,
                branchId: $branchA->id,
                employeeNumber: 'EMP-00002',
            ),
            'user_id'
        );

        $this->assertThrowsValidation(
            fn () => $service->create(
                tenantId: $tenantA->id,
                userId: User::factory()->create([
                    'tenant_id' => $tenantA->id,
                ])->id,
                branchId: $branchB->id,
                employeeNumber: 'EMP-00003',
            ),
            'branch_id'
        );

        $this->assertThrowsValidation(
            fn () => $service->create(
                tenantId: $tenantA->id,
                userId: User::factory()->create([
                    'tenant_id' => $tenantA->id,
                ])->id,
                branchId: $branchA->id,
                employeeNumber: 'EMP-00001',
            ),
            'employee_number'
        );

        $this->assertThrowsValidation(
            fn () => $service->create(
                tenantId: $tenantA->id,
                userId: $userA->id,
                branchId: $branchA->id,
                employeeNumber: 'EMP-00004',
            ),
            'user_id'
        );

        $this->assertDatabaseCount('employees', 1);
    }

    private function assertThrowsValidation(
        callable $callback,
        string $field
    ): void {
        try {
            $callback();

            $this->fail(
                "Expected validation failure for {$field}."
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                $field,
                $exception->errors()
            );
        }
    }
}