<?php

namespace Tests\Feature;

use App\FulfillmentSourceType;
use App\FulfillmentStatus;
use App\OrderStatus;
use App\PaymentStatus;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\FulfillmentRequest;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FulfillmentRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_fulfillment_can_record_a_stock_shortage(): void
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

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
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

        $fulfillment = FulfillmentRequest::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'requested_quantity' => 8,
            'available_quantity' => 5,
            'fulfilled_quantity' => 5,
            'shortfall_quantity' => 3,
            'status' => FulfillmentStatus::PARTIALLY_FULFILLED,
        ]);

        $this->assertDatabaseHas('fulfillment_requests', [
            'id' => $fulfillment->id,
            'tenant_id' => $tenant->id,
            'requested_quantity' => 8,
            'available_quantity' => 5,
            'fulfilled_quantity' => 5,
            'shortfall_quantity' => 3,
            'status' => FulfillmentStatus::PARTIALLY_FULFILLED->value,
        ]);
    }

    public function test_fulfillment_can_be_sourced_from_another_branch(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $customerBranch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $sourceBranch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $customerBranch->id,
            'created_by' => $user->id,
            'order_number' => 'BF-000001',
            'status' => OrderStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
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

        $fulfillment = FulfillmentRequest::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'branch_id' => $customerBranch->id,
            'catalog_item_id' => $product->id,
            'requested_quantity' => 8,
            'available_quantity' => 5,
            'fulfilled_quantity' => 5,
            'shortfall_quantity' => 3,
            'status' => FulfillmentStatus::SOURCING,
            'source_type' => FulfillmentSourceType::BRANCH,
            'source_branch_id' => $sourceBranch->id,
        ]);

        $this->assertSame(
            FulfillmentSourceType::BRANCH,
            $fulfillment->source_type
        );

        $this->assertTrue(
            $fulfillment->sourceBranch->is($sourceBranch)
        );
    }

    public function test_fulfillment_can_be_completed_after_shortfall_is_resolved(): void
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

        $fulfillment = FulfillmentRequest::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'requested_quantity' => 8,
            'available_quantity' => 5,
            'fulfilled_quantity' => 5,
            'shortfall_quantity' => 3,
            'status' => FulfillmentStatus::PARTIALLY_FULFILLED,
        ]);

        $fulfillment->update([
            'fulfilled_quantity' => 8,
            'shortfall_quantity' => 0,
            'status' => FulfillmentStatus::FULFILLED,
        ]);

        $fulfillment->refresh();

        $this->assertSame(
            FulfillmentStatus::FULFILLED,
            $fulfillment->status
        );

        $this->assertEquals('8.000', $fulfillment->fulfilled_quantity);
        $this->assertEquals('0.000', $fulfillment->shortfall_quantity);
    }

    public function test_fulfillment_isolated_between_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $productA = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenantA->id,
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

        $orderItem = $order->items()->create([
            'tenant_id' => $tenantA->id,
            'catalog_item_id' => $productA->id,
            'quantity' => 5,
            'unit_price' => 100,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 500,
        ]);

        $fulfillment = FulfillmentRequest::create([
            'tenant_id' => $tenantA->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'branch_id' => $branchA->id,
            'catalog_item_id' => $productA->id,
            'requested_quantity' => 5,
            'available_quantity' => 2,
            'fulfilled_quantity' => 2,
            'shortfall_quantity' => 3,
            'status' => FulfillmentStatus::PARTIALLY_FULFILLED,
        ]);

        $this->assertTrue(
            FulfillmentRequest::where('tenant_id', $tenantA->id)
                ->whereKey($fulfillment->id)
                ->exists()
        );

        $this->assertFalse(
            FulfillmentRequest::where('tenant_id', $tenantB->id)
                ->whereKey($fulfillment->id)
                ->exists()
        );
    }

    public function test_fulfillment_status_and_source_type_are_cast_to_enums(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $sourceBranch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
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

        $fulfillment = FulfillmentRequest::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'requested_quantity' => 5,
            'available_quantity' => 2,
            'fulfilled_quantity' => 2,
            'shortfall_quantity' => 3,
            'status' => FulfillmentStatus::SOURCING,
            'source_type' => FulfillmentSourceType::BRANCH,
            'source_branch_id' => $sourceBranch->id,
        ]);

        $fulfillment->refresh();

        $this->assertSame(
            FulfillmentStatus::SOURCING,
            $fulfillment->status
        );

        $this->assertSame(
            FulfillmentSourceType::BRANCH,
            $fulfillment->source_type
        );
    }
}