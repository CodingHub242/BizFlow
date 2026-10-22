import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { API_URL } from '../config/api.config';

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

@Injectable({
  providedIn: 'root',
})
export class InventoryApis {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = API_URL;

  checkAvailability(params: {
    branch_id: number;
    catalog_item_id: number;
    quantity: number;
  }): Observable<InventoryAvailabilityResponse> {
    return this.http.get<InventoryAvailabilityResponse>(
      `${this.apiUrl}/inventory/availability`,
      {
        params: {
          branch_id: String(params.branch_id),
          catalog_item_id: String(params.catalog_item_id),
          quantity: String(params.quantity),
        },
      },
    );
  }
}