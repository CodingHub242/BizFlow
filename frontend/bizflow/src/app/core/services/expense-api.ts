
import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { API_URL } from '../config/api.config';

export type ExpensePaymentMethod =
  | 'cash'
  | 'bank_transfer'
  | 'mobile_money'
  | 'card'
  | 'other';

export interface Expense {
  id: number;
  branch_id: number;
  category_id: number | null;
  created_by: number;
  amount: number | string;
  payment_method: ExpensePaymentMethod;
  reference: string | null;
  expense_date: string;
  description: string;
  notes: string | null;
  created_at: string;
  updated_at: string;
}

export interface ExpensePayload {
  branch_id: number;
  category_id: number | null;
  amount: number;
  payment_method: ExpensePaymentMethod;
  reference?: string | null;
  expense_date: string;
  description: string;
  notes?: string | null;
}

export interface ExpenseListResponse {
  success: boolean;
  data: Expense[];
  meta: ExpensePaginationMeta;
}

export interface ExpenseResponse {
  success: boolean;
  data: Expense;
  message?: string;
}

export interface ExpenseFilters {
  page?: number;
  per_page?: number;
  branch_id?: number | null;
  category_id?: number | null;
  payment_method?: ExpensePaymentMethod | '';
  date_from?: string;
  date_to?: string;
  search?: string;
}

export interface ExpensePaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

@Injectable({
  providedIn: 'root',
})
export class ExpenseApi {
  private readonly apiUrl = `${API_URL}/expenses`;

  constructor(private readonly http: HttpClient) {}

  list(filters: ExpenseFilters = {}): Observable<ExpenseListResponse> {
    let params = new HttpParams();

    Object.entries(filters).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') {
        params = params.set(key, String(value));
      }
    });

    return this.http.get<ExpenseListResponse>(this.apiUrl, { params });
  }

  get(id: number): Observable<ExpenseResponse> {
    return this.http.get<ExpenseResponse>(`${this.apiUrl}/${id}`);
  }

  create(payload: ExpensePayload): Observable<ExpenseResponse> {
    return this.http.post<ExpenseResponse>(this.apiUrl, payload);
  }

  update(
    id: number,
    payload: ExpensePayload,
  ): Observable<ExpenseResponse> {
    return this.http.put<ExpenseResponse>(
      `${this.apiUrl}/${id}`,
      payload,
    );
  }

  delete(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${id}`);
  }
}
