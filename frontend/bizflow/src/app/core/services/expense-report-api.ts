
import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { forkJoin, Observable } from 'rxjs';
import { API_URL } from '../config/api.config';

export interface ExpenseReportFilters {
  date_from?: string;
  date_to?: string;
  branch_id?: number | null;
}

export interface ExpenseSummary {
  total_expenses: number;
  current_month: number;
  previous_month: number;
  change_percentage: number | null;
  comparison: 'increased' | 'decreased' | 'unchanged'
    | 'no_previous_month_baseline';
}

export interface CategoryExpenseReport {
  category_id: number | null;
  category_name: string;
  expense_count: number;
  total: number;
}

export interface BranchExpenseReport {
  branch_id: number | null;
  branch_name: string;
  expense_count: number;
  total: number;
}

export interface PaymentMethodExpenseReport {
  payment_method: string;
  expense_count: number;
  total: number;
}

interface ReportResponse<T> {
  success: boolean;
  data: T;
}

@Injectable({ providedIn: 'root' })
export class ExpenseReportApi {
  private readonly baseUrl =
    `${API_URL}/expenses/reports`;

  constructor(private readonly http: HttpClient) {}

  private params(filters: ExpenseReportFilters): HttpParams {
    let params = new HttpParams();

    if (filters.date_from) {
      params = params.set('date_from', filters.date_from);
    }

    if (filters.date_to) {
      params = params.set('date_to', filters.date_to);
    }

    if (filters.branch_id != null) {
      params = params.set('branch_id', String(filters.branch_id));
    }

    return params;
  }

  summary(filters: ExpenseReportFilters = {}):
    Observable<ReportResponse<ExpenseSummary>> {
    return this.http.get<ReportResponse<ExpenseSummary>>(
      `${this.baseUrl}/summary`,
      { params: this.params(filters) }
    );
  }

  categories(filters: ExpenseReportFilters = {}):
    Observable<ReportResponse<CategoryExpenseReport[]>> {
    return this.http.get<ReportResponse<CategoryExpenseReport[]>>(
      `${this.baseUrl}/categories`,
      { params: this.params(filters) }
    );
  }

  branches(filters: ExpenseReportFilters = {}):
    Observable<ReportResponse<BranchExpenseReport[]>> {
    return this.http.get<ReportResponse<BranchExpenseReport[]>>(
      `${this.baseUrl}/branches`,
      { params: this.params(filters) }
    );
  }

  paymentMethods(filters: ExpenseReportFilters = {}):
    Observable<ReportResponse<PaymentMethodExpenseReport[]>> {
    return this.http.get<ReportResponse<PaymentMethodExpenseReport[]>>(
      `${this.baseUrl}/payment-methods`,
      { params: this.params(filters) }
    );
  }

  loadAll(filters: ExpenseReportFilters = {}) {
    return forkJoin({
      summary: this.summary(filters),
      categories: this.categories(filters),
      branches: this.branches(filters),
      paymentMethods: this.paymentMethods(filters),
    });
  }
}
