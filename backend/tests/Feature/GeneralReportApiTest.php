<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use App\Models\User;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GeneralReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'reports.view',
            'guard_name' => 'web',
        ]);

        Permission::firstOrCreate([
            'name' => 'reports.view_profit',
            'guard_name' => 'web',
        ]);
    }

    private function assignReportPermissions(
        User $user,
        bool $canViewProfit = false,
    ): void {
        setPermissionsTeamId($user->tenant_id);

        $permission = Permission::firstOrCreate([
            'name' => 'reports.view',
            'guard_name' => 'web',
        ]);

        $role = Role::query()->firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'name' => 'General Reports Tester',
            'guard_name' => 'web',
        ]);

        $role->givePermissionTo($permission);

        if ($canViewProfit) {
            $profitPermission = Permission::firstOrCreate([
                'name' => 'reports.view_profit',
                'guard_name' => 'web',
            ]);

            $role->givePermissionTo($profitPermission);
        }

        $user->assignRole($role);
    }

    public function test_general_report_requires_authentication(): void
    {
        $this->getJson('/api/reports/overview')
            ->assertUnauthorized();
    }

    public function test_user_with_report_permission_can_retrieve_overview(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignReportPermissions($user);

        Sanctum::actingAs($user);

        $this->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'financial' => [
                        'sales_total',
                        'expense_total',
                        'outstanding_invoice_total',
                        'product_sales',
                        'services_rendered',
                        'sales_count',
                        'payments_by_method',
                    ],
                    'business' => [
                        'customers',
                        'suppliers',
                        'branches',
                        'products',
                        'services',
                        'invoices',
                        'expenses',
                        'purchases',
                        'bills',
                        'employees',
                    ],
                    'inventory' => [
                        'inventory_value',
                        'low_stock_count',
                        'low_stock_items',
                    ],
                    'trends' => [
                        'sales_trend',
                        'sales_by_branch',
                    ],
                ],
            ])
            ->assertJsonPath('data.business.customers', 0)
            ->assertJsonPath('data.business.branches', 0)
            ->assertJsonPath('data.financial.sales_total', 0);
    }

    public function test_general_report_denies_user_without_report_permission(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/reports/overview')
            ->assertForbidden();
    }

    public function test_general_report_only_includes_authenticated_tenant_data(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $otherUser = User::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $this->assignReportPermissions($user);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $otherBranch = Branch::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        Invoice::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
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

        Expense::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'amount' => 100,
            'expense_date' => '2026-09-10',
        ]);

        Expense::factory()->create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'created_by' => $otherUser->id,
            'amount' => 500,
            'expense_date' => '2026-09-10',
        ]);

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/reports/overview?date_from=2026-09-01&date_to=2026-09-30'
        )
            ->assertOk()
            ->assertJsonPath('data.financial.sales_total', 300)
            ->assertJsonPath('data.financial.expense_total', 100)
            ->assertJsonPath('data.business.branches', 1)
            ->assertJsonPath('data.business.invoices', 1)
            ->assertJsonPath('data.business.expenses', 1);
    }

    public function test_general_report_rejects_invalid_date_range(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignReportPermissions($user);

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/reports/overview?date_from=2026-09-30&date_to=2026-09-01'
        )->assertUnprocessable();
    }

    public function test_general_report_hides_gross_profit_without_profit_permission(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignReportPermissions($user);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/reports/overview');

        $response
            ->assertOk()
            ->assertJsonMissingPath('data.financial.gross_profit');
    }

    public function test_general_report_includes_gross_profit_with_profit_permission(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignReportPermissions($user, true);

        Sanctum::actingAs($user);

        $this->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'financial' => [
                        'gross_profit',
                    ],
                ],
            ])
            ->assertJsonPath('data.financial.gross_profit', 0);
    }

    public function test_general_report_includes_operational_sales_metrics(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignReportPermissions($user);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Order::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'confirmed',
        'total' => 100,
    ]);

    Order::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'partially_fulfilled',
        'total' => 150,
    ]);

    Order::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'fulfilled',
        'total' => 200,
    ]);

    Order::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'status' => 'draft',
        'total' => 500,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/reports/overview')
        ->assertOk()
        ->assertJsonPath('data.operational_sales.sales_total', 450)
        ->assertJsonPath('data.operational_sales.order_count', 3)
        ->assertJsonStructure([
            'data' => [
                'operational_sales' => [
                    'sales_total',
                    'order_count',
                    'orders_by_status',
                    'fulfilled_stock_quantity',
                ],
            ],
        ])
        ->assertJsonPath('data.financial.sales_total', 0);
}
}
