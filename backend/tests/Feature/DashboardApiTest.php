<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\CatalogItem;
use App\Models\Inventory;
use App\Models\Customer;
use App\Models\Invoice;
use App\InvoiceStatus;
use App\Models\InvoicePayment;
use App\Models\Tenant;
use Spatie\Permission\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private function assignRole(
    User $user,
    string $roleName = 'Accountant'
): void {
    setPermissionsTeamId($user->tenant_id);

    $role = Role::query()
        ->where('tenant_id', $user->tenant_id)
        ->where('name', $roleName)
        ->firstOrFail();

    $user->assignRole($role);
}

public function test_authenticated_user_can_retrieve_dashboard_summary(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);$this->assignRole($user);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'total' => 1000,
        'issued_at' => '2026-09-10 10:00:00',
    ]);

    Expense::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'amount' => 250,
        'expense_date' => '2026-09-10',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson(
        '/api/dashboard?date_from=2026-09-01&date_to=2026-09-30'
    );

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.sales_total', 1000)
        ->assertJsonPath('data.expense_total', 250)
        ->assertJsonStructure([
            'success',
            'data' => [
                'sales_total',
                'outstanding_invoice_total',
                'expense_total',
                'gross_profit',
                'inventory_value',
                'low_stock_count',
                'sales_by_branch',
                'payments_by_method',
            ],
        ]);
}
public function test_dashboard_requires_authentication(): void
{
    $response = $this->getJson('/api/dashboard');

    $response->assertUnauthorized();
}
public function test_dashboard_rejects_invalid_date_filters(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);$this->assignRole($user);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/dashboard?date_from=not-a-date');

    $response->assertUnprocessable();
}
public function test_dashboard_only_returns_data_for_authenticated_users_tenant(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);$this->assignRole($user);

    $tenantBranch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherUser = User::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $tenantBranch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'total' => 300,
        'issued_at' => '2026-09-10 10:00:00',
    ]);

    Invoice::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
        'created_by' => $otherUser->id,
        'status' => 'issued',
        'total' => 900,
        'issued_at' => '2026-09-10 10:00:00',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson(
        '/api/dashboard?date_from=2026-09-01&date_to=2026-09-30'
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.sales_total', 300);
}
public function test_dashboard_returns_numeric_financial_values(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);$this->assignRole($user);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/dashboard');

    $response
        ->assertOk()
        ->assertJsonPath('data.sales_total', 0)
        ->assertJsonPath('data.outstanding_invoice_total', 0)
        ->assertJsonPath('data.expense_total', 0)
        ->assertJsonPath('data.gross_profit', 0)
        ->assertJsonPath('data.inventory_value', 0)
        ->assertJsonPath('data.low_stock_count', 0);
}
public function test_dashboard_separates_product_sales_from_services_rendered(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $product = \App\Models\CatalogItem::factory()
        ->product()
        ->create([
            'tenant_id' => $tenant->id,
            'selling_price' => 150,
        ]);

    $service = \App\Models\CatalogItem::factory()
        ->service()
        ->create([
            'tenant_id' => $tenant->id,
            'selling_price' => 500,
        ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'total' => 650,
        'subtotal' => 650,
        'issued_at' => '2026-09-10 10:00:00',
    ]);

    \App\Models\InvoiceItem::query()->create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'catalog_item_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 150,
        'discount' => 0,
        'tax' => 0,
        'line_total' => 150,
    ]);

    \App\Models\InvoiceItem::query()->create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'catalog_item_id' => $service->id,
        'quantity' => 1,
        'unit_price' => 500,
        'discount' => 0,
        'tax' => 0,
        'line_total' => 500,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson(
        '/api/dashboard?date_from=2026-09-01&date_to=2026-09-30'
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.product_sales', 150)
        ->assertJsonPath('data.services_rendered', 500);
}
public function test_dashboard_returns_customer_and_sales_counts(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->for($tenant)->create();

    $this->assignRole($user);

    $this->actingAs($user);

    Customer::factory()
        ->count(3)
        ->for($tenant)
        ->create();

    $branch = Branch::factory()->for($tenant)->create();

    $customer = Customer::factory()->for($tenant)->create();

    Invoice::factory()
        ->for($tenant)
        ->for($branch)
        ->for($customer)
        ->count(2)
        ->create([
            'status' => 'issued',
        ]);

    $response = $this->getJson('/api/dashboard');

    $response
        ->assertOk()
        ->assertJsonPath('data.customer_count', 4)
        ->assertJsonPath('data.sales_count', 2);
}
public function test_dashboard_returns_sales_trend_for_selected_date_range(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'total' => 1000,
        'issued_at' => '2026-09-10 10:00:00',
    ]);

    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'total' => 1500,
        'issued_at' => '2026-09-11 14:00:00',
    ]);

    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'total' => 3000,
        'issued_at' => '2026-09-20 09:00:00',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson(
        '/api/dashboard?date_from=2026-09-10&date_to=2026-09-11'
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.sales_trend.0.date', '2026-09-10')
        ->assertJsonPath('data.sales_trend.0.sales', 1000)
        ->assertJsonPath('data.sales_trend.1.date', '2026-09-11')
        ->assertJsonPath('data.sales_trend.1.sales', 1500);
}
public function test_dashboard_returns_low_stock_items(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $lowStockItem = CatalogItem::factory()
        ->for($tenant)
        ->product()
        ->create([
            'name' => 'Low Stock Product',
            'sku' => 'LOW-001',
        ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $lowStockItem->id,
        'quantity' => 3,
        'reorder_level' => 10,
    ]);

    $healthyItem = CatalogItem::factory()
        ->for($tenant)
        ->product()
        ->create([
            'name' => 'Healthy Stock Product',
            'sku' => 'OK-001',
        ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $healthyItem->id,
        'quantity' => 50,
        'reorder_level' => 10,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/dashboard');

    $response
        ->assertOk()
        ->assertJsonPath('data.low_stock_items.0.id', $lowStockItem->id)
        ->assertJsonPath('data.low_stock_items.0.name', 'Low Stock Product')
        ->assertJsonPath('data.low_stock_items.0.sku', 'LOW-001')
        ->assertJsonPath('data.low_stock_items.0.quantity', 3)
        ->assertJsonPath('data.low_stock_items.0.reorder_level', 10);
}
public function test_dashboard_returns_outstanding_invoices(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'ABC Enterprise',
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'invoice_number' => 'INV-000015',
        'status' => 'issued',
        'payment_status' => 'partially_paid',
        'total' => 2500,
        'issued_at' => '2026-09-20 10:00:00',
        'due_at' => '2026-09-30 00:00:00',
    ]);

    InvoicePayment::factory()->create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'amount' => 1000,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/dashboard');

    $response
        ->assertOk()
        ->assertJsonPath('data.outstanding_invoices.0.id', $invoice->id)
        ->assertJsonPath('data.outstanding_invoices.0.invoice_number', 'INV-000015')
        ->assertJsonPath('data.outstanding_invoices.0.customer_name', 'ABC Enterprise')
        ->assertJsonPath('data.outstanding_invoices.0.total', 2500)
        ->assertJsonPath('data.outstanding_invoices.0.paid', 1000)
        ->assertJsonPath('data.outstanding_invoices.0.outstanding', 1500)
        ->assertJsonPath(
    'data.outstanding_invoices.0.due_at',
    '2026-09-30T00:00:00.000000Z'
);
}
public function test_dashboard_returns_recent_activity(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Recent Activity Customer',
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'invoice_number' => 'INV-ACTIVITY-001',
        'status' => 'issued',
        'total' => 1500,
        'issued_at' => '2026-09-27 10:00:00',
    ]);

    InvoicePayment::factory()->create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'amount' => 500,
        'paid_at' => '2026-09-27 12:00:00',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/dashboard');

    $response
        ->assertOk()
        ->assertJsonPath('data.recent_activity.0.type', 'payment')
        ->assertJsonPath(
            'data.recent_activity.0.description',
            'Payment received for INV-ACTIVITY-001'
        )
        ->assertJsonPath('data.recent_activity.0.amount', 500);
}
public function test_dashboard_returns_sales_period_comparison(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    // Previous period: 2,000
    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'total' => 2000,
        'issued_at' => '2026-08-10 10:00:00',
    ]);

    // Current period: 3,000
    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'total' => 3000,
        'issued_at' => '2026-09-10 10:00:00',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson(
        '/api/dashboard?date_from=2026-09-01&date_to=2026-09-30'
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.sales_total', 3000)
        ->assertJsonPath('data.sales_previous_period', 2000)
        ->assertJsonPath('data.sales_change_percentage', 50);
}
public function test_dashboard_returns_outstanding_invoice_period_comparison(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $previousCustomer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $currentCustomer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    // Previous period outstanding: 2,000
    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => $previousCustomer->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'payment_status' => 'unpaid',
        'total' => 2000,
        'issued_at' => '2026-08-10 10:00:00',
    ]);

    // Current period outstanding: 3,000
    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => $currentCustomer->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'payment_status' => 'unpaid',
        'total' => 3000,
        'issued_at' => '2026-09-10 10:00:00',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson(
        '/api/dashboard?date_from=2026-09-01&date_to=2026-09-30'
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.outstanding_invoice_total', 3000)
        ->assertJsonPath('data.outstanding_invoice_previous_period', 2000)
        ->assertJsonPath('data.outstanding_invoice_change_percentage', 50);
}
public function test_dashboard_returns_customer_period_comparison(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user);

    // Previous period: 2 customers
    Customer::factory()
        ->count(2)
        ->for($tenant)
        ->create([
            'created_at' => '2026-08-15 10:00:00',
        ]);

    // Current period: 3 customers
    Customer::factory()
        ->count(3)
        ->for($tenant)
        ->create([
            'created_at' => '2026-09-15 10:00:00',
        ]);

    Sanctum::actingAs($user);

    $response = $this->getJson(
        '/api/dashboard?date_from=2026-09-01&date_to=2026-09-30'
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.customer_count', 5)
        ->assertJsonPath('data.customer_current_period', 3)
        ->assertJsonPath('data.customer_previous_period', 2)
        ->assertJsonPath('data.customer_change_percentage', 50);
}
public function test_dashboard_returns_sales_count_period_comparison(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    // Previous period: 2 sales
    Invoice::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'issued_at' => '2026-08-15 10:00:00',
    ]);

    // Current period: 3 sales
    Invoice::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'issued_at' => '2026-09-15 10:00:00',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson(
        '/api/dashboard?date_from=2026-09-01&date_to=2026-09-30'
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.sales_count', 3)
        ->assertJsonPath('data.sales_count_previous_period', 2)
        ->assertJsonPath('data.sales_count_change_percentage', 50);
}
public function test_returns_sales_grouped_by_branch_with_branch(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user);

    $branchOne = Branch::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main Branch',
    ]);

    $branchTwo = Branch::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Airport Branch',
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branchOne->id,
        'customer_id' => $customer->id,
        'status' => InvoiceStatus::ISSUED,
        'total' => 18500,
    ]);

    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branchTwo->id,
        'customer_id' => $customer->id,
        'status' => InvoiceStatus::ISSUED,
        'total' => 13000,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/dashboard');

    $response
        ->assertOk()
        ->assertJsonPath('data.sales_by_branch.0.branch_id', $branchOne->id)
        ->assertJsonPath('data.sales_by_branch.0.branch_name', 'Main Branch')
        ->assertJsonPath('data.sales_by_branch.0.sales', 18500)
        ->assertJsonPath('data.sales_by_branch.1.branch_id', $branchTwo->id)
        ->assertJsonPath('data.sales_by_branch.1.branch_name', 'Airport Branch')
        ->assertJsonPath('data.sales_by_branch.1.sales', 13000);
}
public function test_returns_payments_grouped_by_method(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'issued',
        'total' => 31500,
    ]);

    InvoicePayment::factory()->create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'amount' => 8500,
        'method' => 'cash',
    ]);

    InvoicePayment::factory()->create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'amount' => 12000,
        'method' => 'mobile_money',
    ]);

    InvoicePayment::factory()->create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'amount' => 11000,
        'method' => 'bank_transfer',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/dashboard');

    $response
        ->assertOk()
        ->assertJsonPath('data.payments_by_method.0.payment_method', 'cash')
        ->assertJsonPath('data.payments_by_method.0.total', 8500)
        ->assertJsonPath('data.payments_by_method.1.payment_method', 'mobile_money')
        ->assertJsonPath('data.payments_by_method.1.total', 12000)
        ->assertJsonPath('data.payments_by_method.2.payment_method', 'bank_transfer')
        ->assertJsonPath('data.payments_by_method.2.total', 11000);
}
}