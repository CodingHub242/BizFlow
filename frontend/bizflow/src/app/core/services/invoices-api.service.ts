
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

export interface InvoiceLine {
  id: number;
  name?: string | null;
  item_name?: string | null;
  description?: string | null;
  quantity: number | string;
  unit_price: number | string;
  discount?: number | string;
  tax?: number | string;
  line_total?: number | string;
  catalog_item?: { name?: string | null } | null;
}


export type InvoicePaymentMethod =
  | 'cash'
  | 'bank_transfer'
  | 'mobile_money'
  | 'card'
  | 'cheque'
  | 'other';

export interface RecordInvoicePaymentRequest {
  amount: number;
  method: InvoicePaymentMethod;
  reference?: string;
  notes?: string;
  paid_at?: string;
}

export interface InvoicePayment {
  id: number;
  invoice_id: number;
  amount: number | string;
  method: InvoicePaymentMethod;
  reference?: string | null;
  notes?: string | null;
  paid_at?: string | null;
  created_at?: string;
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
  items?: InvoiceLine[];
  payments?: InvoicePayment[];
  created_at?: string;
  updated_at?: string;
}

export interface InvoiceListResponse {
  success?: boolean;
  data: Invoice[] | {
    data: Invoice[];
    current_page?: number;
    last_page?: number;
    total?: number;
    per_page?: number;
  };
  links?: {
    first?: string | null;
    last?: string | null;
    prev?: string | null;
    next?: string | null;
  };
  meta?: {
    current_page?: number;
    last_page?: number;
    total?: number;
    per_page?: number;
    from?: number | null;
    to?: number | null;
  };
 summary?: {
  matching_invoice_count?: number;
  matching_invoice_total?: number;
  outstanding_invoice_total: number;
};
}

@Injectable({ providedIn: 'root' })
export class InvoicesApi {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = API_URL;

  

  
list(
  page = 1,
  perPage = 15,
  filters: {
    search?: string;
    status?: string;
    payment_status?: string;
  } = {},
): Observable<InvoiceListResponse> {
  let params: Record<string, string> = {
    page: String(page),
    per_page: String(perPage),
  };

  if (filters.search?.trim()) {
    params['search'] = filters.search.trim();
  }

  if (filters.status) {
    params['status'] = filters.status;
  }

  if (filters.payment_status) {
    params['payment_status'] = filters.payment_status;
  }

  return this.http.get<InvoiceListResponse>(
    `${this.apiUrl}/invoices`,
    { params },
  );
}


  get(id: number): Observable<{ success: boolean; data: Invoice }> {
    return this.http.get<{ success: boolean; data: Invoice }>(
      `${this.apiUrl}/invoices/${id}`,
    );
  }


  recordPayment(id: number,payload: RecordInvoicePaymentRequest,): Observable<{ success: boolean; message?: string; data: InvoicePayment }> 
  {
    return this.http.post<{
      success: boolean;
      message?: string;
      data: InvoicePayment;
    }>(`${this.apiUrl}/invoices/${id}/payments`, payload);
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
