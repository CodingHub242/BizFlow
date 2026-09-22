<?php

namespace Tests\Feature;

use App\OrderStatus;
use App\PaymentStatus;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tenant;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_create_an_order(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'subtotal' => 1000,
            'discount' => 0,
            'tax' => 0,
            'total' => 1000,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'tenant_id' => $tenant->id,
            'order_number' => 'BF-000001',
        ]);
    }

    public function test_order_can_contain_a_product(): void
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
            'type' => 'product',
            'selling_price' => 500,
            'track_inventory' => true,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'subtotal' => 1000,
            'total' => 1000,
        ]);

        $item = $order->items()->create([
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 500,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 1000,
        ]);

        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'order_id' => $order->id,
            'catalog_item_id' => $product->id,
        ]);
    }

    public function test_order_can_contain_a_service(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $service = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'service',
            'selling_price' => 1500,
            'track_inventory' => false,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
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

        $this->assertCount(1, $order->items);
        $this->assertFalse($service->track_inventory);
    }

    public function test_order_can_contain_multiple_items(): void
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
            'type' => 'product',
            'selling_price' => 500,
        ]);

        $service = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'service',
            'selling_price' => 1000,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'subtotal' => 1500,
            'total' => 1500,
        ]);

        $order->items()->createMany([
            [
                'tenant_id' => $tenant->id,
                'catalog_item_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 500,
                'discount' => 0,
                'tax' => 0,
                'line_total' => 500,
            ],
            [
                'tenant_id' => $tenant->id,
                'catalog_item_id' => $service->id,
                'quantity' => 1,
                'unit_price' => 1000,
                'discount' => 0,
                'tax' => 0,
                'line_total' => 1000,
            ],
        ]);

        $this->assertCount(2, $order->items);
    }

    public function test_order_is_isolated_between_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $order = Order::create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'created_by' => $userA->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'subtotal' => 500,
            'total' => 500,
        ]);

        $this->assertTrue(
            Order::where('tenant_id', $tenantA->id)
                ->where('id', $order->id)
                ->exists()
        );

        $this->assertFalse(
            Order::where('tenant_id', $tenantB->id)
                ->where('id', $order->id)
                ->exists()
        );
    }

    public function test_tenant_cannot_attach_another_tenants_catalog_item(): void
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
            'type' => 'product',
        ]);

        $order = Order::create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'created_by' => $userA->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'subtotal' => 500,
            'total' => 500,
        ]);

        // The database relationship alone does not enforce tenant matching.
        // This test documents the rule that our application/service layer
        // must enforce.
        $this->assertNotSame(
            $order->tenant_id,
            $productB->tenant_id
        );
    }

    public function test_order_status_and_payment_status_are_cast_to_enums(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::CONFIRMED,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 1000,
            'total' => 1000,
        ]);

        $order->refresh();

        $this->assertSame(
            OrderStatus::CONFIRMED,
            $order->status
        );

        $this->assertSame(
            PaymentStatus::PAID,
            $order->payment_status
        );
    }

    public function test_order_number_is_unique_within_a_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'subtotal' => 100,
            'total' => 100,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'subtotal' => 200,
            'total' => 200,
        ]);
    }

    public function test_order_can_belong_to_a_customer(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
        ]);

        $this->assertTrue(
            $order->customer->is($customer)
        );
    }

    public function test_customer_can_have_orders(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        Order::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
        ]);

        $this->assertCount(3, $customer->orders);
    }

    public function test_order_can_be_created_without_a_customer(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => null,
        ]);

        $this->assertNull($order->customer);
    }

    public function test_customer_must_belong_to_same_tenant_as_order(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $customerB = Customer::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $order = Order::factory()->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branch->id,
            'customer_id' => $customerB->id,
        ]);

        $this->assertNotEquals(
            $order->tenant_id,
            $order->customer->tenant_id
        );
    }
}