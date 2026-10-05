import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { API_URL } from '../config/api.config';

export interface Inventory {
  id: number;

  branch_id: number;
  catalog_item_id: number;

  quantity: number | string;
  reorder_level: number | string;

  branch?: {
    id: number;
    name: string;
    code?: string | null;
  } | null;

  catalog_item?: {
    id: number;
    name: string;
    type: 'product' | 'service';
    sku?: string | null;
    selling_price: number | string;
    track_inventory: boolean;
    is_active: boolean;
  } | null;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface InventoryPagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface InventoryListParams {
  page?: number;
  search?: string;
  branchId?: number;
  catalogItemId?: number;
  perPage?: number;
}

export interface InventoryListResponse {
  success: boolean;
  data: Inventory[];
  meta: InventoryPagination;
}

export interface InventoryResponse {
  success: boolean;
  message?: string;
  data: Inventory;
}

export interface InventoryMovement {
  id: number;
  branch_id: number;
  catalog_item_id: number;
  type: string;
  quantity: number | string;
  reference_type?: string | null;
  reference_id?: number | null;
  notes?: string | null;
  created_at?: string | null;

  branch?: {
    id: number;
    name: string;
  } | null;

  catalog_item?: {
    id: number;
    name: string;
    sku?: string | null;
  } | null;
}

export interface InventoryMovementPagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface InventoryMovementsResponse {
  success: boolean;
  data: InventoryMovement[];
  meta: InventoryMovementPagination;
}

export interface InventoryAvailability {
  catalog_item_id: number;
  branch_id: number;
  requested_quantity: number;
  available_quantity: number | null;
  shortfall_quantity: number;
  can_fulfill: boolean;
  tracks_inventory: boolean;
}

export interface InventoryAvailabilityResponse {
  success: boolean;
  data: InventoryAvailability;
}

export interface ReceiveStockRequest {
  branch_id: number;
  catalog_item_id: number;
  quantity: number;
  reference_type?: string | null;
  reference_id?: number | null;
  notes?: string | null;
}

export interface AdjustStockRequest {
  branch_id: number;
  catalog_item_id: number;
  quantity: number;
  notes?: string | null;
}

export interface TransferStockRequest {
  catalog_item_id: number;
  source_branch_id: number;
  destination_branch_id: number;
  quantity: number;
  notes?: string | null;
}

export interface InventoryActionResponse {
  success: boolean;
  message: string;
  data?: Inventory;
}

@Injectable({
  providedIn: 'root',
})
export class InventoryApi {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = API_URL;

  list(
    params?: InventoryListParams,
  ): Observable<InventoryListResponse> {
    const queryParams: Record<string, string> = {};

    if (params?.page !== undefined) {
      queryParams['page'] = String(params.page);
    }

    if (params?.branchId !== undefined) {
      queryParams['branch_id'] = String(params.branchId);
    }

    if (params?.catalogItemId !== undefined) {
      queryParams['catalog_item_id'] = String(params.catalogItemId);
    }

    if (params?.perPage !== undefined) {
      queryParams['per_page'] = String(params.perPage);
    }

    if (params?.search) {
        queryParams['search'] = params.search;
    }

    return this.http.get<InventoryListResponse>(
      `${this.apiUrl}/inventory`,
      { params: queryParams },
    );
  }

  get(id: number): Observable<InventoryResponse> {
    return this.http.get<InventoryResponse>(
      `${this.apiUrl}/inventory/${id}`,
    );
  }

  movements(params?: {
    page?: number;
    branchId?: number;
    catalogItemId?: number;
    type?: string;
    perPage?: number;
  }): Observable<InventoryMovementsResponse> {
    const queryParams: Record<string, string> = {};

    if (params?.page !== undefined) {
      queryParams['page'] = String(params.page);
    }

    if (params?.branchId !== undefined) {
      queryParams['branch_id'] = String(params.branchId);
    }

    if (params?.catalogItemId !== undefined) {
      queryParams['catalog_item_id'] = String(params.catalogItemId);
    }

    if (params?.type) {
      queryParams['type'] = params.type;
    }

    if (params?.perPage !== undefined) {
      queryParams['per_page'] = String(params.perPage);
    }

    return this.http.get<InventoryMovementsResponse>(
      `${this.apiUrl}/inventory/movements`,
      { params: queryParams },
    );
  }

  availability(
    branchId: number,
    catalogItemId: number,
    quantity: number,
  ): Observable<InventoryAvailabilityResponse> {
    return this.http.get<InventoryAvailabilityResponse>(
      `${this.apiUrl}/inventory/availability`,
      {
        params: {
          branch_id: String(branchId),
          catalog_item_id: String(catalogItemId),
          quantity: String(quantity),
        },
      },
    );
  }

  receive(
    data: ReceiveStockRequest,
  ): Observable<InventoryActionResponse> {
    return this.http.post<InventoryActionResponse>(
      `${this.apiUrl}/inventory/receive`,
      data,
    );
  }

  adjust(
    data: AdjustStockRequest,
  ): Observable<InventoryActionResponse> {
    return this.http.post<InventoryActionResponse>(
      `${this.apiUrl}/inventory/adjust`,
      data,
    );
  }

  transfer(
    data: TransferStockRequest,
  ): Observable<InventoryActionResponse> {
    return this.http.post<InventoryActionResponse>(
      `${this.apiUrl}/inventory/transfer`,
      data,
    );
  }
}