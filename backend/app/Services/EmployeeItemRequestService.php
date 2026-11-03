<?php

namespace App\Services;

use App\EmploymentStatus;
use App\EmployeeItemRequestStatus;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Employee;
use App\Models\EmployeeItemRequest;
use App\Services\InventoryService;
use App\Models\EmployeeItemRequestItem;
use App\Models\User;
use App\Models\EmployeeItemRequestReturn;
use App\Models\EmployeeItemRequestIssueItem;
use App\Models\EmployeeItemRequestReturnItem;
use App\Models\InventoryBatch;
use App\Models\InventoryBatchAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeItemRequestService
{
    public function __construct(
    private readonly InventoryService $inventoryService
) {}
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
                 if (
                    ! is_array($item) ||
                    ! isset($item['catalog_item_id']) ||
                    ! is_numeric($item['catalog_item_id']) ||
                    (int) $item['catalog_item_id'] <= 0
                ) {
                    throw ValidationException::withMessages([
                        "items.{$index}.catalog_item_id" => 'A valid catalog item is required.',
                    ]);
                }

                if (
                    ! isset($item['quantity_requested']) ||
                    ! is_numeric($item['quantity_requested']) ||
                    (float) $item['quantity_requested'] <= 0
                ) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity_requested" => 'Requested quantity must be greater than zero.',
                    ]);
                }

                // $quantity = (float) ($item['quantity_requested'] ?? 0);

                // if ($quantity <= 0) {
                //     throw ValidationException::withMessages([
                //         "items.{$index}.quantity_requested" =>
                //             'Requested quantity must be greater than zero.',
                //     ]);
                // }

               
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

             if ($approvedQuantities !== null) {
                $validItemIds = $requestItems->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $submittedItemIds = array_map(
                    'intval',
                    array_keys($approvedQuantities)
                );

                $invalidItemIds = array_diff(
                    $submittedItemIds,
                    $validItemIds
                );

                if (! empty($invalidItemIds)) {
                    throw ValidationException::withMessages([
                        'approved_quantities' => 'One or more items do not belong to this request.',
                    ]);
                }
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
            $request->rejected_by_user_id = $actor->id;
            $request->rejected_at = now();
            $request->rejection_reason = $reason;
            $request->save();

            return $request->fresh([
                'employee.user:id,name,email',
                'rejectedBy:id,name,email',
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
            $request->cancelled_by_user_id = $actor->id;
            $request->cancelled_at = now();
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

    /**
 * Issue approved quantities from an employee item request.
 *
 * $items is keyed by employee item request item ID:
 * [
 *     15 => 3,
 *     16 => 2,
 * ]
 */
    public function issueItems(
        int $tenantId,
        User $actor,
        int $requestId,
        array $items,
        ?int $branchId = null,
        ?string $notes = null
    ): EmployeeItemRequest 
    {
       $this->assertActorTenant($actor, $tenantId);

        $this->assertPermission(
            $actor,
            $tenantId,
            'employee_item_requests.issue'
        );

        if (empty($items)) {
            throw ValidationException::withMessages([
                'items' => 'At least one item must be supplied for issuance.',
            ]);
        }

        return DB::transaction(function () use (
            $tenantId,
            $actor,
            $requestId,
            $items,
            $branchId,
            $notes
        ) {
            $request = EmployeeItemRequest::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($request->status, [
                EmployeeItemRequestStatus::APPROVED,
                EmployeeItemRequestStatus::PARTIALLY_APPROVED,
                EmployeeItemRequestStatus::PARTIALLY_ISSUED,
            ], true)) {
                throw ValidationException::withMessages([
                    'request' => 'Only approved requests can be issued.',
                ]);
            }

            // Use the request branch unless an explicit branch is supplied.
            $resolvedBranchId = $branchId ?? $request->branch_id;

            if (! $resolvedBranchId) {
                throw ValidationException::withMessages([
                    'branch_id' => 'A branch is required to issue inventory.',
                ]);
            }

            // Do not allow issuance from a different branch.
            if (
                $request->branch_id !== null &&
                (int) $request->branch_id !== (int) $resolvedBranchId
            ) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Items must be issued from the request branch.',
                ]);
            }

            Branch::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($resolvedBranchId)
                ->firstOrFail();

            $requestItems = EmployeeItemRequestItem::query()
                ->where('tenant_id', $tenantId)
                ->where('employee_item_request_id', $request->id)
                ->whereIn('id', array_keys($items))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($requestItems->count() !== count($items)) {
                throw ValidationException::withMessages([
                    'items' => 'One or more supplied items do not belong to this request.',
                ]);
            }

            // Create the issue header.
            $issue = \App\Models\EmployeeItemRequestIssue::create([
                'tenant_id' => $tenantId,
                'employee_item_request_id' => $request->id,
                'branch_id' => $resolvedBranchId,
                'issue_number' => 'EII-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
                'issued_by_user_id' => $actor->id,
                'issued_at' => now(),
                'notes' => $notes,
            ]);

            foreach ($items as $requestItemId => $quantity) {
                $requestItem = $requestItems->get($requestItemId);

                $quantity = (float) $quantity;

                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$requestItemId}" => 'Issue quantity must be greater than zero.',
                    ]);
                }

                // Calculate quantities already issued for this request item.
                $previouslyIssued = (float)
                    \App\Models\EmployeeItemRequestIssueItem::query()
                        ->where('tenant_id', $tenantId)
                        ->where('employee_item_request_item_id', $requestItem->id)
                        ->whereHas('issue', function ($query) use ($request) {
                            $query->where(
                                'employee_item_request_id',
                                $request->id
                            );
                        })
                        ->sum('quantity_issued');

                $approvedQuantity = (float) $requestItem->quantity_approved;
                $remainingApproved = $approvedQuantity - $previouslyIssued;

                if ($quantity > $remainingApproved + 0.000001) {
                    throw ValidationException::withMessages([
                        "items.{$requestItemId}" =>
                            'Issue quantity exceeds the remaining approved quantity.',
                    ]);
                }

                $catalogItem = CatalogItem::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($requestItem->catalog_item_id)
                    ->firstOrFail();

                // Create the issue item first because its ID is used
                // as the inventory allocation reference.
                $issueItem = \App\Models\EmployeeItemRequestIssueItem::create([
                    'tenant_id' => $tenantId,
                    'employee_item_request_issue_id' => $issue->id,
                    'employee_item_request_item_id' => $requestItem->id,
                    'catalog_item_id' => $catalogItem->id,
                    'quantity_issued' => $quantity,
                    'tracks_inventory' => (bool) $catalogItem->track_inventory,
                    'notes' => $requestItem->notes,
                ]);

                // InventoryService handles FIFO batch consumption and
                // records cost and selling-price snapshots.
                $result = $this->inventoryService->sellStock(
                    $tenantId,
                    $resolvedBranchId,
                    $catalogItem->id,
                    $quantity,
                    'employee_item_request_issue_item',
                    $issueItem->id,
                    $requestItem->id
                );

                // Issuance is all-or-nothing for each submitted line.
                // A shortage rolls back the entire issue transaction.
                if (
                    abs((float) $result['fulfilled_quantity'] - $quantity)
                    > 0.000001
                ) {
                    throw ValidationException::withMessages([
                        "items.{$requestItemId}" =>
                            'Insufficient stock to complete this issuance.',
                    ]);
                }
            }

            // Recalculate the request's overall issuance status.
            $approvedItems = EmployeeItemRequestItem::query()
                ->where('tenant_id', $tenantId)
                ->where('employee_item_request_id', $request->id)
                ->where('quantity_approved', '>', 0)
                ->get();

            $allFullyIssued = true;
            $anyIssued = false;

            foreach ($approvedItems as $approvedItem) {
                $issuedQuantity = (float)
                    \App\Models\EmployeeItemRequestIssueItem::query()
                        ->where('tenant_id', $tenantId)
                        ->where('employee_item_request_item_id', $approvedItem->id)
                        ->whereHas('issue', function ($query) use ($request) {
                            $query->where(
                                'employee_item_request_id',
                                $request->id
                            );
                        })
                        ->sum('quantity_issued');

                $approvedQuantity = (float) $approvedItem->quantity_approved;

                if ($issuedQuantity > 0) {
                    $anyIssued = true;
                }

                if ($issuedQuantity + 0.000001 < $approvedQuantity) {
                    $allFullyIssued = false;
                }
            }

            if ($allFullyIssued && $approvedItems->isNotEmpty()) {
                $request->status = EmployeeItemRequestStatus::ISSUED;
            } elseif ($anyIssued) {
                $request->status = EmployeeItemRequestStatus::PARTIALLY_ISSUED;
            }

            $request->save();

            return $request->fresh([
                'employee.user:id,name,email',
                'branch',
                'items.catalogItem',
                'issues.issuedBy',
                'issues.items.catalogItem',
            ]);
        });
    }

    public function returnItems(
        int $tenantId,
        User $actor,
        int $requestId,
        array $items,
        ?string $notes = null
    ): EmployeeItemRequestReturn 
    {
      $this->assertActorTenant($actor, $tenantId);

        $this->assertPermission(
            $actor,
            $tenantId,
            'employee_item_requests.return'
        );

        return DB::transaction(function () use (
            $tenantId,
            $actor,
            $requestId,
            $items,
            $notes
        ) {
            $request = EmployeeItemRequest::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($requestId);

            if (!in_array($request->status, [
                EmployeeItemRequestStatus::ISSUED,
                EmployeeItemRequestStatus::PARTIALLY_ISSUED,
            ], true)) {
                throw ValidationException::withMessages([
                    'request' => 'Only issued requests can receive returns.',
                ]);
            }

            if (empty($items)) {
                throw ValidationException::withMessages([
                    'items' => 'At least one return item is required.',
                ]);
            }

            $returnNumber = 'EIRTN-' . now()->format('Ymd') . '-'
                . Str::upper(Str::random(6));

            $return = EmployeeItemRequestReturn::create([
                'tenant_id' => $tenantId,
                'employee_item_request_id' => $request->id,
                'return_number' => $returnNumber,
                'received_by_user_id' => $actor->id,
                'returned_at' => now(),
                'notes' => $notes,
            ]);

            foreach ($items as $item) {
                $issueItemId = (int) ($item['issue_item_id'] ?? 0);
                $batchId = (int) ($item['inventory_batch_id'] ?? 0);
                $quantity = (float) ($item['quantity_returned'] ?? 0);
                $condition = $item['return_condition'] ?? null;

                if ($issueItemId <= 0 || $batchId <= 0 || $quantity <= 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Each return must have a valid issue item, batch and positive quantity.',
                    ]);
                }

                if (!in_array($condition, [
                    'restockable',
                    'damaged',
                    'unusable',
                ], true)) {
                    throw ValidationException::withMessages([
                        'return_condition' => 'Invalid return condition.',
                    ]);
                }

                $issueItem = EmployeeItemRequestIssueItem::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($issueItemId)
                    ->whereHas('issue', function ($query) use ($request) {
                        $query->where(
                            'employee_item_request_id',
                            $request->id
                        );
                    })
                    ->lockForUpdate()
                    ->firstOrFail();

                if (!$issueItem->tracks_inventory) {
                    throw ValidationException::withMessages([
                        'items' => 'Service items cannot be returned as inventory.',
                    ]);
                }

                $issue = $issueItem->issue;

                $batch = InventoryBatch::query()
                    ->where('tenant_id', $tenantId)
                    ->where('branch_id', $issue->branch_id)
                    ->where('catalog_item_id', $issueItem->catalog_item_id)
                    ->whereKey($batchId)
                    ->firstOrFail();

                // Confirm that this batch was actually allocated
                // to this particular issue item.
                $allocatedQuantity = InventoryBatchAllocation::query()
                    ->where('tenant_id', $tenantId)
                    ->where(
                        'source_type',
                        'employee_item_request_issue_item'
                    )
                    ->where('source_id', $issueItem->id)
                    ->where('inventory_batch_id', $batchId)
                    ->sum('quantity');

                if ($allocatedQuantity <= 0) {
                    throw ValidationException::withMessages([
                        'inventory_batch_id' => 'This batch was not allocated to the selected issue item.',
                    ]);
                }

                // Calculate previously returned quantity for
                // this issue item and original inventory batch.
                $previouslyReturned = EmployeeItemRequestReturnItem::query()
                    ->where('tenant_id', $tenantId)
                    ->where('employee_item_request_issue_item_id', $issueItem->id)
                    ->where('inventory_batch_id', $batchId)
                    ->whereHas('employeeItemRequestReturn', function ($query) use ($request) {
                        $query->where(
                            'employee_item_request_id',
                            $request->id
                        );
                    })
                    ->sum('quantity_returned');

                $remainingReturnable = (float) $allocatedQuantity
                    - (float) $previouslyReturned;

                if ($quantity > $remainingReturnable + 0.000001) {
                    throw ValidationException::withMessages([
                        'quantity_returned' => 'Returned quantity exceeds the outstanding quantity issued from this batch.',
                    ]);
                }

                $returnItem = EmployeeItemRequestReturnItem::create([
                    'tenant_id' => $tenantId,
                    'employee_item_request_return_id' => $return->id,
                    'employee_item_request_issue_item_id' => $issueItem->id,
                    'inventory_batch_id' => $batchId,
                    'quantity_returned' => $quantity,
                    'return_condition' => $condition,
                    'notes' => $item['notes'] ?? null,
                ]);

                if ($condition === 'restockable') {
                    $this->inventoryService->restoreReturnedStock(
                        $tenantId,
                        $issue->branch_id,
                        $issueItem->catalog_item_id,
                        $batchId,
                        $quantity,
                        $returnItem->id,
                        $item['notes'] ?? null
                    );
                }
            }

            return $return->fresh([
                'employeeItemRequest',
                'receivedBy',
                'items.issueItem.catalogItem',
                'items.inventoryBatch',
            ]);
        });
    }
}