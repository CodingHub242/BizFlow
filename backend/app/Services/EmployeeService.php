<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\EmploymentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EmployeeService
{
    public function create(int $tenantId,int $userId,?int $branchId,string $employeeNumber,?string $phone = null,?string $jobTitle = null,EmploymentStatus $employmentStatus = EmploymentStatus::ACTIVE,?string $hiredAt = null,): Employee 
    {
        return DB::transaction(function () use (
            $tenantId,
            $userId,
            $branchId,
            $employeeNumber,
            $phone,
            $jobTitle,
            $employmentStatus,
            $hiredAt
        ) {
            $user = User::query()
                ->whereKey($userId)
                ->where('tenant_id', $tenantId)
                ->first();

            if (!$user) {
                throw ValidationException::withMessages([
                    'user_id' => 'The selected user does not belong to this tenant.',
                ]);
            }

            if (Employee::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->exists()
            ) {
                throw ValidationException::withMessages([
                    'user_id' => 'This user is already an employee of this tenant.',
                ]);
            }

            if ($branchId !== null) {
                $branchExists = Branch::query()
                    ->whereKey($branchId)
                    ->where('tenant_id', $tenantId)
                    ->exists();

                if (!$branchExists) {
                    throw ValidationException::withMessages([
                        'branch_id' => 'The selected branch does not belong to this tenant.',
                    ]);
                }
            }

            $employeeNumberExists = Employee::query()
                ->where('tenant_id', $tenantId)
                ->where('employee_number', $employeeNumber)
                ->exists();

            if ($employeeNumberExists) {
                throw ValidationException::withMessages([
                    'employee_number' => 'This employee number is already in use.',
                ]);
            }

            return Employee::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'branch_id' => $branchId,
                'employee_number' => $employeeNumber,
                'phone' => $phone,
                'job_title' => $jobTitle,
                'employment_status' => $employmentStatus,
                'hired_at' => $hiredAt,
            ]);
        });
    }
}