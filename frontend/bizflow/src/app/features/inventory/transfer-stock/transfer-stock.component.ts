import {ChangeDetectorRef,Component,OnDestroy,OnInit,inject} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { forkJoin, Subject, takeUntil } from 'rxjs';

import {Inventory,InventoryApi,TransferStockRequest} from '../../../core/services/inventory-api.service';
import { Branch, BranchApi } from '../../../core/services/branch-api';
import {CatalogApi,CatalogItem} from '../../../core/services/catalog-api';
import {SearchableSelectComponent,SearchableSelectOption} from '../../../shared/components/searchable-select/searchable-select.component';

@Component({
  selector: 'app-transfer-stock',
  standalone: true,
  imports: [FormsModule, SearchableSelectComponent],
  templateUrl: './transfer-stock.component.html',
  styleUrl: './transfer-stock.component.scss',
})
export class TransferStockComponent implements OnInit, OnDestroy {
  private readonly inventoryApi = inject(InventoryApi);
  private readonly branchApi = inject(BranchApi);
  private readonly catalogApi = inject(CatalogApi);
  private readonly router = inject(Router);
  private readonly cdr = inject(ChangeDetectorRef);

  private readonly destroy$ = new Subject<void>();
  private balanceRequestVersion = 0;

  branches: Branch[] = [];
  catalogItems: CatalogItem[] = [];

  sourceBranchId: number | null = null;
  destinationBranchId: number | null = null;
  catalogItemId: number | null = null;

  quantity: number | null = null;
  notes = '';

  sourceInventory: Inventory | null = null;
  destinationInventory: Inventory | null = null;

  loading = false;
  loadingBranches = false;
  loadingCatalogItems = false;
  loadingBalances = false;
  balancesLoaded = false;

  error = '';
  success = '';
  branchLoadError = '';
  catalogLoadError = '';
  balanceError = '';

  ngOnInit(): void {
    this.loadBranches();
    this.loadCatalogItems();
  }

  loadBranches(): void {
    this.loadingBranches = true;
    this.branchLoadError = '';

    this.branchApi
      .list({
        isActive: true,
        perPage: 100,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.branches = response.data;
          this.loadingBranches = false;
          this.cdr.markForCheck();
        },
        error: () => {
          this.branches = [];
          this.loadingBranches = false;
          this.branchLoadError =
            'Unable to load branches. Please try again.';
          this.cdr.markForCheck();
        },
      });
  }

  loadCatalogItems(): void {
    this.loadingCatalogItems = true;
    this.catalogLoadError = '';

    this.catalogApi
      .list({
        type: 'product',
        isActive: true,
        perPage: 100,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.catalogItems = response.data.filter(
            (item) => item.track_inventory,
          );

          this.loadingCatalogItems = false;
          this.cdr.markForCheck();
        },
        error: () => {
          this.catalogItems = [];
          this.loadingCatalogItems = false;
          this.catalogLoadError =
            'Unable to load products. Please try again.';
          this.cdr.markForCheck();
        },
      });
  }

  get branchOptions(): SearchableSelectOption[] {
    return this.branches.map((branch) => ({
      value: branch.id,
      label: branch.name,
      description: branch.code || undefined,
    }));
  }

  get productOptions(): SearchableSelectOption[] {
    return this.catalogItems.map((item) => ({
      value: item.id,
      label: item.name,
      description: item.sku || undefined,
    }));
  }

  get sourceQuantity(): number | null {
    if (!this.sourceInventory) {
      return null;
    }

    return Number(this.sourceInventory.quantity);
  }

  get destinationQuantity(): number {
    return this.destinationInventory
      ? Number(this.destinationInventory.quantity)
      : 0;
  }

  get projectedSourceQuantity(): number | null {
    if (this.sourceQuantity === null || !this.validQuantity) {
      return this.sourceQuantity;
    }

    return this.sourceQuantity - this.quantity!;
  }

  get projectedDestinationQuantity(): number | null {
    if (!this.validQuantity || !this.balancesLoaded) {
      return null;
    }

    return this.destinationQuantity + this.quantity!;
  }

  get validQuantity(): boolean {
    return (
      this.quantity !== null &&
      Number.isFinite(this.quantity) &&
      this.quantity > 0
    );
  }

  onSelectionChanged(): void {
    this.error = '';
    this.success = '';
    this.balanceError = '';

    this.sourceInventory = null;
    this.destinationInventory = null;
    this.balancesLoaded = false;

    // Invalidate previous requests when selections change.
    this.balanceRequestVersion++;

    if (
      this.sourceBranchId === null ||
      this.destinationBranchId === null ||
      this.catalogItemId === null
    ) {
      this.loadingBalances = false;
      this.cdr.markForCheck();
      this.cdr.detectChanges();
      return;
    }

    if (this.sourceBranchId === this.destinationBranchId) {
      this.loadingBalances = false;
      this.balanceError =
        'Source and destination branches must be different.';
      this.cdr.markForCheck();
      this.cdr.detectChanges();
      return;
    }

    this.loadBalances();
  }

  loadBalances(): void {
    if (
      this.sourceBranchId === null ||
      this.destinationBranchId === null ||
      this.catalogItemId === null ||
      this.sourceBranchId === this.destinationBranchId
    ) {
      return;
    }

    const sourceBranchId = this.sourceBranchId;
    const destinationBranchId = this.destinationBranchId;
    const catalogItemId = this.catalogItemId;
    const requestVersion = ++this.balanceRequestVersion;

    this.loadingBalances = true;
    this.balancesLoaded = false;
    this.balanceError = '';

    forkJoin({
      source: this.inventoryApi.list({
        branchId: sourceBranchId,
        catalogItemId,
        perPage: 100,
      }),
      destination: this.inventoryApi.list({
        branchId: destinationBranchId,
        catalogItemId,
        perPage: 100,
      }),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ source, destination }) => {
          if (requestVersion !== this.balanceRequestVersion) {
            return;
          }

          this.sourceInventory =
            source.data.find(
              (item) =>
                item.branch_id === sourceBranchId &&
                item.catalog_item_id === catalogItemId,
            ) ?? null;

          this.destinationInventory =
            destination.data.find(
              (item) =>
                item.branch_id === destinationBranchId &&
                item.catalog_item_id === catalogItemId,
            ) ?? null;

          this.loadingBalances = false;
          this.balancesLoaded = true;

          if (!this.sourceInventory) {
            this.balanceError =
              'This product has no inventory record at the source branch. Receive stock there first.';
          }

          this.cdr.markForCheck();
          this.cdr.detectChanges();
        },
        error: () => {
          if (requestVersion !== this.balanceRequestVersion) {
            return;
          }

          this.sourceInventory = null;
          this.destinationInventory = null;
          this.loadingBalances = false;
          this.balancesLoaded = false;
          this.balanceError =
            'Unable to check branch stock balances. Please try again.';

          this.cdr.markForCheck();
        },
      });
  }

  submit(): void {
    this.error = '';
    this.success = '';

    if (
      this.sourceBranchId === null ||
      this.destinationBranchId === null
    ) {
      this.error = 'Please select both branches.';
      return;
    }

    if (this.sourceBranchId === this.destinationBranchId) {
      this.error =
        'Source and destination branches must be different.';
      return;
    }

    if (this.catalogItemId === null) {
      this.error = 'Please select a product.';
      return;
    }

    if (this.loadingBalances || !this.balancesLoaded) {
      this.error = 'Please wait for the stock balances to load.';
      return;
    }

    if (!this.sourceInventory || this.sourceQuantity === null) {
      this.error =
        'The selected product has no inventory record at the source branch.';
      return;
    }

    if (!this.validQuantity) {
      this.error = 'Transfer quantity must be greater than zero.';
      return;
    }

    if (this.quantity! > this.sourceQuantity) {
      this.error =
        'Transfer quantity cannot exceed available source stock.';
      return;
    }

    const payload: TransferStockRequest = {
      catalog_item_id: this.catalogItemId,
      source_branch_id: this.sourceBranchId,
      destination_branch_id: this.destinationBranchId,
      quantity: this.quantity!,
    };

    if (this.notes.trim()) {
      payload.notes = this.notes.trim();
    }

    this.loading = true;

    this.inventoryApi
      .transfer(payload)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.loading = false;
          this.success =
            response.message || 'Stock transferred successfully.';

          this.quantity = null;
          this.notes = '';

          this.loadBalances();
          this.cdr.markForCheck();
          this.cdr.detectChanges();

          this.catalogItemId = null;
          this.sourceBranchId = null;
          this.destinationBranchId = null
          this.quantity = null;
        },
        error: (error) => {
          this.loading = false;
          this.error =
            error?.error?.message ||
            'Unable to transfer stock. Please try again.';

          this.cdr.markForCheck();
          this.cdr.detectChanges();
        },
      });
  }

  formatQuantity(value: number | string): string {
    return Number(value).toLocaleString(undefined, {
      maximumFractionDigits: 3,
    });
  }

  cancel(): void {
    this.router.navigate(['/inventory']);
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}