<?php

namespace Tests\Feature;

use App\ExpensePaymentMethod;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

public function test_expense_can_be_created_with_valid_data(): void
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

    $expense = Expense::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'created_by' => $user->id,
        'amount' => 1500.50,
        'payment_method' => ExpensePaymentMethod::MOBILE_MONEY,
        'reference' => 'EXP-2026-001',
        'expense_date' => '2026-09-23',
        'description' => 'Office internet subscription',
        'notes' => 'September subscription',
    ]);

    $this->assertDatabaseHas('expenses', [
        'id' => $expense->id,
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'created_by' => $user->id,
        'payment_method' => 'mobile_money',
        'reference' => 'EXP-2026-001',
        'description' => 'Office internet subscription',
    ]);

    $this->assertInstanceOf(
        ExpensePaymentMethod::class,
        $expense->payment_method
    );

    $this->assertSame(
        ExpensePaymentMethod::MOBILE_MONEY,
        $expense->payment_method
    );
}
public function test_expense_creation_rejects_cross_tenant_relationships(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $branchB = Branch::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $categoryB = Category::create([
        'tenant_id' => $tenantB->id,
        'name' => 'Office Expenses',
        'description' => 'General office expenses',
        'is_active' => true,
    ]);

    $service = app(\App\Services\ExpenseService::class);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
        'The selected branch does not belong to this tenant.'
    );

    $service->create($tenantA->id, [
        'branch_id' => $branchB->id,
        'category_id' => $categoryB->id,
        'created_by' => $userA->id,
        'amount' => 500,
        'payment_method' => ExpensePaymentMethod::CASH,
        'expense_date' => '2026-09-23',
        'description' => 'Cross-tenant expense',
    ]);
}
public function test_expense_can_be_updated_without_changing_tenant_or_creator(): void
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

    $expense = Expense::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'created_by' => $user->id,
        'amount' => 500,
        'payment_method' => ExpensePaymentMethod::CASH,
        'expense_date' => '2026-09-20',
        'description' => 'Office supplies',
    ]);

    $service = app(\App\Services\ExpenseService::class);

    $updated = $service->update($tenant->id, $expense, [
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'amount' => 750.50,
        'payment_method' => ExpensePaymentMethod::BANK_TRANSFER,
        'reference' => 'EXP-UPDATED-001',
        'expense_date' => '2026-09-23',
        'description' => 'Updated office supplies',
        'notes' => 'Updated expense record',
    ]);

    $this->assertSame($tenant->id, $updated->tenant_id);
    $this->assertSame($user->id, $updated->created_by);
    $this->assertSame('750.50', $updated->amount);
    $this->assertSame(
        ExpensePaymentMethod::BANK_TRANSFER,
        $updated->payment_method
    );
    $this->assertSame('EXP-UPDATED-001', $updated->reference);
    $this->assertSame(
        'Updated office supplies',
        $updated->description
    );
}
public function test_expense_can_be_soft_deleted_and_remains_in_database(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $expense = Expense::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'amount' => 250,
        'payment_method' => ExpensePaymentMethod::CASH,
        'expense_date' => '2026-09-23',
        'description' => 'Office transport',
    ]);

    $service = app(\App\Services\ExpenseService::class);

    $service->delete($tenant->id, $expense);

    $this->assertSoftDeleted('expenses', [
        'id' => $expense->id,
    ]);

    $this->assertNotNull(
        Expense::withTrashed()->find($expense->id)
    );
}
}