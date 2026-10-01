import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

import { API_URL } from '../config/api.config';

export interface Customer {
  id: number;
  name: string;
  email?: string | null;
  phone?: string | null;
  address?: string | null;
  city?: string | null;
  country?: string | null;
  notes?: string | null;
  is_active?: boolean;

  company_name?: string | null;
}

export interface CustomerPagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface CustomerListParams {
  page?: number;
  search?: string;
  perPage?: number;
}

export interface CustomerListResponse {
  success: boolean;
  data: Customer[];
  meta: CustomerPagination;
}

export interface CreateCustomerRequest {
  name: string;
  email?: string | null;
  phone?: string | null;
  company_name?: string | null;
  address?: string | null;
  city?: string | null;
  country?: string | null;
  notes?: string | null;
}

export interface CustomerResponse {
  success: boolean;
  message: string;
  data: Customer;
}

export interface UpdateCustomerRequest {
  name: string;
  email?: string | null;
  phone?: string | null;
  company_name?: string | null;
  address?: string | null;
  city?: string | null;
  country?: string | null;
  notes?: string | null;
}

export interface CustomerDetailResponse {
  success: boolean;
  data: Customer;
}

@Injectable({
  providedIn: 'root',
})
export class CustomerApi {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = API_URL;

  list(params?: CustomerListParams): Observable<CustomerListResponse> {
        const queryParams: Record<string, string> = {};

        if (params?.page !== undefined) {
            queryParams['page'] = String(params.page);
        }

        if (params?.search) {
            queryParams['search'] = params.search;
        }

        if (params?.perPage !== undefined) {
            queryParams['per_page'] = String(params.perPage);
        }

        return this.http.get<CustomerListResponse>(
            `${this.apiUrl}/customers`,
            {
            params: queryParams,
            },
        );
    }

    create(data: CreateCustomerRequest): Observable<CustomerResponse> {
        return this.http.post<CustomerResponse>(
            `${this.apiUrl}/customers`,
            data,
        );
    }

    get(id: number): Observable<CustomerDetailResponse> {
        return this.http.get<CustomerDetailResponse>(
            `${this.apiUrl}/customers/${id}`,
        );
    }

    update(id: number,data: UpdateCustomerRequest,): Observable<CustomerResponse> {
        return this.http.put<CustomerResponse>(
            `${this.apiUrl}/customers/${id}`,
            data,
        );
    }
}