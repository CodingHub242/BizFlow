<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\OrderPaymentMethod;
use App\PaymentStatus;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\FulfillmentStatus;
use App\OrderStatus;
use Tests\TestCase;

class SalesApiTest extends TestCase
{
    use RefreshDatabase;

    private function grantPermission(
        User $user,
        string $permission,
        int $tenantId
    ): void {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenantId);

        $permissionModel = Permission::firstOrCreate([
            'name' => $permission,
            'guard_name' => 'web',
        ]);

        $user->givePermissionTo($permissionModel);
    }

    public function test_authorized_user_can_create_order(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
            'selling_price' => 150,
            'is_active' => true,
        ]);

        $this->grantPermission($user, 'sales.create', $tenant->id);

        $response = $this->actingAs($user)->postJson('/api/sales', [
            'branch_id' => $branch->id,
            'order_number' => 'SALE-0001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonPath('data.created_by', $user->id)
            ->assertJsonPath('data.total', '300.00');

        $this->assertDatabaseHas('orders', [
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'order_number' => 'SALE-0001',
        ]);
    }

    public function test_order_creation_requires_at_least_one_item(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->grantPermission($user, 'sales.create', $tenant->id);

        $response = $this->actingAs($user)->postJson('/api/sales', [
            'branch_id' => $branch->id,
            'order_number' => 'SALE-EMPTY',
            'items' => [],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseMissing('orders', [
            'tenant_id' => $tenant->id,
            'order_number' => 'SALE-EMPTY',
        ]);
    }

    public function test_user_cannot_view_another_tenants_order(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $order = Order::factory()->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'created_by' => $userA->id,
        ]);

        $userB = User::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $this->grantPermission($userB, 'sales.view', $tenantB->id);

        $response = $this->actingAs($userB)->getJson(
            "/api/sales/{$order->id}"
        );

        $response->assertNotFound();
    }

   
public function test_user_without_sales_permission_is_forbidden(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $registrar = app(
        \Spatie\Permission\PermissionRegistrar::class
    );

    $registrar->setPermissionsTeamId($tenant->id);

    \Spatie\Permission\Models\Permission::firstOrCreate([
        'name' => 'sales.view',
        'guard_name' => 'web',
    ]);

    $this->actingAs($user)
        ->getJson('/api/sales')
        ->assertForbidden();

    $registrar->setPermissionsTeamId(null);
}

public function test_order_creation_rejects_another_tenants_branch(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $branchB = Branch::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $productA = CatalogItem::factory()->create([
        'tenant_id' => $tenantA->id,
        'is_active' => true,
    ]);

    $this->grantPermission($userA, 'sales.create', $tenantA->id);

    $this->actingAs($userA)->postJson('/api/sales', [
        'branch_id' => $branchB->id,
        'order_number' => 'SALE-CROSS-BRANCH',
        'items' => [
            [
                'catalog_item_id' => $productA->id,
                'quantity' => 1,
            ],
        ],
    ])->assertNotFound();

    $this->assertDatabaseMissing('orders', [
        'tenant_id' => $tenantA->id,
        'order_number' => 'SALE-CROSS-BRANCH',
    ]);
}

public function test_order_creation_rejects_another_tenants_customer(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $branchA = Branch::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $productA = CatalogItem::factory()->create([
        'tenant_id' => $tenantA->id,
        'is_active' => true,
    ]);

    $customerB = \App\Models\Customer::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $this->grantPermission($userA, 'sales.create', $tenantA->id);

    $this->actingAs($userA)->postJson('/api/sales', [
        'branch_id' => $branchA->id,
        'customer_id' => $customerB->id,
        'order_number' => 'SALE-CROSS-CUSTOMER',
        'items' => [
            [
                'catalog_item_id' => $productA->id,
                'quantity' => 1,
            ],
        ],
    ])->assertUnprocessable()
      ->assertJsonValidationErrors('customer_id');

    $this->assertDatabaseMissing('orders', [
        'tenant_id' => $tenantA->id,
        'order_number' => 'SALE-CROSS-CUSTOMER',
    ]);
}

public function test_order_creation_rejects_another_tenants_catalog_item(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $branchA = Branch::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $productB = CatalogItem::factory()->create([
        'tenant_id' => $tenantB->id,
        'is_active' => true,
    ]);

    $this->grantPermission($userA, 'sales.create', $tenantA->id);

    $this->actingAs($userA)->postJson('/api/sales', [
        'branch_id' => $branchA->id,
        'order_number' => 'SALE-CROSS-PRODUCT',
        'items' => [
            [
                'catalog_item_id' => $productB->id,
                'quantity' => 1,
            ],
        ],
    ])->assertUnprocessable()
      ->assertJsonValidationErrors('items.0.catalog_item_id');

    $this->assertDatabaseMissing('orders', [
        'tenant_id' => $tenantA->id,
        'order_number' => 'SALE-CROSS-PRODUCT',
    ]);
}

public function test_order_creation_ignores_forged_tenant_and_creator_ids(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $branchA = Branch::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $productA = CatalogItem::factory()->create([
        'tenant_id' => $tenantA->id,
        'selling_price' => 150,
        'is_active' => true,
    ]);

    $this->grantPermission($userA, 'sales.create', $tenantA->id);

    $response = $this->actingAs($userA)->postJson('/api/sales', [
        'tenant_id' => $tenantB->id,
        'created_by' => 999999,
        'branch_id' => $branchA->id,
        'order_number' => 'SALE-FORGED-IDS',
        'items' => [
            [
                'catalog_item_id' => $productA->id,
                'quantity' => 1,
            ],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.tenant_id', $tenantA->id)
        ->assertJsonPath('data.created_by', $userA->id);

    $this->assertDatabaseHas('orders', [
        'tenant_id' => $tenantA->id,
        'created_by' => $userA->id,
        'order_number' => 'SALE-FORGED-IDS',
    ]);
}
public function test_authorized_user_can_record_full_payment(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'total' => 300.00,
        'payment_status' => PaymentStatus::UNPAID,
    ]);

    $this->grantPermission($user, 'sales.update', $tenant->id);

    $response = $this->actingAs($user)->postJson(
        "/api/sales/{$order->id}/payments",
        [
            'amount' => 300.00,
            'method' => OrderPaymentMethod::CASH->value,
            'reference' => 'PAY-001',
            'paid_at' => now()->toDateTimeString(),
        ]
    );

    $response->assertCreated()
        ->assertJsonPath('data.amount', '300.00')
        ->assertJsonPath('payment_status', PaymentStatus::PAID->value);

    $this->assertDatabaseHas('order_payments', [
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'recorded_by' => $user->id,
        'amount' => '300.00',
        'reference' => 'PAY-001',
    ]);

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'payment_status' => PaymentStatus::PAID->value,
    ]);
}

public function test_authorized_user_can_record_partial_payment(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'total' => 300.00,
        'payment_status' => PaymentStatus::UNPAID,
    ]);

    $this->grantPermission($user, 'sales.update', $tenant->id);

    $response = $this->actingAs($user)->postJson(
        "/api/sales/{$order->id}/payments",
        [
            'amount' => 100.00,
            'method' => OrderPaymentMethod::MOBILE_MONEY->value,
            'reference' => 'PAY-002',
            'paid_at' => now()->toDateTimeString(),
        ]
    );

    $response->assertCreated()
        ->assertJsonPath('data.amount', '100.00')
        ->assertJsonPath(
            'payment_status',
            PaymentStatus::PARTIALLY_PAID->value
        );
}

public function test_payment_cannot_exceed_order_balance(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'total' => 300.00,
        'payment_status' => PaymentStatus::UNPAID,
    ]);

    $this->grantPermission($user, 'sales.update', $tenant->id);

    $response = $this->actingAs($user)->postJson(
        "/api/sales/{$order->id}/payments",
        [
            'amount' => 350.00,
            'method' => OrderPaymentMethod::CASH->value,
            'reference' => 'PAY-003',
            'paid_at' => now()->toDateTimeString(),
        ]
    );

    $response->assertUnprocessable();

    $this->assertDatabaseMissing('order_payments', [
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'reference' => 'PAY-003',
    ]);
}

public function test_duplicate_payment_reference_is_rejected(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'total' => 300.00,
        'payment_status' => PaymentStatus::UNPAID,
    ]);

    $this->grantPermission($user, 'sales.update', $tenant->id);

    $payload = [
        'amount' => 100.00,
        'method' => OrderPaymentMethod::CASH->value,
        'reference' => 'DUPLICATE-REF',
        'paid_at' => now()->toDateTimeString(),
    ];

    $this->actingAs($user)
        ->postJson("/api/sales/{$order->id}/payments", $payload)
        ->assertCreated();

    $this->actingAs($user)
        ->postJson("/api/sales/{$order->id}/payments", $payload)
        ->assertUnprocessable();

    $this->assertDatabaseCount('order_payments', 1);
}

public function test_user_cannot_record_payment_for_another_tenants_order(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $ownerA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $order = Order::factory()->create([
        'tenant_id' => $tenantA->id,
        'created_by' => $ownerA->id,
        'total' => 300.00,
        'payment_status' => PaymentStatus::UNPAID,
    ]);

    $this->grantPermission($userB, 'sales.update', $tenantB->id);

    $this->actingAs($userB)
        ->postJson("/api/sales/{$order->id}/payments", [
            'amount' => 100.00,
            'method' => OrderPaymentMethod::CASH->value,
            'reference' => 'CROSS-TENANT-REF',
            'paid_at' => now()->toDateTimeString(),
        ])
        ->assertNotFound();

    $this->assertDatabaseMissing('order_payments', [
        'reference' => 'CROSS-TENANT-REF',
    ]);
}

public function test_user_without_sales_update_permission_cannot_record_payment(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'total' => 300.00,
        'payment_status' => PaymentStatus::UNPAID,
    ]);

    // Register the permission so the middleware returns 403 rather than
    // failing because the permission does not exist in the test database.
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->id);

    Permission::firstOrCreate([
        'name' => 'sales.update',
        'guard_name' => 'web',
    ]);

    $this->actingAs($user)
        ->postJson("/api/sales/{$order->id}/payments", [
            'amount' => 100.00,
            'method' => OrderPaymentMethod::CASH->value,
            'reference' => 'NO-PERMISSION-REF',
            'paid_at' => now()->toDateTimeString(),
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('order_payments', [
        'reference' => 'NO-PERMISSION-REF',
    ]);

    $registrar->setPermissionsTeamId(null);
}
public function test_authorized_user_can_fulfill_order_and_deduct_stock(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $product = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'track_inventory' => true,
        'is_active' => true,
    ]);

    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $product->id,
        'quantity' => 10,
    ]);

    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => OrderStatus::DRAFT,
    ]);

    OrderItem::create([
    'tenant_id' => $tenant->id,
    'order_id' => $order->id,
    'catalog_item_id' => $product->id,
    'quantity' => 3,
    'unit_price' => $product->selling_price,
    'discount' => 0,
    'tax' => 0,
    'line_total' => 3 * $product->selling_price,
]);

    $this->grantPermission($user, 'sales.update', $tenant->id);

    $this->actingAs($user)
        ->postJson("/api/sales/{$order->id}/fulfill")
        ->assertOk()
        ->assertJsonPath('message', 'Order fulfillment processed.');

    $this->assertEquals(
        7.0,
        (float) $inventory->fresh()->quantity
    );

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => OrderStatus::FULFILLED->value,
    ]);

    $this->assertDatabaseHas('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $product->id,
        'reference_id' => $order->id,
        'quantity' => 3,
    ]);
}
public function test_repeated_fulfillment_does_not_deduct_stock_twice(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $product = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'track_inventory' => true,
        'is_active' => true,
    ]);

    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $product->id,
        'quantity' => 10,
    ]);

    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => OrderStatus::DRAFT,
    ]);

    OrderItem::create([
    'tenant_id' => $tenant->id,
    'order_id' => $order->id,
    'catalog_item_id' => $product->id,
    'quantity' => 3,
    'unit_price' => $product->selling_price,
    'discount' => 0,
    'tax' => 0,
    'line_total' => 3 * $product->selling_price,
]);

    $this->grantPermission($user, 'sales.update', $tenant->id);

    $url = "/api/sales/{$order->id}/fulfill";

    $this->actingAs($user)->postJson($url)->assertOk();
    $this->postJson($url)->assertOk();

    $this->assertEquals(
        7.0,
        (float) $inventory->fresh()->quantity
    );

    $this->assertDatabaseCount('fulfillment_requests', 1);

    $this->assertDatabaseCount('inventory_movements', 1);
}
public function test_fulfillment_records_shortfall_without_negative_stock(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $product = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'track_inventory' => true,
        'is_active' => true,
    ]);

    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $product->id,
        'quantity' => 2,
    ]);

    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => OrderStatus::DRAFT,
    ]);

   OrderItem::create([
    'tenant_id' => $tenant->id,
    'order_id' => $order->id,
    'catalog_item_id' => $product->id,
    'quantity' => 5,
    'unit_price' => $product->selling_price,
    'discount' => 0,
    'tax' => 0,
    'line_total' => 3 * $product->selling_price,
]);

    $this->grantPermission($user, 'sales.update', $tenant->id);

    $this->actingAs($user)
        ->postJson("/api/sales/{$order->id}/fulfill")
        ->assertOk();

    $this->assertEquals(
        0.0,
        (float) $inventory->fresh()->quantity
    );

    $this->assertDatabaseHas('fulfillment_requests', [
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'requested_quantity' => 5,
        'fulfilled_quantity' => 2,
        'shortfall_quantity' => 3,
        'status' => FulfillmentStatus::PARTIALLY_FULFILLED->value,
    ]);

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => OrderStatus::PARTIALLY_FULFILLED->value,
    ]);
}
public function test_user_cannot_fulfill_another_tenants_order(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $ownerA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $branchA = Branch::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $order = Order::factory()->create([
        'tenant_id' => $tenantA->id,
        'branch_id' => $branchA->id,
        'created_by' => $ownerA->id,
    ]);

    $this->grantPermission($userB, 'sales.update', $tenantB->id);

    $this->actingAs($userB)
        ->postJson("/api/sales/{$order->id}/fulfill")
        ->assertNotFound();
}
public function test_user_without_sales_update_permission_cannot_fulfill(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
    ]);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->id);

    Permission::firstOrCreate([
        'name' => 'sales.update',
        'guard_name' => 'web',
    ]);

    $this->actingAs($user)
        ->postJson("/api/sales/{$order->id}/fulfill")
        ->assertForbidden();

    $registrar->setPermissionsTeamId(null);
}
}
