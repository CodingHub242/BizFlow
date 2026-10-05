import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

import { API_URL } from '../config/api.config';

export interface Branch {
  id: number;
  name: string;
  code: string;
  address?: string | null;
  phone?: string | null;
  email?: string | null;
  is_active: boolean;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface BranchPagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface BranchListParams {
  page?: number;
  search?: string;
  isActive?: boolean;
  perPage?: number;
}

export interface BranchListResponse {
  success: boolean;
  data: Branch[];
  meta: BranchPagination;
}

export interface BranchResponse {
  success: boolean;
  message: string;
  data: Branch;
}

@Injectable({
  providedIn: 'root',
})
export class BranchApi {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = API_URL;

  list(params?: BranchListParams): Observable<BranchListResponse> {
    const queryParams: Record<string, string> = {};

    if (params?.page !== undefined) {
      queryParams['page'] = String(params.page);
    }

    if (params?.search) {
      queryParams['search'] = params.search;
    }

    if (params?.isActive !== undefined) {
      queryParams['is_active'] = String(params.isActive);
    }

    if (params?.perPage !== undefined) {
      queryParams['per_page'] = String(params.perPage);
    }

    return this.http.get<BranchListResponse>(
      `${this.apiUrl}/branches`,
      { params: queryParams },
    );
  }

  get(id: number): Observable<BranchResponse> {
    return this.http.get<BranchResponse>(
      `${this.apiUrl}/branches/${id}`,
    );
  }
}