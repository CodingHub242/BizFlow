<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\Supplier;

class GeneralReportService
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function overview(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        bool $canViewProfit = false,
    ): array {
        $dashboard = $this->dashboardService->summary(
            $tenantId,
            $dateFrom,
            $dateTo,
        );

        $financial = [
            'sales_total' => $dashboard['sales_total'] ?? 0,
            'expense_total' => $dashboard['expense_total'] ?? 0,
            'outstanding_invoice_total' =>
                $dashboard['outstanding_invoice_total'] ?? 0,
            'product_sales' => $dashboard['product_sales'] ?? 0,
            'services_rendered' => $dashboard['services_rendered'] ?? 0,
            'sales_count' => $dashboard['sales_count'] ?? 0,
            'payments_by_method' =>
                $dashboard['payments_by_method'] ?? [],
        ];

        if ($canViewProfit) {
            $financial['gross_profit'] =
                $dashboard['gross_profit'] ?? 0;
        }

        return [
            'financial' => $financial,

            'business' => [
                'customers' => Customer::where('tenant_id', $tenantId)
                    ->count(),

                'suppliers' => Supplier::where('tenant_id', $tenantId)
                    ->count(),

                'branches' => Branch::where('tenant_id', $tenantId)
                    ->count(),

                'products' => CatalogItem::where('tenant_id', $tenantId)
                    ->where('type', 'product')
                    ->count(),

                'services' => CatalogItem::where('tenant_id', $tenantId)
                    ->where('type', 'service')
                    ->count(),

                'invoices' => Invoice::where('tenant_id', $tenantId)
                    ->count(),

                'expenses' => Expense::where('tenant_id', $tenantId)
                    ->count(),

                'purchases' => Purchase::where('tenant_id', $tenantId)
                    ->count(),

                'bills' => Bill::where('tenant_id', $tenantId)
                    ->count(),

                'employees' => Employee::where('tenant_id', $tenantId)
                    ->count(),
            ],

            'inventory' => [
                'inventory_value' => $dashboard['inventory_value'] ?? 0,
                'low_stock_count' => $dashboard['low_stock_count'] ?? 0,
                'low_stock_items' => $dashboard['low_stock_items'] ?? [],
            ],

            'operational_sales' => [
                'sales_total' =>
                    $dashboard['operational_sales_total'] ?? 0,

                'order_count' =>
                    $dashboard['operational_order_count'] ?? 0,

                'orders_by_status' =>
                    $dashboard['operational_orders_by_status'] ?? [],

                'fulfilled_stock_quantity' =>
                    $dashboard['fulfilled_stock_quantity'] ?? 0,
            ],

            'trends' => [
                'sales_trend' => $dashboard['sales_trend'] ?? [],
                'sales_by_branch' => $dashboard['sales_by_branch'] ?? [],
            ],
        ];
    }
}
