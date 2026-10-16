
import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { API_URL } from '../config/api.config';

export interface InvoiceCustomer {
  id: number;
  name: string;
  company_name?: string | null;
}

export interface InvoiceBranch {
  id: number;
  name: string;
  code?: string | null;
}

export interface Invoice {
  id: number;
  invoice_number: string;
  status: string;
  payment_status: string;
  subtotal: number | string;
  discount: number | string;
  tax: number | string;
  total: number | string;
  net_paid?: number;
  credit_applied?: number;
  balance_due?: number;
  issued_at?: string | null;
  due_at?: string | null;
  notes?: string | null;
  customer_id?: number | null;
  branch_id?: number | null;
  customer?: InvoiceCustomer | null;
  branch?: InvoiceBranch | null;
  created_at?: string;
  updated_at?: string;
}

export interface InvoiceListResponse {
  success: boolean;
  data: Invoice[] | {
    data: Invoice[];
    current_page?: number;
    last_page?: number;
    total?: number;
    per_page?: number;
  };
  meta?: {
    current_page?: number;
    last_page?: number;
    total?: number;
    per_page?: number;
  };
}

@Injectable({ providedIn: 'root' })
export class InvoicesApi {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = API_URL;

  list(): Observable<InvoiceListResponse> {
    return this.http.get<InvoiceListResponse>(
      `${this.apiUrl}/invoices`,
    );
  }

  get(id: number): Observable<{ success: boolean; data: Invoice }> {
    return this.http.get<{ success: boolean; data: Invoice }>(
      `${this.apiUrl}/invoices/${id}`,
    );
  }

  issue(id: number): Observable<unknown> {
    return this.http.post(
      `${this.apiUrl}/invoices/${id}/issue`,
      {},
    );
  }

  cancel(id: number): Observable<unknown> {
    return this.http.post(
      `${this.apiUrl}/invoices/${id}/cancel`,
      {},
    );
  }
}
