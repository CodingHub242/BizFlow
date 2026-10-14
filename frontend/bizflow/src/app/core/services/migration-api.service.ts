
import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { API_URL } from '../config/api.config';

export type MigrationEntityType =
  | 'customers'
  | 'suppliers'
  | 'catalog_items'
  | 'invoices'
  | 'expenses'
  | 'payments';

export interface MigrationApiResponse<T> {
  success: boolean;
  message?: string;
  data: T;
}

export interface MigrationSession {
  id: number;
  source: 'quickbooks';
  status: string;
  created_at?: string;
  original_filename?: string | null;
  file_size?: number | null;
  mime_type?: string | null;
}

export interface MigrationAnalysis {
  id: number;
  migration_session_id: number;
  entity_type: string | null;
  row_count: number;
  headers: string[];
  sample_rows: Array<Record<string, unknown> | unknown[]>;
}

export interface MigrationMappingResult {
  id: number;
  migration_session_id: number;
  entity_type: MigrationEntityType;
  field_mapping: Record<string, string | null>;
}

export interface MigrationValidationIssue {
  row?: number;
  field?: string;
  message?: string;
  [key: string]: unknown;
}

export interface MigrationValidationResult {
  total_rows: number;
  valid_rows: number;
  invalid_rows: number;
  errors: MigrationValidationIssue[];
}

export interface MigrationImportBatch {
  id: number;
  migration_session_id: number;
  entity_type: MigrationEntityType;
  status: string;
  total_rows: number;
  successful_rows: number;
  failed_rows: number;
  errors: MigrationValidationIssue[];
}

export interface MigrationReviewData {
  session?: Record<string, unknown>;
  analysis?: {
    entity_type?: string;
    row_count?: number;
    headers?: string[];
  };
  mapping?: {
    field_mapping?: Record<string, string | null>;
  };
  validation?: MigrationValidationResult;
  batches?: MigrationImportBatch[];
  [key: string]: unknown;
}

export interface MigrationReport {
  summary?: {
    total_batches?: number;
    completed_batches?: number;
    failed_batches?: number;
    pending_batches?: number;
    total_rows?: number;
    successful_rows?: number;
    failed_rows?: number;
    complete?: boolean;
  };
  batches?: MigrationImportBatch[];
  [key: string]: unknown;
}

@Injectable({
  providedIn: 'root',
})
export class MigrationApi {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = API_URL;

  private sessionUrl(sessionId: number): string {
    return `${this.apiUrl}/migration-sessions/${sessionId}`;
  }

  createSession(): Observable<MigrationApiResponse<MigrationSession>> {
    return this.http.post<MigrationApiResponse<MigrationSession>>(
      `${this.apiUrl}/migration-sessions`,
      { source: 'quickbooks' },
    );
  }

  uploadFile(sessionId: number,file: File): Observable<MigrationApiResponse<MigrationSession>> {
    const formData = new FormData();
    formData.append('file', file, file.name);

    return this.http.post<MigrationApiResponse<MigrationSession>>(
      `${this.sessionUrl(sessionId)}/upload`,
      formData,
    );
  }

  analyze(sessionId: number): Observable<MigrationApiResponse<MigrationAnalysis>> {
    return this.http.post<MigrationApiResponse<MigrationAnalysis>>(
      `${this.sessionUrl(sessionId)}/analyze`,
      {},
    );
  }

  saveMapping(sessionId: number,entityType: MigrationEntityType,fieldMapping: Record<string, string | null>): Observable<MigrationApiResponse<MigrationMappingResult>> {
    return this.http.post<MigrationApiResponse<MigrationMappingResult>>(
      `${this.sessionUrl(sessionId)}/mapping`,
      {
        entity_type: entityType,
        field_mapping: fieldMapping,
      },
    );
  }

  validate(sessionId: number,entityType: MigrationEntityType,fieldMapping: Record<string, string | null>): Observable<MigrationApiResponse<MigrationValidationResult>> {
    return this.http.post<MigrationApiResponse<MigrationValidationResult>>(
      `${this.sessionUrl(sessionId)}/validate`,
      {
        entity_type: entityType,
        field_mapping: fieldMapping,
      },
    );
  }

  createBatch(sessionId: number,entityType: MigrationEntityType): Observable<MigrationApiResponse<MigrationImportBatch>> {
    return this.http.post<MigrationApiResponse<MigrationImportBatch>>(
      `${this.sessionUrl(sessionId)}/batches`,
      { entity_type: entityType },
    );
  }

  runImport(sessionId: number,batchId: number,mapping: Record<string, string | null>,branchId?: number,): Observable<MigrationApiResponse<MigrationImportBatch>> {
    const body: {
      mapping: Record<string, string | null>;
      branch_id?: number;
    } = { mapping };

    if (branchId !== undefined) {
      body.branch_id = branchId;
    }

    return this.http.post<MigrationApiResponse<MigrationImportBatch>>(
      `${this.sessionUrl(sessionId)}/batches/${batchId}/import`,
      body,
    );
  }

  review(sessionId: number,entityType: MigrationEntityType,): Observable<MigrationApiResponse<MigrationReviewData>> {
    return this.http.get<MigrationApiResponse<MigrationReviewData>>(
      `${this.sessionUrl(sessionId)}/review`,
      { params: { entity_type: entityType } },
    );
  }

  report(sessionId: number): Observable<MigrationReport> {
    return this.http.get<MigrationReport>(
      `${this.sessionUrl(sessionId)}/report`,
    );
  }
}
