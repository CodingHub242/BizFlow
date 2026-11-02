<?php

namespace App\Services;

use App\EmploymentStatus;
use App\EmployeeItemRequestStatus;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Employee;
use App\Models\EmployeeItemRequest;
use App\Models\EmployeeItemRequestItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeItemRequestService
{
    /**
     * Create a request for the authenticated employee.
     *
     * Items:
     * [
     *     [
     *         'catalog_item_id' => 1,
     *         'quantity_requested' => 2,
     *         'notes' => null,
     *     ],
     * ]
     */
    public function create(
        int $tenantId,
        User $actor,
        array $items,
        ?int $branchId = null,
        ?string $purpose = null,
        ?string $notes = null,
    ): EmployeeItemRequest {
        $this->assertActorTenant($actor, $tenantId);
        $this->assertPermission($actor, $tenantId, 'employee_item_requests.create');

        return DB::transaction(function () use (
            $tenantId,
            $actor,
            $items,
            $branchId,
            $purpose,
            $notes
        ) {
            $employee = Employee::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            if (! $employee) {
                throw ValidationException::withMessages([
                    'employee' => 'Your user account is not linked to an employee record.',
                ]);
            }

            if ($employee->employment_status !== EmploymentStatus::ACTIVE) {
                throw ValidationException::withMessages([
                    'employee' => 'Only active employees can submit item requests.',
                ]);
            }

            // Use the employee's assigned branch unless another
            // tenant-owned branch is explicitly selected.
            $resolvedBranchId = $branchId ?? $employee->branch_id;

            if ($resolvedBranchId !== null) {
                $branchExists = Branch::query()
                    ->whereKey($resolvedBranchId)
                    ->where('tenant_id', $tenantId)
                    ->exists();

                if (! $branchExists) {
                    throw ValidationException::withMessages([
                        'branch_id' => 'The selected branch does not belong to this tenant.',
                    ]);
                }
            }

            if (empty($items)) {
                throw ValidationException::withMessages([
                    'items' => 'At least one item is required.',
                ]);
            }

            $catalogItemIds = collect($items)
                ->pluck('catalog_item_id')
                ->map(fn ($id) => (int) $id);

            if ($catalogItemIds->contains(0)) {
                throw ValidationException::withMessages([
                    'items' => 'Every item must have a valid catalog item ID.',
                ]);
            }

            if ($catalogItemIds->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'A catalog item can only appear once in a request.',
                ]);
            }

            foreach ($items as $index => $item) {
                $quantity = (float) ($item['quantity_requested'] ?? 0);

                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity_requested" =>
                            'Requested quantity must be greater than zero.',
                    ]);
                }
            }

            $catalogItems = CatalogItem::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $catalogItemIds)
                ->get()
                ->keyBy('id');

            if ($catalogItems->count() !== $catalogItemIds->count()) {
                throw ValidationException::withMessages([
                    'items' => 'One or more selected catalog items do not belong to this tenant.',
                ]);
            }

            $request = EmployeeItemRequest::create([
                'tenant_id' => $tenantId,
                'branch_id' => $resolvedBranchId,
                'requested_by_employee_id' => $employee->id,
                'request_number' => $this->generateRequestNumber(),
                'status' => EmployeeItemRequestStatus::PENDING,
                'purpose' => $purpose,
                'notes' => $notes,
            ]);

            foreach ($items as $item) {
                EmployeeItemRequestItem::create([
                    'tenant_id' => $tenantId,
                    'employee_item_request_id' => $request->id,
                    'catalog_item_id' => (int) $item['catalog_item_id'],
                    'quantity_requested' => $item['quantity_requested'],
                    'quantity_approved' => 0,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            return $request->fresh([
                'employee.user:id,name,email',
                'branch',
                'items.catalogItem',
            ]);
        });
    }

    /**
     * Approve all requested quantities or selected quantities.
     *
     * $approvedQuantities is keyed by request item ID:
     * [
     *     10 => 5,
     *     11 => 2,
     * ]
     *
     * If null, all requested quantities are approved.
     */
    public function approve(
        int $tenantId,
        User $actor,
        int $requestId,
        ?array $approvedQuantities = null,
    ): EmployeeItemRequest {
        $this->assertActorTenant($actor, $tenantId);
        $this->assertPermission($actor, $tenantId, 'employee_item_requests.approve');

        return DB::transaction(function () use (
            $tenantId,
            $actor,
            $requestId,
            $approvedQuantities
        ) {
            $request = EmployeeItemRequest::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== EmployeeItemRequestStatus::PENDING) {
                throw ValidationException::withMessages([
                    'request' => 'Only pending requests can be approved.',
                ]);
            }

            $requester = Employee::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($request->requested_by_employee_id)
                ->with('user')
                ->firstOrFail();

            if ((int) $requester->user_id === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'request' => 'You cannot approve your own item request.',
                ]);
            }

            $requestItems = EmployeeItemRequestItem::query()
                ->where('tenant_id', $tenantId)
                ->where('employee_item_request_id', $request->id)
                ->lockForUpdate()
                ->get();

            if ($requestItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'request' => 'This request has no items to approve.',
                ]);
            }

            $approvedCount = 0;
            $fullyApproved = true;

            foreach ($requestItems as $requestItem) {
                $quantity = $approvedQuantities === null
                    ? (float) $requestItem->quantity_requested
                    : (float) ($approvedQuantities[$requestItem->id] ?? 0);

                $requested = (float) $requestItem->quantity_requested;

                if ($quantity < 0 || $quantity > $requested) {
                    throw ValidationException::withMessages([
                        "approved_quantities.{$requestItem->id}" =>
                            'Approved quantity cannot be negative or exceed the requested quantity.',
                    ]);
                }

                $requestItem->quantity_approved = $quantity;
                $requestItem->save();

                if ($quantity > 0) {
                    $approvedCount++;
                }

                if (abs($quantity - $requested) > 0.000001) {
                    $fullyApproved = false;
                }
            }

            if ($approvedCount === 0) {
                throw ValidationException::withMessages([
                    'approved_quantities' =>
                        'At least one item must have an approved quantity greater than zero. Reject the request if nothing is approved.',
                ]);
            }

            $request->status = $fullyApproved
                ? EmployeeItemRequestStatus::APPROVED
                : EmployeeItemRequestStatus::PARTIALLY_APPROVED;

            $request->approved_by_user_id = $actor->id;
            $request->approved_at = now();
            $request->rejection_reason = null;
            $request->save();

            return $request->fresh([
                'employee.user:id,name,email',
                'approvedBy:id,name,email',
                'branch',
                'items.catalogItem',
            ]);
        });
    }

    /**
     * Reject a pending request.
     */
    public function reject(
        int $tenantId,
        User $actor,
        int $requestId,
        string $reason,
    ): EmployeeItemRequest {
        $this->assertActorTenant($actor, $tenantId);
        $this->assertPermission($actor, $tenantId, 'employee_item_requests.approve');

        return DB::transaction(function () use (
            $tenantId,
            $actor,
            $requestId,
            $reason
        ) {
            $request = EmployeeItemRequest::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== EmployeeItemRequestStatus::PENDING) {
                throw ValidationException::withMessages([
                    'request' => 'Only pending requests can be rejected.',
                ]);
            }

            $requester = Employee::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($request->requested_by_employee_id)
                ->firstOrFail();

            if ((int) $requester->user_id === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'request' => 'You cannot reject your own item request.',
                ]);
            }

            if (trim($reason) === '') {
                throw ValidationException::withMessages([
                    'rejection_reason' => 'A rejection reason is required.',
                ]);
            }

            $request->status = EmployeeItemRequestStatus::REJECTED;
            $request->approved_by_user_id = $actor->id;
            $request->approved_at = now();
            $request->rejection_reason = $reason;
            $request->save();

            return $request->fresh([
                'employee.user:id,name,email',
                'approvedBy:id,name,email',
                'items.catalogItem',
            ]);
        });
    }

    /**
     * Cancel a request by its requester or an authorized manager.
     */
    public function cancel(
        int $tenantId,
        User $actor,
        int $requestId,
    ): EmployeeItemRequest {
        $this->assertActorTenant($actor, $tenantId);

        return DB::transaction(function () use (
            $tenantId,
            $actor,
            $requestId
        ) {
            $request = EmployeeItemRequest::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($requestId)
                ->lockForUpdate()
                ->firstOrFail();

            $requester = Employee::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($request->requested_by_employee_id)
                ->firstOrFail();

            $isRequester = (int) $requester->user_id === (int) $actor->id;

            if (! $isRequester) {
                $this->assertPermission(
                    $actor,
                    $tenantId,
                    'employee_item_requests.manage'
                );
            }

            if (! in_array($request->status, [
                EmployeeItemRequestStatus::PENDING,
                EmployeeItemRequestStatus::APPROVED,
                EmployeeItemRequestStatus::PARTIALLY_APPROVED,
            ], true)) {
                throw ValidationException::withMessages([
                    'request' => 'This request can no longer be cancelled.',
                ]);
            }

            $request->status = EmployeeItemRequestStatus::CANCELLED;
            $request->save();

            return $request->fresh([
                'employee.user:id,name,email',
                'items.catalogItem',
            ]);
        });
    }

    private function assertActorTenant(User $actor, int $tenantId): void
    {
        if ((int) $actor->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'tenant_id' => 'You cannot perform this action for another tenant.',
            ]);
        }
    }

    private function assertPermission(
        User $actor,
        int $tenantId,
        string $permission
    ): void {
        setPermissionsTeamId($tenantId);

        if (! $actor->hasPermissionTo($permission, 'web')) {
            throw ValidationException::withMessages([
                'permission' => 'You are not authorized to perform this action.',
            ]);
        }
    }

    private function generateRequestNumber(): string
    {
        return 'EIR-' . now()->format('Ymd') . '-' . Str::upper(Str::random(8));
    }
}