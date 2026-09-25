<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Tenant;
use App\Models\Order;
use App\Models\Invoice;
use App\InvoiceStatus;
use App\PaymentStatus;
use App\InvoicePaymentMethod;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_invoice(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $catalogItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/invoices', [
                'branch_id' => $branch->id,
                'invoice_number' => 'INV-API-001',
                'items' => [
                    [
                        'catalog_item_id' => $catalogItem->id,
                        'quantity' => 1,
                        'unit_price' => 1000,
                    ],
                ],
            ]);

        $response->assertStatus(201);
    }

    public function test_created_invoice_belongs_to_authenticated_users_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $catalogItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/invoices', [
                'branch_id' => $branch->id,
                'invoice_number' => 'INV-API-TENANT-001',
                'items' => [
                    [
                        'catalog_item_id' => $catalogItem->id,
                        'quantity' => 1,
                        'unit_price' => 1000,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('invoices', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-API-TENANT-001',
        ]);
    }

    public function test_invoice_cannot_use_branch_from_another_tenant(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-API-CROSS-TENANT-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                ],
            ],
        ]);

    $response->assertStatus(422);
}

public function test_invoice_cannot_use_catalog_item_from_another_tenant(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-API-CROSS-TENANT-002',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                ],
            ],
        ]);

    $response->assertStatus(422);
}

public function test_unauthenticated_user_cannot_create_invoice(): void
{
    $response = $this->postJson('/api/invoices', [
        'branch_id' => 1,
        'invoice_number' => 'INV-API-UNAUTH-001',
        'items' => [
            [
                'catalog_item_id' => 1,
                'quantity' => 1,
                'unit_price' => 1000,
            ],
        ],
    ]);

    $response->assertUnauthorized();
}

public function test_invoice_cannot_use_customer_from_another_tenant(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = \App\Models\Customer::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-API-CROSS-TENANT-003',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                ],
            ],
        ]);

    $response->assertStatus(422);
}
public function test_invoice_cannot_use_order_from_another_tenant(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $order = \App\Models\Order::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'invoice_number' => 'INV-API-CROSS-TENANT-004',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                ],
            ],
        ]);

    $response->assertStatus(422);
}

public function test_create_invoice_returns_invoice_data(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'selling_price' => 1000,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-API-RESPONSE-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                ],
            ],
        ]);

    $response
        ->assertStatus(201)
        ->assertJson([
            'success' => true,
            'message' => 'Invoice created successfully.',
        ])
        ->assertJsonPath('data.invoice_number', 'INV-API-RESPONSE-001')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.payment_status', 'unpaid');
}

public function test_create_invoice_returns_invoice_items(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'selling_price' => 1000,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-API-ITEMS-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 2,
                    'unit_price' => 1000,
                ],
            ],
        ]);

    $response
        ->assertStatus(201)
        ->assertJsonPath('data.items.0.catalog_item_id', $catalogItem->id)
        ->assertJsonPath('data.items.0.quantity', '2.000')
        ->assertJsonPath('data.items.0.unit_price', '1000.00');
}

public function test_create_invoice_returns_expected_status_values(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-API-STATUS-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                ],
            ],
        ]);

    $response
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.payment_status', 'unpaid');
}

public function test_create_invoice_requires_invoice_number(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                ],
            ],
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'invoice_number',
        ]);
}

public function test_create_invoice_requires_branch(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/invoices', [
            'invoice_number' => 'INV-API-NO-BRANCH-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                ],
            ],
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'branch_id',
        ]);
}

public function test_create_invoice_requires_items(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-API-NO-ITEMS-001',
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'items',
        ]);
}

public function test_create_invoice_accepts_multiple_items(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $itemOne = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $itemTwo = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-API-MULTI-001',
            'items' => [
                [
                    'catalog_item_id' => $itemOne->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                ],
                [
                    'catalog_item_id' => $itemTwo->id,
                    'quantity' => 3,
                    'unit_price' => 200,
                ],
            ],
        ]);

    $response
        ->assertStatus(201)
        ->assertJsonCount(2, 'data.items');

    $this->assertDatabaseCount('invoice_items', 2);
}

public function test_create_invoice_can_include_customer(): void
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

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-API-CUSTOMER-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                ],
            ],
        ]);

    $response
        ->assertStatus(201)
        ->assertJsonPath('data.customer_id', $customer->id);

    $this->assertDatabaseHas('invoices', [
        'tenant_id' => $tenant->id,
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-API-CUSTOMER-001',
    ]);
}

public function test_create_invoice_can_include_order(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'invoice_number' => 'INV-API-ORDER-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 750,
                ],
            ],
        ]);

    $response
        ->assertStatus(201)
        ->assertJsonPath('data.order_id', $order->id);

    $this->assertDatabaseHas('invoices', [
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'invoice_number' => 'INV-API-ORDER-001',
    ]);
}

public function test_authenticated_user_can_record_invoice_payment(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => InvoiceStatus::ISSUED,
        'payment_status' => PaymentStatus::UNPAID,
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    $invoice->items()->create([
        'tenant_id' => $tenant->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 1,
        'unit_price' => 1000,
        'discount' => 0,
        'tax' => 0,
        'line_total' => 1000,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 1000,
            'method' => InvoicePaymentMethod::CASH->value,
            'reference' => 'PAY-API-001',
        ]);

    $response
        ->assertStatus(201)
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('invoice_payments', [
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'amount' => 1000,
        'method' => InvoicePaymentMethod::CASH->value,
        'reference' => 'PAY-API-001',
    ]);
}

public function test_invoice_payment_cannot_exceed_outstanding_balance(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => InvoiceStatus::ISSUED,
        'payment_status' => PaymentStatus::UNPAID,
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    $invoice->items()->create([
        'tenant_id' => $tenant->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 1,
        'unit_price' => 1000,
        'discount' => 0,
        'tax' => 0,
        'line_total' => 1000,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 1500,
            'method' => InvoicePaymentMethod::CASH->value,
            'reference' => 'PAY-API-OVER-001',
        ]);

    $response = $this
    ->actingAs($user)
    ->postJson("/api/invoices/{$invoice->id}/payments", [
        'amount' => 1500,
        'method' => InvoicePaymentMethod::CASH->value,
        'reference' => 'PAY-API-OVER-001',
    ]);

//$response->dump();

$response->assertStatus(422);
}

public function test_unauthenticated_user_cannot_record_invoice_payment(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => InvoiceStatus::ISSUED,
        'payment_status' => PaymentStatus::UNPAID,
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    $response = $this
        ->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 1000,
            'method' => InvoicePaymentMethod::CASH->value,
            'reference' => 'PAY-API-UNAUTH-001',
        ]);

    $response->assertStatus(401);
}

public function test_invoice_payment_cannot_target_invoice_from_another_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $branchA = Branch::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenantA->id,
        'branch_id' => $branchA->id,
        'created_by' => $userA->id,
        'status' => InvoiceStatus::ISSUED,
        'payment_status' => PaymentStatus::UNPAID,
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    $response = $this
        ->actingAs($userB)
        ->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 1000,
            'method' => InvoicePaymentMethod::CASH->value,
            'reference' => 'PAY-API-CROSS-TENANT-001',
        ]);

   $response
    ->assertStatus(422)
    ->assertJsonValidationErrors([
        'recorded_by',
    ]);

    $this->assertDatabaseMissing('invoice_payments', [
        'invoice_id' => $invoice->id,
        'reference' => 'PAY-API-CROSS-TENANT-001',
    ]);
}

public function test_invoice_payment_requires_positive_amount(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => InvoiceStatus::ISSUED,
        'payment_status' => PaymentStatus::UNPAID,
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 0,
            'method' => InvoicePaymentMethod::CASH->value,
            'reference' => 'PAY-API-ZERO-001',
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'amount',
        ]);
}

public function test_invoice_payment_updates_invoice_to_partially_paid(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => InvoiceStatus::ISSUED,
        'payment_status' => PaymentStatus::UNPAID,
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 500,
            'method' => InvoicePaymentMethod::CASH->value,
            'reference' => 'PAY-API-PARTIAL-001',
        ]);

    $response
        ->assertStatus(201)
        ->assertJsonPath('data.amount', '500.00');

    $this->assertDatabaseHas('invoice_payments', [
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'amount' => 500,
        'reference' => 'PAY-API-PARTIAL-001',
    ]);

    $this->assertDatabaseHas('invoices', [
        'id' => $invoice->id,
        'payment_status' => PaymentStatus::PARTIALLY_PAID->value,
        'status' => InvoiceStatus::PARTIALLY_PAID->value,
    ]);
}

public function test_invoice_payment_updates_invoice_to_paid(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => InvoiceStatus::ISSUED,
        'payment_status' => PaymentStatus::UNPAID,
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 1000,
            'method' => InvoicePaymentMethod::MOBILE_MONEY->value,
            'reference' => 'PAY-API-PAID-001',
        ]);

    $response
        ->assertStatus(201)
        ->assertJsonPath('data.amount', '1000.00')
        ->assertJsonPath('data.method', InvoicePaymentMethod::MOBILE_MONEY->value);

    $this->assertDatabaseHas('invoice_payments', [
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'amount' => 1000,
        'method' => InvoicePaymentMethod::MOBILE_MONEY->value,
        'reference' => 'PAY-API-PAID-001',
    ]);

    $this->assertDatabaseHas('invoices', [
        'id' => $invoice->id,
        'payment_status' => PaymentStatus::PAID->value,
        'status' => InvoiceStatus::PAID->value,
    ]);
}

public function test_authenticated_user_can_view_invoice(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'invoice_number' => 'INV-API-SHOW-001',
        'status' => InvoiceStatus::ISSUED,
        'payment_status' => PaymentStatus::UNPAID,
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    $invoice->items()->create([
        'tenant_id' => $tenant->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 2,
        'unit_price' => 500,
        'discount' => 0,
        'tax' => 0,
        'line_total' => 1000,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/invoices/{$invoice->id}");

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $invoice->id,
                'invoice_number' => 'INV-API-SHOW-001',
                'status' => InvoiceStatus::ISSUED->value,
                'payment_status' => PaymentStatus::UNPAID->value,
                'branch_id' => $branch->id,
                'total' => '1000.00',
            ],
        ])
        ->assertJsonCount(1, 'data.items');
}

public function test_invoice_from_another_tenant_cannot_be_viewed(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $branchA = Branch::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenantA->id,
        'branch_id' => $branchA->id,
        'created_by' => $userA->id,
        'invoice_number' => 'INV-CROSS-TENANT-001',
    ]);

    $response = $this
        ->actingAs($userB)
        ->getJson("/api/invoices/{$invoice->id}");

    $response->assertStatus(404);
}

public function test_authenticated_user_can_list_invoices_for_their_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $branchA = Branch::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $branchB = Branch::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    Invoice::factory()->count(2)->create([
        'tenant_id' => $tenantA->id,
        'branch_id' => $branchA->id,
        'created_by' => $userA->id,
    ]);

    Invoice::factory()->create([
        'tenant_id' => $tenantB->id,
        'branch_id' => $branchB->id,
        'created_by' => $userB->id,
    ]);

    $response = $this
        ->actingAs($userA)
        ->getJson('/api/invoices');

    $response
    ->assertStatus(200)
    ->assertJson([
        'success' => true,
    ])
    ->assertJsonCount(2, 'data');
}

public function test_unauthenticated_user_cannot_list_invoices(): void
{
    $response = $this->getJson('/api/invoices');

    $response->assertStatus(401);
}

public function test_authenticated_user_can_issue_invoice(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => InvoiceStatus::DRAFT,
        'payment_status' => PaymentStatus::UNPAID,
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson("/api/invoices/{$invoice->id}/issue");

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonPath('data.status', InvoiceStatus::ISSUED->value);

    $this->assertDatabaseHas('invoices', [
        'id' => $invoice->id,
        'status' => InvoiceStatus::ISSUED->value,
    ]);
}

public function test_invoice_from_another_tenant_cannot_be_issued(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $branchA = Branch::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenantA->id,
        'branch_id' => $branchA->id,
        'created_by' => $userA->id,
        'status' => InvoiceStatus::DRAFT,
        'payment_status' => PaymentStatus::UNPAID,
    ]);

    $response = $this
        ->actingAs($userB)
        ->postJson("/api/invoices/{$invoice->id}/issue");

    $response->assertStatus(404);

    $this->assertDatabaseHas('invoices', [
        'id' => $invoice->id,
        'tenant_id' => $tenantA->id,
        'status' => InvoiceStatus::DRAFT->value,
    ]);
}

public function test_authenticated_user_can_cancel_draft_invoice(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => InvoiceStatus::DRAFT,
        'payment_status' => PaymentStatus::UNPAID,
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson("/api/invoices/{$invoice->id}/cancel");

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonPath(
            'data.status',
            InvoiceStatus::CANCELLED->value
        );

    $this->assertDatabaseHas('invoices', [
        'id' => $invoice->id,
        'status' => InvoiceStatus::CANCELLED->value,
    ]);
}

public function test_invoice_from_another_tenant_cannot_be_cancelled(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $branchA = Branch::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenantA->id,
        'branch_id' => $branchA->id,
        'created_by' => $userA->id,
        'status' => InvoiceStatus::DRAFT,
        'payment_status' => PaymentStatus::UNPAID,
    ]);

    $response = $this
        ->actingAs($userB)
        ->postJson("/api/invoices/{$invoice->id}/cancel");

    $response->assertStatus(404);

    $this->assertDatabaseHas('invoices', [
        'id' => $invoice->id,
        'tenant_id' => $tenantA->id,
        'status' => InvoiceStatus::DRAFT->value,
    ]);
}

}