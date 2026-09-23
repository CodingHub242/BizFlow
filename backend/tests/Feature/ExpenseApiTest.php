<?php

namespace Tests\Feature;

use App\ExpensePaymentMethod;
use App\Models\Branch;
use App\Models\Category;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseApiTest extends TestCase
{
    use RefreshDatabase;

public function test_authenticated_user_can_create_an_expense(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $category = Category::create([
        'tenant_id' => $tenant->id,
        'name' => 'Office Expenses',
        'description' => 'General office expenses',
        'is_active' => true,
    ]);

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/expenses', [
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'amount' => 1500.50,
        'payment_method' => ExpensePaymentMethod::MOBILE_MONEY->value,
        'reference' => 'EXP-2026-001',
        'expense_date' => '2026-09-23',
        'description' => 'Office internet subscription',
        'notes' => 'September subscription',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath(
            'data.amount',
            '1500.50'
        )
        ->assertJsonPath(
            'data.payment_method',
            'mobile_money'
        )
        ->assertJsonPath(
            'data.branch_id',
            $branch->id
        )
        ->assertJsonPath(
            'data.category_id',
            $category->id
        );

    $this->assertDatabaseHas('expenses', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'created_by' => $user->id,
        'payment_method' => 'mobile_money',
        'reference' => 'EXP-2026-001',
    ]);
}
public function test_user_cannot_view_an_expense_from_another_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $branchA = Branch::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $expense = \App\Models\Expense::create([
        'tenant_id' => $tenantA->id,
        'branch_id' => $branchA->id,
        'created_by' => $userA->id,
        'amount' => 800,
        'payment_method' => ExpensePaymentMethod::CASH,
        'expense_date' => '2026-09-23',
        'description' => 'Tenant A expense',
    ]);

    Sanctum::actingAs($userB);

    $this->getJson("/api/expenses/{$expense->id}")
        ->assertNotFound();
}
public function test_expense_index_returns_only_current_tenant_expenses_with_pagination(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $branchA = Branch::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $branchB = Branch::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    for ($i = 1; $i <= 3; $i++) {
        \App\Models\Expense::create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'created_by' => $userA->id,
            'amount' => $i * 100,
            'payment_method' => ExpensePaymentMethod::CASH,
            'expense_date' => '2026-09-2' . $i,
            'description' => "Tenant A expense {$i}",
        ]);
    }

    \App\Models\Expense::create([
        'tenant_id' => $tenantB->id,
        'branch_id' => $branchB->id,
        'created_by' => $userB->id,
        'amount' => 9999,
        'payment_method' => ExpensePaymentMethod::CASH,
        'expense_date' => '2026-09-23',
        'description' => 'Tenant B expense',
    ]);

    Sanctum::actingAs($userA);

    $response = $this->getJson('/api/expenses?per_page=2');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3);

    $this->assertCount(
        2,
        $response->json('data')
    );

    $this->assertStringContainsString(
        'Tenant A',
        json_encode($response->json('data'))
    );

    $this->assertStringNotContainsString(
        'Tenant B',
        json_encode($response->json('data'))
    );
}
public function test_expense_index_supports_business_filters(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $category = Category::create([
        'tenant_id' => $tenant->id,
        'name' => 'Utilities',
        'description' => 'Utility expenses',
        'is_active' => true,
    ]);

    \App\Models\Expense::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'created_by' => $user->id,
        'amount' => 1200,
        'payment_method' => ExpensePaymentMethod::MOBILE_MONEY,
        'reference' => 'UTIL-001',
        'expense_date' => '2026-09-10',
        'description' => 'Office electricity bill',
    ]);

    \App\Models\Expense::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'created_by' => $user->id,
        'amount' => 500,
        'payment_method' => ExpensePaymentMethod::CASH,
        'reference' => 'TRAN-001',
        'expense_date' => '2026-08-10',
        'description' => 'Office transport',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson(
        '/api/expenses?' . http_build_query([
            'branch_id' => $branch->id,
            'category_id' => $category->id,
            'payment_method' => 'mobile_money',
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
            'search' => 'electricity',
        ])
    );

    $response
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath(
            'data.0.description',
            'Office electricity bill'
        )
        ->assertJsonPath(
            'data.0.payment_method',
            'mobile_money'
        );
}
public function test_authenticated_user_can_update_an_expense(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $category = Category::create([
        'tenant_id' => $tenant->id,
        'name' => 'Office Expenses',
        'description' => 'General office expenses',
        'is_active' => true,
    ]);

    $expense = \App\Models\Expense::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'created_by' => $user->id,
        'amount' => 500,
        'payment_method' => ExpensePaymentMethod::CASH,
        'expense_date' => '2026-09-20',
        'description' => 'Office supplies',
    ]);

    Sanctum::actingAs($user);

    $response = $this->putJson("/api/expenses/{$expense->id}", [
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'amount' => 850.75,
        'payment_method' => ExpensePaymentMethod::BANK_TRANSFER->value,
        'reference' => 'EXP-UPDATED-001',
        'expense_date' => '2026-09-23',
        'description' => 'Updated office supplies',
        'notes' => 'Updated expense record',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.amount', '850.75')
        ->assertJsonPath(
            'data.payment_method',
            'bank_transfer'
        )
        ->assertJsonPath(
            'data.reference',
            'EXP-UPDATED-001'
        )
        ->assertJsonPath(
            'data.description',
            'Updated office supplies'
        );

    $this->assertDatabaseHas('expenses', [
        'id' => $expense->id,
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'amount' => 850.75,
        'payment_method' => 'bank_transfer',
    ]);
}
public function test_authenticated_user_can_soft_delete_an_expense(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $expense = \App\Models\Expense::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'amount' => 300,
        'payment_method' => ExpensePaymentMethod::CASH,
        'expense_date' => '2026-09-23',
        'description' => 'Office transport',
    ]);

    Sanctum::actingAs($user);

    $this->deleteJson("/api/expenses/{$expense->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertSoftDeleted('expenses', [
        'id' => $expense->id,
    ]);

    $this->assertNotNull(
        \App\Models\Expense::withTrashed()->find($expense->id)
    );
}
public function test_expense_api_requires_authentication(): void
{
    $this->getJson('/api/expenses')
        ->assertUnauthorized();

    $this->getJson('/api/expenses/1')
        ->assertUnauthorized();

    $this->postJson('/api/expenses', [])
        ->assertUnauthorized();

    $this->putJson('/api/expenses/1', [])
        ->assertUnauthorized();

    $this->deleteJson('/api/expenses/1')
        ->assertUnauthorized();
}
}