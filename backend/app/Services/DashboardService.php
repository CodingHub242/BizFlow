<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Customer;
use App\Models\InvoicePayment;
use App\Models\Expense;
use App\Models\Inventory;
use App\Models\InvoiceItem;
use App\Models\InventoryMovement;

class DashboardService
{
    public function summary(int $tenantId,?string $dateFrom = null,?string $dateTo = null): array
    {
        $salesTotal = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->when($dateFrom, function ($query) use ($dateFrom) {
                $query->whereDate('issued_at', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo) {
                $query->whereDate('issued_at', '<=', $dateTo);
            })
            ->sum('total');

        $productSales = InvoiceItem::query()
        ->where('invoice_items.tenant_id', $tenantId)
        ->whereHas('invoice', function ($query) use ($tenantId, $dateFrom, $dateTo) {
            $query->where('tenant_id', $tenantId)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->when($dateFrom, function ($query) use ($dateFrom) {
                    $query->whereDate('issued_at', '>=', $dateFrom);
                })
                ->when($dateTo, function ($query) use ($dateTo) {
                    $query->whereDate('issued_at', '<=', $dateTo);
                });
        })
        ->join(
            'catalog_items',
            'catalog_items.id',
            '=',
            'invoice_items.catalog_item_id'
        )
        ->where('catalog_items.tenant_id', $tenantId)
        ->where('catalog_items.type', 'product')
        ->sum('invoice_items.line_total');

    $servicesRendered = InvoiceItem::query()
        ->where('invoice_items.tenant_id', $tenantId)
        ->whereHas('invoice', function ($query) use ($tenantId, $dateFrom, $dateTo) {
            $query->where('tenant_id', $tenantId)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->when($dateFrom, function ($query) use ($dateFrom) {
                    $query->whereDate('issued_at', '>=', $dateFrom);
                })
                ->when($dateTo, function ($query) use ($dateTo) {
                    $query->whereDate('issued_at', '<=', $dateTo);
                });
        })
        ->join(
            'catalog_items',
            'catalog_items.id',
            '=',
            'invoice_items.catalog_item_id'
        )
        ->where('catalog_items.tenant_id', $tenantId)
        ->where('catalog_items.type', 'service')
        ->sum('invoice_items.line_total');

       $outstandingInvoiceTotal = (float) Invoice::query()
            ->where('invoices.tenant_id', $tenantId)
            ->whereNotIn('invoices.status', ['draft', 'cancelled'])
            ->when($dateFrom, function ($query) use ($dateFrom) {
                $query->whereDate('invoices.issued_at', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo) {
                $query->whereDate('invoices.issued_at', '<=', $dateTo);
            })
            ->leftJoinSub(
                InvoicePayment::query()
                    ->selectRaw('invoice_id, SUM(amount) as paid_total')
                    ->where('tenant_id', $tenantId)
                    ->groupBy('invoice_id'),
                'payments',
                function ($join) {
                    $join->on('payments.invoice_id', '=', 'invoices.id');
                }
            )
            ->selectRaw(
                'COALESCE(SUM(invoices.total - COALESCE(payments.paid_total, 0)), 0) as outstanding'
            )
            ->value('outstanding');

        $outstandingInvoicePreviousPeriod = 0;

    if ($dateFrom && $dateTo) {
        $currentFrom = \Carbon\Carbon::parse($dateFrom)->startOfDay();
        $currentTo = \Carbon\Carbon::parse($dateTo)->endOfDay();

        $periodDays = $currentFrom->diffInDays($currentTo) + 1;

        $previousFrom = $currentFrom->copy()->subDays($periodDays);
        $previousTo = $currentTo->copy()->subDays($periodDays);

        $outstandingInvoicePreviousPeriod = (float) Invoice::query()
            ->where('invoices.tenant_id', $tenantId)
            ->whereNotIn('invoices.status', ['draft', 'cancelled'])
            ->whereIn('invoices.payment_status', ['unpaid', 'partially_paid'])
            ->whereBetween('invoices.issued_at', [$previousFrom, $previousTo])
            ->leftJoinSub(
                InvoicePayment::query()
                    ->select('invoice_id')
                    ->selectRaw('SUM(amount) as paid')
                    ->where('tenant_id', $tenantId)
                    ->groupBy('invoice_id'),
                'previous_payments',
                'previous_payments.invoice_id',
                '=',
                'invoices.id'
            )
            ->whereRaw(
                '(invoices.total - COALESCE(previous_payments.paid, 0)) > 0'
            )
            ->selectRaw(
                'COALESCE(SUM(invoices.total - COALESCE(previous_payments.paid, 0)), 0) as outstanding'
            )
            ->value('outstanding');
            }

        $outstandingInvoiceChangePercentage = 0;

        if ($outstandingInvoicePreviousPeriod > 0) {
            $outstandingInvoiceChangePercentage =
                (
                    ($outstandingInvoiceTotal - $outstandingInvoicePreviousPeriod)
                    / $outstandingInvoicePreviousPeriod
                ) * 100;
        }

        $outstandingInvoices = Invoice::query()
            ->where('invoices.tenant_id', $tenantId)
            ->whereNotIn('invoices.status', ['draft', 'cancelled'])
            ->whereIn('invoices.payment_status', ['unpaid', 'partially_paid'])
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoinSub(
                InvoicePayment::query()
                    ->select('invoice_id')
                    ->selectRaw('SUM(amount) as paid')
                    ->where('tenant_id', $tenantId)
                    ->groupBy('invoice_id'),
                'payments',
                'payments.invoice_id',
                '=',
                'invoices.id'
            )
            ->where('invoices.tenant_id', $tenantId)
            ->select([
                'invoices.id',
                'invoices.invoice_number',
                'customers.name as customer_name',
                'invoices.total',
                'invoices.due_at',
            ])
            ->selectRaw('COALESCE(payments.paid, 0) as paid')
            ->selectRaw('(invoices.total - COALESCE(payments.paid, 0)) as outstanding')
            ->whereRaw('(invoices.total - COALESCE(payments.paid, 0)) > 0')
            ->orderBy('invoices.due_at')
            ->get()
            ->map(fn ($invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_name' => $invoice->customer_name,
                'total' => (float) $invoice->total,
                'paid' => (float) $invoice->paid,
                'outstanding' => (float) $invoice->outstanding,
                'due_at' => $invoice->due_at,
            ])
            ->values()
            ->all();

       $expenseTotal = Expense::query()
        ->where('tenant_id', $tenantId)
        ->when($dateFrom, function ($query) use ($dateFrom) {
            $query->whereDate('expense_date', '>=', $dateFrom);
        })
        ->when($dateTo, function ($query) use ($dateTo) {
            $query->whereDate('expense_date', '<=', $dateTo);
        })
        ->sum('amount');

        $grossProfit = InvoiceItem::query()
            ->where('invoice_items.tenant_id', $tenantId)
            ->whereHas('invoice', function ($query) use ($tenantId, $dateFrom, $dateTo) {
                $query->where('tenant_id', $tenantId)
                    ->whereNotIn('status', ['draft', 'cancelled'])
                    ->when($dateFrom, function ($query) use ($dateFrom) {
                        $query->whereDate('issued_at', '>=', $dateFrom);
                    })
                    ->when($dateTo, function ($query) use ($dateTo) {
                        $query->whereDate('issued_at', '<=', $dateTo);
                    });
            })
            ->join('catalog_items', 'catalog_items.id', '=', 'invoice_items.catalog_item_id')
            ->selectRaw(
                'COALESCE(SUM(invoice_items.line_total - (invoice_items.quantity * catalog_items.cost_price)), 0) as gross_profit'
            )
            ->value('gross_profit');

        $inventoryValue = Inventory::query()
            ->where('inventories.tenant_id', $tenantId)
            ->join(
                'catalog_items',
                'catalog_items.id',
                '=',
                'inventories.catalog_item_id'
            )
            ->where('catalog_items.tenant_id', $tenantId)
            ->where('catalog_items.type', 'product')
            ->selectRaw(
                'COALESCE(SUM(inventories.quantity * catalog_items.cost_price), 0) as inventory_value'
            )
            ->value('inventory_value');

        $lowStockCount = Inventory::query()
            ->where('inventories.tenant_id', $tenantId)
            ->join(
                'catalog_items',
                'catalog_items.id',
                '=',
                'inventories.catalog_item_id'
            )
            ->where('catalog_items.tenant_id', $tenantId)
            ->where('catalog_items.type', 'product')
            ->whereColumn('inventories.quantity', '<=', 'inventories.reorder_level')
            ->count();

       $lowStockItems = Inventory::query()
            ->where('inventories.tenant_id', $tenantId)
            ->join(
                'catalog_items',
                'catalog_items.id',
                '=',
                'inventories.catalog_item_id'
            )
            ->where('catalog_items.tenant_id', $tenantId)
            ->where('catalog_items.type', 'product')
            ->whereColumn('inventories.quantity', '<=', 'inventories.reorder_level')
            ->select([
                'catalog_items.id',
                'catalog_items.name',
                'catalog_items.sku',
                'inventories.quantity',
                'inventories.reorder_level',
            ])
            ->orderBy('inventories.quantity')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'quantity' => (float) $item->quantity,
                'reorder_level' => (float) $item->reorder_level,
            ])
            ->values()
            ->all();

       $salesByBranch = Invoice::query()
            ->where('invoices.tenant_id', $tenantId)
            ->whereNotIn('invoices.status', ['draft', 'cancelled'])
            ->when(
                $dateFrom,
                fn($query) => $query->whereDate('invoices.issued_at', '>=', $dateFrom)
            )
            ->when(
                $dateTo,
                fn($query) => $query->whereDate('invoices.issued_at', '<=', $dateTo)
            )
            ->join('branches', 'branches.id', '=', 'invoices.branch_id')
            ->selectRaw('
                invoices.branch_id,
                branches.name as branch_name,
                SUM(invoices.total) as sales
            ')
            ->groupBy('invoices.branch_id', 'branches.name')
            ->orderBy('invoices.branch_id')
            ->get()
            ->map(fn($branch) => [
                'branch_id' => (int) $branch->branch_id,
                'branch_name' => $branch->branch_name,
                'sales' => (float) $branch->sales,
            ])
            ->values()
            ->toArray();

       $paymentsByMethod = InvoicePayment::query()
            ->where('tenant_id', $tenantId)
            ->when(
                $dateFrom,
                fn($query) => $query->whereDate('paid_at', '>=', $dateFrom)
            )
            ->when(
                $dateTo,
                fn($query) => $query->whereDate('paid_at', '<=', $dateTo)
            )
            ->selectRaw('method as payment_method, SUM(amount) as total')
            ->groupBy('method')
            ->orderByRaw("
                CASE method
                    WHEN 'cash' THEN 1
                    WHEN 'mobile_money' THEN 2
                    WHEN 'bank_transfer' THEN 3
                    WHEN 'card' THEN 4
                    ELSE 5
                END
            ")
            ->get()
            ->map(fn($payment) => [
                'payment_method' => $payment->payment_method,
                'total' => (float) $payment->total,
            ])
            ->values()
            ->toArray();

        $customerCount = Customer::query()
        ->where('tenant_id', $tenantId)
        ->count();

        $customerPreviousPeriod = 0;
        $customerCurrentPeriod = $customerCount;

        if ($dateFrom && $dateTo) {
            $currentFrom = \Carbon\Carbon::parse($dateFrom)->startOfDay();
            $currentTo = \Carbon\Carbon::parse($dateTo)->endOfDay();

            $periodDays = $currentFrom->diffInDays($currentTo) + 1;

            $previousFrom = $currentFrom->copy()->subDays($periodDays);
            $previousTo = $currentTo->copy()->subDays($periodDays);

            $customerCurrentPeriod = Customer::query()
                ->where('tenant_id', $tenantId)
                ->whereBetween('created_at', [$currentFrom, $currentTo])
                ->count();

        $customerPreviousPeriod = Customer::query()
                ->where('tenant_id', $tenantId)
                ->whereBetween('created_at', [$previousFrom, $previousTo])
                ->count();
        }

    $customerChangePercentage = 0;

    if ($customerPreviousPeriod > 0) {
        $customerChangePercentage =
            (
                ($customerCurrentPeriod - $customerPreviousPeriod)
                / $customerPreviousPeriod
            ) * 100;
    }

        $salesCount = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->when($dateFrom, function ($query) use ($dateFrom) {
                $query->whereDate('issued_at', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo) {
                $query->whereDate('issued_at', '<=', $dateTo);
            })
            ->count();

        $salesCountPreviousPeriod = 0;

        if ($dateFrom && $dateTo) {
            $currentFrom = \Carbon\Carbon::parse($dateFrom)->startOfDay();
            $currentTo = \Carbon\Carbon::parse($dateTo)->endOfDay();

            $periodDays = $currentFrom->diffInDays($currentTo) + 1;

            $previousFrom = $currentFrom->copy()->subDays($periodDays);
            $previousTo = $currentTo->copy()->subDays($periodDays);

            $salesCountPreviousPeriod = Invoice::query()
                ->where('tenant_id', $tenantId)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->whereBetween('issued_at', [$previousFrom, $previousTo])
                ->count();
        }

        $salesCountChangePercentage = 0;

        if ($salesCountPreviousPeriod > 0) {
            $salesCountChangePercentage =
                (
                    ($salesCount - $salesCountPreviousPeriod)
                    / $salesCountPreviousPeriod
                ) * 100;
        }

        $salesTrend = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->when($dateFrom, function ($query) use ($dateFrom) {
                $query->whereDate('issued_at', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo) {
                $query->whereDate('issued_at', '<=', $dateTo);
            })
            ->selectRaw('DATE(issued_at) as date, SUM(total) as sales')
            ->groupByRaw('DATE(issued_at)')
            ->orderByRaw('DATE(issued_at)')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'sales' => (float) $row->sales,
            ])
            ->values()
            ->all();

        $salesPreviousPeriod = 0;

    if ($dateFrom && $dateTo) {
            $currentFrom = \Carbon\Carbon::parse($dateFrom)->startOfDay();
            $currentTo = \Carbon\Carbon::parse($dateTo)->endOfDay();

            $periodDays = $currentFrom->diffInDays($currentTo) + 1;

            $previousFrom = $currentFrom->copy()->subDays($periodDays);
            $previousTo = $currentTo->copy()->subDays($periodDays);

            $salesPreviousPeriod = Invoice::query()
                ->where('tenant_id', $tenantId)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->whereBetween('issued_at', [$previousFrom, $previousTo])
                ->sum('total');
        }

        $salesChangePercentage = 0;

        if ($salesPreviousPeriod > 0) {
            $salesChangePercentage = (($salesTotal - $salesPreviousPeriod) / $salesPreviousPeriod) * 100;
        }

        $recentActivity = collect();

        $recentInvoices = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->with('customer')
            ->latest('issued_at')
            ->limit(5)
            ->get()
            ->map(fn ($invoice) => [
                'type' => 'invoice',
                'description' => 'Invoice ' . $invoice->invoice_number . ' created',
                'amount' => (float) $invoice->total,
                'timestamp' => $invoice->issued_at,
            ]);

        $recentPayments = InvoicePayment::query()
            ->where('tenant_id', $tenantId)
            ->with('invoice')
            ->latest('paid_at')
            ->limit(5)
            ->get()
            ->map(fn ($payment) => [
                'type' => 'payment',
                'description' => 'Payment received for ' . ($payment->invoice?->invoice_number ?? 'invoice'),
                'amount' => (float) $payment->amount,
                'timestamp' => $payment->paid_at,
            ]);

        $recentExpenses = Expense::query()
            ->where('tenant_id', $tenantId)
            ->latest('expense_date')
            ->limit(5)
            ->get()
            ->map(fn ($expense) => [
                'type' => 'expense',
                'description' => 'Expense recorded',
                'amount' => (float) $expense->amount,
                'timestamp' => $expense->expense_date,
            ]);

        $recentActivity = $recentInvoices
            ->concat($recentPayments)
            ->concat($recentExpenses)
            ->sortByDesc('timestamp')
            ->take(5)
            ->values()
            ->all();

        return [
            'sales_total' => (float) $salesTotal,
            'outstanding_invoice_total' => (float) $outstandingInvoiceTotal,
            'expense_total' => (float) $expenseTotal,
            'gross_profit' => (float) $grossProfit,
            'inventory_value' => (float) $inventoryValue,
            'low_stock_count' => $lowStockCount,
            'product_sales' => (float) $productSales,
            'sales_count' => $salesCount,
            'sales_count_previous_period' => $salesCountPreviousPeriod,
            'sales_count_change_percentage' => round($salesCountChangePercentage,2),
            'customer_count' => $customerCount,
            'customer_current_period' => $customerCurrentPeriod,
            'customer_previous_period' => $customerPreviousPeriod,
            'customer_change_percentage' => round($customerChangePercentage,2),
            'sales_trend' => $salesTrend,
            'services_rendered' => (float) $servicesRendered,
            'sales_by_branch' => $salesByBranch,
            'payments_by_method' => $paymentsByMethod,
            'low_stock_items' => $lowStockItems,
            'outstanding_invoices' => $outstandingInvoices,
            'outstanding_invoice_previous_period' => (float) $outstandingInvoicePreviousPeriod,
            'outstanding_invoice_change_percentage' => round($outstandingInvoiceChangePercentage,2),
            'recent_activity' => $recentActivity,
            'sales_previous_period' => (float) $salesPreviousPeriod,
            'sales_change_percentage' => round($salesChangePercentage, 2),
        ];
    }
}