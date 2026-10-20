<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseReportController extends Controller
{
    /**
     * Build the tenant-scoped expense query used by reports.
     */
    private function filteredExpenses(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return Expense::query()
            ->where('expenses.tenant_id', $user->tenant_id)
            ->when(
                $validated['date_from'] ?? null,
                fn ($query, $date) =>
                    $query->whereDate('expenses.expense_date', '>=', $date)
            )
            ->when(
                $validated['date_to'] ?? null,
                fn ($query, $date) =>
                    $query->whereDate('expenses.expense_date', '<=', $date)
            )
            ->when(
                $validated['branch_id'] ?? null,
                fn ($query, $branchId) =>
                    $query->where('expenses.branch_id', $branchId)
            );
    }

    /**
     * Overall expense summary and monthly comparison.
     */
    public function summary(Request $request): JsonResponse
    {
        $expenses = $this->filteredExpenses($request);

        $total = (clone $expenses)->sum('amount');

        $now = Carbon::today();
        $currentMonthStart = $now->copy()->startOfMonth();
        $currentMonthEnd = $now->copy()->endOfMonth();

        $previousMonthStart = $now->copy()
            ->subMonthNoOverflow()
            ->startOfMonth();

        $previousMonthEnd = $now->copy()
            ->subMonthNoOverflow()
            ->endOfMonth();

        $currentMonth = (clone $expenses)
            ->whereBetween('expenses.expense_date', [
                $currentMonthStart,
                $currentMonthEnd,
            ])
            ->sum('amount');

        $previousMonth = (clone $expenses)
            ->whereBetween('expenses.expense_date', [
                $previousMonthStart,
                $previousMonthEnd,
            ])
            ->sum('amount');

        $change = $previousMonth > 0
            ? (($currentMonth - $previousMonth) / $previousMonth) * 100
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'total_expenses' => round((float) $total, 2),
                'current_month' => round((float) $currentMonth, 2),
                'previous_month' => round((float) $previousMonth, 2),
                'change_percentage' => $change === null
                    ? null
                    : round($change, 2),
                'comparison' => $change === null
                    ? 'no_previous_month_baseline'
                    : ($change > 0
                        ? 'increased'
                        : ($change < 0 ? 'decreased' : 'unchanged')),
            ],
        ]);
    }

    /**
     * Expense totals grouped by category.
     */
    public function categories(Request $request): JsonResponse
    {
        $rows = $this->filteredExpenses($request)
            ->leftJoin('categories', function ($join) {
                $join->on('categories.id', '=', 'expenses.category_id')
                    ->on('categories.tenant_id', '=', 'expenses.tenant_id')
                    ->whereNull('categories.deleted_at');
            })
            ->selectRaw("
                expenses.category_id,
                COALESCE(categories.name, 'Uncategorized') AS category_name,
                COUNT(expenses.id) AS expense_count,
                SUM(expenses.amount) AS total
            ")
            ->groupBy('expenses.category_id', 'categories.name')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn ($row) => [
                'category_id' => $row->category_id,
                'category_name' => $row->category_name,
                'expense_count' => (int) $row->expense_count,
                'total' => round((float) $row->total, 2),
            ]),
        ]);
    }

    /**
     * Expense totals grouped by branch.
     */
    public function branches(Request $request): JsonResponse
    {
        $rows = $this->filteredExpenses($request)
            ->leftJoin('branches', function ($join) {
                $join->on('branches.id', '=', 'expenses.branch_id')
                    ->on('branches.tenant_id', '=', 'expenses.tenant_id');
            })
            ->selectRaw("
                expenses.branch_id,
                COALESCE(branches.name, 'Unknown branch') AS branch_name,
                COUNT(expenses.id) AS expense_count,
                SUM(expenses.amount) AS total
            ")
            ->groupBy('expenses.branch_id', 'branches.name')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn ($row) => [
                'branch_id' => $row->branch_id,
                'branch_name' => $row->branch_name,
                'expense_count' => (int) $row->expense_count,
                'total' => round((float) $row->total, 2),
            ]),
        ]);
    }

    /**
     * Expense totals grouped by payment method.
     */
    public function paymentMethods(Request $request): JsonResponse
    {
        $rows = $this->filteredExpenses($request)
            ->selectRaw("
                payment_method,
                COUNT(id) AS expense_count,
                SUM(amount) AS total
            ")
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'expense_count' => (int) $row->expense_count,
                'total' => round((float) $row->total, 2),
            ]),
        ]);
    }
}
