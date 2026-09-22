<?php

namespace Tests\Feature;

use App\FulfillmentStatus;
use App\InventoryMovementType;
use App\OrderStatus;
use App\PaymentStatus;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\FulfillmentRequest;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OrderFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OrderFulfillmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderFulfillmentService $fulfillmentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fulfillmentService = app(OrderFulfillmentService::class);
    }

    public function test_order_can_be_fully_fulfilled_when_stock_is_available(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 10,
            'reorder_level' => 2,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::CONFIRMED,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 750,
            'total' => 750,
        ]);

        $order->items()->create([
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $product->id,
            'quantity' => 5,
            'unit_price' => 150,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 750,
        ]);

        $result = $this->fulfillmentService->fulfill($order);

        $this->assertSame(
            OrderStatus::FULFILLED,
            $result->status
        );

        $this->assertDatabaseHas('inventories', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 5,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'type' => InventoryMovementType::SALE->value,
            'quantity' => 5,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
        ]);

        $this->assertDatabaseCount('fulfillment_requests', 0);
    }

    public function test_order_is_partially_fulfilled_when_stock_is_insufficient(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 5,
            'reorder_level' => 2,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::CONFIRMED,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 1200,
            'total' => 1200,
        ]);

        $orderItem = $order->items()->create([
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $product->id,
            'quantity' => 8,
            'unit_price' => 150,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 1200,
        ]);

        $result = $this->fulfillmentService->fulfill($order);

        $this->assertSame(
            OrderStatus::PARTIALLY_FULFILLED,
            $result->status
        );

        $this->assertDatabaseHas('inventories', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 0,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'type' => InventoryMovementType::SALE->value,
            'quantity' => 5,
        ]);

        $this->assertDatabaseHas('fulfillment_requests', [
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'requested_quantity' => 8,
            'available_quantity' => 5,
            'fulfilled_quantity' => 5,
            'shortfall_quantity' => 3,
            'status' => FulfillmentStatus::PARTIALLY_FULFILLED->value,
        ]);
    }

    public function test_order_with_no_available_stock_creates_pending_fulfillment_request(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 0,
            'reorder_level' => 2,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
            'subtotal' => 500,
            'total' => 500,
        ]);

        $orderItem = $order->items()->create([
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $product->id,
            'quantity' => 5,
            'unit_price' => 100,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 500,
        ]);

        $result = $this->fulfillmentService->fulfill($order);

        $this->assertSame(
            OrderStatus::PARTIALLY_FULFILLED,
            $result->status
        );

        $this->assertDatabaseHas('fulfillment_requests', [
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'requested_quantity' => 5,
            'available_quantity' => 0,
            'fulfilled_quantity' => 0,
            'shortfall_quantity' => 5,
            'status' => FulfillmentStatus::PENDING->value,
        ]);

        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_service_items_do_not_affect_inventory(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $service = CatalogItem::factory()->service()->create([
            'tenant_id' => $tenant->id,
            'track_inventory' => false,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::CONFIRMED,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 1500,
            'total' => 1500,
        ]);

        $order->items()->create([
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $service->id,
            'quantity' => 1,
            'unit_price' => 1500,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 1500,
        ]);

        $result = $this->fulfillmentService->fulfill($order);

        $this->assertSame(
            OrderStatus::FULFILLED,
            $result->status
        );

        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('fulfillment_requests', 0);
    }

    public function test_cancelled_order_cannot_be_fulfilled(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 10,
            'reorder_level' => 2,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::CANCELLED,
            'payment_status' => PaymentStatus::UNPAID,
            'subtotal' => 500,
            'total' => 500,
        ]);

        $order->items()->create([
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $product->id,
            'quantity' => 5,
            'unit_price' => 100,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 500,
        ]);

        $this->expectException(RuntimeException::class);

        $this->fulfillmentService->fulfill($order);

        $this->assertDatabaseHas('inventories', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 10,
        ]);
    }

    public function test_multiple_items_are_fulfilled_together(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        $secondProduct = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 10,
            'reorder_level' => 2,
        ]);

        Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $secondProduct->id,
            'quantity' => 3,
            'reorder_level' => 1,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::CONFIRMED,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 1300,
            'total' => 1300,
        ]);

        $order->items()->createMany([
            [
                'tenant_id' => $tenant->id,
                'catalog_item_id' => $product->id,
                'quantity' => 5,
                'unit_price' => 100,
                'discount' => 0,
                'tax' => 0,
                'line_total' => 500,
            ],
            [
                'tenant_id' => $tenant->id,
                'catalog_item_id' => $secondProduct->id,
                'quantity' => 3,
                'unit_price' => 266.67,
                'discount' => 0,
                'tax' => 0,
                'line_total' => 800,
            ],
        ]);

        $result = $this->fulfillmentService->fulfill($order);

        $this->assertSame(
            OrderStatus::FULFILLED,
            $result->status
        );

        $this->assertDatabaseHas('inventories', [
            'catalog_item_id' => $product->id,
            'quantity' => 5,
        ]);

        $this->assertDatabaseHas('inventories', [
            'catalog_item_id' => $secondProduct->id,
            'quantity' => 0,
        ]);

        $this->assertDatabaseCount('fulfillment_requests', 0);
    }

    public function test_fulfillment_respects_tenant_boundaries(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $productB = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $order = Order::create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'created_by' => $userA->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
            'subtotal' => 500,
            'total' => 500,
        ]);

        $order->items()->create([
            'tenant_id' => $tenantA->id,
            'catalog_item_id' => $productB->id,
            'quantity' => 1,
            'unit_price' => 500,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 500,
        ]);

        $this->expectException(RuntimeException::class);

        $this->fulfillmentService->fulfill($order);
    }
}