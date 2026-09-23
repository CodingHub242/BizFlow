<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

public function test_authenticated_user_can_retrieve_dashboard_summary(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

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
    ]);

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
    ]);

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
    ]);

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
}