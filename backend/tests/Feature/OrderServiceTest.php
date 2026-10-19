<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_service_can_create_order_for_customer(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $order = app(OrderService::class)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-TEST-001',
        ]);

        $this->assertInstanceOf(Order::class, $order);

        $this->assertEquals(
            $customer->id,
            $order->customer_id
        );

        $this->assertEquals(
            $tenant->id,
            $order->tenant_id
        );
    }

    public function test_order_service_can_create_walk_in_order(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $order = app(OrderService::class)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'customer_id' => null,
            'order_number' => 'ORD-WALKIN-001',
        ]);

        $this->assertNull($order->customer_id);
    }

    public function test_order_service_rejects_customer_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $customerB = Customer::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $this->expectException(ValidationException::class);

        app(OrderService::class)->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'created_by' => $userA->id,
            'customer_id' => $customerB->id,
            'order_number' => 'ORD-CROSS-TENANT-001',
        ]);
    }

    public function test_order_service_rejects_branch_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchB = Branch::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(OrderService::class)->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchB->id,
            'created_by' => $userA->id,
            'customer_id' => null,
            'order_number' => 'ORD-CROSS-BRANCH-001',
        ]);
    }

    public function test_order_service_can_create_order_with_product_item(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
            'selling_price' => 150,
            'track_inventory' => true,
        ]);

        $order = app(OrderService::class)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'ORD-ITEM-001',

            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $this->assertCount(1, $order->items);

        $item = $order->items->first();

        $this->assertEquals($product->id, $item->catalog_item_id);
        $this->assertEquals(2.0, $item->quantity);
        $this->assertEquals(150.0, $item->unit_price);
        $this->assertEquals(300.0, $item->line_total);
    }

    public function test_order_service_can_create_order_with_service_item(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $service = CatalogItem::factory()->service()->create([
            'tenant_id' => $tenant->id,
            'selling_price' => 500,
        ]);

        $order = app(OrderService::class)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'ORD-SERVICE-001',

            'items' => [
                [
                    'catalog_item_id' => $service->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $this->assertCount(1, $order->items);

        $item = $order->items->first();

        $this->assertEquals($service->id, $item->catalog_item_id);
        $this->assertEquals(500.0, $item->unit_price);
        $this->assertEquals(500.0, $item->line_total);
    }

    public function test_order_service_rejects_catalog_item_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $productB = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $this->expectException(ValidationException::class);

        app(OrderService::class)->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'created_by' => $userA->id,
            'order_number' => 'ORD-CROSS-CATALOG-001',

            'items' => [
                [
                    'catalog_item_id' => $productB->id,
                    'quantity' => 1,
                ],
            ],
        ]);
    }

    public function test_order_service_rejects_invalid_item_quantity(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->expectException(ValidationException::class);

        app(OrderService::class)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'ORD-INVALID-QTY-001',

            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 0,
                ],
            ],
        ]);
    }

    public function test_order_service_can_update_draft_order_and_recalculate_totals(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $product = CatalogItem::factory()->product()->create([
        'tenant_id' => $tenant->id,
        'selling_price' => 150,
        'track_inventory' => true,
    ]);

    $service = app(OrderService::class);

    $order = $service->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'order_number' => 'ORD-UPDATE-001',
        'items' => [
            [
                'catalog_item_id' => $product->id,
                'quantity' => 2,
            ],
        ],
    ]);

    $updated = $service->update($order, [
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'order_number' => 'ORD-UPDATE-001',
        'discount' => 10,
        'tax' => 5,
        'notes' => 'Updated order',
        'items' => [
            [
                'catalog_item_id' => $product->id,
                'quantity' => 3,
                'discount' => 0,
                'tax' => 0,
            ],
        ],
    ]);

    $this->assertEquals($customer->id, $updated->customer_id);
    $this->assertEquals('Updated order', $updated->notes);
    $this->assertEquals(450.00, (float) $updated->subtotal);
    $this->assertEquals(10.00, (float) $updated->discount);
    $this->assertEquals(5.00, (float) $updated->tax);
    $this->assertEquals(445.00, (float) $updated->total);
    $this->assertCount(1, $updated->items);
    $this->assertEquals(3.0, (float) $updated->items->first()->quantity);
}

public function test_order_service_rejects_updating_order_from_another_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $branchB = Branch::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $orderB = app(OrderService::class)->create([
        'tenant_id' => $tenantB->id,
        'branch_id' => $branchB->id,
        'created_by' => $userB->id,
        'order_number' => 'ORD-TENANT-B-001',
    ]);

    $branchA = Branch::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    try {
        app(OrderService::class)->update($orderB, [
            'branch_id' => $branchA->id,
            'customer_id' => null,
            'order_number' => 'ORD-TENANT-B-001',
            'discount' => 0,
            'tax' => 0,
            'notes' => null,
            'items' => [
                [
                    'catalog_item_id' => CatalogItem::factory()
                        ->product()
                        ->create(['tenant_id' => $tenantA->id])
                        ->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $this->fail('Expected the update to reject a cross-tenant branch.');
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
        $this->assertDatabaseHas('orders', [
            'id' => $orderB->id,
            'tenant_id' => $tenantB->id,
        ]);
    }
}
}