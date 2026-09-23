<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Invoice;
use App\Models\Inventory;
use App\Models\Expense;
use App\Models\InvoiceItem;
use App\Models\Tenant;
use App\InvoicePaymentMethod;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

public function test_dashboard_sales_totals_are_scoped_to_the_tenant(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $item = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'selling_price' => 100,
    ]);

    $otherItem = CatalogItem::factory()->create([
        'tenant_id' => $otherTenant->id,
        'selling_price' => 500,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'subtotal' => 100,
        'total' => 100,
    ]);

    InvoiceItem::create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'catalog_item_id' => $item->id,
        'quantity' => 1,
        'unit_price' => 100,
        'discount' => 0,
        'tax' => 0,
        'line_total' => 100,
    ]);

    $otherUser = User::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $otherInvoice = Invoice::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
        'created_by' => $otherUser->id,
        'status' => 'issued',
        'subtotal' => 500,
        'total' => 500,
    ]);

    InvoiceItem::create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'catalog_item_id' => $item->id,
        'quantity' => 1,
        'unit_price' => 100,
        'discount' => 0,
        'tax' => 0,
        'line_total' => 100,
    ]);

    $dashboard = app(DashboardService::class);

    $result = $dashboard->summary($tenant->id);

    $this->assertSame(100.0, (float) $result['sales_total']);
}
public function test_dashboard_calculates_outstanding_invoice_balance(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'payment_status' => 'partially_paid',
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    InvoicePayment::create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'recorded_by' => $user->id,
        'amount' => 300,
        'method' => InvoicePaymentMethod::CASH,
        'reference' => 'PAY-TEST-001',
        'paid_at' => now(),
    ]);

    $dashboard = app(DashboardService::class);

    $result = $dashboard->summary($tenant->id);

    $this->assertSame(700.0, (float) $result['outstanding_invoice_total']);
}
public function test_dashboard_does_not_duplicate_invoice_total_when_invoice_has_multiple_payments(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'payment_status' => 'partially_paid',
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    InvoicePayment::create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'recorded_by' => $user->id,
        'amount' => 300,
        'method' => InvoicePaymentMethod::CASH,
        'reference' => 'PAY-MULTI-001',
        'paid_at' => now(),
    ]);

    InvoicePayment::create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'recorded_by' => $user->id,
        'amount' => 200,
        'method' => InvoicePaymentMethod::BANK_TRANSFER,
        'reference' => 'PAY-MULTI-002',
        'paid_at' => now(),
    ]);

    $dashboard = app(DashboardService::class);

    $result = $dashboard->summary($tenant->id);

    $this->assertSame(500.0, (float) $result['outstanding_invoice_total']);
}
public function test_dashboard_calculates_tenant_expense_total(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Expense::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'amount' => 250,
    ]);

    Expense::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'amount' => 150,
    ]);

    $otherUser = User::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    Expense::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
        'created_by' => $otherUser->id,
        'amount' => 1000,
    ]);

    $dashboard = app(DashboardService::class);

    $result = $dashboard->summary($tenant->id);

    $this->assertSame(400.0, (float) $result['expense_total']);
}
public function test_dashboard_respects_date_range_for_sales_and_expenses(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    // $item = CatalogItem::factory()->create([
    //     'tenant_id' => $tenant->id,
    // ]);

    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'total' => 100,
        'issued_at' => '2026-09-10 10:00:00',
    ]);

    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'total' => 500,
        'issued_at' => '2026-08-10 10:00:00',
    ]);

    Expense::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'amount' => 50,
        'expense_date' => '2026-09-10',
    ]);

    Expense::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'amount' => 200,
        'expense_date' => '2026-08-10',
    ]);

    $dashboard = app(DashboardService::class);

    $result = $dashboard->summary(
        $tenant->id,
        '2026-09-01',
        '2026-09-30'
    );

    $this->assertSame(100.0, (float) $result['sales_total']);
    $this->assertSame(50.0, (float) $result['expense_total']);
}
public function test_dashboard_calculates_gross_profit_from_product_sales(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $item = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'cost_price' => 60,
        'selling_price' => 100,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'subtotal' => 1000,
        'total' => 1000,
        'issued_at' => '2026-09-10 10:00:00',
    ]);

    InvoiceItem::create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'catalog_item_id' => $item->id,
        'quantity' => 10,
        'unit_price' => 100,
        'discount' => 0,
        'tax' => 0,
        'line_total' => 1000,
    ]);

    $dashboard = app(DashboardService::class);

    $result = $dashboard->summary(
        $tenant->id,
        '2026-09-01',
        '2026-09-30'
    );

    $this->assertSame(400.0, (float) $result['gross_profit']);
}
public function test_dashboard_includes_service_revenue_in_gross_profit_without_product_cogs(): void
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
        'cost_price' => 0,
        'selling_price' => 500,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'subtotal' => 500,
        'total' => 500,
        'issued_at' => '2026-09-15 10:00:00',
    ]);

    InvoiceItem::create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'catalog_item_id' => $service->id,
        'quantity' => 1,
        'unit_price' => 500,
        'discount' => 0,
        'tax' => 0,
        'line_total' => 500,
    ]);

    $dashboard = app(DashboardService::class);

    $result = $dashboard->summary(
        $tenant->id,
        '2026-09-01',
        '2026-09-30'
    );

    $this->assertSame(500.0, (float) $result['gross_profit']);
}
public function test_dashboard_calculates_inventory_value_from_product_stock(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $product = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'cost_price' => 40,
    ]);

    $service = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'service',
        'cost_price' => 500,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $product->id,
        'quantity' => 10,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $service->id,
        'quantity' => 5,
    ]);

    $dashboard = app(DashboardService::class);

    $result = $dashboard->summary($tenant->id);

    $this->assertSame(400.0, (float) $result['inventory_value']);
}
public function test_dashboard_returns_low_stock_product_count(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $lowStockProduct = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'cost_price' => 50,
    ]);

    $healthyProduct = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'cost_price' => 50,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $lowStockProduct->id,
        'quantity' => 3,
        'reorder_level' => 5,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $healthyProduct->id,
        'quantity' => 10,
        'reorder_level' => 5,
    ]);

    $dashboard = app(DashboardService::class);

    $result = $dashboard->summary($tenant->id);

    $this->assertSame(1, $result['low_stock_count']);
}
public function test_dashboard_calculates_sales_by_branch(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branchOne = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branchTwo = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branchOne->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'total' => 1000,
        'issued_at' => '2026-09-10 10:00:00',
    ]);

    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branchTwo->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'total' => 600,
        'issued_at' => '2026-09-10 10:00:00',
    ]);

    $dashboard = app(DashboardService::class);

    $result = $dashboard->summary(
        $tenant->id,
        '2026-09-01',
        '2026-09-30'
    );

    $this->assertSame(
        [
            $branchOne->id => 1000.0,
            $branchTwo->id => 600.0,
        ],
        $result['sales_by_branch']
    );
}
public function test_dashboard_calculates_payment_totals_by_method(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'total' => 1000,
        'issued_at' => '2026-09-10 10:00:00',
    ]);

    InvoicePayment::create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'recorded_by' => $user->id,
        'amount' => 300,
        'method' => InvoicePaymentMethod::CASH,
        'reference' => 'PAY-METHOD-001',
        'paid_at' => '2026-09-10 12:00:00',
    ]);

    InvoicePayment::create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'recorded_by' => $user->id,
        'amount' => 400,
        'method' => InvoicePaymentMethod::MOBILE_MONEY,
        'reference' => 'PAY-METHOD-002',
        'paid_at' => '2026-09-11 12:00:00',
    ]);

    $dashboard = app(DashboardService::class);

    $result = $dashboard->summary(
        $tenant->id,
        '2026-09-01',
        '2026-09-30'
    );

    $this->assertSame(
        [
            InvoicePaymentMethod::CASH->value => 300.0,
            InvoicePaymentMethod::MOBILE_MONEY->value => 400.0,
        ],
        $result['payments_by_method']
    );
}
}