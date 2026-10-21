import { Component, OnInit,inject,ChangeDetectorRef } from '@angular/core';
import { DecimalPipe,DatePipe,TitleCasePipe } from '@angular/common';
import { IonIcon } from '@ionic/angular';
import { RouterLink } from '@angular/router';
import { addIcons } from 'ionicons';
import {
  arrowDownOutline,
  arrowUpOutline,
  cartOutline,
  documentTextOutline,
  peopleOutline,
  cardOutline,
  receiptOutline,
  walletOutline,
  alertCircleOutline,
  arrowForwardOutline,
  ellipsisHorizontalOutline,
} from 'ionicons/icons';

import {DashboardApiService,DashboardData,DashboardOutstandingInvoice} from '../../core/services/dashboard-api';

interface SalesChartPoint {
  x: number;
  y: number;
}

interface DashboardBranchSummary {
  key: string;
  branch_name: string;
  sales: number;
}

interface DashboardPaymentSummary {
  key: string;
  payment_method: string;
  total: number;
}

@Component({
  selector: 'app-dashboard',
  templateUrl: './dashboard.component.html',
  styleUrls: ['./dashboard.component.scss'],
  standalone: true,
  imports: [IonIcon,DecimalPipe,DatePipe,TitleCasePipe,RouterLink],
})
export class DashboardComponent {
 private readonly dashboardApi = inject(DashboardApiService);
 private readonly changeDetector = inject(ChangeDetectorRef);
salesChartPoints: SalesChartPoint[] = [];
salesChartAxisLabels: string[] = ['30k', '20k', '10k', '0'];
displayedBranchSales: DashboardBranchSummary[] = [];
displayedPaymentsByMethod: DashboardPaymentSummary[] = [];
 dashboardData: DashboardData = {
    sales_total: 0,
    product_sales: 0,
    services_rendered: 0,
    expense_total: 0,
    gross_profit: 0,
    outstanding_invoice_total: 0,
    inventory_value: 0,
    customer_count: 0,
    sales_count: 0,
    low_stock_count: 0,
    sales_trend: [],
    sales_by_branch: [],
    payments_by_method: [],
    low_stock_items: [],
    outstanding_invoices: [],
    recent_activity: [],
    sales_previous_period: 0,
    sales_change_percentage: 0,
    outstanding_invoice_previous_period: 0,
    outstanding_invoice_change_percentage: 0,
    customer_current_period: 0,
    customer_previous_period: 0,
    customer_change_percentage: 0,
    sales_count_previous_period: 0,
    sales_count_change_percentage: 0,
    operational_sales_total: 0,
    operational_order_count: 0,
    operational_orders_by_status: [],
    fulfilled_stock_quantity: 0,
  };
  constructor() { 
     addIcons({
      arrowDownOutline,
      arrowUpOutline,
      cartOutline,
      cardOutline,
      receiptOutline,
      documentTextOutline,
      peopleOutline,
      walletOutline,
      alertCircleOutline,
      arrowForwardOutline,
      ellipsisHorizontalOutline,
    });
  }

   ngOnInit(): void {
    this.loadDashboard();
  }

  private loadDashboard(): void {
    this.dashboardApi.getDashboard().subscribe({
      next: (response) => {
        this.dashboardData = response.data;
        this.displayedBranchSales = this.buildBranchSummary(
          response.data.sales_by_branch
        );

        this.displayedPaymentsByMethod = this.buildPaymentSummary(
          response.data.payments_by_method
        );
         this.salesChartPoints = this.buildSalesChartPoints(
        response.data.sales_trend,);
        this.changeDetector.detectChanges();
      },
    });
  }

  private buildBranchSummary(
  branches: DashboardData['sales_by_branch']
): DashboardBranchSummary[] {
  const sorted = [...branches].sort(
    (a, b) => b.sales - a.sales
  );

  const result: DashboardBranchSummary[] = sorted
    .slice(0, 5)
    .map(branch => ({
      key: `branch-${branch.branch_id}`,
      branch_name: branch.branch_name,
      sales: Number(branch.sales) || 0,
    }));

  const remaining = sorted.slice(5);

  if (remaining.length > 0) {
    result.push({
      key: 'other-branches',
      branch_name: 'Other branches',
      sales: remaining.reduce(
        (total, branch) => total + (Number(branch.sales) || 0),
        0
      ),
    });
  }

  return result;
}

private buildPaymentSummary(
    methods: DashboardData['payments_by_method']
  ): DashboardPaymentSummary[] {
    const sorted = [...methods].sort(
      (a, b) => b.total - a.total
    );

    const result: DashboardPaymentSummary[] = sorted
      .slice(0, 4)
      .map(payment => ({
        key: `method-${payment.payment_method}`,
        payment_method: payment.payment_method,
        total: Number(payment.total) || 0,
      }));

    const remaining = sorted.slice(4);

    if (remaining.length > 0) {
      result.push({
        key: 'other-methods',
        payment_method: 'Other methods',
        total: remaining.reduce(
          (total, payment) => total + (Number(payment.total) || 0),
          0
        ),
      });
    }

    return result;
  }

  private buildSalesChartPoints(
  trend: DashboardData['sales_trend']
): SalesChartPoint[] {
  const chartWidth = 700;
  const chartHeight = 220;
  const padding = 15;
  const usableHeight = chartHeight - padding * 2;

  const maxSales = Math.max(
    0,
    ...trend.map(item => Math.max(0, Number(item.sales) || 0))
  );

  // Choose a rounded maximum with three equal vertical intervals.
  const axisStep = this.getNiceSalesAxisStep(maxSales);
  const axisMax = axisStep * 3;

  this.salesChartAxisLabels = [
    this.formatSalesAxisLabel(axisMax),
    this.formatSalesAxisLabel(axisStep * 2),
    this.formatSalesAxisLabel(axisStep),
    '0',
  ];

  if (trend.length === 0) {
    return [];
  }

  return trend.map((item, index) => {
    const sales = Math.max(0, Number(item.sales) || 0);

    const x = trend.length === 1
      ? chartWidth / 2
      : (index / (trend.length - 1)) * chartWidth;

    const y =
      chartHeight -
      padding -
      (sales / axisMax) * usableHeight;

    return { x, y };
  });
}

private getNiceSalesAxisStep(maxSales: number): number {
  if (maxSales <= 0) {
    return 500;
  }

  const roughStep = maxSales / 3;
  const magnitude = Math.pow(
    10,
    Math.floor(Math.log10(roughStep))
  );
  const normalized = roughStep / magnitude;

  const niceMultiplier =
    normalized <= 1 ? 1 :
    normalized <= 2 ? 2 :
    normalized <= 2.5 ? 2.5 :
    normalized <= 5 ? 5 : 10;

  return niceMultiplier * magnitude;
}

private formatSalesAxisLabel(value: number): string {
  if (value >= 1000) {
    const thousands = value / 1000;
    const formatted = Number(thousands.toFixed(1));
    return `${formatted}k`;
  }

  return Math.round(value).toString();
}

getSalesChartLabels(): string[] {
  return this.dashboardData.sales_trend.map((item) => {
    const date = new Date(item.date);

    return date.toLocaleDateString('en-US', {
      month: 'short',
      // day: 'numeric',
    });
  });
}

  getBranchSalesPercentage(sales: number): number {
    const maxSales = Math.max(
      0,
      ...this.displayedBranchSales.map(branch => branch.sales)
    );

    return maxSales > 0
      ? Math.min(100, (sales / maxSales) * 100)
      : 0;
  }


  getPaymentMethodPercentage(total: number): number {
    const totalPayments = this.displayedPaymentsByMethod.reduce(
      (sum, payment) => sum + payment.total,
      0
    );

    return totalPayments > 0
      ? (total / totalPayments) * 100
      : 0;
  }

  isInvoiceOverdue(invoice: DashboardOutstandingInvoice): boolean {
    if (invoice.outstanding <= 0) {
      return false;
    }

    const dueDate = this.toDateOnly(invoice.due_at);

    if (!dueDate) {
      return false;
    }

    return dueDate < this.getTodayDateOnly();
  }

  getDaysOverdue(invoice: DashboardOutstandingInvoice): number {
    if (!this.isInvoiceOverdue(invoice)) {
      return 0;
    }

    const dueDate = this.toDateOnly(invoice.due_at);

    if (!dueDate) {
      return 0;
    }

    const today = this.getTodayDateOnly();
    const dueTime = Date.parse(`${dueDate}T00:00:00Z`);
    const todayTime = Date.parse(`${today}T00:00:00Z`);

    return Math.floor(
      (todayTime - dueTime) / (1000 * 60 * 60 * 24)
    );
  }

  private toDateOnly(value: string | null): string | null {
    if (!value) {
      return null;
    }

    // Works with YYYY-MM-DD and ISO timestamp responses.
    const match = /^(\d{4}-\d{2}-\d{2})/.exec(value);

    if (!match) {
      return null;
    }

    const dateString = match[1];
    const [year, month, day] = dateString.split('-').map(Number);
    const parsed = new Date(Date.UTC(year, month - 1, day));

    // Reject invalid calendar dates instead of mislabeling invoices.
    if (
      parsed.getUTCFullYear() !== year ||
      parsed.getUTCMonth() !== month - 1 ||
      parsed.getUTCDate() !== day
    ) {
      return null;
    }

    return dateString;
  }

  private getTodayDateOnly(): string {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
  }
}
