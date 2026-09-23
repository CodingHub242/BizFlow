<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExpenseService
{
    public function create(int $tenantId, array $data): Expense
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $branch = Branch::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($data['branch_id'])
                ->first();

            if (! $branch) {
                throw new RuntimeException(
                    'The selected branch does not belong to this tenant.'
                );
            }

            if (! empty($data['category_id'])) {
                $category = Category::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($data['category_id'])
                    ->first();

                if (! $category) {
                    throw new RuntimeException(
                        'The selected category does not belong to this tenant.'
                    );
                }
            }

            $user = User::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($data['created_by'])
                ->first();

            if (! $user) {
                throw new RuntimeException(
                    'The selected creator does not belong to this tenant.'
                );
            }

            return Expense::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branch->id,
                'category_id' => $data['category_id'] ?? null,
                'created_by' => $user->id,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'reference' => $data['reference'] ?? null,
                'expense_date' => $data['expense_date'],
                'description' => $data['description'],
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    public function update(int $tenantId,Expense $expense,array $data): Expense 
    {
        return DB::transaction(function () use (
            $tenantId,
            $expense,
            $data
        ) {
            $existingExpense = Expense::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->first();

            if (! $existingExpense) {
                throw new RuntimeException(
                    'Expense not found.'
                );
            }

            $branch = Branch::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($data['branch_id'])
                ->first();

            if (! $branch) {
                throw new RuntimeException(
                    'The selected branch does not belong to this tenant.'
                );
            }

            if (! empty($data['category_id'])) {
                $category = Category::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($data['category_id'])
                    ->first();

                if (! $category) {
                    throw new RuntimeException(
                        'The selected category does not belong to this tenant.'
                    );
                }
            }

            $existingExpense->update([
                'branch_id' => $branch->id,
                'category_id' => $data['category_id'] ?? null,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'reference' => $data['reference'] ?? null,
                'expense_date' => $data['expense_date'],
                'description' => $data['description'],
                'notes' => $data['notes'] ?? null,
            ]);

            return $existingExpense->fresh();
        });
    }

    public function delete(int $tenantId,Expense $expense): void 
    {
        DB::transaction(function () use ($tenantId, $expense) {
            $existingExpense = Expense::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->first();

            if (! $existingExpense) {
                throw new RuntimeException(
                    'Expense not found.'
                );
            }

            $existingExpense->delete();
        });
    }
}