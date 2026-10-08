import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { API_URL } from '../config/api.config';

export type CatalogItemType = 'product' | 'service';

export interface CatalogItem {
  id: number;
  name: string;
  type: CatalogItemType;
  sku?: string | null;
  description?: string | null;
  unit?: string | null;
  cost_price: number | string;
  selling_price: number | string;
  tax_rate?: number | string | null;
  track_inventory: boolean;
  is_active: boolean;
  image_url : string | null;
  created_at?: string | null;
  updated_at?: string | null;
  deleted_at?: string | null;
}

export interface CatalogPagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface CatalogListParams {
  page?: number;
  search?: string;
  type?: CatalogItemType;
  isActive?: boolean;
  perPage?: number;
}

export interface CatalogListResponse {
  success: boolean;
  data: CatalogItem[];
  meta: CatalogPagination;
}

export interface CreateCatalogItemRequest {
  name: string;
  type: CatalogItemType;
  sku?: string | null;
  description?: string | null;
  unit?: string | null;
  cost_price: number;
  selling_price: number;
  tax_rate?: number | null;
  track_inventory?: boolean;
  is_active?: boolean;
}

export interface UpdateCatalogItemRequest {
  name?: string;
  type?: CatalogItemType;
  sku?: string | null;
  description?: string | null;
  unit?: string | null;
  cost_price?: number;
  selling_price?: number;
  tax_rate?: number | null;
  track_inventory?: boolean;
  is_active?: boolean;
}

export interface CatalogItemResponse {
  success: boolean;
  message: string;
  data: CatalogItem;
}

export interface CatalogDeleteResponse {
  success: boolean;
  message: string;
}

@Injectable({
  providedIn: 'root',
})
export class CatalogApi {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = API_URL;

  list(params?: CatalogListParams,): Observable<CatalogListResponse> {
    const queryParams: Record<string, string> = {};

    if (params?.page !== undefined) {
      queryParams['page'] = String(params.page);
    }

    if (params?.search) {
      queryParams['search'] = params.search;
    }

    if (params?.type) {
      queryParams['type'] = params.type;
    }

    if (params?.isActive !== undefined) {
      queryParams['is_active'] = String(params.isActive);
    }

    if (params?.perPage !== undefined) {
      queryParams['per_page'] = String(params.perPage);
    }

    return this.http.get<CatalogListResponse>(
      `${this.apiUrl}/catalog-items`,
      {
        params: queryParams,
      },
    );
  }

  archived(params?: CatalogListParams): Observable<CatalogListResponse> {
        const queryParams: Record<string, string> = {};

        if (params?.page !== undefined) {
            queryParams['page'] = String(params.page);
        }

        if (params?.search) {
            queryParams['search'] = params.search;
        }

        if (params?.type) {
            queryParams['type'] = params.type;
        }

        if (params?.perPage !== undefined) {
            queryParams['per_page'] = String(params.perPage);
        }

        return this.http.get<CatalogListResponse>(
            `${this.apiUrl}/catalog-items/archived`,
            { params: queryParams }
        );
    }

  create(
    data: CreateCatalogItemRequest,
  ): Observable<CatalogItemResponse> {
    return this.http.post<CatalogItemResponse>(
      `${this.apiUrl}/catalog-items`,
      data,
    );
  }

  get(id: number): Observable<CatalogItemResponse> {
    return this.http.get<CatalogItemResponse>(
      `${this.apiUrl}/catalog-items/${id}`,
    );
  }

  update(
    id: number,
    data: UpdateCatalogItemRequest,
  ): Observable<CatalogItemResponse> {
    return this.http.put<CatalogItemResponse>(
      `${this.apiUrl}/catalog-items/${id}`,
      data,
    );
  }

  delete(id: number): Observable<CatalogDeleteResponse> {
    return this.http.delete<CatalogDeleteResponse>(
      `${this.apiUrl}/catalog-items/${id}`,
    );
  }

  restore(id: number): Observable<CatalogItemResponse> {
    return this.http.post<CatalogItemResponse>(
      `${this.apiUrl}/catalog-items/${id}/restore`,
      {},
    );
  }
}