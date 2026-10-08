import {ChangeDetectorRef,Component,OnDestroy,OnInit,inject} from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import {Branch,BranchApi} from '../../../core/services/branch-api';
import {Inventory,InventoryApi} from '../../../core/services/inventory-api.service';

@Component({
  selector: 'app-branch-details',
  standalone: true,
  templateUrl: './branch-details.component.html',
  styleUrl: './branch-details.component.scss',
})
export class BranchDetailsComponent implements OnInit, OnDestroy {
  private readonly branchApi = inject(BranchApi);
  private readonly inventoryApi = inject(InventoryApi);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly cdr = inject(ChangeDetectorRef);

  private readonly destroy$ = new Subject<void>();

  branch: Branch | null = null;
  inventory: Inventory[] = [];

  loading = false;
  loadingInventory = false;

  error = '';
  inventoryError = '';

  ngOnInit(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));

    if (!Number.isInteger(id) || id <= 0) {
      this.error = 'Invalid branch.';
      return;
    }

    this.loadBranch(id);
    this.loadBranchInventory(id);
  }

  loadBranch(id: number): void {
    this.loading = true;
    this.error = '';

    this.branchApi
      .get(id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.branch = response.data;
          this.loading = false;

          this.cdr.markForCheck();
        },

        error: () => {
          this.branch = null;
          this.loading = false;
          this.error =
            'Unable to load branch details. Please try again.';

          this.cdr.markForCheck();
        },
      });
  }

  loadBranchInventory(id: number): void {
    this.loadingInventory = true;
    this.inventoryError = '';

    this.inventoryApi
      .list({
        branchId: id,
        perPage: 100,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.inventory = response.data;
          this.loadingInventory = false;

          this.cdr.markForCheck();
        },

        error: () => {
          this.inventory = [];
          this.loadingInventory = false;
          this.inventoryError =
            'Unable to load branch inventory.';

          this.cdr.markForCheck();
        },
      });
  }

  viewInventory(id: number): void {
    this.router.navigate([
      '/inventory',
      id,
    ]);
  }

  back(): void {
    this.router.navigate(['/branches']);
  }

  getStockStatus(item: Inventory):
    'in-stock' | 'low-stock' | 'out-of-stock' {

    const quantity = Number(item.quantity);
    const reorderLevel = Number(item.reorder_level);

    if (quantity <= 0) {
      return 'out-of-stock';
    }

    if (quantity <= reorderLevel) {
      return 'low-stock';
    }

    return 'in-stock';
  }

  getStockStatusLabel(item: Inventory): string {
    switch (this.getStockStatus(item)) {
      case 'out-of-stock':
        return 'Out of Stock';

      case 'low-stock':
        return 'Low Stock';

      default:
        return 'In Stock';
    }
  }

  formatQuantity(value: number | string): string {
    return Number(value).toLocaleString(undefined, {
      maximumFractionDigits: 3,
    });
  }

  backToBranches(): void {
    this.router.navigate(['/branches']);
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}