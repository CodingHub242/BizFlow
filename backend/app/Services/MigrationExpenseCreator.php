<?php

namespace App\Services;

use App\Models\Expense;

class MigrationExpenseCreator
{
    public function create(int $tenantId, array $data): Expense
    {
        return Expense::create([
            'tenant_id' => $tenantId,
            'branch_id' => $data['branch_id'],
            'category_id' => $data['category_id'] ?? null,
            'created_by' => $data['created_by'],
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'description' => $data['description'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
        ]);
    }
}