<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Customer;
use App\CatalogItemType;
use App\InvoicePaymentMethod;
use App\PaymentStatus;
use App\InvoiceStatus;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_create_invoice_with_items(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $product = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $invoice = $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-1001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'tenant_id' => $tenant->id,
            'invoice_number' => 'INV-1001',
            'subtotal' => 1000,
            'discount' => 0,
            'tax' => 0,
            'total' => 1000,
        ]);

        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'catalog_item_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 500,
            'line_total' => 1000,
        ]);
    }

    public function test_invoice_cannot_use_branch_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()
            ->for($tenantA)
            ->create();

        $branchB = Branch::factory()
            ->for($tenantB)
            ->create();

        $userA = User::factory()
            ->for($tenantA)
            ->create();

        $productA = CatalogItem::factory()
            ->for($tenantA)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchB->id,
            'created_by' => $userA->id,
            'invoice_number' => 'INV-CROSS-TENANT-001',
            'items' => [
                [
                    'catalog_item_id' => $productA->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertDatabaseMissing('invoices', [
            'tenant_id' => $tenantA->id,
            'invoice_number' => 'INV-CROSS-TENANT-001',
        ]);
    }

    public function test_invoice_cannot_use_creator_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()
            ->for($tenantA)
            ->create();

        $userB = User::factory()
            ->for($tenantB)
            ->create();

        $productA = CatalogItem::factory()
            ->for($tenantA)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'created_by' => $userB->id,
            'invoice_number' => 'INV-CROSS-TENANT-002',
            'items' => [
                [
                    'catalog_item_id' => $productA->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertDatabaseMissing('invoices', [
            'tenant_id' => $tenantA->id,
            'invoice_number' => 'INV-CROSS-TENANT-002',
        ]);
    }

    public function test_invoice_cannot_use_customer_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()
            ->for($tenantA)
            ->create();

        $userA = User::factory()
            ->for($tenantA)
            ->create();

        $customerB = \App\Models\Customer::factory()
            ->for($tenantB)
            ->create();

        $productA = CatalogItem::factory()
            ->for($tenantA)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'customer_id' => $customerB->id,
            'created_by' => $userA->id,
            'invoice_number' => 'INV-CROSS-TENANT-003',
            'items' => [
                [
                    'catalog_item_id' => $productA->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertDatabaseMissing('invoices', [
            'tenant_id' => $tenantA->id,
            'invoice_number' => 'INV-CROSS-TENANT-003',
        ]);
    }

    public function test_invoice_cannot_use_order_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()
            ->for($tenantA)
            ->create();

        $userA = User::factory()
            ->for($tenantA)
            ->create();

        $orderB = \App\Models\Order::factory()
            ->for($tenantB)
            ->create();

        $productA = CatalogItem::factory()
            ->for($tenantA)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'order_id' => $orderB->id,
            'created_by' => $userA->id,
            'invoice_number' => 'INV-CROSS-TENANT-004',
            'items' => [
                [
                    'catalog_item_id' => $productA->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertDatabaseMissing('invoices', [
            'tenant_id' => $tenantA->id,
            'invoice_number' => 'INV-CROSS-TENANT-004',
        ]);
    }

    public function test_invoice_number_must_be_unique_within_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $product = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-DUPLICATE-001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-DUPLICATE-001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);
    }

    public function test_different_tenants_can_use_same_invoice_number(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()
            ->for($tenantA)
            ->create();

        $branchB = Branch::factory()
            ->for($tenantB)
            ->create();

        $userA = User::factory()
            ->for($tenantA)
            ->create();

        $userB = User::factory()
            ->for($tenantB)
            ->create();

        $productA = CatalogItem::factory()
            ->for($tenantA)
            ->create([
                'selling_price' => 500,
            ]);

        $productB = CatalogItem::factory()
            ->for($tenantB)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $invoiceA = $service->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'created_by' => $userA->id,
            'invoice_number' => 'INV-SAME-001',
            'items' => [
                [
                    'catalog_item_id' => $productA->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $invoiceB = $service->create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'created_by' => $userB->id,
            'invoice_number' => 'INV-SAME-001',
            'items' => [
                [
                    'catalog_item_id' => $productB->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertEquals($tenantA->id, $invoiceA->tenant_id);
        $this->assertEquals($tenantB->id, $invoiceB->tenant_id);
        $this->assertEquals('INV-SAME-001', $invoiceA->invoice_number);
        $this->assertEquals('INV-SAME-001', $invoiceB->invoice_number);
        $this->assertNotEquals($invoiceA->id, $invoiceB->id);
    }

    public function test_invoice_item_quantity_must_be_greater_than_zero(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $product = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-INVALID-QTY-001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 0,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertDatabaseMissing('invoices', [
            'tenant_id' => $tenant->id,
            'invoice_number' => 'INV-INVALID-QTY-001',
        ]);
    }

    public function test_invoice_totals_are_calculated_correctly(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $product = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $invoice = $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-CALC-001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 500,
                    'discount' => 100,
                    'tax' => 80,
                ],
            ],
        ]);

        $this->assertEquals('1000.00', $invoice->subtotal);
        $this->assertEquals('100.00', $invoice->discount);
        $this->assertEquals('80.00', $invoice->tax);
        $this->assertEquals('980.00', $invoice->total);

        $this->assertEquals(
            '980.00',
            $invoice->items()->first()->line_total
        );
    }

    public function test_invoice_totals_are_calculated_across_multiple_items(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $productA = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 500,
            ]);

        $productB = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 200,
            ]);

        $service = app(InvoiceService::class);

        $invoice = $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-MULTI-001',
            'items' => [
                [
                    'catalog_item_id' => $productA->id,
                    'quantity' => 2,
                    'unit_price' => 500,
                    'discount' => 100,
                    'tax' => 80,
                ],
                [
                    'catalog_item_id' => $productB->id,
                    'quantity' => 3,
                    'unit_price' => 200,
                    'discount' => 50,
                    'tax' => 30,
                ],
            ],
        ]);

        // Item A: 2 × 500 = 1000
        // Item B: 3 × 200 = 600
        // Subtotal = 1600
        // Discount = 150
        // Tax = 110
        // Total = 1560

        $this->assertEquals('1600.00', $invoice->subtotal);
        $this->assertEquals('150.00', $invoice->discount);
        $this->assertEquals('110.00', $invoice->tax);
        $this->assertEquals('1560.00', $invoice->total);

        $this->assertCount(2, $invoice->items);
    }

    public function test_invoice_item_total_cannot_be_negative(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $product = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-NEGATIVE-TOTAL-001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 600,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertDatabaseMissing('invoices', [
            'tenant_id' => $tenant->id,
            'invoice_number' => 'INV-NEGATIVE-TOTAL-001',
        ]);

        $this->assertDatabaseCount('invoice_items', 0);
    }

    public function test_invoice_creation_rolls_back_when_an_item_fails(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()
            ->for($tenantA)
            ->create();

        $userA = User::factory()
            ->for($tenantA)
            ->create();

        $productA = CatalogItem::factory()
            ->for($tenantA)
            ->create([
                'selling_price' => 500,
            ]);

        $productB = CatalogItem::factory()
            ->for($tenantB)
            ->create([
                'selling_price' => 300,
            ]);

        $service = app(InvoiceService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'created_by' => $userA->id,
            'invoice_number' => 'INV-ROLLBACK-001',
            'items' => [
                [
                    'catalog_item_id' => $productA->id,
                    'quantity' => 2,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
                [
                    'catalog_item_id' => $productB->id,
                    'quantity' => 1,
                    'unit_price' => 300,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertDatabaseMissing('invoices', [
            'tenant_id' => $tenantA->id,
            'invoice_number' => 'INV-ROLLBACK-001',
        ]);

        $this->assertDatabaseMissing('invoice_items', [
            'tenant_id' => $tenantA->id,
        ]);
    }

    public function test_invoice_item_unit_price_cannot_be_negative(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $product = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-NEGATIVE-PRICE-001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => -100,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertDatabaseMissing('invoices', [
            'tenant_id' => $tenant->id,
            'invoice_number' => 'INV-NEGATIVE-PRICE-001',
        ]);
    }
    
    public function test_invoice_item_discount_and_tax_cannot_be_negative(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $product = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-NEGATIVE-DISCOUNT-001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => -50,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertDatabaseMissing('invoices', [
            'tenant_id' => $tenant->id,
            'invoice_number' => 'INV-NEGATIVE-DISCOUNT-001',
        ]);
    }

    public function test_invoice_item_tax_cannot_be_negative(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $product = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-NEGATIVE-TAX-001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => -25,
                ],
            ],
        ]);

        $this->assertDatabaseMissing('invoices', [
            'tenant_id' => $tenant->id,
            'invoice_number' => 'INV-NEGATIVE-TAX-001',
        ]);
    }

    public function test_invoice_can_contain_a_service_without_inventory(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $serviceItem = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'type' => 'service',
                'track_inventory' => false,
                'selling_price' => 1500,
            ]);

        $service = app(InvoiceService::class);

        $invoice = $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-SERVICE-001',
            'items' => [
                [
                    'catalog_item_id' => $serviceItem->id,
                    'quantity' => 1,
                    'unit_price' => 1500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertEquals('1500.00', $invoice->total);

        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'catalog_item_id' => $serviceItem->id,
            'line_total' => 1500,
        ]);

        $this->assertDatabaseMissing('inventories', [
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $serviceItem->id,
        ]);
    }

    public function test_new_invoice_starts_as_draft_and_unpaid(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $product = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $invoice = $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-LIFECYCLE-001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertEquals(
            \App\InvoiceStatus::DRAFT,
            $invoice->status
        );

        $this->assertEquals(
            \App\PaymentStatus::UNPAID,
            $invoice->payment_status
        );
    }

    public function test_draft_invoice_can_be_issued(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $product = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $invoice = $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-ISSUE-001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $issuedInvoice = $service->issue($invoice);

        $this->assertEquals(
            \App\InvoiceStatus::ISSUED,
            $issuedInvoice->status
        );

        $this->assertNotNull($issuedInvoice->issued_at);

        $this->assertEquals(
            \App\PaymentStatus::UNPAID,
            $issuedInvoice->payment_status
        );
    }

    public function test_issued_invoice_cannot_be_issued_again(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $product = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $invoice = $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-ISSUE-002',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $service->issue($invoice);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->issue($invoice);
    }

    public function test_reissuing_invoice_does_not_change_issued_at(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $product = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 500,
            ]);

        $service = app(InvoiceService::class);

        $invoice = $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-ISSUE-003',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $issuedInvoice = $service->issue($invoice);

        $originalIssuedAt = $issuedInvoice->issued_at;

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        try {
            $service->issue($issuedInvoice);
        } finally {
            $issuedInvoice->refresh();

            $this->assertTrue(
                $issuedInvoice->issued_at->equalTo($originalIssuedAt)
            );
        }
    }

    public function test_invoice_can_receive_a_partial_payment(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $product = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'selling_price' => 1000,
            ]);

        $service = app(InvoiceService::class);

        $invoice = $service->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-PAYMENT-001',
            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $invoice = $service->issue($invoice);

        $payment = $service->recordPayment($invoice, [
            'recorded_by' => $user->id,
            'amount' => 400,
            'method' => 'cash',
            'paid_at' => now(),
        ]);

        $this->assertDatabaseHas('invoice_payments', [
            'id' => $payment->id,
            'invoice_id' => $invoice->id,
            'tenant_id' => $tenant->id,
            'amount' => 400,
            'method' => 'cash',
        ]);

        $invoice->refresh();

        $this->assertEquals(
            \App\PaymentStatus::PARTIALLY_PAID,
            $invoice->payment_status
        );
    }

    public function test_invoice_becomes_paid_when_final_payment_settles_balance(): void
    {
        $tenant = Tenant::factory()->create();

        $invoiceService = app(\App\Services\InvoiceService::class);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $catalogItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-FINAL-PAYMENT-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $invoiceService->recordPayment($invoice, [
            'recorded_by' => $user->id,
            'amount' => 400,
            'method' => InvoicePaymentMethod::CASH,
            'paid_at' => now(),
        ]);

        $invoiceService->recordPayment($invoice->fresh(), [
            'recorded_by' => $user->id,
            'amount' => 600,
            'method' => InvoicePaymentMethod::MOBILE_MONEY,
            'paid_at' => now(),
        ]);

        $invoice->refresh();

        $this->assertSame(
            PaymentStatus::PAID,
            $invoice->payment_status
        );

        $this->assertSame(
            InvoiceStatus::PAID,
            $invoice->status
        );

        $this->assertEquals(
            2,
            $invoice->payments()->count()
        );

        $this->assertEquals(
            1000,
            (float) $invoice->payments()->sum('amount')
        );
    }

    public function test_invoice_payment_cannot_exceed_outstanding_balance(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $catalogItem = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-OVERPAY-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $invoiceService->recordPayment($invoice, [
            'recorded_by' => $user->id,
            'amount' => 1001,
            'method' => InvoicePaymentMethod::CASH,
            'paid_at' => now(),
        ]);
    }

    public function test_invoice_payment_amount_must_be_greater_than_zero(): void
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
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-ZERO-PAYMENT-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $invoiceService->recordPayment($invoice, [
            'recorded_by' => $user->id,
            'amount' => 0,
            'method' => InvoicePaymentMethod::CASH,
            'paid_at' => now(),
        ]);
    }

    public function test_invoice_payment_cannot_use_invoice_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $catalogItemB = CatalogItem::factory()->create([
            'tenant_id' => $tenantB->id,
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoiceB = $invoiceService->create([
            'tenant_id' => $tenantB->id,
            'branch_id' => Branch::factory()->create([
                'tenant_id' => $tenantB->id,
            ])->id,
            'created_by' => User::factory()->create([
                'tenant_id' => $tenantB->id,
            ])->id,
            'invoice_number' => 'INV-TENANT-B-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItemB->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $invoiceService->recordPayment(
            $invoiceB->setAttribute('tenant_id', $tenantA->id),
            [
                'recorded_by' => $userA->id,
                'amount' => 500,
                'method' => InvoicePaymentMethod::CASH,
                'paid_at' => now(),
            ]
        );
    }

    public function test_invoice_payment_cannot_be_recorded_by_user_from_another_tenant(): void
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

        $catalogItemA = CatalogItem::factory()->create([
            'tenant_id' => $tenantA->id,
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'created_by' => $userA->id,
            'invoice_number' => 'INV-CROSS-TENANT-PAYMENT-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItemA->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $invoiceService->recordPayment($invoice, [
            'recorded_by' => $userB->id,
            'amount' => 500,
            'method' => InvoicePaymentMethod::CASH,
            'paid_at' => now(),
        ]);
    }

    public function test_invoice_payment_reference_must_be_unique_within_tenant(): void
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
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-PAY-REF-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $invoiceService->recordPayment($invoice, [
            'recorded_by' => $user->id,
            'amount' => 500,
            'method' => InvoicePaymentMethod::CASH,
            'reference' => 'PAY-REF-001',
            'paid_at' => now(),
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $invoiceService->recordPayment($invoice->fresh(), [
            'recorded_by' => $user->id,
            'amount' => 500,
            'method' => InvoicePaymentMethod::MOBILE_MONEY,
            'reference' => 'PAY-REF-001',
            'paid_at' => now(),
        ]);
    }

    public function test_failed_invoice_payment_does_not_create_payment_record(): void
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
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-ROLLBACK-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $invoiceService->recordPayment($invoice, [
            'recorded_by' => $user->id,
            'amount' => 400,
            'method' => InvoicePaymentMethod::CASH,
            'reference' => 'ROLLBACK-REF-001',
            'paid_at' => now(),
        ]);

        try {
            $invoiceService->recordPayment($invoice->fresh(), [
                'recorded_by' => $user->id,
                'amount' => 600,
                'method' => InvoicePaymentMethod::MOBILE_MONEY,
                'reference' => 'ROLLBACK-REF-001',
                'paid_at' => now(),
            ]);

            $this->fail('Expected duplicate payment reference validation exception.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Expected.
        }

        $invoice->refresh();

        $this->assertEquals(1, $invoice->payments()->count());

        $this->assertEquals(
            400,
            (float) $invoice->payments()->sum('amount')
        );

        $this->assertSame(
            PaymentStatus::PARTIALLY_PAID,
            $invoice->payment_status
        );

        $this->assertSame(
            InvoiceStatus::PARTIALLY_PAID,
            $invoice->status
        );
    }

    public function test_fully_paid_invoice_cannot_receive_another_payment(): void
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
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-PAID-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $invoiceService->recordPayment($invoice, [
            'recorded_by' => $user->id,
            'amount' => 1000,
            'method' => InvoicePaymentMethod::CASH,
            'reference' => 'PAID-REF-001',
            'paid_at' => now(),
        ]);

        $this->assertSame(
            PaymentStatus::PAID,
            $invoice->fresh()->payment_status
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $invoiceService->recordPayment($invoice->fresh(), [
            'recorded_by' => $user->id,
            'amount' => 1,
            'method' => InvoicePaymentMethod::CASH,
            'reference' => 'PAID-REF-002',
            'paid_at' => now(),
        ]);
    }

    public function test_issued_unpaid_invoice_can_be_marked_as_overdue_after_due_date(): void
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
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-OVERDUE-001',
            'due_at' => now()->subDay(),
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $invoiceService->issue($invoice);

        $invoice = $invoice->fresh();

        $invoiceService->markOverdue($invoice);

        $invoice->refresh();

        $this->assertSame(
            InvoiceStatus::OVERDUE,
            $invoice->status
        );

        $this->assertSame(
            PaymentStatus::UNPAID,
            $invoice->payment_status
        );
    }

    public function test_paid_invoice_cannot_be_marked_as_overdue(): void
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
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-PAID-NOT-OVERDUE-001',
            'due_at' => now()->subDay(),
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $invoiceService->issue($invoice);

        $invoiceService->recordPayment($invoice->fresh(), [
            'recorded_by' => $user->id,
            'amount' => 1000,
            'method' => InvoicePaymentMethod::CASH,
            'reference' => 'PAID-NOT-OVERDUE-001',
            'paid_at' => now(),
        ]);

        $invoice = $invoice->fresh();

        $this->assertSame(
            InvoiceStatus::PAID,
            $invoice->status
        );

        $invoiceService->markOverdue($invoice);

        $invoice->refresh();

        $this->assertSame(
            InvoiceStatus::PAID,
            $invoice->status
        );

        $this->assertSame(
            PaymentStatus::PAID,
            $invoice->payment_status
        );
    }

    public function test_partially_paid_invoice_can_be_marked_as_overdue(): void
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
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-PARTIAL-OVERDUE-001',
            'due_at' => now()->subDay(),
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $invoiceService->issue($invoice);

        $invoiceService->recordPayment($invoice->fresh(), [
            'recorded_by' => $user->id,
            'amount' => 400,
            'method' => InvoicePaymentMethod::CASH,
            'reference' => 'PARTIAL-OVERDUE-001',
            'paid_at' => now(),
        ]);

        $invoice = $invoice->fresh();

        $this->assertSame(
            PaymentStatus::PARTIALLY_PAID,
            $invoice->payment_status
        );

        $invoiceService->markOverdue($invoice);

        $invoice->refresh();

        $this->assertSame(
            InvoiceStatus::OVERDUE,
            $invoice->status
        );

        $this->assertSame(
            PaymentStatus::PARTIALLY_PAID,
            $invoice->payment_status
        );
    }

    public function test_draft_invoice_cannot_be_marked_as_overdue(): void
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
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-DRAFT-OVERDUE-001',
            'due_at' => now()->subDay(),
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertSame(
            InvoiceStatus::DRAFT,
            $invoice->status
        );

        $invoiceService->markOverdue($invoice);

        $invoice->refresh();

        $this->assertSame(
            InvoiceStatus::DRAFT,
            $invoice->status
        );
    }

    public function test_invoice_cannot_be_marked_as_overdue_before_due_date(): void
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
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-NOT-YET-DUE-001',
            'due_at' => now()->addDay(),
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $invoiceService->issue($invoice);

        $invoiceService->markOverdue($invoice->fresh());

        $invoice->refresh();

        $this->assertSame(
            InvoiceStatus::ISSUED,
            $invoice->status
        );

        $this->assertSame(
            PaymentStatus::UNPAID,
            $invoice->payment_status
        );
    }

    public function test_draft_invoice_can_be_updated(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $catalogItemA = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 500,
        ]);

        $catalogItemB = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => CatalogItemType::SERVICE,
            'selling_price' => 750,
            'track_inventory' => false,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-UPDATE-001',
            'due_at' => now()->addDays(7),
            'items' => [
                [
                    'catalog_item_id' => $catalogItemA->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $updatedInvoice = $invoiceService->update($invoice, [
            'customer_id' => null,
            'due_at' => now()->addDays(14),
            'notes' => 'Updated draft invoice',
            'items' => [
                [
                    'catalog_item_id' => $catalogItemA->id,
                    'quantity' => 2,
                    'unit_price' => 500,
                    'discount' => 50,
                    'tax' => 0,
                ],
                [
                    'catalog_item_id' => $catalogItemB->id,
                    'quantity' => 1,
                    'unit_price' => 750,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $this->assertEquals(1750, (float) $updatedInvoice->subtotal);
        $this->assertEquals(50, (float) $updatedInvoice->discount);
        $this->assertEquals(1700, (float) $updatedInvoice->total);

        $this->assertEquals(
            'Updated draft invoice',
            $updatedInvoice->notes
        );

        $this->assertEquals(
            2,
            $updatedInvoice->items()->count()
        );

        $this->assertSame(
            InvoiceStatus::DRAFT,
            $updatedInvoice->status
        );
    }

    public function test_issued_invoice_cannot_be_updated(): void
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
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-ISSUED-UPDATE-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $invoiceService->issue($invoice);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $invoiceService->update($invoice->fresh(), [
            'notes' => 'Attempted modification after issuance',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 2,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);
    }

    public function test_partially_paid_invoice_cannot_be_updated(): void
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
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-PARTIAL-UPDATE-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $invoiceService->issue($invoice);

        $invoiceService->recordPayment($invoice->fresh(), [
            'recorded_by' => $user->id,
            'amount' => 400,
            'method' => InvoicePaymentMethod::CASH,
            'reference' => 'PARTIAL-UPDATE-001',
            'paid_at' => now(),
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $invoiceService->update($invoice->fresh(), [
            'notes' => 'Attempted modification after partial payment',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);
    }

    public function test_draft_invoice_can_be_cancelled(): void
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
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-CANCEL-DRAFT-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

       $cancelledInvoice = $invoiceService->cancel($invoice);

        $this->assertSame(
            InvoiceStatus::CANCELLED,
            $cancelledInvoice->status
        );

        $this->assertSame(
            PaymentStatus::UNPAID,
            $cancelledInvoice->payment_status
        );
    }

    public function test_issued_unpaid_invoice_can_be_cancelled(): void
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
            'type' => CatalogItemType::PRODUCT,
            'selling_price' => 1000,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);

        $invoice = $invoiceService->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-CANCEL-ISSUED-001',
            'items' => [
                [
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
        ]);

        $invoiceService->issue($invoice);

        $cancelledInvoice = $invoiceService->cancel($invoice->fresh());

        $this->assertSame(
            InvoiceStatus::CANCELLED,
            $cancelledInvoice->status
        );

        $this->assertSame(
            PaymentStatus::UNPAID,
            $cancelledInvoice->payment_status
        );
    }
}