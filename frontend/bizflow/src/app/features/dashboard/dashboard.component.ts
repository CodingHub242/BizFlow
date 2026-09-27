import { Component, OnInit,inject } from '@angular/core';
import { DecimalPipe,DatePipe,TitleCasePipe } from '@angular/common';
import { IonIcon } from '@ionic/angular';

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

import {
  DashboardApiService,
  DashboardData,
} from '../../core/services/dashboard-api';

interface SalesChartPoint {
  x: number;
  y: number;
}

@Component({
  selector: 'app-dashboard',
  templateUrl: './dashboard.component.html',
  styleUrls: ['./dashboard.component.scss'],
  standalone: true,
  imports: [IonIcon,DecimalPipe,DatePipe,TitleCasePipe],
})
export class DashboardComponent {
 private readonly dashboardApi = inject(DashboardApiService);
salesChartPoints: SalesChartPoint[] = [];
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
         this.salesChartPoints = this.buildSalesChartPoints(
        response.data.sales_trend,);
      },
    });
  }

  private buildSalesChartPoints(trend: DashboardData['sales_trend'],): SalesChartPoint[] 
  {
    if (trend.length === 0) {
      return [];
    }

  if (trend.length === 1) {
    return [
      {
        x: 0,
        y: 110,
      },
    ];
  }

  const chartWidth = 700;
  const chartHeight = 220;
  const padding = 15;

  const maxSales = Math.max(...trend.map((item) => item.sales));

  const usableHeight = chartHeight - padding * 2;

  return trend.map((item, index) => {
    const x = (index / (trend.length - 1)) * chartWidth;

    const normalized =
      maxSales > 0 ? item.sales / maxSales : 0;

    const y =
      chartHeight -
      padding -
      normalized * usableHeight;

    return {
      x,
      y,
    };
  });
}

getSalesChartLabels(): string[] {
  return this.dashboardData.sales_trend.map((item) => {
    const date = new Date(item.date);

    return date.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
    });
  });
}

getBranchSalesPercentage(sales: number): number {
  const maxSales = Math.max(
    ...this.dashboardData.sales_by_branch.map((branch) => branch.sales),
  );

  if (maxSales <= 0) {
    return 0;
  }

  return (sales / maxSales) * 100;
}

getPaymentMethodPercentage(total: number): number {
  const totalPayments = this.dashboardData.payments_by_method.reduce(
    (sum, payment) => sum + payment.total,
    0,
  );

  if (totalPayments <= 0) {
    return 0;
  }

  return (total / totalPayments) * 100;
}
}
