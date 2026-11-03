<?php

namespace Tests\Feature;

use App\EmploymentStatus;
use App\EmployeeItemRequestStatus;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Employee;
use App\Models\EmployeeItemRequest;
use App\Models\EmployeeItemRequestItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\EmployeeItemRequestReturn;
use App\Models\InventoryBatchAllocation;
use App\Models\EmployeeItemRequestReturnItem;
use App\Models\EmployeeItemRequestIssue;
use App\Models\EmployeeItemRequestIssueItem;
use Illuminate\Validation\ValidationException;
use App\Services\EmployeeItemRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EmployeeItemRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    protected EmployeeItemRequestService $service;

   private function prepareReturnScenario(
    float $quantity = 5,
    float $stockQuantity = 10
): array {
    $data = $this->prepareIssueScenario(
        requestedQuantity: $quantity,
        stockQuantity: $stockQuantity
    );

    $this->grantPermission(
        $data['officer'],
        $data['tenant']->id,
        'employee_item_requests.return'
    );

    $this->service->issueItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [$data['requestItem']->id => $quantity],
        $data['branch']->id
    );

    $issueItem = EmployeeItemRequestIssueItem::query()
        ->where(
            'employee_item_request_item_id',
            $data['requestItem']->id
        )
        ->firstOrFail();

    $allocation = InventoryBatchAllocation::query()
        ->where('tenant_id', $data['tenant']->id)
        ->where('source_type', 'employee_item_request_issue_item')
        ->where('source_id', $issueItem->id)
        ->firstOrFail();

    $data['issueItem'] = $issueItem;
    $data['batch'] = InventoryBatch::findOrFail(
        $allocation->inventory_batch_id
    );

    return $data;
}

    private function prepareIssueScenario(
    float $requestedQuantity = 5,
    float $stockQuantity = 10,
    ?float $approvedQuantity = null
): array {
    $tenant = $this->createTenant();

    $employeeUser = $this->createUser($tenant);
    $employee = $this->createEmployee($tenant, $employeeUser);

    $officer = $this->createUser($tenant);
    $this->grantPermission(
        $officer,
        $tenant->id,
        'employee_item_requests.issue'
    );

    $manager = $this->createUser($tenant);
    $this->grantPermission(
        $manager,
        $tenant->id,
        'employee_item_requests.approve'
    );

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'track_inventory' => true,
    ]);

    $request = $this->createRequest(
        $tenant,
        $employeeUser,
        $branch,
        $catalogItem,
        $requestedQuantity
    );

    $requestItem = $request->items()->first();

    $this->service->approve(
        $tenant->id,
        $manager,
        $request->id,
        [
            $requestItem->id => $approvedQuantity ?? $requestedQuantity,
        ]
    );

    app(\App\Services\InventoryService::class)->receiveStock(
        $tenant->id,
        $branch->id,
        $catalogItem->id,
        $stockQuantity
    );

    return compact(
        'tenant',
        'employeeUser',
        'officer',
        'manager',
        'branch',
        'catalogItem',
        'request',
        'requestItem'
    );
}

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->service = app(EmployeeItemRequestService::class);
    }

    private function createTenant(): Tenant
    {
        return Tenant::factory()->create();
    }

    private function createUser(Tenant $tenant): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
    }

    private function createEmployee(
        Tenant $tenant,
        User $user,
        ?Branch $branch = null,
        string $employeeNumber = 'EMP-001'
    ): Employee {
        return Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'branch_id' => $branch?->id,
            'employee_number' => $employeeNumber,
            'phone' => '0240000000',
            'job_title' => 'Staff',
            'employment_status' => EmploymentStatus::ACTIVE,
            'hired_at' => now()->toDateString(),
        ]);
    }

    private function grantPermission(
        User $user,
        int $tenantId,
        string $permissionName
    ): void {
        setPermissionsTeamId($tenantId);

        // $permission = Permission::findOrCreate(
        //     $permissionName,
        //     'web'
        // );

        $permissionModel = Permission::findOrCreate($permissionName, 'web');

        $user->givePermissionTo($permissionModel);
    }

    private function createRequest(
        Tenant $tenant,
        User $employeeUser,
        Branch $branch,
        CatalogItem $catalogItem,
        float $quantity = 5
    ): EmployeeItemRequest {
        $this->grantPermission(
            $employeeUser,
            $tenant->id,
            'employee_item_requests.create'
        );

        return $this->service->create(
            $tenant->id,
            $employeeUser,
            [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity_requested' => $quantity,
                ],
            ],
            $branch->id,
            'Employee operational needs',
            null
        );
    }

    private function assertPermission(User $actor, string $permission): void
{
    setPermissionsTeamId($actor->tenant_id);

    $permissionExists = Permission::query()
        ->where('name', $permission)
        ->where('guard_name', 'web')
        ->exists();

    if (! $permissionExists || ! $actor->hasPermissionTo($permission, 'web')) {
        throw ValidationException::withMessages([
            'permission' => 'You are not authorized to perform this action.',
        ]);
    }
}

   
    public function test_active_employee_can_create_an_item_request(): void
    {
        $tenant = $this->createTenant();
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = $this->createUser($tenant);

        $this->createEmployee(
            $tenant,
            $user,
            $branch
        );

        $catalogItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $request = $this->createRequest(
            $tenant,
            $user,
            $branch,
            $catalogItem,
            5
        );

        $this->assertDatabaseHas('employee_item_requests', [
            'id' => $request->id,
            'tenant_id' => $tenant->id,
            'status' => EmployeeItemRequestStatus::PENDING->value,
        ]);

        $this->assertDatabaseHas('employee_item_request_items', [
            'employee_item_request_id' => $request->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity_requested' => 5,
            'quantity_approved' => 0,
        ]);
    }

  
    public function test_user_without_employee_record_cannot_create_request(): void
    {
        $this->expectException(ValidationException::class);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $this->grantPermission(
            $user,
            $tenant->id,
            'employee_item_requests.create'
        );

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $catalogItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->service->create(
            $tenant->id,
            $user,
            [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity_requested' => 2,
                ],
            ],
            $branch->id
        );
    }

   
    public function test_employee_cannot_request_catalog_item_from_another_tenant(): void
    {
        $this->expectException(ValidationException::class);

        $tenant = $this->createTenant();
        $otherTenant = $this->createTenant();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = $this->createUser($tenant);

        $this->createEmployee($tenant, $user, $branch);

        $this->grantPermission(
            $user,
            $tenant->id,
            'employee_item_requests.create'
        );

        $catalogItem = CatalogItem::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $this->service->create(
            $tenant->id,
            $user,
            [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity_requested' => 2,
                ],
            ]
        );
    }

   
    public function test_duplicate_catalog_items_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $tenant = $this->createTenant();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = $this->createUser($tenant);

        $this->createEmployee($tenant, $user, $branch);

        $this->grantPermission(
            $user,
            $tenant->id,
            'employee_item_requests.create'
        );

        $catalogItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->service->create(
            $tenant->id,
            $user,
            [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity_requested' => 2,
                ],
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity_requested' => 3,
                ],
            ]
        );
    }

    
    public function test_manager_can_approve_an_entire_request(): void
    {
        $tenant = $this->createTenant();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $employeeUser = $this->createUser($tenant);
        $manager = $this->createUser($tenant);

        $this->createEmployee($tenant, $employeeUser, $branch);

        $catalogItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $request = $this->createRequest(
            $tenant,
            $employeeUser,
            $branch,
            $catalogItem,
            5
        );

        $this->grantPermission(
            $manager,
            $tenant->id,
            'employee_item_requests.approve'
        );

        $approved = $this->service->approve(
            $tenant->id,
            $manager,
            $request->id
        );

        $this->assertEquals(
            EmployeeItemRequestStatus::APPROVED,
            $approved->status
        );

        $this->assertEquals(
            $manager->id,
            $approved->approved_by_user_id
        );

        $this->assertDatabaseHas('employee_item_request_items', [
            'employee_item_request_id' => $request->id,
            'quantity_approved' => 5,
        ]);
    }

    
    public function test_manager_can_partially_approve_a_request(): void
    {
        $tenant = $this->createTenant();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $employeeUser = $this->createUser($tenant);
        $manager = $this->createUser($tenant);

        $this->createEmployee($tenant, $employeeUser, $branch);

        $firstItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $secondItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $request = $this->createRequest(
            $tenant,
            $employeeUser,
            $branch,
            $firstItem,
            5
        );

        EmployeeItemRequestItem::create([
            'tenant_id' => $tenant->id,
            'employee_item_request_id' => $request->id,
            'catalog_item_id' => $secondItem->id,
            'quantity_requested' => 4,
            'quantity_approved' => 0,
        ]);

        $requestItems = $request->items()->get();

        $this->grantPermission(
            $manager,
            $tenant->id,
            'employee_item_requests.approve'
        );

        $approved = $this->service->approve(
            $tenant->id,
            $manager,
            $request->id,
            [
                $requestItems[0]->id => 3,
                $requestItems[1]->id => 0,
            ]
        );

        $this->assertEquals(
            EmployeeItemRequestStatus::PARTIALLY_APPROVED,
            $approved->status
        );
    }

    
    public function test_employee_cannot_approve_own_request(): void
    {
        $this->expectException(ValidationException::class);

        $tenant = $this->createTenant();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $employeeUser = $this->createUser($tenant);

        $this->createEmployee($tenant, $employeeUser, $branch);

        $catalogItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $request = $this->createRequest(
            $tenant,
            $employeeUser,
            $branch,
            $catalogItem
        );

        $this->grantPermission(
            $employeeUser,
            $tenant->id,
            'employee_item_requests.approve'
        );

        $this->service->approve(
            $tenant->id,
            $employeeUser,
            $request->id
        );
    }

    
    public function test_manager_can_reject_request_with_a_reason(): void
    {
        $tenant = $this->createTenant();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $employeeUser = $this->createUser($tenant);
        $manager = $this->createUser($tenant);

        $this->createEmployee($tenant, $employeeUser, $branch);

        $catalogItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $request = $this->createRequest(
            $tenant,
            $employeeUser,
            $branch,
            $catalogItem
        );

        $this->grantPermission(
            $manager,
            $tenant->id,
            'employee_item_requests.approve'
        );

        $rejected = $this->service->reject(
            $tenant->id,
            $manager,
            $request->id,
            'Insufficient justification.'
        );

        $this->assertEquals(
            EmployeeItemRequestStatus::REJECTED,
            $rejected->status
        );

        $this->assertEquals(
            $manager->id,
            $rejected->rejected_by_user_id
        );

        $this->assertNotNull($rejected->rejected_at);

        $this->assertEquals(
            'Insufficient justification.',
            $rejected->rejection_reason
        );
    }

    
    public function test_employee_can_cancel_own_pending_request(): void
    {
        $tenant = $this->createTenant();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $employeeUser = $this->createUser($tenant);

        $this->createEmployee($tenant, $employeeUser, $branch);

        $catalogItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $request = $this->createRequest(
            $tenant,
            $employeeUser,
            $branch,
            $catalogItem
        );

        $cancelled = $this->service->cancel(
            $tenant->id,
            $employeeUser,
            $request->id
        );

        $this->assertEquals(
            EmployeeItemRequestStatus::CANCELLED,
            $cancelled->status
        );

        $this->assertEquals(
            $employeeUser->id,
            $cancelled->cancelled_by_user_id
        );

        $this->assertNotNull($cancelled->cancelled_at);
    }

   
    public function test_unauthorized_user_cannot_cancel_another_employees_request(): void
    {
        $this->expectException(ValidationException::class);

        $tenant = $this->createTenant();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $employeeUser = $this->createUser($tenant);
        $otherUser = $this->createUser($tenant);

        

        $this->createEmployee($tenant, $employeeUser, $branch);

        $catalogItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $request = $this->createRequest(
            $tenant,
            $employeeUser,
            $branch,
            $catalogItem
        );
        // Ensure the permission exists, but do NOT assign it to $otherUser.
Permission::findOrCreate('employee_item_requests.manage', 'web');

$this->service->cancel(
    $tenant->id,
    $otherUser,
    $request->id
);

        $this->service->cancel(
            $tenant->id,
            $otherUser,
            $request->id
        );
    }

    
    public function test_request_creation_and_approval_do_not_change_inventory(): void
    {
        $tenant = $this->createTenant();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $employeeUser = $this->createUser($tenant);
        $manager = $this->createUser($tenant);

        $this->createEmployee($tenant, $employeeUser, $branch);

        $catalogItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        DB::table('inventories')->insert([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 20,
            'reorder_level' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = $this->createRequest(
            $tenant,
            $employeeUser,
            $branch,
            $catalogItem,
            5
        );

        $this->grantPermission(
            $manager,
            $tenant->id,
            'employee_item_requests.approve'
        );

        $this->service->approve(
            $tenant->id,
            $manager,
            $request->id
        );

        $this->assertDatabaseHas('inventories', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 20,
        ]);
    }

    public function test_inventory_officer_can_fully_issue_approved_items(): void
{
    $data = $this->prepareIssueScenario(5, 10);

    $this->service->issueItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [$data['requestItem']->id => 5],
        $data['branch']->id
    );

    $this->assertDatabaseHas('employee_item_request_issues', [
        'tenant_id' => $data['tenant']->id,
        'employee_item_request_id' => $data['request']->id,
        'issued_by_user_id' => $data['officer']->id,
    ]);

    $this->assertDatabaseHas('employee_item_request_issue_items', [
        'employee_item_request_item_id' => $data['requestItem']->id,
        'catalog_item_id' => $data['catalogItem']->id,
        'quantity_issued' => 5,
    ]);

    $inventory = Inventory::where('tenant_id', $data['tenant']->id)
        ->where('branch_id', $data['branch']->id)
        ->where('catalog_item_id', $data['catalogItem']->id)
        ->firstOrFail();

    $this->assertEquals(5, (float) $inventory->quantity);

    $this->assertDatabaseHas('employee_item_requests', [
        'id' => $data['request']->id,
        'status' => EmployeeItemRequestStatus::ISSUED->value,
    ]);
}
public function test_inventory_officer_can_partially_issue_approved_items(): void
{
    $data = $this->prepareIssueScenario(5, 10);

    // First issuance: 2 units.
    $this->service->issueItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [$data['requestItem']->id => 2],
        $data['branch']->id
    );

    $request = $data['request']->fresh();

    $this->assertEquals(
        EmployeeItemRequestStatus::PARTIALLY_ISSUED,
        $request->status
    );

    // Second issuance: remaining 3 units.
    $this->service->issueItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [$data['requestItem']->id => 3],
        $data['branch']->id
    );

    $this->assertEquals(
        EmployeeItemRequestStatus::ISSUED,
        $data['request']->fresh()->status
    );

    $totalIssued = EmployeeItemRequestIssueItem::where(
        'employee_item_request_item_id',
        $data['requestItem']->id
    )->sum('quantity_issued');

    $this->assertEquals(5, (float) $totalIssued);
}
public function test_issue_cannot_exceed_approved_quantity(): void
{
    $data = $this->prepareIssueScenario(
        requestedQuantity: 5,
        stockQuantity: 10,
        approvedQuantity: 3
    );

    $this->expectException(ValidationException::class);

    try {
        $this->service->issueItems(
            $data['tenant']->id,
            $data['officer'],
            $data['request']->id,
            [$data['requestItem']->id => 4],
            $data['branch']->id
        );
    } finally {
        $this->assertDatabaseMissing('employee_item_request_issues', [
            'employee_item_request_id' => $data['request']->id,
        ]);
    }
}
public function test_insufficient_stock_rolls_back_entire_issue(): void
{
    $data = $this->prepareIssueScenario(5, 2);

    try {
        $this->service->issueItems(
            $data['tenant']->id,
            $data['officer'],
            $data['request']->id,
            [$data['requestItem']->id => 5],
            $data['branch']->id
        );

        $this->fail('Expected issuance to fail because stock is insufficient.');
    } catch (ValidationException $exception) {
        $this->assertDatabaseMissing('employee_item_request_issues', [
            'employee_item_request_id' => $data['request']->id,
        ]);

        $this->assertDatabaseMissing('employee_item_request_issue_items', [
            'employee_item_request_item_id' => $data['requestItem']->id,
        ]);

        $inventory = Inventory::where('tenant_id', $data['tenant']->id)
            ->where('branch_id', $data['branch']->id)
            ->where('catalog_item_id', $data['catalogItem']->id)
            ->firstOrFail();

        // The original 2 units must remain untouched.
        $this->assertEquals(2, (float) $inventory->quantity);

        $this->assertEquals(
            EmployeeItemRequestStatus::APPROVED,
            $data['request']->fresh()->status
        );
    }
}
public function test_user_without_issue_permission_cannot_issue_items(): void
{
    $data = $this->prepareIssueScenario(5, 10);

    $unauthorizedUser = $this->createUser($data['tenant']);

    $this->expectException(ValidationException::class);

    $this->service->issueItems(
        $data['tenant']->id,
        $unauthorizedUser,
        $data['request']->id,
        [$data['requestItem']->id => 5],
        $data['branch']->id
    );
}
public function test_issue_records_fifo_batch_allocations(): void
{
    $data = $this->prepareIssueScenario(5, 10);

    $this->service->issueItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [$data['requestItem']->id => 5],
        $data['branch']->id
    );

    $issueItem = EmployeeItemRequestIssueItem::where(
        'employee_item_request_item_id',
        $data['requestItem']->id
    )->firstOrFail();

    $this->assertDatabaseHas('inventory_batch_allocations', [
        'tenant_id' => $data['tenant']->id,
        'source_type' => 'employee_item_request_issue_item',
        'source_id' => $issueItem->id,
        'source_item_id' => $data['requestItem']->id,
        'quantity' => 5,
    ]);
}

public function test_restockable_return_restores_original_inventory_batch(): void
{
    $data = $this->prepareReturnScenario(5, 10);

    $inventoryBefore = Inventory::where('tenant_id', $data['tenant']->id)
        ->where('branch_id', $data['branch']->id)
        ->where('catalog_item_id', $data['catalogItem']->id)
        ->firstOrFail();

    $this->service->returnItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [[
            'issue_item_id' => $data['issueItem']->id,
            'inventory_batch_id' => $data['batch']->id,
            'quantity_returned' => 2,
            'return_condition' => 'restockable',
        ]]
    );

    $inventoryAfter = $inventoryBefore->fresh();

    $this->assertEquals(
        (float) $inventoryBefore->quantity + 2,
        (float) $inventoryAfter->quantity
    );

    $this->assertDatabaseHas('inventory_batches', [
        'id' => $data['batch']->id,
        'quantity_remaining' => 7,
    ]);

    $this->assertDatabaseHas('employee_item_request_return_items', [
        'employee_item_request_issue_item_id' => $data['issueItem']->id,
        'inventory_batch_id' => $data['batch']->id,
        'quantity_returned' => 2,
        'return_condition' => 'restockable',
    ]);
}
public function test_damaged_return_is_recorded_without_restoring_inventory(): void
{
    $data = $this->prepareReturnScenario(5, 10);

    $inventoryBefore = Inventory::where('tenant_id', $data['tenant']->id)
        ->where('branch_id', $data['branch']->id)
        ->where('catalog_item_id', $data['catalogItem']->id)
        ->firstOrFail();

    $batchBefore = $data['batch']->fresh();

    $this->service->returnItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [[
            'issue_item_id' => $data['issueItem']->id,
            'inventory_batch_id' => $data['batch']->id,
            'quantity_returned' => 2,
            'return_condition' => 'damaged',
        ]]
    );

    $this->assertEquals(
        (float) $inventoryBefore->quantity,
        (float) $inventoryBefore->fresh()->quantity
    );

    $this->assertEquals(
        (float) $batchBefore->quantity_remaining,
        (float) $data['batch']->fresh()->quantity_remaining
    );

    $this->assertDatabaseHas('employee_item_request_return_items', [
        'employee_item_request_issue_item_id' => $data['issueItem']->id,
        'return_condition' => 'damaged',
        'quantity_returned' => 2,
    ]);
}
public function test_return_cannot_exceed_outstanding_batch_allocation(): void
{
    $data = $this->prepareReturnScenario(5, 10);

    $this->expectException(ValidationException::class);

    $this->service->returnItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [[
            'issue_item_id' => $data['issueItem']->id,
            'inventory_batch_id' => $data['batch']->id,
            'quantity_returned' => 6,
            'return_condition' => 'restockable',
        ]]
    );
}
public function test_return_rejects_batch_not_allocated_to_issue_item(): void
{
    $data = $this->prepareReturnScenario(5, 10);

   app(\App\Services\InventoryService::class)->receiveStock(
    $data['tenant']->id,
    $data['branch']->id,
    $data['catalogItem']->id,
    5
);

$otherBatch = InventoryBatch::query()
    ->where('tenant_id', $data['tenant']->id)
    ->where('branch_id', $data['branch']->id)
    ->where('catalog_item_id', $data['catalogItem']->id)
    ->where('id', '!=', $data['batch']->id)
    ->latest('id')
    ->firstOrFail();

    $this->expectException(ValidationException::class);

    $this->service->returnItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [[
            'issue_item_id' => $data['issueItem']->id,
            'inventory_batch_id' => $otherBatch->id,
            'quantity_returned' => 1,
            'return_condition' => 'restockable',
        ]]
    );
}
public function test_unauthorized_user_cannot_process_item_returns(): void
{
    $data = $this->prepareReturnScenario(5, 10);

    $unauthorizedUser = $this->createUser($data['tenant']);

    $this->expectException(ValidationException::class);

    $this->service->returnItems(
        $data['tenant']->id,
        $unauthorizedUser,
        $data['request']->id,
        [[
            'issue_item_id' => $data['issueItem']->id,
            'inventory_batch_id' => $data['batch']->id,
            'quantity_returned' => 1,
            'return_condition' => 'restockable',
        ]]
    );
}
public function test_user_cannot_process_returns_for_another_tenant(): void
{
    $data = $this->prepareReturnScenario(5, 10);

    $otherTenant = $this->createTenant();
    $otherUser = $this->createUser($otherTenant);

    $this->grantPermission(
        $otherUser,
        $otherTenant->id,
        'employee_item_requests.return'
    );

    $this->expectException(ValidationException::class);

    $this->service->returnItems(
        $data['tenant']->id,
        $otherUser,
        $data['request']->id,
        [[
            'issue_item_id' => $data['issueItem']->id,
            'inventory_batch_id' => $data['batch']->id,
            'quantity_returned' => 1,
            'return_condition' => 'restockable',
        ]]
    );
}
public function test_unusable_return_is_recorded_without_restoring_inventory(): void
{
    $data = $this->prepareReturnScenario(5, 10);

    $inventoryBefore = Inventory::where('tenant_id', $data['tenant']->id)
        ->where('branch_id', $data['branch']->id)
        ->where('catalog_item_id', $data['catalogItem']->id)
        ->firstOrFail();

    $batchBefore = $data['batch']->fresh();

    $this->service->returnItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [[
            'issue_item_id' => $data['issueItem']->id,
            'inventory_batch_id' => $data['batch']->id,
            'quantity_returned' => 1,
            'return_condition' => 'unusable',
        ]]
    );

    $this->assertEquals(
        (float) $inventoryBefore->quantity,
        (float) $inventoryBefore->fresh()->quantity
    );

    $this->assertEquals(
        (float) $batchBefore->quantity_remaining,
        (float) $data['batch']->fresh()->quantity_remaining
    );

    $this->assertDatabaseHas('employee_item_request_return_items', [
        'employee_item_request_issue_item_id' => $data['issueItem']->id,
        'inventory_batch_id' => $data['batch']->id,
        'quantity_returned' => 1,
        'return_condition' => 'unusable',
    ]);
}
public function test_multiple_partial_returns_cannot_exceed_original_allocation(): void
{
    $data = $this->prepareReturnScenario(5, 10);

    // First return: 2 units.
    $this->service->returnItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [[
            'issue_item_id' => $data['issueItem']->id,
            'inventory_batch_id' => $data['batch']->id,
            'quantity_returned' => 2,
            'return_condition' => 'restockable',
        ]]
    );

    // Second return: remaining 3 units.
    $this->service->returnItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [[
            'issue_item_id' => $data['issueItem']->id,
            'inventory_batch_id' => $data['batch']->id,
            'quantity_returned' => 3,
            'return_condition' => 'damaged',
        ]]
    );

    // A further return must be rejected.
    $this->expectException(ValidationException::class);

    $this->service->returnItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [[
            'issue_item_id' => $data['issueItem']->id,
            'inventory_batch_id' => $data['batch']->id,
            'quantity_returned' => 1,
            'return_condition' => 'restockable',
        ]]
    );
}
public function test_failed_multi_item_return_rolls_back_all_return_records_and_stock(): void
{
    $data = $this->prepareReturnScenario(5, 10);

  $permission = Permission::firstOrCreate([
    'name' => 'employee_item_requests.return',
    'guard_name' => 'web',
]);

app(PermissionRegistrar::class)->setPermissionsTeamId(
    $data['tenant']->id
);

app(PermissionRegistrar::class)->forgetCachedPermissions();

$data['officer']->givePermissionTo($permission);

    $inventoryBefore = Inventory::where('tenant_id', $data['tenant']->id)
        ->where('branch_id', $data['branch']->id)
        ->where('catalog_item_id', $data['catalogItem']->id)
        ->firstOrFail();

    $returnCountBefore = EmployeeItemRequestReturn::where(
        'employee_item_request_id',
        $data['request']->id
    )->count();

    try {
        $this->service->returnItems(
            $data['tenant']->id,
            $data['officer'],
            $data['request']->id,
            [
                [
                    'issue_item_id' => $data['issueItem']->id,
                    'inventory_batch_id' => $data['batch']->id,
                    'quantity_returned' => 2,
                    'return_condition' => 'restockable',
                ],
                [
                    'issue_item_id' => $data['issueItem']->id,
                    'inventory_batch_id' => $data['batch']->id,
                    'quantity_returned' => 4,
                    'return_condition' => 'restockable',
                ],
            ]
        );

        $this->fail('Expected the combined return quantity to be rejected.');
    } catch (ValidationException $exception) {
        $this->assertEquals(
            $returnCountBefore,
            EmployeeItemRequestReturn::where(
                'employee_item_request_id',
                $data['request']->id
            )->count()
        );

        $this->assertDatabaseMissing('employee_item_request_return_items', [
            'employee_item_request_issue_item_id' => $data['issueItem']->id,
        ]);

        $this->assertEquals(
            (float) $inventoryBefore->quantity,
            (float) $inventoryBefore->fresh()->quantity
        );

        $this->assertEquals(
            5,
            (float) $data['batch']->fresh()->quantity_remaining
        );
    }
}
public function test_service_items_cannot_be_returned_as_inventory(): void
{
    $data = $this->prepareIssueScenario(5, 10);

    $permission = Permission::firstOrCreate([
    'name' => 'employee_item_requests.return',
    'guard_name' => 'web',
]);

app(PermissionRegistrar::class)->setPermissionsTeamId(
    $data['tenant']->id
);

app(PermissionRegistrar::class)->forgetCachedPermissions();

$data['officer']->givePermissionTo($permission);
    // Convert the catalog item into a non-inventory service.
    $data['catalogItem']->update([
        'track_inventory' => false,
    ]);

    $this->service->issueItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [$data['requestItem']->id => 5],
        $data['branch']->id
    );

    $issueItem = EmployeeItemRequestIssueItem::where(
        'employee_item_request_item_id',
        $data['requestItem']->id
    )->firstOrFail();

    $this->assertFalse($issueItem->tracks_inventory);

    $batch = InventoryBatch::where('tenant_id', $data['tenant']->id)
        ->where('branch_id', $data['branch']->id)
        ->where('catalog_item_id', $data['catalogItem']->id)
        ->firstOrFail();

    $this->expectException(ValidationException::class);

    $this->service->returnItems(
        $data['tenant']->id,
        $data['officer'],
        $data['request']->id,
        [[
            'issue_item_id' => $issueItem->id,
            'inventory_batch_id' => $batch->id,
            'quantity_returned' => 1,
            'return_condition' => 'restockable',
        ]]
    );
}
public function test_request_cannot_be_cancelled_after_issuance(): void
{
    $data = $this->prepareReturnScenario(5, 10);

    $this->expectException(ValidationException::class);

    try {
        $this->service->cancel(
            $data['tenant']->id,
            $data['employeeUser'],
            $data['request']->id
        );
    } finally {
        $this->assertEquals(
            EmployeeItemRequestStatus::ISSUED,
            $data['request']->fresh()->status
        );

        $this->assertNull(
            $data['request']->fresh()->cancelled_at
        );
    }
}
}
