<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Expense;
use App\Models\Inventory;
use App\Models\InvoiceItem;

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

        $salesByBranch = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->when($dateFrom, function ($query) use ($dateFrom) {
                $query->whereDate('issued_at', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo) {
                $query->whereDate('issued_at', '<=', $dateTo);
            })
            ->selectRaw('branch_id, SUM(total) as sales_total')
            ->groupBy('branch_id')
            ->orderBy('branch_id')
            ->pluck('sales_total', 'branch_id')
            ->map(fn ($total) => (float) $total)
            ->toArray();

        $paymentsByMethod = InvoicePayment::query()
            ->where('tenant_id', $tenantId)
            ->when($dateFrom, function ($query) use ($dateFrom) {
                $query->whereDate('paid_at', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo) {
                $query->whereDate('paid_at', '<=', $dateTo);
            })
            ->selectRaw('method, SUM(amount) as payment_total')
            ->groupBy('method')
            ->orderBy('method')
            ->pluck('payment_total', 'method')
            ->map(fn ($total) => (float) $total)
            ->toArray();

        return [
            'sales_total' => (float) $salesTotal,
            'outstanding_invoice_total' => (float) $outstandingInvoiceTotal,
            'expense_total' => (float) $expenseTotal,
            'gross_profit' => (float) $grossProfit,
            'inventory_value' => (float) $inventoryValue,
            'low_stock_count' => $lowStockCount,
            'sales_by_branch' => $salesByBranch,
            'payments_by_method' => $paymentsByMethod,
        ];
    }
}