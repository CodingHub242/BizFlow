import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { API_URL } from '../config/api.config';

export type QuickBooksConnectionStatus =
  | 'connected'
  | 'disconnected'
  | 'reauthorization_required';

export type QuickBooksMigrationEntityType =
  | 'customers'
  | 'suppliers'
  | 'catalog_items'
  | 'invoices'
  | 'bills'
  | 'estimates'
  | 'chart_of_accounts'
  | 'expenses'
  | 'payments';

export type QuickBooksImportEntityType =
  | 'customers'
  | 'suppliers'
  | 'catalog_items'
  | 'invoices'
  | 'bills'
  | 'estimates'
  | 'chart_of_accounts'
  | 'payments';

export interface QuickBooksConnection {
  id: number;
  realm_id: string;
  company_name: string | null;
  connection_status: QuickBooksConnectionStatus;
  access_token_expires_at?: string | null;
  refresh_token_expires_at?: string | null;
  connected_at?: string | null;
  last_synced_at?: string | null;
  created_at?: string | null;
}

export interface QuickBooksApiResponse<T> {
  success: boolean;
  message?: string;
  data: T;
}

export interface QuickBooksConnectData {
  authorization_url: string;
}

export interface QuickBooksCompanyAddress {
  line1: string | null;
  line2: string | null;
  city: string | null;
  region: string | null;
  postal_code: string | null;
  country: string | null;
}

export interface QuickBooksCompanyInfo {
  id: string;
  company_name: string | null;
  legal_name: string | null;
  country: string | null;
  email: string | null;
  phone: string | null;
  fiscal_year_start_month: string | null;
  address: QuickBooksCompanyAddress;
}

export interface QuickBooksCompanyInfoData {
  connection_id: number;
  realm_id: string;
  company: QuickBooksCompanyInfo;
}

export interface QuickBooksCatalogEntity {
  entity_type: QuickBooksMigrationEntityType;
  label: string;
  quickbooks_entity: string;
  record_count: number;
  sample_url: string;
}

export interface QuickBooksMigrationCatalog {
  connection_id: number;
  realm_id: string;
  entities: QuickBooksCatalogEntity[];
}

export interface QuickBooksPreviewSample {
  quickbooks_id: string | null;
  name?: string | null;
  customer?: string | null;
  supplier?: string | null;
  [key: string]: unknown;
}

export interface QuickBooksEntityPreview {
  connection_id: number;
  entity_type: QuickBooksMigrationEntityType;
  label: string;
  record_count: number;
  sample_count: number;
  more_records_available: boolean;
  samples: QuickBooksPreviewSample[];
}

export interface QuickBooksImportResult {
  connection_id: number;
  entity_type: QuickBooksImportEntityType;
  branch_id?: number;

  fetched_count: number;
  imported_count: number;
  already_imported_count: number;
  skipped_count: number;

  hierarchy_links?: number;
  unresolved_line_reference_count?: number;
  allocation_count?: number;
  unresolved_invoice_reference_count?: number;
  unapplied_or_unlinked_payment_count?: number;
  source_amount_difference_count?: number;
}

export interface QuickBooksDisconnectResponse {
  success: boolean;
  message: string;
}

@Injectable({
  providedIn: 'root',
})
export class QuickBooksApi {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = API_URL;

  private readonly baseUrl =
    `${this.apiUrl}/integrations/quickbooks`;

  connect(): Observable<QuickBooksApiResponse<QuickBooksConnectData>> 
  {
    return this.http.post<
      QuickBooksApiResponse<QuickBooksConnectData>
    >(
      `${this.baseUrl}/connect`,
      {},
    );
  }

  status(): Observable<QuickBooksApiResponse<QuickBooksConnection[]>> 
  {
    return this.http.get<
      QuickBooksApiResponse<QuickBooksConnection[]>
    >(
      `${this.baseUrl}/status`,
    );
  }

  disconnect(connectionId: number,): Observable<QuickBooksDisconnectResponse> 
  {
    return this.http.delete<QuickBooksDisconnectResponse>(
      `${this.baseUrl}/connections/${connectionId}`,
    );
  }

  companyInfo(connectionId: number,): Observable<QuickBooksApiResponse<QuickBooksCompanyInfoData>> 
  {
    return this.http.get<
      QuickBooksApiResponse<QuickBooksCompanyInfoData>
    >(
      `${this.baseUrl}/connections/${connectionId}/company-info`,
    );
  }

  migrationCatalog(connectionId: number,): Observable<QuickBooksApiResponse<QuickBooksMigrationCatalog>> 
  {
    return this.http.get<
      QuickBooksApiResponse<QuickBooksMigrationCatalog>
    >(
      `${this.baseUrl}/connections/${connectionId}/migration-catalog`,
    );
  }

  previewEntity(connectionId: number,entityType: QuickBooksMigrationEntityType,sampleSize = 3,): Observable<QuickBooksApiResponse<QuickBooksEntityPreview>> 
  {
    const params: Record<string, string> = {
      sample_size: String(sampleSize),
    };

    return this.http.get<
      QuickBooksApiResponse<QuickBooksEntityPreview>
    >(
      `${this.baseUrl}/connections/${connectionId}/migration-catalog/${entityType}`,
      { params },
    );
  }

  importEntity(connectionId: number,entityType: QuickBooksImportEntityType,options: { branchId?: number } = {},): Observable<QuickBooksApiResponse<QuickBooksImportResult>>
  {
    const body: { branch_id?: number } = {};

    if (options.branchId !== undefined) {
      body.branch_id = options.branchId;
    }

    return this.http.post<
      QuickBooksApiResponse<QuickBooksImportResult>
    >(
      `${this.baseUrl}/connections/${connectionId}/migration-catalog/${entityType}/import`,
      body,
    );
  }
}
