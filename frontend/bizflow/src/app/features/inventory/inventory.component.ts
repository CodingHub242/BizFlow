import {ChangeDetectorRef,Component,OnDestroy,OnInit,inject} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import {Branch,BranchApi} from '../../core/services/branch-api';
import { Subject, debounceTime, distinctUntilChanged, takeUntil } from 'rxjs';

import {
  Inventory,
  InventoryApi,
} from '../../core/services/inventory-api.service';

type StockStatus =
  | 'in-stock'
  | 'low-stock'
  | 'out-of-stock';

@Component({
  selector: 'app-inventory',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './inventory.component.html',
  styleUrl: './inventory.component.scss',
})
export class InventoryComponent implements OnInit, OnDestroy {
  private readonly inventoryApi = inject(InventoryApi);
  private readonly router = inject(Router);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly branchApi = inject(BranchApi);

  private readonly destroy$ = new Subject<void>();
  private readonly searchSubject = new Subject<string>();

  inventory: Inventory[] = [];
  branches: Branch[] = [];

  loading = false;
  error = '';

  search = '';

  selectedBranchId: number | null = null;
  selectedStockStatus: StockStatus | '' = '';

  currentPage = 1;
  lastPage = 1;
  totalItems = 0;
  perPage = 15;

  ngOnInit(): void {
    this.loadBranches();

    this.searchSubject.pipe(
        debounceTime(300),
        distinctUntilChanged(),
        takeUntil(this.destroy$),
      )
      .subscribe((search) => {
        this.search = search.trim();
        this.loadInventory(1);
      });

    this.loadInventory();
  }

  loadBranches(): void {
  this.branchApi.list({
      isActive: true,
      perPage: 100,
    }).pipe(takeUntil(this.destroy$)).subscribe({
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

  loadInventory(page = 1): void {
    this.loading = true;
    this.error = '';

    const params: {
      page: number;
      branchId?: number;
      search?: string;
      perPage: number;
    } = {
      page,
      perPage: this.perPage,
    };

    if (this.search.trim()) {
      params.search = this.search.trim();
    }

    if (this.selectedBranchId !== null) {
      params.branchId = this.selectedBranchId;
    }

    this.inventoryApi
      .list(params)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.inventory = response.data;
          this.currentPage = response.meta.current_page;
          this.lastPage = response.meta.last_page;
          this.totalItems = response.meta.total;

          this.loading = false;

          this.cdr.markForCheck();
        },
        error: () => {
          this.inventory = [];
          this.loading = false;
          this.error = 'Unable to load inventory. Please try again.';

          this.cdr.markForCheck();
        },
      });
  }

  searchInventoryItems(value: string): void {
    this.searchSubject.next(value);
  }

  filterBranch(): void {
    this.loadInventory(1);
  }

  filterInventory(): void {
     if (this.selectedBranchId !== null) {
      this.loadInventory(1);
    }
  }

  loadPage(page: number): void {
    if (
      page < 1 ||
      page > this.lastPage ||
      page === this.currentPage
    ) {
      return;
    }

    this.loadInventory(page);
  }

  viewInventory(id: number): void {
    this.router.navigate([
      '/inventory',
      id,
    ]);
  }

  getStockStatus(inventory: Inventory): StockStatus {
    const quantity = Number(inventory.quantity);
    const reorderLevel = Number(inventory.reorder_level);

    if (quantity <= 0) {
      return 'out-of-stock';
    }

    if (quantity <= reorderLevel) {
      return 'low-stock';
    }

    return 'in-stock';
  }

  getStockStatusLabel(inventory: Inventory): string {
    switch (this.getStockStatus(inventory)) {
      case 'out-of-stock':
        return 'Out of Stock';

      case 'low-stock':
        return 'Low Stock';

      default:
        return 'In Stock';
    }
  }

  getStockStatusClass(inventory: Inventory): string {
    return this.getStockStatus(inventory);
  }

  formatQuantity(value: number | string): string {
    return Number(value).toLocaleString(undefined, {
      maximumFractionDigits: 3,
    });
  }

  get filteredInventory(): Inventory[] {
    if (!this.selectedStockStatus) {
      return this.inventory;
    }

    return this.inventory.filter((item) =>
      this.getStockStatus(item) === this.selectedStockStatus
    );
  }

  formatPrice(value: number | string): string {
    return Number(value).toFixed(2);
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}