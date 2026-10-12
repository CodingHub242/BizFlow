import {ChangeDetectorRef,Component,OnDestroy,OnInit,inject} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import {SearchableSelectComponent,SearchableSelectOption} from '../../../shared/components/searchable-select/searchable-select.component';

import {AdjustStockRequest,Inventory,InventoryApi} from '../../../core/services/inventory-api.service';
import { Branch, BranchApi } from '../../../core/services/branch-api';
import {CatalogApi,CatalogItem} from '../../../core/services/catalog-api';

type AdjustmentDirection = 'increase' | 'decrease';

@Component({
  selector: 'app-adjust-stock',
  standalone: true,
  imports: [FormsModule,SearchableSelectComponent],
  templateUrl: './adjust-stock.component.html',
  styleUrl: './adjust-stock.component.scss',
})
export class AdjustStockComponent implements OnInit, OnDestroy {
  private readonly inventoryApi = inject(InventoryApi);
  private readonly branchApi = inject(BranchApi);
  private readonly catalogApi = inject(CatalogApi);
  private readonly router = inject(Router);
  private readonly cdr = inject(ChangeDetectorRef);

  private readonly destroy$ = new Subject<void>();
  private stockLookupVersion = 0;

  branches: Branch[] = [];
  catalogItems: CatalogItem[] = [];

  branchId: number | null = null;
  catalogItemId: number | null = null;

  direction: AdjustmentDirection = 'decrease';
  quantity: number | null = null;
  notes = '';

  currentInventory: Inventory | null = null;

  loading = false;
  loadingBranches = false;
  loadingCatalogItems = false;
  loadingCurrentStock = false;
  stockLookupComplete = false;

  error = '';
  success = '';
  branchLoadError = '';
  catalogLoadError = '';
  stockLookupError = '';

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

  onSelectionChanged(): void {
    this.error = '';
    this.success = '';
    this.stockLookupError = '';
    this.currentInventory = null;
    this.stockLookupComplete = false;

    // Ignore responses from an earlier product/branch selection.
    this.stockLookupVersion++;

    if (this.branchId === null || this.catalogItemId === null) {
      this.loadingCurrentStock = false;
      this.cdr.markForCheck();
      return;
    }

    this.loadCurrentStock();
  }

  loadCurrentStock(): void {
    if (this.branchId === null || this.catalogItemId === null) {
      return;
    }

    const branchId = this.branchId;
    const catalogItemId = this.catalogItemId;
    const requestVersion = ++this.stockLookupVersion;

    this.loadingCurrentStock = true;
    this.stockLookupComplete = false;
    this.stockLookupError = '';
    this.currentInventory = null;

    this.inventoryApi
      .list({
        branchId,
        catalogItemId,
        perPage: 1,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          if (requestVersion !== this.stockLookupVersion) {
            return;
          }

          const record = response.data.find(
            (item) =>
              item.branch_id === branchId &&
              item.catalog_item_id === catalogItemId,
          );

          this.currentInventory = record ?? null;
          this.loadingCurrentStock = false;
          this.stockLookupComplete = true;

          this.cdr.markForCheck();
          this.cdr.detectChanges();
        },
        error: () => {
          if (requestVersion !== this.stockLookupVersion) {
            return;
          }

          this.currentInventory = null;
          this.loadingCurrentStock = false;
          this.stockLookupComplete = true;
          this.stockLookupError =
            'Unable to check current stock. Please try again.';

          this.cdr.markForCheck();
          this.cdr.detectChanges();
        },
      });
  }

  submit(): void {
    this.error = '';
    this.success = '';

    if (this.branchId === null) {
      this.error = 'Please select a branch.';
      return;
    }

    if (this.catalogItemId === null) {
      this.error = 'Please select a product.';
      return;
    }

    if (this.loadingCurrentStock) {
      this.error = 'Please wait while current stock is checked.';
      return;
    }

    if (!this.currentInventory) {
      this.error =
        'No inventory record exists for this product at the selected branch. Receive stock first.';
      return;
    }

    if (
      this.quantity === null ||
      !Number.isFinite(this.quantity) ||
      this.quantity <= 0
    ) {
      this.error = 'Adjustment quantity must be greater than zero.';
      return;
    }

    const currentQuantity = Number(this.currentInventory.quantity);

    if (
      this.direction === 'decrease' &&
      this.quantity > currentQuantity
    ) {
      this.error =
        'The adjustment cannot reduce stock below zero.';
      return;
    }

    if (!this.notes.trim()) {
      this.error = 'Please provide a reason for the adjustment.';
      return;
    }

    const signedQuantity =
      this.direction === 'increase'
        ? this.quantity
        : -this.quantity;

    const payload: AdjustStockRequest = {
      branch_id: this.branchId,
      catalog_item_id: this.catalogItemId,
      quantity: signedQuantity,
      notes: this.notes.trim(),
    };

    this.loading = true;

    this.inventoryApi
      .adjust(payload)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.loading = false;
          this.success =
            response.message || 'Stock adjusted successfully.';

          this.quantity = null;
          this.notes = '';

          if (response.data) {
            this.currentInventory = response.data;
          } else {
            this.loadCurrentStock();
          }

          this.cdr.markForCheck();
        },
        error: (error) => {
          this.loading = false;
          this.error =
            error?.error?.message ||
            'Unable to adjust stock. Please try again.';

          this.cdr.markForCheck();
        },
      });
  }

  get previewQuantity(): number | null {
    if (!this.currentInventory || this.quantity === null) {
      return null;
    }

    if (!Number.isFinite(this.quantity) || this.quantity <= 0) {
      return null;
    }

    const current = Number(this.currentInventory.quantity);

    return this.direction === 'increase'
      ? current + this.quantity
      : current - this.quantity;
  }

  // get filteredBranches(): Branch[] {
  //   const search = this.branchSearch.trim().toLowerCase();

  //   if (!search) {
  //     return this.branches;
  //   }

  //   return this.branches.filter(
  //     (branch) =>
  //       branch.name.toLowerCase().includes(search) ||
  //       (branch.code ?? '').toLowerCase().includes(search),
  //   );
  // }

  // get filteredCatalogItems(): CatalogItem[] {
  //   const search = this.productSearch.trim().toLowerCase();

  //   if (!search) {
  //     return this.catalogItems;
  //   }

  //   return this.catalogItems.filter(
  //     (item) =>
  //       item.name.toLowerCase().includes(search) ||
  //       (item.sku ?? '').toLowerCase().includes(search),
  //   );
  // }

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