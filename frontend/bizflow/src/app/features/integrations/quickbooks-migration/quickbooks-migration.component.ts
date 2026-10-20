import {Component,ChangeDetectorRef,OnDestroy,OnInit,inject} from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { FormsModule } from '@angular/forms';
import {QuickBooksApi,QuickBooksConnection,QuickBooksCompanyInfoData,QuickBooksEntityPreview,QuickBooksImportEntityType,QuickBooksImportResult,QuickBooksMigrationCatalog,QuickBooksMigrationEntityType,QuickBooksPreviewSample,QuickBooksCreditMemoReconciliationResult} from '../../../core/services/quickbooks-api';
import { EMPTY, Subscription } from 'rxjs';
import { expand, reduce } from 'rxjs/operators';
import {Branch,BranchApi} from '../../../core/services/branch-api';
import { CatalogApi, CatalogItem } from '../../../core/services/catalog-api';
import { from, of } from 'rxjs';
import { catchError, concatMap, map, toArray } from 'rxjs/operators';
import {SearchableSelectComponent,SearchableSelectOption} from '../../../shared/components/searchable-select/searchable-select.component';

@Component({
  selector: 'app-quickbooks-migration',
  templateUrl: './quickbooks-migration.component.html',
  styleUrls: ['./quickbooks-migration.component.scss'],
  imports: [FormsModule,SearchableSelectComponent],
})
export class QuickBooksMigrationComponent implements OnInit, OnDestroy {
  private readonly quickBooksApi = inject(QuickBooksApi);
  private readonly route = inject(ActivatedRoute);
  private readonly changeDetector = inject(ChangeDetectorRef);
  private readonly branchApi = inject(BranchApi);
  private readonly branchPageSize = 100;
  private readonly catalogApi = inject(CatalogApi);
  private readonly subscriptions: Subscription[] = [];

  connections: QuickBooksConnection[] = [];
  selectedConnectionId: number | null = null;

  catalog: QuickBooksMigrationCatalog | null = null;
  companyInfo: QuickBooksCompanyInfoData | null = null;
  branches: Branch[] = [];
  branchOptions: SearchableSelectOption[] = [];
  loadingBranches = false;
  branchError = '';

  preview: QuickBooksEntityPreview | null = null;
  importResult: QuickBooksImportResult | null = null;

  loadingConnections = false;
  loadingCatalog = false;
  loadingCompanyInfo = false;
  connecting = false;

  loadingPreview: QuickBooksMigrationEntityType | null = null;
  importingEntity: QuickBooksImportEntityType | null = null;

  pendingImportEntity: QuickBooksImportEntityType | null = null;
  branchId: number | null = null;

  reconcilingCreditMemoApplications = false;

  creditMemoReconciliationResult: QuickBooksCreditMemoReconciliationResult | null = null;

  successMessage = '';
  errorMessage = '';

  readonly branchRequiredEntities: QuickBooksImportEntityType[] = [
    'invoices',
    'bills',
    'estimates',
    'credit_memos',
    'expenses',
  ];

  readonly importableEntities: QuickBooksImportEntityType[] = [
    'customers',
    'suppliers',
    'catalog_items',
    'invoices',
    'bills',
    'estimates',
    'expenses',
    'credit_memos',
    'chart_of_accounts',
    'payments',
  ];



  ///Opening Stock
  trackedProducts: CatalogItem[] = [];
loadingTrackedProducts = false;

openingStockBranchId: number | null = null;
openingQuantities: Record<number, number | null> = {};

openingStockResults: {
  productName: string;
  quantity: number;
  success: boolean;
  message: string;
}[] = [];

openingStockPage = 1;
openingStockLastPage = 1;
openingStockTotal = 0;
openingStockSearch = '';
openingStockPageSize = 20;
catalogItemsImportCompleted = false;

initializingOpeningStock = false;
openingStockError = '';

  constructor(cdr: ChangeDetectorRef) {
  this.changeDetector = cdr;
}

  ngOnInit(): void {
    this.subscriptions.push(
      this.route.queryParamMap.subscribe((params) => {
        this.loadConnections();
        this.loadBranches();
        const callbackResult = params.get('quickbooks');

        if (callbackResult) {
          this.handleOAuthResult(callbackResult);
        }
        
      }),
    );
  }

  get selectedConnection(): QuickBooksConnection | null {
    return (
      this.connections.find(
        (connection) => connection.id === this.selectedConnectionId,
      ) ?? null
    );
  }

  get entities(): QuickBooksMigrationCatalog['entities'] {
    return this.catalog?.entities ?? [];
  }

  private handleOAuthResult(result: string): void {
    const messages: Record<string, string> = {
      connected: 'QuickBooks connected successfully.',
      authorization_denied: 'QuickBooks authorization was cancelled.',
      invalid_state: 'The QuickBooks authorization session expired. Please try again.',
      invalid_callback: 'The QuickBooks authorization response was invalid.',
      connection_failed: 'QuickBooks could not be connected. Please try again.',
    };

    if (result === 'connected') {
      this.successMessage = messages[result];
      this.errorMessage = '';
      return;
    }

    this.errorMessage =
      messages[result] ?? 'QuickBooks connection could not be completed.';
    this.successMessage = '';
  }

  loadConnections(): void {
    this.loadingConnections = true;
    this.errorMessage = '';

    this.subscriptions.push(
      this.quickBooksApi.status().subscribe({
        next: (response) => {
          this.connections = response.success ? response.data : [];

          const preferredConnection =
            this.connections.find(
              (connection) =>
                connection.connection_status === 'connected',
            ) ??
            this.connections[0] ??
            null;

          this.selectedConnectionId = preferredConnection?.id ?? null;
          this.loadingConnections = false;

          if (preferredConnection) {
            this.loadConnectionData(preferredConnection.id);
          } else {
            this.catalog = null;
            this.companyInfo = null;
          }

          this.changeDetector.detectChanges();
        },
        error: (error: unknown) => {
          this.loadingConnections = false;
          this.errorMessage = this.getErrorMessage(
            error,
            'Unable to load QuickBooks connections.',
          );
          this.changeDetector.detectChanges();
        },
      }),
    );
  }

  onConnectionChange(): void {
    this.catalog = null;
    this.companyInfo = null;
    this.preview = null;
    this.importResult = null;
    this.creditMemoReconciliationResult = null;
    this.successMessage = '';
    this.errorMessage = '';

    if (this.selectedConnectionId !== null) {
      this.loadConnectionData(this.selectedConnectionId);
    }
  }

  private loadConnectionData(connectionId: number): void {
    this.loadCompanyInfo(connectionId);
    this.loadCatalog(connectionId);
  }

  private loadCompanyInfo(connectionId: number): void {
    this.loadingCompanyInfo = true;

    this.subscriptions.push(
      this.quickBooksApi.companyInfo(connectionId).subscribe({
       next: (response) => {
          this.companyInfo = response.success
            ? response.data
            : null;

          this.loadingCompanyInfo = false;
          this.changeDetector.detectChanges();
        },
        error: () => {
          this.companyInfo = null;
          this.loadingCompanyInfo = false;
          this.changeDetector.detectChanges();
        },
      }),
    );
  }

  private loadCatalog(connectionId: number): void {
    this.loadingCatalog = true;

    this.subscriptions.push(
      this.quickBooksApi.migrationCatalog(connectionId).subscribe({
        next: (response) => {
          this.catalog = response.success ? response.data : null;

          if (!response.success) {
            this.errorMessage =
              response.message ?? 'Unable to load the QuickBooks catalogue.';
          }

          this.loadingCatalog = false;
          this.changeDetector.detectChanges();
        },
        error: (error: unknown) => {
          this.catalog = null;
          this.loadingCatalog = false;
          this.errorMessage = this.getErrorMessage(
            error,
            'Unable to load QuickBooks records.',
          );
          this.changeDetector.detectChanges();
        },
      }),
    );
  }

  connectQuickBooks(): void {
    this.connecting = true;
    this.errorMessage = '';
    this.successMessage = '';

    this.subscriptions.push(
      this.quickBooksApi.connect().subscribe({
        next: (response) => {
          this.connecting = false;

          if (!response.success || !response.data.authorization_url) {
            this.errorMessage =
              response.message ?? 'Unable to start QuickBooks authorization.';
            this.changeDetector.detectChanges();
            return;
          }

          window.location.assign(response.data.authorization_url);
        },
        error: (error: unknown) => {
          this.connecting = false;
          this.errorMessage = this.getErrorMessage(
            error,
            'Unable to connect to QuickBooks.',
          );
          this.changeDetector.detectChanges();
        },
      }),
    );
  }

  disconnectQuickBooks(): void {
    const connection = this.selectedConnection;

    if (!connection) {
      return;
    }

    const confirmed = window.confirm(
      `Disconnect ${connection.company_name || 'this QuickBooks company'} from BizFlow?`,
    );

    if (!confirmed) {
      return;
    }

    this.errorMessage = '';
    this.successMessage = '';

    this.subscriptions.push(
      this.quickBooksApi.disconnect(connection.id).subscribe({
        next: (response) => {
          this.successMessage =
            response.message ?? 'QuickBooks disconnected.';
          this.loadConnections();
        },
        error: (error: unknown) => {
          this.errorMessage = this.getErrorMessage(
            error,
            'Unable to disconnect QuickBooks.',
          );
          this.changeDetector.detectChanges();
        },
      }),
    );
  }

  previewEntity(entity: QuickBooksMigrationEntityType): void {
    if (this.selectedConnectionId === null) {
      this.errorMessage = 'Connect and select a QuickBooks company first.';
      return;
    }

    this.preview = null;
    this.errorMessage = '';
    this.loadingPreview = entity;

    this.subscriptions.push(
      this.quickBooksApi
        .previewEntity(this.selectedConnectionId, entity)
        .subscribe({
          next: (response) => {
            this.preview = response.success ? response.data : null;

            if (!response.success) {
              this.errorMessage =
                response.message ?? 'Unable to preview these records.';
            }

            this.loadingPreview = null;
            this.changeDetector.detectChanges();
          },
          error: (error: unknown) => {
            this.loadingPreview = null;
            this.errorMessage = this.getErrorMessage(
              error,
              'Unable to preview these QuickBooks records.',
            );
            this.changeDetector.detectChanges();
          },
        }),
    );
  }

  isImportable(
    entity: QuickBooksMigrationEntityType,
  ): entity is QuickBooksImportEntityType {
    return this.importableEntities.includes(
      entity as QuickBooksImportEntityType,
    );
  }

  requiresBranch(entity: QuickBooksImportEntityType): boolean {
    return this.branchRequiredEntities.includes(entity);
  }

  requestImport(entity: QuickBooksMigrationEntityType): void {
    this.errorMessage = '';
    this.successMessage = '';
    this.importResult = null;

    if (!this.isImportable(entity)) {
      this.errorMessage =
        'Import for this entity is not available yet.';
      return;
    }

    if (this.selectedConnectionId === null) {
      this.errorMessage = 'Select a QuickBooks company before importing.';
      return;
    }

    if (this.requiresBranch(entity)) {
      this.pendingImportEntity = entity;
      this.branchId = null;
      return;
    }

    this.runImport(entity);
  }

  cancelPendingImport(): void {
    this.pendingImportEntity = null;
    this.branchId = null;
  }

  confirmPendingImport(): void {
    if (!this.pendingImportEntity) {
      return;
    }

    const branchExists = this.branches.some(
      (branch) =>
        branch.id === this.branchId &&
        branch.is_active,
    );

    if (!branchExists) {
      this.errorMessage =
        'Select a valid active BizFlow branch before continuing.';
      return;
    }

    const entity = this.pendingImportEntity;
    const branchId = this.branchId!;

    this.pendingImportEntity = null;
    this.runImport(entity, branchId);
  }

  private runImport(
    entity: QuickBooksImportEntityType,
    branchId?: number,
  ): void {
    if (this.selectedConnectionId === null) {
      this.errorMessage = 'Select a QuickBooks company before importing.';
      return;
    }

    const confirmed = window.confirm(
      `Import ${this.getEntityLabel(entity)} from QuickBooks into BizFlow? Existing imported records will not be duplicated.`,
    );

    if (!confirmed) {
      return;
    }

    this.importingEntity = entity;
    this.errorMessage = '';
    this.successMessage = '';
    this.importResult = null;

    this.subscriptions.push(
      this.quickBooksApi
        .importEntity(this.selectedConnectionId, entity, { branchId })
        .subscribe({
          next: (response) => {
            this.importingEntity = null;

            if (!response.success) {
              this.errorMessage =
                response.message ?? 'The import could not be completed.';
              this.changeDetector.detectChanges();
              return;
            }

            this.importResult = response.data;
            this.successMessage =
              `${this.getEntityLabel(entity)} import completed. ` +
              `${response.data.imported_count} imported, ` +
              `${response.data.already_imported_count} already imported, ` +
              `${response.data.skipped_count} skipped.`;

                if (entity === 'catalog_items') {
                  this.catalogItemsImportCompleted = true;

                  if (this.openingStockBranchId !== null) {
                    this.loadTrackedProducts(1);
                  }
                }

            this.changeDetector.detectChanges();
          },
          error: (error: unknown) => {
            this.importingEntity = null;
            this.errorMessage = this.getErrorMessage(
              error,
              'The QuickBooks import failed.',
            );
            this.changeDetector.detectChanges();
          },
        }),
    );
  }

  previewEntries(
    sample: QuickBooksPreviewSample,
  ): { key: string; value: string }[] {
    return Object.entries(sample).map(([key, value]) => ({
      key: this.formatFieldLabel(key),
      value:
        value === null || value === undefined
          ? '—'
          : typeof value === 'object'
            ? JSON.stringify(value)
            : String(value),
    }));
  }

  getEntityLabel(entity: QuickBooksMigrationEntityType): string {
    return (
      this.entities.find((item) => item.entity_type === entity)?.label ??
      entity.replaceAll('_', ' ')
    );
  }

  formatFieldLabel(value: string): string {
    return value
      .replaceAll('_', ' ')
      .replace(/\b\w/g, (character) => character.toUpperCase());
  }

  private getErrorMessage(error: unknown, fallback: string): string {
    if (
      typeof error === 'object' &&
      error !== null &&
      'error' in error
    ) {
      const apiError = (error as {
        error?: { message?: unknown };
      }).error;

      if (
        apiError &&
        typeof apiError.message === 'string' &&
        apiError.message.trim() !== ''
      ) {
        return apiError.message;
      }
    }

    return fallback;
  }

  private loadBranches(): void {
    this.loadingBranches = true;
    this.branchError = '';

    this.subscriptions.push(
      this.branchApi
        .list({
          page: 1,
          isActive: true,
          perPage: this.branchPageSize,
        })
        .pipe(
          expand((response) => {
            if (
              response.meta.current_page >= response.meta.last_page
            ) {
              return EMPTY;
            }

            return this.branchApi.list({
              page: response.meta.current_page + 1,
              isActive: true,
              perPage: this.branchPageSize,
            });
          }),
          reduce(
            (allBranches, response) => [
              ...allBranches,
              ...response.data,
            ],
            [] as Branch[],
          ),
        )
        .subscribe({
          next: (branches) => {
            this.branches = branches;

            this.branchOptions = branches.map((branch) => ({
              value: branch.id,
              label: branch.name,
              description: branch.code,
            }));

            this.loadingBranches = false;
            this.changeDetector.detectChanges();
          },
          error: (error: unknown) => {
            this.branches = [];
            this.branchOptions = [];
            this.loadingBranches = false;
            this.branchError = this.getErrorMessage(
              error,
              'Unable to load BizFlow branches.',
            );
            this.changeDetector.detectChanges();
          },
        }),
    );
  }

  reconcileCreditMemoApplications(): void {
    const connectionId = this.selectedConnectionId;

    if (connectionId === null) {
      this.errorMessage = 'Select a QuickBooks company first.';
      return;
    }

    if (this.selectedConnection?.connection_status !== 'connected') {
      this.errorMessage =
        'Connect the QuickBooks company before reconciling credit applications.';
      return;
    }

    if (
      this.importingEntity !== null ||
      this.loadingPreview !== null ||
      this.reconcilingCreditMemoApplications
    ) {
      return;
    }

    const confirmed = window.confirm(
      'Reconcile QuickBooks credit memo applications with BizFlow invoices? ' +
      'Only validated source applications will be recorded. ' +
      'This will not create cash payments or modify invoice totals.',
    );

    if (!confirmed) {
      return;
    }

    this.errorMessage = '';
    this.successMessage = '';
    this.creditMemoReconciliationResult = null;
    this.reconcilingCreditMemoApplications = true;

    this.subscriptions.push(
      this.quickBooksApi
        .reconcileCreditMemoApplications(connectionId)
        .subscribe({
          next: (response) => {
            this.reconcilingCreditMemoApplications = false;

            if (!response.success) {
              this.errorMessage =
                response.message ??
                'Credit memo application reconciliation failed.';

              this.changeDetector.detectChanges();
              return;
            }

            this.creditMemoReconciliationResult = response.data;

            this.successMessage =
              'Credit memo reconciliation completed. ' +
              `${response.data.created_count} applications created, ` +
              `${response.data.already_applied_count} already applied, ` +
              `${response.data.skipped_count} skipped.`;

            this.changeDetector.detectChanges();
          },

          error: (error: unknown) => {
            this.reconcilingCreditMemoApplications = false;

            this.errorMessage = this.getErrorMessage(
              error,
              'Unable to reconcile QuickBooks credit memo applications.',
            );

            this.changeDetector.detectChanges();
          },
        }),
    );
  }

  ///////opening
  loadTrackedProducts(page = 1): void {
  if (this.openingStockBranchId === null) {
    this.trackedProducts = [];
    return;
  }

  this.loadingTrackedProducts = true;
  this.openingStockError = '';
  this.openingStockPage = page;

  this.subscriptions.push(
    this.catalogApi.getOpeningStockCandidates({
      branch_id: this.openingStockBranchId,
      search: this.openingStockSearch.trim() || undefined,
      page,
      per_page: this.openingStockPageSize,
    }).subscribe({
      next: response => {
        this.trackedProducts = response.data;
        this.openingStockPage = response.meta.current_page;
        this.openingStockLastPage = response.meta.last_page;
        this.openingStockTotal = response.meta.total;

        this.openingQuantities = {};
        this.trackedProducts.forEach(item => {
          this.openingQuantities[item.id] = null;
        });

        this.loadingTrackedProducts = false;
        this.changeDetector.detectChanges();
      },
      error: error => {
        this.loadingTrackedProducts = false;
        this.openingStockError = this.getErrorMessage(
          error,
          'Unable to load products awaiting opening inventory.',
        );
        this.changeDetector.detectChanges();
      },
    }),
  );
}

onOpeningStockBranchChange(): void {
  this.openingStockPage = 1;
  this.openingQuantities = {};
  this.loadTrackedProducts(1);
}

initializeOpeningStock(): void {
  this.openingStockError = '';
  this.openingStockResults = [];

  if (this.openingStockBranchId === null) {
    this.openingStockError = 'Select a destination branch.';
    return;
  }

  const branchExists = this.branches.some(
    (branch) =>
      branch.id === this.openingStockBranchId &&
      branch.is_active,
  );

  if (!branchExists) {
    this.openingStockError =
      'Select a valid active Bizflow branch.';
    return;
  }

  const items = this.trackedProducts
    .filter((product) => {
      const quantity = this.openingQuantities[product.id];

      return quantity !== null && quantity !== undefined;
    })
    .map((product) => ({
      product,
      quantity: this.openingQuantities[product.id] as number,
    }));

  if (items.length === 0) {
    this.openingStockError =
      'Enter an opening quantity for at least one product.';
    return;
  }

  if (
    items.some(
      (item) =>
        !Number.isFinite(item.quantity) ||
        item.quantity < 0,
    )
  ) {
    this.openingStockError =
      'Quantities must be valid numbers greater than or equal to zero.';
    return;
  }

  const confirmed = window.confirm(
    `Initialize opening inventory for ${items.length} product(s) ` +
    `at the selected branch? Products with existing inventory will ` +
    `not be overwritten.`,
  );

  if (!confirmed) {
    return;
  }

  this.initializingOpeningStock = true;

  this.subscriptions.push(
    from(items)
      .pipe(
        concatMap(({ product, quantity }) =>
          this.catalogApi.setOpeningStock({
            branch_id: this.openingStockBranchId!,
            catalog_item_id: product.id,
            quantity,
            notes: 'Opening inventory initialized from QuickBooks Migration Center',
          }).pipe(
            map((response) => ({
              productName: product.name,
              quantity,
              success: response.success,
              message: response.message || 'Opening stock initialized.',
            })),
            catchError((error: unknown) =>
              of({
                productName: product.name,
                quantity,
                success: false,
                message: this.getErrorMessage(
                  error,
                  'Unable to initialize opening stock. Inventory may already exist.',
                ),
              }),
            ),
          ),
        ),
        toArray(),
      )
      .subscribe({
        next: (results) => {
          this.openingStockResults = results;
          this.initializingOpeningStock = false;

          const succeeded = results.filter(
            (result) => result.success,
          ).length;

          this.successMessage =
            `Opening inventory completed: ${succeeded} of ` +
            `${results.length} product(s) initialized.`;

          this.changeDetector.detectChanges();
        },
        error: () => {
          this.initializingOpeningStock = false;
          this.openingStockError =
            'Opening inventory processing could not be completed.';
          this.changeDetector.detectChanges();
        },
      }),
  );
}
////////////

  closePreview(): void {
    this.preview = null;
  }

  ngOnDestroy(): void {
    this.subscriptions.forEach((subscription) =>
      subscription.unsubscribe(),
    );
  }
}