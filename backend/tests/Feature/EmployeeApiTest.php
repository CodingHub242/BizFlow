<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_employee_but_cannot_cross_tenant_boundaries(): void
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
}