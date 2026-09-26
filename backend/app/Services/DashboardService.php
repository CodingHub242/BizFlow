<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Customer;
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

        $customerCount = Customer::query()
        ->where('tenant_id', $tenantId)
        ->count();

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

        return [
            'sales_total' => (float) $salesTotal,
            'outstanding_invoice_total' => (float) $outstandingInvoiceTotal,
            'expense_total' => (float) $expenseTotal,
            'gross_profit' => (float) $grossProfit,
            'inventory_value' => (float) $inventoryValue,
            'low_stock_count' => $lowStockCount,
            'product_sales' => (float) $productSales,
            'sales_count' => $salesCount,
            'customer_count' => $customerCount,
            'sales_trend' => $salesTrend,
            'services_rendered' => (float) $servicesRendered,
            'sales_by_branch' => $salesByBranch,
            'payments_by_method' => $paymentsByMethod,
            'low_stock_items' => $lowStockItems,
            'outstanding_invoices' => $outstandingInvoices,
        ];
    }
}