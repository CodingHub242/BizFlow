
import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { API_URL } from '../config/api.config';

export interface GeneralReportFinancial {
  sales_total: number;
  expense_total: number;
  outstanding_invoice_total: number;
  product_sales: number;
  services_rendered: number;
  sales_count: number;
  payments_by_method: {
    payment_method: string;
    total: number;
  }[];
  gross_profit?: number;
}

export interface GeneralReportBusiness {
  customers: number;
  suppliers: number;
  branches: number;
  products: number;
  services: number;
  invoices: number;
  expenses: number;
  purchases: number;
  bills: number;
  employees: number;
}

export interface GeneralReportInventory {
  inventory_value: number;
  low_stock_count: number;
  low_stock_items: {
    id: number;
    name: string;
    sku: string | null;
    quantity: number;
    reorder_level: number;
  }[];
}

export interface GeneralReportTrends {
  sales_trend: {
    date: string;
    sales: number;
  }[];
  sales_by_branch: {
    branch_id: number;
    branch_name: string;
    sales: number;
  }[];
}

export interface GeneralReportData {
  financial: GeneralReportFinancial;
  business: GeneralReportBusiness;
  inventory: GeneralReportInventory;
  trends: GeneralReportTrends;
}

export interface GeneralReportResponse {
  success: boolean;
  data: GeneralReportData;
}

@Injectable({
  providedIn: 'root',
})
export class GeneralReportApiService {
  private readonly http = inject(HttpClient);

  getOverview(
    dateFrom?: string,
    dateTo?: string,
  ): Observable<GeneralReportResponse> {
    let params = new HttpParams();

    if (dateFrom) {
      params = params.set('date_from', dateFrom);
    }

    if (dateTo) {
      params = params.set('date_to', dateTo);
    }

    return this.http.get<GeneralReportResponse>(
      `${API_URL}/reports/overview`,
      { params },
    );
  }
}
