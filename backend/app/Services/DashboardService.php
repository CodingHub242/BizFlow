<?php

namespace App\Services;

use App\Models\Invoice;

use App\Models\Customer;

use App\Models\InvoicePayment;

use Illuminate\Support\Facades\DB;

use App\Models\Expense;

use App\Models\Order;
use App\InventoryMovementType;

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

            
        $operationalOrderStatuses = [
            'confirmed',
            'partially_fulfilled',
            'fulfilled',
        ];

        $operationalOrders = Order::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', $operationalOrderStatuses)
            ->when($dateFrom, function ($query) use ($dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            });

        $operationalSalesTotal = (float) (clone $operationalOrders)
            ->sum('total');

        $operationalOrderCount = (clone $operationalOrders)
            ->count();

        $operationalOrdersByStatus = (clone $operationalOrders)
            ->selectRaw('status, COUNT(*) as order_count, SUM(total) as total')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'order_count' => (int) $row->order_count,
                'total' => (float) $row->total,
            ])
            ->values()
            ->all();

            $fulfilledStockMovements = InventoryMovement::query()
                ->where('tenant_id', $tenantId)
                ->where('type', InventoryMovementType::SALE->value)
                ->where('reference_type', Order::class)
                ->when($dateFrom, function ($query) use ($dateFrom) {
                    $query->whereDate('created_at', '>=', $dateFrom);
                })
                ->when($dateTo, function ($query) use ($dateTo) {
                    $query->whereDate('created_at', '<=', $dateTo);
                });

            $fulfilledStockQuantity = (float) (clone $fulfilledStockMovements)
                ->sum('quantity');


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

           $netPayments = DB::table('invoice_payments as ip')

           ->leftJoinSub(

               DB::table('invoice_payment_reversals as ipr')

                   ->select('invoice_payment_id')

                   ->selectRaw('SUM(amount) AS reversed_total')

                   ->where('tenant_id', $tenantId)

                   ->groupBy('invoice_payment_id'),

               'reversals',

function ($join) {

                   $join->on(

                       'reversals.invoice_payment_id',

                       '=',

                       'ip.id'

                   );

               }

           )

           ->select('ip.invoice_id')

           ->selectRaw(

               'SUM(ip.amount - COALESCE(reversals.reversed_total, 0)) AS paid_total'

           )

           ->where('ip.tenant_id', $tenantId)

           ->groupBy('ip.invoice_id');

           $creditApplications = DB::table('credit_memo_applications')

           ->select('invoice_id')

           ->selectRaw('SUM(amount_applied) AS applied_total')

           ->where('tenant_id', $tenantId)

           ->groupBy('invoice_id');

            $outstandingExpression = 'CASE WHEN invoices.total - COALESCE(payments.paid_total, 0) - COALESCE(credit_applications.applied_total, 0) > 0 THEN invoices.total - COALESCE(payments.paid_total, 0) - COALESCE(credit_applications.applied_total, 0) ELSE 0 END';

        $outstandingInvoiceTotal = (float) Invoice::query()
            ->where('invoices.tenant_id', $tenantId)
            ->whereNotIn('invoices.status', ['draft', 'cancelled'])
            ->when($dateFrom, function ($query) use ($dateFrom) {
                $query->whereDate('invoices.issued_at', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo) {
                $query->whereDate('invoices.issued_at', '<=', $dateTo);
            })
            ->leftJoinSub($netPayments, 'payments', function ($join) {
                $join->on('payments.invoice_id', '=', 'invoices.id');
            })
            ->leftJoinSub($creditApplications, 'credit_applications', function ($join) {
                $join->on('credit_applications.invoice_id', '=', 'invoices.id');
            })
            ->selectRaw(
                'COALESCE(SUM('.$outstandingExpression.'), 0) AS outstanding'
            )
            ->value('outstanding');

        $outstandingInvoicePreviousPeriod = 0.0;

        if ($dateFrom && $dateTo) {
            $currentFrom = \Carbon\Carbon::parse($dateFrom)->startOfDay();
            $currentTo = \Carbon\Carbon::parse($dateTo)->endOfDay();
            $periodDays = $currentFrom->diffInDays($currentTo) + 1;
            $previousFrom = $currentFrom->copy()->subDays($periodDays);
            $previousTo = $currentTo->copy()->subDays($periodDays);

            $previousOutstandingExpression = 'CASE WHEN invoices.total - COALESCE(previous_payments.paid_total, 0) - COALESCE(previous_credit_applications.applied_total, 0) > 0 THEN invoices.total - COALESCE(previous_payments.paid_total, 0) - COALESCE(previous_credit_applications.applied_total, 0) ELSE 0 END';

            $outstandingInvoicePreviousPeriod = (float) Invoice::query()
                ->where('invoices.tenant_id', $tenantId)
                ->whereNotIn('invoices.status', ['draft', 'cancelled'])
                ->whereBetween('invoices.issued_at', [$previousFrom, $previousTo])
                ->leftJoinSub($netPayments, 'previous_payments', function ($join) {
                    $join->on('previous_payments.invoice_id', '=', 'invoices.id');
                })
                ->leftJoinSub($creditApplications, 'previous_credit_applications', function ($join) {
                    $join->on('previous_credit_applications.invoice_id', '=', 'invoices.id');
                })
                ->selectRaw(
                    'COALESCE(SUM('.$previousOutstandingExpression.'), 0) AS outstanding'
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
            ->leftJoin('customers', function ($join) {
                $join->on('customers.id', '=', 'invoices.customer_id')
                    ->on('customers.tenant_id', '=', 'invoices.tenant_id');
            })
            ->leftJoinSub($netPayments, 'payments', function ($join) {
                $join->on('payments.invoice_id', '=', 'invoices.id');
            })
            ->leftJoinSub($creditApplications, 'credit_applications', function ($join) {
                $join->on('credit_applications.invoice_id', '=', 'invoices.id');
            })
            ->select([
                'invoices.id',
                'invoices.invoice_number',
                'customers.name as customer_name',
                'invoices.total',
                'invoices.due_at',
            ])
            ->selectRaw('COALESCE(payments.paid_total, 0) AS paid')
            ->selectRaw('COALESCE(credit_applications.applied_total, 0) AS credit_applied')
            ->selectRaw(
                'CASE WHEN invoices.total - COALESCE(payments.paid_total, 0) - COALESCE(credit_applications.applied_total, 0) > 0 THEN invoices.total - COALESCE(payments.paid_total, 0) - COALESCE(credit_applications.applied_total, 0) ELSE 0 END AS outstanding'
            )
            ->whereRaw(
                '(invoices.total - COALESCE(payments.paid_total, 0) - COALESCE(credit_applications.applied_total, 0)) > 0'
            )
            ->orderByRaw('invoices.due_at ASC NULLS LAST')
            ->get()
            ->map(fn ($invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_name' => $invoice->customer_name,
                'total' => (float) $invoice->total,
                'paid' => (float) $invoice->paid,
                'credit_applied' => (float) $invoice->credit_applied,
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
            ->where('catalog_items.tenant_id', $tenantId)
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

          $trendStart = $dateFrom

               ? \Carbon\Carbon::parse($dateFrom)->startOfDay()

               : (

                   $dateTo

                       ? \Carbon\Carbon::parse($dateTo)

                           ->subMonths(6)

                           ->startOfMonth()

                       : now()->subMonths(6)->startOfMonth()

               );

           $trendEnd = $dateTo

               ? \Carbon\Carbon::parse($dateTo)->endOfDay()

               : now()->endOfDay();

           $useDailyTrend = $dateFrom !== null

               && $dateTo !== null

               && \Carbon\Carbon::parse($dateFrom)

                   ->startOfDay()

                   ->diffInDays(

                       \Carbon\Carbon::parse($dateTo)->startOfDay()

                   ) <= 30;

           $driver = DB::connection()->getDriverName();

           if ($useDailyTrend) {

               $periodExpression = $driver === 'sqlite'

                   ? 'DATE(issued_at)'

                   : 'CAST(issued_at AS DATE)';

               $periodFormat = 'Y-m-d';

               $cursor = $trendStart->copy()->startOfDay();

               $lastPeriod = $trendEnd->copy()->startOfDay();

           } else {

               $periodExpression = $driver === 'sqlite'

                   ? "strftime('%Y-%m-01', issued_at)"

                   : "DATE_TRUNC('month', issued_at)";

               $periodFormat = 'Y-m';

               $cursor = $trendStart->copy()->startOfMonth();

               $lastPeriod = $trendEnd->copy()->startOfMonth();

           }

           $trendSales = Invoice::query()

               ->where('tenant_id', $tenantId)

               ->whereNotIn('status', ['draft', 'cancelled'])

               ->whereBetween('issued_at', [$trendStart, $trendEnd])

               ->selectRaw(

                   "$periodExpression AS period, SUM(total) AS sales"

               )

               ->groupByRaw($periodExpression)

               ->orderByRaw($periodExpression)

               ->get()

               ->keyBy(

fn ($row) => \Carbon\Carbon::parse($row->period)

                       ->format($periodFormat)

               );

           $salesTrend = [];

           while ($cursor->lessThanOrEqualTo($lastPeriod)) {

               $periodKey = $cursor->format($periodFormat);

               $periodData = $trendSales->get($periodKey);

               $salesTrend[] = [

                   'date' => $cursor->toDateString(),

                   'sales' => (float) ($periodData?->sales ?? 0),

               ];

               if ($useDailyTrend) {

                   $cursor->addDay();

               } else {

                   $cursor->addMonth();

               }

           }

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

               'operational_sales_total' => $operationalSalesTotal,

                'operational_order_count' => $operationalOrderCount,

                'operational_orders_by_status' => $operationalOrdersByStatus,
                
                'fulfilled_stock_quantity' => $fulfilledStockQuantity,

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

public function activityFeed(int $tenantId,?string $type = null,?string $dateFrom = null,?string $dateTo = null,

       int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator

       {

          $invoices = DB::table('invoices')

               ->where('tenant_id', $tenantId)

               ->whereNotIn('status', ['draft', 'cancelled'])

               ->whereNotNull('issued_at')

               ->selectRaw("

                   'invoice' AS type,

                   ('Invoice ' || invoice_number || ' created') AS description,

                   total AS amount,

                   issued_at AS activity_at,

                   id AS source_id

               ");

          $payments = DB::table('invoice_payments as p')

               ->leftJoin('invoices as i', function ($join) {

                   $join->on('i.id', '=', 'p.invoice_id')

                       ->on('i.tenant_id', '=', 'p.tenant_id');

               })

               ->where('p.tenant_id', $tenantId)

               ->whereNotNull('p.paid_at')

               ->selectRaw("

                   'payment' AS type,

                   (

                       'Payment received for ' ||

                       COALESCE(i.invoice_number, 'invoice')

                   ) AS description,

                   p.amount AS amount,

                   p.paid_at AS activity_at,

                   p.id AS source_id

               ");

           $expenses = DB::table('expenses')

               ->where('tenant_id', $tenantId)

               ->whereNotNull('expense_date')

               ->selectRaw("

                   'expense' AS type,

                   'Expense recorded' AS description,

                   amount AS amount,

                   expense_date AS activity_at,

                   id AS source_id

               ");

           $union = $invoices

               ->unionAll($payments)

               ->unionAll($expenses);

           $query = DB::query()

               ->fromSub($union, 'activity_feed')

               ->select([

                   'type',

                   'description',

                   'amount',

                   'activity_at',

                   'source_id',

               ]);

           if ($type !== null) {

               $query->where('type', $type);

           }

           if ($dateFrom !== null) {

               $query->whereDate('activity_at', '>=', $dateFrom);

           }

           if ($dateTo !== null) {

               $query->whereDate('activity_at', '<=', $dateTo);

           }

           return $query

               ->orderByDesc('activity_at')

               ->orderByDesc('source_id')

               ->paginate(min(max($perPage, 1), 50));

       }

}
