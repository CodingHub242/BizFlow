import { CommonModule } from '@angular/common';
import {
  ChangeDetectorRef,
  Component,
  OnDestroy,
  OnInit,
  inject,
} from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { finalize, Subject, takeUntil } from 'rxjs';

import { Branch, BranchApi } from '../../../core/services/branch-api';
import {
  InventoryApi,
  InventoryExportParams,
  InventoryImportBatch,
  InventoryImportPreviewRow,
} from '../../../core/services/inventory-api.service';

type ExportStockStatus =
  | 'in-stock'
  | 'low-stock'
  | 'out-of-stock';

@Component({
  selector: 'app-inventory-import-export',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './inventory-import-export.component.html',
  styleUrl: './inventory-import-export.component.scss',
})
export class InventoryImportExportComponent
  implements OnInit, OnDestroy {

  private readonly inventoryApi = inject(InventoryApi);
  private readonly branchApi = inject(BranchApi);
  private readonly router = inject(Router);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly destroy$ = new Subject<void>();

  branches: Branch[] = [];

  // Export filters
  exportBranchId: number | null = null;
  exportSearch = '';
  exportStockStatus: ExportStockStatus | '' = '';
  exportFormat: 'csv' | 'xlsx' = 'xlsx';
  exporting = false;
  exportError = '';

  // Import state
  selectedFile: File | null = null;
  analyzing = false;
  confirming = false;
  importError = '';
  importSuccess = '';
  batch: InventoryImportBatch | null = null;

  readonly previewPageSize = 25;
  previewPage = 1;

  ngOnInit(): void {
    this.branchApi.list({
      isActive: true,
      perPage: 100,
    })
    .pipe(takeUntil(this.destroy$))
    .subscribe({
      next: (response) => {
        this.branches = response.data;
        this.cdr.markForCheck();
      },
      error: () => {
        this.branches = [];
        this.cdr.markForCheck();
      },
    });
  }

  backToInventory(): void {
    this.router.navigate(['/inventory']);
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    this.selectedFile = null;
    this.batch = null;
    this.importError = '';
    this.importSuccess = '';
    this.previewPage = 1;

    if (!file) {
      return;
    }

    const lowerName = file.name.toLowerCase();
    const supported = lowerName.endsWith('.csv')
      || lowerName.endsWith('.xlsx');

    if (!supported) {
      this.importError = 'Choose a CSV or Excel (.xlsx) file.';
      input.value = '';
      return;
    }

    if (file.size > 10 * 1024 * 1024) {
      this.importError = 'The file must be 10 MB or smaller.';
      input.value = '';
      return;
    }

    this.selectedFile = file;
  }

  analyzeFile(): void {
    if (!this.selectedFile || this.analyzing) {
      return;
    }

    this.analyzing = true;
    this.importError = '';
    this.importSuccess = '';
    this.batch = null;
    this.previewPage = 1;

    this.inventoryApi.previewImport(this.selectedFile)
      .pipe(
        takeUntil(this.destroy$),
        finalize(() => {
          this.analyzing = false;
          this.cdr.markForCheck();
        }),
      )
      .subscribe({
        next: (response) => {
          this.batch = response.data;
          this.importSuccess = response.message
            || 'File analyzed. No stock has been changed.';
        },
        error: (error: HttpErrorResponse) => {
          this.importError = this.readError(
            error,
            'Unable to analyze this file.',
          );
        },
      });
  }

  confirmImport(): void {
    if (
      !this.batch
      || !this.batch.summary.can_confirm
      || this.confirming
    ) {
      return;
    }

    const invalidCount = this.batch.summary.invalid_rows;

    const prompt = invalidCount > 0
      ? `This will apply ${this.batch.summary.valid_rows} valid rows and skip ${invalidCount} invalid rows. Stock quantities will change. Continue?`
      : `This will apply ${this.batch.summary.valid_rows} rows and may change stock quantities. Continue?`;

    if (!window.confirm(prompt)) {
      return;
    }

    this.confirming = true;
    this.importError = '';
    this.importSuccess = '';

    this.inventoryApi.confirmImport(this.batch.import_id)
      .pipe(
        takeUntil(this.destroy$),
        finalize(() => {
          this.confirming = false;
          this.cdr.markForCheck();
        }),
      )
      .subscribe({
        next: (response) => {
          this.batch = response.data;
          this.importSuccess = response.message
            || 'Import processing completed.';
        },
        error: (error: HttpErrorResponse) => {
          this.importError = this.readError(
            error,
            'The import could not be completed.',
          );
        },
      });
  }

  get pagedPreviewRows(): InventoryImportPreviewRow[] {
    const rows = this.batch?.rows ?? [];
    const start = (this.previewPage - 1) * this.previewPageSize;

    return rows.slice(start, start + this.previewPageSize);
  }

  get previewPageCount(): number {
    const total = this.batch?.summary.total_rows ?? 0;

    return Math.max(1, Math.ceil(total / this.previewPageSize));
  }

  previousPreviewPage(): void {
    if (this.previewPage > 1) {
      this.previewPage--;
    }
  }

  nextPreviewPage(): void {
    if (this.previewPage < this.previewPageCount) {
      this.previewPage++;
    }
  }

  downloadTemplate(): void {
    const content = '\uFEFFbranch_code,sku,quantity,reorder_level\r\n';

    this.saveBlob(
      new Blob([content], { type: 'text/csv;charset=utf-8;' }),
      'bizflow-inventory-import-template.csv',
    );
  }

  exportInventory(): void {
    if (this.exporting) {
      return;
    }

    this.exporting = true;
    this.exportError = '';

    const params: InventoryExportParams = {
      format: this.exportFormat,
    };

    if (this.exportBranchId !== null) {
      params.branchId = this.exportBranchId;
    }

    if (this.exportSearch.trim()) {
      params.search = this.exportSearch.trim();
    }

    if (this.exportStockStatus) {
      params.stockStatus = this.exportStockStatus;
    }

    this.inventoryApi.exportInventory(params)
      .pipe(
        takeUntil(this.destroy$),
        finalize(() => {
          this.exporting = false;
          this.cdr.markForCheck();
        }),
      )
      .subscribe({
        next: (response) => {
          if (!response.body) {
            this.exportError = 'The server returned an empty export.';
            return;
          }

          const disposition = response.headers.get('content-disposition');
          const match = disposition?.match(/filename="?([^";]+)"?/i);

          const filename = match?.[1]
            ?? `bizflow-inventory.${this.exportFormat}`;

          this.saveBlob(response.body, filename);
        },
        error: (error: HttpErrorResponse) => {
          this.exportError = this.readError(
            error,
            'Unable to export inventory.',
          );
        },
      });
  }

  private saveBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    link.style.display = 'none';

    document.body.appendChild(link);
    link.click();
    link.remove();

    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  private readError(error: HttpErrorResponse,fallback: string): string {
    const message = error.error?.message;

    if (typeof message === 'string' && message.trim()) {
      return message;
    }

    return fallback;
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}