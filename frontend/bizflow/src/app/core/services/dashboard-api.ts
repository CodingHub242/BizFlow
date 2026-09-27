import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';

export interface DashboardTrendItem {
  date: string;
  sales: number;
}

export interface DashboardLowStockItem {
  id: number;
  name: string;
  sku: string | null;
  quantity: number;
  reorder_level: number;
}

export interface DashboardOutstandingInvoice {
  id: number;
  invoice_number: string;
  customer_name: string | null;
  total: number;
  paid: number;
  outstanding: number;
  due_at: string | null;
}

export interface DashboardSalesByBranch {
  branch_id: number;
  branch_name: string;
  sales: number;
}

export interface DashboardPaymentByMethod {
  payment_method: string;
  total: number;
}

export interface DashboardRecentActivity {
  type: 'invoice' | 'payment' | 'expense';
  description: string;
  amount: number;
  timestamp: string;
}

export interface DashboardData {
  sales_total: number;
  product_sales: number;
  services_rendered: number;
  expense_total: number;
  gross_profit: number;
  outstanding_invoice_total: number;
  inventory_value: number;
  customer_count: number;
  sales_count: number;
  low_stock_count: number;
  recent_activity: DashboardRecentActivity[];
  sales_trend: DashboardTrendItem[];
  sales_by_branch: DashboardSalesByBranch[];
  payments_by_method: DashboardPaymentByMethod[];
  low_stock_items: DashboardLowStockItem[];
  outstanding_invoices: DashboardOutstandingInvoice[];
  sales_previous_period: number;
  sales_change_percentage: number;
  outstanding_invoice_previous_period: number;
  outstanding_invoice_change_percentage: number;
  customer_current_period: number;
  customer_previous_period: number;
  customer_change_percentage: number;
  sales_count_previous_period: number;
  sales_count_change_percentage: number;
}

export interface DashboardResponse {
  success: boolean;
  data: DashboardData;
}



@Injectable({
  providedIn: 'root',
})
export class DashboardApiService {
  private readonly http = inject(HttpClient);

  getDashboard(dateFrom?: string,dateTo?: string,): Observable<DashboardResponse> 
  {
    let params = new HttpParams();

    if (dateFrom) {
      params = params.set('date_from', dateFrom);
    }

    if (dateTo) {
      params = params.set('date_to', dateTo);
    }

    return this.http.get<DashboardResponse>('/api/dashboard', {
      params,
    });
  }
}