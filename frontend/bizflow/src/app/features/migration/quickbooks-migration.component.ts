
import { CommonModule } from '@angular/common';
import {ChangeDetectorRef,Component,OnDestroy,OnInit,inject} from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { finalize, forkJoin, Subject, switchMap, takeUntil, tap } from 'rxjs';
import { Branch, BranchApi } from '../../core/services/branch-api';
import {MigrationApi,MigrationAnalysis,MigrationEntityType,MigrationImportBatch,MigrationMappingResult,MigrationReport,MigrationReviewData,MigrationSession,MigrationValidationIssue,MigrationValidationResult} from '../../core/services/migration-api.service';

interface TargetField {
  key: string;
  label: string;
  aliases: string[];
}

interface MigrationEntityConfig {
  value: MigrationEntityType;
  label: string;
  description: string;
  requiresBranch: boolean;
  requiredTargets: string[];
  fields: TargetField[];
}

interface DisplayIssue {
  rowLabel: string;
  message: string;
}

const MIGRATION_ENTITIES: MigrationEntityConfig[] = [
  {
    value: 'customers',
    label: 'Customers',
    description: 'Customer names and contact details.',
    requiresBranch: false,
    requiredTargets: ['name'],
    fields: [
      {
        key: 'name',
        label: 'Customer Name',
        aliases: ['customer', 'customer name', 'display name', 'full name', 'name'],
      },
      {
        key: 'email',
        label: 'Email',
        aliases: ['email address', 'e-mail', 'email'],
      },
      {
        key: 'phone',
        label: 'Phone',
        aliases: ['phone number', 'mobile', 'mobile phone', 'phone'],
      },
      {
        key: 'company_name',
        label: 'Company',
        aliases: ['company', 'company name', 'business name'],
      },
    ],
  },
  {
    value: 'suppliers',
    label: 'Suppliers',
    description: 'Vendors and supplier contact details.',
    requiresBranch: false,
    requiredTargets: ['name'],
    fields: [
      {
        key: 'name',
        label: 'Supplier Name',
        aliases: ['vendor', 'vendor name', 'supplier', 'supplier name', 'name'],
      },
      {
        key: 'email',
        label: 'Email',
        aliases: ['email address', 'e-mail', 'email'],
      },
      {
        key: 'phone',
        label: 'Phone',
        aliases: ['phone number', 'mobile', 'phone'],
      },
      {
        key: 'company_name',
        label: 'Company',
        aliases: ['company', 'company name', 'business name'],
      },
    ],
  },
  {
    value: 'catalog_items',
    label: 'Products & Services',
    description: 'Catalog items, SKUs, costs and selling prices.',
    requiresBranch: false,
    requiredTargets: ['name'],
    fields: [
      {
        key: 'name',
        label: 'Product / Service Name',
        aliases: [
          'product/service name',
          'product name',
          'service name',
          'item',
          'item name',
          'name',
        ],
      },
      {
        key: 'sku',
        label: 'SKU',
        aliases: ['item number', 'product code', 'sku'],
      },
      {
        key: 'description',
        label: 'Description',
        aliases: ['sales description', 'purchase description', 'description'],
      },
      {
        key: 'selling_price',
        label: 'Selling Price',
        aliases: ['sales price', 'rate', 'price', 'selling price'],
      },
      {
        key: 'cost_price',
        label: 'Cost Price',
        aliases: ['cost', 'purchase cost', 'cost price'],
      },
    ],
  },
  {
    value: 'invoices',
    label: 'Invoices',
    description: 'Historical invoice records. A branch is required.',
    requiresBranch: true,
    requiredTargets: ['invoice_number'],
    fields: [
      {
        key: 'invoice_number',
        label: 'Invoice Number',
        aliases: ['invoice no', 'invoice #', 'number', 'invoice number'],
      },
      {
        key: 'customer_id',
        label: 'Customer',
        aliases: ['customer name', 'customer', 'customer id'],
      },
      {
        key: 'issued_at',
        label: 'Invoice Date',
        aliases: ['invoice date', 'date', 'transaction date'],
      },
      {
        key: 'due_at',
        label: 'Due Date',
        aliases: ['due date', 'due'],
      },
      {
        key: 'total',
        label: 'Invoice Total',
        aliases: ['amount', 'total amount', 'balance', 'total'],
      },
    ],
  },
  {
    value: 'expenses',
    label: 'Expenses',
    description: 'Historical expense records. A branch is required.',
    requiresBranch: true,
    requiredTargets: ['amount'],
    fields: [
      {
        key: 'expense_date',
        label: 'Expense Date',
        aliases: ['date', 'transaction date', 'expense date'],
      },
      {
        key: 'description',
        label: 'Payee / Description',
        aliases: ['payee', 'vendor', 'description', 'name'],
      },
      {
        key: 'category_id',
        label: 'Category',
        aliases: ['expense category', 'category'],
      },
      {
        key: 'amount',
        label: 'Amount',
        aliases: ['amount', 'total'],
      },
      {
        key: 'payment_method',
        label: 'Payment Method',
        aliases: ['payment method', 'payment type', 'method'],
      },
    ],
  },
  {
    value: 'payments',
    label: 'Invoice Payments',
    description: 'Historical payments linked to customers and invoices.',
    requiresBranch: false,
    requiredTargets: ['amount'],
    fields: [
      {
        key: 'paid_at',
        label: 'Payment Date',
        aliases: ['payment date', 'date', 'paid at'],
      },
      {
        key: 'customer_id',
        label: 'Customer',
        aliases: ['customer name', 'customer', 'customer id'],
      },
      {
        key: 'invoice_id',
        label: 'Invoice Number',
        aliases: ['invoice number', 'invoice no', 'invoice #'],
      },
      {
        key: 'amount',
        label: 'Amount',
        aliases: ['payment amount', 'amount', 'total'],
      },
      {
        key: 'method',
        label: 'Payment Method',
        aliases: ['payment method', 'method', 'payment type'],
      },
    ],
  },
];

@Component({
  selector: 'app-quickbooks-migration',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './quickbooks-migration.component.html',
  styleUrl: './quickbooks-migration.component.scss',
})
export class QuickbooksMigrationComponent implements OnInit, OnDestroy {
  private readonly api = inject(MigrationApi);
  private readonly branchApi = inject(BranchApi);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly destroy$ = new Subject<void>();

  readonly steps = [
    'Upload',
    'Analyze',
    'Mapping',
    'Validate',
    'Import',
    'Review',
  ];

  readonly entities = MIGRATION_ENTITIES;
  readonly maxFileBytes = 10 * 1024 * 1024;

  currentStep = 1;
  busyAction: 'creating' | 'uploading' | 'analyzing' | 'validating' | 'importing' | null = null;

  session: MigrationSession | null = null;
  selectedFile: File | null = null;
  fileUploaded = false;

  analysis: MigrationAnalysis | null = null;
  entityType: MigrationEntityType = 'customers';
  fieldMapping: Record<string, string | null> = {};
  mappingResult: MigrationMappingResult | null = null;

  branches: Branch[] = [];
  branchId: number | null = null;
  branchesError = '';

  validation: MigrationValidationResult | null = null;
  batch: MigrationImportBatch | null = null;
  reviewData: MigrationReviewData | null = null;
  reportData: MigrationReport | null = null;

  errorMessage = '';
  successMessage = '';
  reportError = '';

  get isBusy(): boolean {
    return this.busyAction !== null;
  }

  get selectedEntity(): MigrationEntityConfig {
    return this.entities.find((item) => item.value === this.entityType)
      ?? this.entities[0];
  }

  get selectedBranchName(): string {
  return this.branches.find(
    (branch) => branch.id === this.branchId,
  )?.name ?? 'No branch selected';
}

  get mappingHeaders(): string[] {
    return this.analysis?.headers ?? [];
  }

  get missingRequiredTargets(): string[] {
    const mappedTargets = Object.values(this.fieldMapping)
      .filter((value): value is string => value !== null && value !== '');

    return this.selectedEntity.requiredTargets.filter(
      (target) => !mappedTargets.includes(target),
    );
  }

  get canImport(): boolean {
    return Boolean(
      this.session
      && this.validation
      && this.validation.valid_rows > 0
      && (!this.selectedEntity.requiresBranch || this.branchId !== null)
      && !this.isBusy,
    );
  }

  get validationIssues(): DisplayIssue[] {
    return (this.validation?.errors ?? []).map((issue) =>
      this.toDisplayIssue(issue),
    );
  }

  get importIssues(): DisplayIssue[] {
    return (this.batch?.errors ?? []).map((issue) =>
      this.toDisplayIssue(issue),
    );
  }

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
        this.branchesError = 'Branches could not be loaded.';
        this.cdr.markForCheck();
      },
    });
  }

  createMigration(): void {
    if (this.isBusy || this.session) {
      return;
    }

    this.resetMessages();
    this.busyAction = 'creating';

    this.api.createSession()
      .pipe(
        takeUntil(this.destroy$),
        finalize(() => {
          this.busyAction = null;
          this.cdr.markForCheck();
        }),
      )
      .subscribe({
        next: (response) => {
          this.session = response.data;
          this.successMessage = response.message
            ?? 'QuickBooks migration session created.';
        },
        error: (error: HttpErrorResponse) => {
          this.errorMessage = this.readError(
            error,
            'Unable to start the migration.',
          );
        },
      });
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    this.selectedFile = null;
    this.fileUploaded = false;
    this.analysis = null;
    this.validation = null;
    this.mappingResult = null;
    this.batch = null;
    this.reviewData = null;
    this.reportData = null;
    this.resetMessages();

    if (!file) {
      return;
    }

    if (!file.name.toLowerCase().endsWith('.csv')) {
      this.errorMessage = 'QuickBooks migration currently accepts CSV exports only.';
      input.value = '';
      return;
    }

    if (file.size > this.maxFileBytes) {
      this.errorMessage = 'The file must be 10 MB or smaller.';
      input.value = '';
      return;
    }

    this.selectedFile = file;
  }

  uploadFile(): void {
    if (!this.session || !this.selectedFile || this.isBusy) {
      return;
    }

    this.resetMessages();
    this.busyAction = 'uploading';

    this.api.uploadFile(this.session.id, this.selectedFile)
      .pipe(
        takeUntil(this.destroy$),
        finalize(() => {
          this.busyAction = null;
          this.cdr.markForCheck();
        }),
      )
      .subscribe({
        next: (response) => {
          this.fileUploaded = true;
          this.session = { ...this.session!, ...response.data };
          this.successMessage = response.message
            ?? 'QuickBooks CSV uploaded. Analyze it to continue.';
        },
        error: (error: HttpErrorResponse) => {
          this.errorMessage = this.readError(
            error,
            'Unable to upload the CSV file.',
          );
        },
      });
  }

  analyzeFile(): void {
    if (!this.session || !this.fileUploaded || this.isBusy) {
      return;
    }

    this.resetMessages();
    this.busyAction = 'analyzing';

    this.api.analyze(this.session.id)
      .pipe(
        takeUntil(this.destroy$),
        finalize(() => {
          this.busyAction = null;
          this.cdr.markForCheck();
        }),
      )
      .subscribe({
        next: (response) => {
          this.analysis = response.data;
          this.entityType = this.detectEntityType(
            response.data.entity_type,
            response.data.headers,
          );
          this.fieldMapping = this.suggestMapping(
            response.data.headers,
            this.entityType,
          );
          this.currentStep = 3;
          this.successMessage = response.message
            ?? 'File analyzed. Review and confirm the column mapping.';
        },
        error: (error: HttpErrorResponse) => {
          this.errorMessage = this.readError(
            error,
            'Unable to analyze this CSV file.',
          );
        },
      });
  }

  onEntityTypeChange(value: MigrationEntityType): void {
    if (!this.entities.some((item) => item.value === value)) {
      return;
    }

    this.entityType = value;

    if (this.analysis) {
      this.fieldMapping = this.suggestMapping(
        this.analysis.headers,
        value,
      );
    }

    this.mappingResult = null;
    this.validation = null;
    this.batch = null;
    this.resetMessages();
  }

  validateMappingAndContinue(): void {
    if (!this.session || !this.analysis || this.isBusy) {
      return;
    }

    if (this.missingRequiredTargets.length > 0) {
      this.errorMessage =
        'Map the required field(s): '
        + this.missingRequiredTargets.join(', ')
        + '.';
      return;
    }

    this.resetMessages();
    this.busyAction = 'validating';

    const mapping = { ...this.fieldMapping };

    this.api.saveMapping(
      this.session.id,
      this.entityType,
      mapping,
    )
    .pipe(
      switchMap((mappingResponse) => {
        this.mappingResult = mappingResponse.data;

        return this.api.validate(
          this.session!.id,
          this.entityType,
          mapping,
        );
      }),
      takeUntil(this.destroy$),
      finalize(() => {
        this.busyAction = null;
        this.cdr.markForCheck();
      }),
    )
    .subscribe({
      next: (response) => {
        this.validation = response.data;
        this.currentStep = 4;
        this.successMessage = response.message
          ?? 'Validation complete. Review the errors before importing.';
      },
      error: (error: HttpErrorResponse) => {
        this.errorMessage = this.readError(
          error,
          'Unable to save the mapping or validate the CSV.',
        );
      },
    });
  }

  confirmImport(): void {
    if (!this.session || !this.validation || !this.canImport) {
      return;
    }

    const valid = this.validation.valid_rows;
    const invalid = this.validation.invalid_rows;

    const message = invalid > 0
      ? `This will attempt to import ${valid} valid row(s). There are ${invalid} invalid row(s), which may fail during import. Continue?`
      : `This will import ${valid} row(s) into Bizflow. Continue?`;

    if (!window.confirm(message)) {
      return;
    }

    this.resetMessages();
    this.busyAction = 'importing';
    this.currentStep = 5;

    const mapping = { ...this.fieldMapping };

    this.api.createBatch(this.session.id, this.entityType)
      .pipe(
        tap((response) => {
          this.batch = response.data;
        }),
        switchMap((response) => this.api.runImport(
          this.session!.id,
          response.data.id,
          mapping,
          this.selectedEntity.requiresBranch
            ? this.branchId ?? undefined
            : undefined,
        )),
        takeUntil(this.destroy$),
        finalize(() => {
          this.busyAction = null;
          this.cdr.markForCheck();
        }),
      )
      .subscribe({
        next: (response) => {
          this.batch = response.data;
          this.currentStep = 6;
          this.successMessage = response.message
            ?? 'Migration import processing completed.';

          this.loadFinalReview();
        },
        error: (error: HttpErrorResponse) => {
          this.errorMessage = this.readError(
            error,
            'The import did not complete. Check the batch status before trying again.',
          );
          // Keep the current batch ID, if one was created, so the failed
          // attempt is not silently repeated.
        },
      });
  }

  private loadFinalReview(): void {
    if (!this.session) {
      return;
    }

    this.reportError = '';

    this.api.review(this.session.id, this.entityType)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.reviewData = response.data;
          this.cdr.markForCheck();
        },
        error: (error: HttpErrorResponse) => {
          this.reportError = this.readError(
            error,
            'The import completed, but the detailed review could not be loaded.',
          );
          this.cdr.markForCheck();
        },
      });

    this.api.report(this.session.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (report) => {
          this.reportData = report;
          this.cdr.markForCheck();
        },
        error: (error: HttpErrorResponse) => {
          this.reportError = this.readError(
            error,
            'The import completed, but the migration report could not be loaded.',
          );
          this.cdr.markForCheck();
        },
      });
  }

  startAnotherMigration(): void {
    this.session = null;
    this.selectedFile = null;
    this.fileUploaded = false;
    this.analysis = null;
    this.entityType = 'customers';
    this.fieldMapping = {};
    this.mappingResult = null;
    this.validation = null;
    this.batch = null;
    this.reviewData = null;
    this.reportData = null;
    this.branchId = null;
    this.currentStep = 1;
    this.resetMessages();
  }

  sampleValue(row: Record<string, unknown> | unknown[],header: string,index: number): string 
  {
    const value = Array.isArray(row)
    ? row[index]
    : (row as Record<string, unknown>)[header];

    return value === null || value === undefined || value === ''
      ? '—'
      : String(value);
  }

  private suggestMapping(
    headers: string[],
    entityType: MigrationEntityType,
  ): Record<string, string | null> {
    const config = this.entities.find((item) => item.value === entityType);
    const result: Record<string, string | null> = {};

    if (!config) {
      headers.forEach((header) => result[header] = null);
      return result;
    }

    for (const header of headers) {
      const normalizedHeader = this.normalize(header);

      const field = config.fields.find((candidate) => {
        const synonyms = [
          candidate.key,
          candidate.label,
          ...candidate.aliases,
        ];

        return synonyms.some(
          (alias) => this.normalize(alias) === normalizedHeader,
        );
      });

      result[header] = field?.key ?? null;
    }

    return result;
  }

  private detectEntityType(detectedType: string | null,headers: string[],): MigrationEntityType 
  {
    if (
      detectedType
      && this.entities.some((item) => item.value === detectedType)
    ) {
      return detectedType as MigrationEntityType;
    }

    const scores = this.entities.map((entity) => ({
      entity,
      score: entity.fields.reduce((total, field) => {
        const allAliases = [
          field.key,
          field.label,
          ...field.aliases,
        ].map((alias) => this.normalize(alias));

        return total + (
          headers.some((header) => allAliases.includes(this.normalize(header)))
            ? 1
            : 0
        );
      }, 0),
    }));

    scores.sort((a, b) => b.score - a.score);

    return scores[0]?.score > 0
      ? scores[0].entity.value
      : 'customers';
  }

  private normalize(value: string): string {
    return value.toLowerCase().replace(/[^a-z0-9]/g, '');
  }

  private toDisplayIssue(issue: MigrationValidationIssue): DisplayIssue {
    const rowLabel = issue.row !== undefined
      ? `Row ${issue.row}`
      : issue.field
        ? String(issue.field)
        : 'Import';

    const message = typeof issue.message === 'string'
      ? issue.message
      : JSON.stringify(issue);

    return { rowLabel, message };
  }

  private readError(error: HttpErrorResponse, fallback: string): string {
    const body = error.error as {
      message?: unknown;
      errors?: Record<string, unknown>;
    } | null;

    if (typeof body?.message === 'string' && body.message.trim()) {
      return body.message;
    }

    if (body?.errors && typeof body.errors === 'object') {
      const messages = Object.values(body.errors).flatMap((value) =>
        Array.isArray(value) ? value.map(String) : [String(value)],
      );

      if (messages.length > 0) {
        return messages.join(' ');
      }
    }

    return fallback;
  }

  private resetMessages(): void {
    this.errorMessage = '';
    this.successMessage = '';
    this.reportError = '';
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}
