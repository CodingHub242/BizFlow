import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';

import { API_URL } from '../config/api.config';

export type OrderStatus =
  | 'draft'
  | 'pending'
  | 'confirmed'
  | 'partially_fulfilled'
  | 'fulfilled'
  | 'cancelled';

export type PaymentStatus =
  | 'unpaid'
  | 'partially_paid'
  | 'paid'
  | 'refunded';

export type OrderPaymentMethod =
  | 'cash'
  | 'bank_transfer'
  | 'mobile_money'
  | 'card'
  | 'other';

export interface SalesCustomer {
  id: number;
  name: string;
  company_name?: string | null;
  email?: string | null;
  phone?: string | null;
}

export interface SalesBranch {
  id: number;
  name: string;
  code: string;
  is_active: boolean;
}

export interface SalesCatalogItem {
  id: number;
  name: string;
  type: 'product' | 'service';
  sku?: string | null;
  selling_price: number | string;
  track_inventory: boolean;
  is_active: boolean;
}

export interface SalesOrderItem {
  id: number;
  order_id: number;
  catalog_item_id: number | null;
  catalog_item?: SalesCatalogItem | null;
  quantity: string | number;
  unit_price: string | number;
  discount: string | number;
  tax: string | number;
  line_total: string | number;
}

export interface SalesPayment {
  id: number;
  order_id: number;
  amount: string | number;
  method: OrderPaymentMethod;
  reference?: string | null;
  notes?: string | null;
  paid_at: string;
}

export interface SalesOrder {
  id: number;
  tenant_id: number;
  branch_id: number;
  customer_id?: number | null;
  created_by: number;
  order_number: string;
  status: OrderStatus;
  payment_status: PaymentStatus;
  subtotal: string | number;
  discount: string | number;
  tax: string | number;
  total: string | number;
  notes?: string | null;
  branch?: SalesBranch | null;
  customer?: SalesCustomer | null;
  items?: SalesOrderItem[];
  payments?: SalesPayment[];
  created_at?: string;
  updated_at?: string;
}

export interface SalesPagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number | null;
  to?: number | null;
}

export interface SalesListResponse {
  data: SalesOrder[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number | null;
  to?: number | null;
}

export interface SalesListParams {
  page?: number;
  search?: string;
  status?: OrderStatus;
  payment_status?: PaymentStatus;
  branch_id?: number;
  customer_id?: number;
  per_page?: number;
}

export interface CreateSalesOrderItem {
  catalog_item_id: number;
  quantity: number;
  discount?: number;
  tax?: number;
}

export interface CreateSalesOrderRequest {
  branch_id: number;
  customer_id?: number | null;
  order_number: string;
  items: CreateSalesOrderItem[];
  discount?: number;
  tax?: number;
  notes?: string | null;
}

export interface SalesOrderResponse {
  message: string;
  data: SalesOrder;
}

export interface RecordSalesPaymentRequest {
  amount: number;
  method: OrderPaymentMethod;
  reference?: string | null;
  notes?: string | null;
  paid_at: string;
}

export interface SalesPaymentResponse {
  message: string;
  data: SalesPayment;
  payment_status: PaymentStatus;
}

export interface SalesFulfillmentResponse {
  message: string;
  data: SalesOrder;
}

@Injectable({
  providedIn: 'root',
})
export class SalesService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = API_URL;

  list(params?: SalesListParams): Observable<SalesListResponse> {
    let queryParams = new HttpParams();

    if (params?.page !== undefined) {
      queryParams = queryParams.set('page', params.page);
    }

    if (params?.search) {
      queryParams = queryParams.set('search', params.search);
    }

    if (params?.status) {
      queryParams = queryParams.set('status', params.status);
    }

    if (params?.payment_status) {
      queryParams = queryParams.set(
        'payment_status',
        params.payment_status,
      );
    }

    if (params?.branch_id !== undefined) {
      queryParams = queryParams.set('branch_id', params.branch_id);
    }

    if (params?.customer_id !== undefined) {
      queryParams = queryParams.set('customer_id', params.customer_id);
    }

    if (params?.per_page !== undefined) {
      queryParams = queryParams.set('per_page', params.per_page);
    }

    return this.http.get<SalesListResponse>(
      `${this.apiUrl}/sales`,
      { params: queryParams },
    );
  }

  get(id: number): Observable<{ data: SalesOrder }> {
    return this.http.get<{ data: SalesOrder }>(
      `${this.apiUrl}/sales/${id}`,
    );
  }

  create(data: CreateSalesOrderRequest): Observable<SalesOrderResponse> {
    return this.http.post<SalesOrderResponse>(
      `${this.apiUrl}/sales`,
      data,
    );
  }

  recordPayment(
    orderId: number,
    data: RecordSalesPaymentRequest,
  ): Observable<SalesPaymentResponse> {
    return this.http.post<SalesPaymentResponse>(
      `${this.apiUrl}/sales/${orderId}/payments`,
      data,
    );
  }

  fulfill(orderId: number): Observable<SalesFulfillmentResponse> {
    return this.http.post<SalesFulfillmentResponse>(
      `${this.apiUrl}/sales/${orderId}/fulfill`,
      {},
    );
  }
}