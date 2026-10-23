<?php

namespace Tests\Feature;

use App\OrderStatus;
use App\PaymentStatus;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Order;
use App\Models\Invoices;
use App\Models\OrderItem;
use App\Services\InvoiceService;
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

    public function test_creating_order_creates_one_linked_draft_invoice(): void
{
    $tenant = Tenant::factory()->create();
    $branch = Branch::factory()->for($tenant)->create();
    $user = User::factory()->for($tenant)->create();

    $product = CatalogItem::factory()
        ->for($tenant)
        ->create(['selling_price' => 100]);

    $order = app(\App\Services\OrderService::class)->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'order_number' => 'ORD-AUTO-001',
        'items' => [
            [
                'catalog_item_id' => $product->id,
                'quantity' => 2,
                'discount' => 0,
                'tax' => 0,
            ],
        ],
    ]);

    $this->assertDatabaseHas('invoices', [
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'customer_id' => null,
        'status' => 'draft',
        'payment_status' => 'unpaid',
        'total' => 200,
    ]);

    $this->assertSame(
        1,
        \App\Models\Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('order_id', $order->id)
            ->count()
    );
}
public function test_order_and_invoice_creation_roll_back_together(): void
{
    $tenant = Tenant::factory()->create();
    $branch = Branch::factory()->for($tenant)->create();
    $user = User::factory()->for($tenant)->create();

    $product = CatalogItem::factory()
        ->for($tenant)
        ->create(['selling_price' => 100]);

    // Force the invoice number to collide with an existing invoice.
    \App\Models\Invoice::factory()
        ->for($tenant)
        ->create([
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-ORD-ROLLBACK-001',
            'order_id' => null,
        ]);

    try {
        app(\App\Services\OrderService::class)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'ORD-ROLLBACK-001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 1,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->fail('Expected invoice creation to fail.');
    } catch (\Illuminate\Validation\ValidationException $e) {
        // Expected: the duplicate invoice number is rejected.
    }

    $this->assertDatabaseMissing('orders', [
        'tenant_id' => $tenant->id,
        'order_number' => 'ORD-ROLLBACK-001',
    ]);
}

public function test_updating_order_synchronizes_its_linked_draft_invoice(): void
{
     $tenant = Tenant::factory()->create();
    $branch = Branch::factory()->for($tenant)->create();
    $user = User::factory()->for($tenant)->create();

     $product = CatalogItem::factory()
        ->for($tenant)
        ->create(['selling_price' => 100]);
    // Arrange: create an order using your existing test helper.
    $order =  app(\App\Services\OrderService::class)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'order_number' => 'ORD-ROLLBACK-001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 1,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

    $invoice = \App\Models\Invoice::query()
        ->where('tenant_id', $order->tenant_id)
        ->where('order_id', $order->id)
        ->firstOrFail();

    $originalInvoiceId = $invoice->id;
    $originalInvoiceNumber = $invoice->invoice_number;

    // Act: update the order using your existing valid update payload.
    app(\App\Services\OrderService::class)->update($order, [
        'branch_id' => $order->branch_id,
        'customer_id' => $order->customer_id,
        'order_number' => $order->order_number,
        'discount' => 5,
        'tax' => 2,
        'notes' => 'Updated order notes',
        'items' => [
            [
                'catalog_item_id' => $order->items->first()->catalog_item_id,
                'quantity' => 3,
                'discount' => 1,
                'tax' => 2,
            ],
        ],
    ]);

    // Assert: same invoice, updated values, no duplicate.
    $invoice->refresh();

    $this->assertSame($originalInvoiceId, $invoice->id);
    $this->assertSame($originalInvoiceNumber, $invoice->invoice_number);
    $this->assertSame('Updated order notes', $invoice->notes);
    $this->assertEquals(1, $invoice->items()->count());

    $this->assertDatabaseHas('invoices', [
        'id' => $originalInvoiceId,
        'order_id' => $order->id,
        'status' => 'draft',
    ]);

    $this->assertSame(
        1,
        \App\Models\Invoice::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('order_id', $order->id)
            ->count()
    );
}

public function test_updating_order_is_rejected_when_linked_invoice_is_issued(): void
{
    [$order, $product] = $this->createOrderForInvoiceProtectionTest(
        'ORD-ISSUED-001'
    );

    $invoice = \App\Models\Invoice::query()
    ->where('tenant_id', $order->tenant_id)
    ->where('order_id', $order->id)
    ->firstOrFail();

    $this->assertNotNull($invoice);

    app(\App\Services\InvoiceService::class)->issue($invoice);

    $originalOrderTotal = $order->fresh()->total;
    $originalInvoiceTotal = $invoice->fresh()->total;
    $originalOrderQuantity = $order->items()->first()->quantity;
    $originalInvoiceQuantity = $invoice->items()->first()->quantity;

    try {
        app(\App\Services\OrderService::class)->update($order, [
            'branch_id' => $order->branch_id,
            'customer_id' => $order->customer_id,
            'order_number' => $order->order_number,
            'discount' => 0,
            'tax' => 0,
            'notes' => 'Attempted edit',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 2,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->fail('Expected the order update to be rejected.');
    } catch (\Illuminate\Validation\ValidationException $e) {
        $this->assertNotEmpty($e->errors());
    }

    $this->assertEquals($originalOrderTotal, $order->fresh()->total);
    $this->assertEquals($originalInvoiceTotal, $invoice->fresh()->total);

    $this->assertEquals(
        $originalOrderQuantity,
        $order->items()->first()->quantity
    );

    $this->assertEquals(
        $originalInvoiceQuantity,
        $invoice->items()->first()->quantity
    );

    $this->assertSame('issued', $invoice->fresh()->status->value);
    $this->assertSame('draft', $order->fresh()->status->value);
    $this->assertNotSame('Attempted edit', $order->fresh()->notes);

    // The original order and invoice line items remain intact.
$this->assertCount(1, $order->fresh()->items);
$this->assertCount(1, $invoice->fresh()->items);

// The issued invoice remains issued and unpaid.
$this->assertSame('issued', $invoice->fresh()->status->value);
$this->assertSame('unpaid', $invoice->fresh()->payment_status->value);

// The order's original notes remain unchanged.
$this->assertNotSame(
    'Attempted edit',
    $order->fresh()->notes
);

// No duplicate linked invoice was created.
$this->assertSame(
    1,
    \App\Models\Invoice::query()
        ->where('tenant_id', $order->tenant_id)
        ->where('order_id', $order->id)
        ->count()
);
}

public function test_updating_order_is_rejected_when_linked_invoice_is_paid(): void
{
    [$order, $product, $user] = $this->createOrderForInvoiceProtectionTest(
        'ORD-PAID-001'
    );

 $invoice = \App\Models\Invoice::query()
    ->where('tenant_id', $order->tenant_id)
    ->where('order_id', $order->id)
    ->firstOrFail();

    $this->assertNotNull($invoice);

    $invoiceService = app(\App\Services\InvoiceService::class);

    $invoiceService->issue($invoice);

    $invoice = $invoice->fresh();

    $invoiceService->recordPayment($invoice, [
        'recorded_by' => $user->id,
        'amount' => $invoice->total,
        'method' => 'cash',
        'paid_at' => now(),
    ]);

    $invoice->refresh();

    $originalOrderTotal = $order->fresh()->total;
    $originalInvoiceTotal = $invoice->total;
    $originalOrderQuantity = $order->items()->first()->quantity;
    $originalInvoiceQuantity = $invoice->items()->first()->quantity;

    try {
        app(\App\Services\OrderService::class)->update($order, [
            'branch_id' => $order->branch_id,
            'customer_id' => $order->customer_id,
            'order_number' => $order->order_number,
            'discount' => 0,
            'tax' => 0,
            'notes' => 'Attempted paid-order edit',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 2,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->fail('Expected the order update to be rejected.');
    } catch (\Illuminate\Validation\ValidationException $e) {
        $this->assertNotEmpty($e->errors());
    }

    $this->assertEquals($originalOrderTotal, $order->fresh()->total);
    $this->assertEquals($originalInvoiceTotal, $invoice->fresh()->total);

    $this->assertEquals(
        $originalOrderQuantity,
        $order->items()->first()->quantity
    );

    $this->assertEquals(
        $originalInvoiceQuantity,
        $invoice->items()->first()->quantity
    );

 $this->assertSame('issued', $invoice->fresh()->status->value);
$this->assertSame('paid', $invoice->fresh()->payment_status->value);
    $this->assertSame('draft', $order->fresh()->status->value);
    $this->assertNotSame('Attempted paid-order edit', $order->fresh()->notes);

    // The original order and invoice line items remain intact.
$this->assertCount(1, $order->fresh()->items);
$this->assertCount(1, $invoice->fresh()->items);

// The invoice remains paid.
$this->assertSame('paid', $invoice->fresh()->payment_status->value);

// The order's original notes remain unchanged.
$this->assertNotSame(
    'Attempted paid-order edit',
    $order->fresh()->notes
);

// No duplicate linked invoice was created.
$this->assertSame(
    1,
    \App\Models\Invoice::query()
        ->where('tenant_id', $order->tenant_id)
        ->where('order_id', $order->id)
        ->count()
);
}

/**
 * Shared fixture for linked-invoice protection tests.
 */
private function createOrderForInvoiceProtectionTest(
    string $orderNumber
): array {
    $tenant = \App\Models\Tenant::factory()->create();

    $user = \App\Models\User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = \App\Models\Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $product = \App\Models\CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'selling_price' => 100,
        'is_active' => true,
    ]);

    $order = app(\App\Services\OrderService::class)->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'order_number' => $orderNumber,
        'items' => [
            [
                'catalog_item_id' => $product->id,
                'quantity' => 1,
                'discount' => 0,
                'tax' => 0,
            ],
        ],
    ]);

    return [$order, $product, $user];
}

}