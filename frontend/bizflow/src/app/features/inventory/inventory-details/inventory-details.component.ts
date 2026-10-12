import {ChangeDetectorRef,Component,OnDestroy,OnInit,inject} from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';

import {Inventory,InventoryApi,InventoryMovement} from '../../../core/services/inventory-api.service';

@Component({
  selector: 'app-inventory-details',
  standalone: true,
  templateUrl: './inventory-details.component.html',
  styleUrl: './inventory-details.component.scss',
})
export class InventoryDetailsComponent implements OnInit, OnDestroy {
  private readonly inventoryApi = inject(InventoryApi);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly cdr = inject(ChangeDetectorRef);

  private readonly destroy$ = new Subject<void>();

  inventory: Inventory | null = null;

  loading = false;
  error = '';

    movements: InventoryMovement[] = [];
  
    movementsLoading = false;
    movementsError = '';
  
    movementPage = 1;
    movementLastPage = 1;
    movementTotal = 0;
    movementPerPage = 10;

  ngOnInit(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));

    if (!Number.isInteger(id) || id <= 0) {
      this.error = 'Invalid inventory record.';
      return;
    }

    this.loadInventory(id);
  }

  loadInventory(id: number): void {
    this.loading = true;
    this.error = '';

    this.inventoryApi
      .get(id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.inventory = response.data;
          this.loading = false;

          this.loadMovements(1);

          this.cdr.markForCheck();

          this.cdr.detectChanges();
        },

        error: () => {
          this.inventory = null;
          this.loading = false;
          this.error =
            'Unable to load inventory details. Please try again.';

          this.cdr.markForCheck();
        },
      });
  }

  loadMovements(page = 1): void {
    if (!this.inventory) {
      return;
    }

    console.log(this.inventory.branch_id);

    this.movementsLoading = true;
    this.movementsError = '';

    this.inventoryApi
      .movements({
        page,
        branchId: this.inventory.branch_id,
        catalogItemId: this.inventory?.catalog_item_id,
        perPage: this.movementPerPage,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.movements = response.data;
          this.movementPage = response.meta.current_page;
          this.movementLastPage = response.meta.last_page;
          this.movementTotal = response.meta.total;
          this.movementsLoading = false;

          this.cdr.markForCheck();
        },
        error: () => {
          this.movements = [];
          this.movementsLoading = false;
          this.movementsError =
            'Unable to load stock movement history. Please try again.';

          this.cdr.markForCheck();

          this.cdr.detectChanges();
        },
      });
  }

   loadMovementPage(page: number): void {
    if (
      this.movementsLoading ||
      page < 1 ||
      page > this.movementLastPage ||
      page === this.movementPage
    ) {
      return;
    }

    this.loadMovements(page);
  }

formatMovementDate(value?: string | null): string {
  if (!value) {
    return '—';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return '—';
  }

  return date.toLocaleString();
}

getMovementLabel(type: string): string {
  const labels: Record<string, string> = {
    PURCHASE: 'Stock Received',
    SALE: 'Sale',
    ADJUSTMENT: 'Stock Adjustment',
    TRANSFER_IN: 'Transfer In',
    TRANSFER_OUT: 'Transfer Out',
  };

  return labels[type.toUpperCase()] ??
    type.replace(/_/g, ' ');
}

getMovementClass(type: string): string {
  return type.toLowerCase().replace(/_/g, '-');
}

formatMovementQuantity(movement: InventoryMovement): string {
  const quantity = Number(movement.quantity);
  const absoluteQuantity = this.formatQuantity(Math.abs(quantity));

  switch (movement.type.toUpperCase()) {
    case 'PURCHASE':
    case 'TRANSFER_IN':
      return `+${absoluteQuantity}`;

    case 'SALE':
    case 'TRANSFER_OUT':
      return `−${absoluteQuantity}`;

    case 'ADJUSTMENT':
      if (quantity > 0) {
        return `+${absoluteQuantity}`;
      }

      if (quantity < 0) {
        return `−${absoluteQuantity}`;
      }

      return '0';

    default:
      return quantity < 0
        ? `−${absoluteQuantity}`
        : `+${absoluteQuantity}`;
  }
}

  getStockStatus(): 'in-stock' | 'low-stock' | 'out-of-stock' {
    if (!this.inventory) {
      return 'out-of-stock';
    }

    const quantity = Number(this.inventory.quantity);
    const reorderLevel = Number(this.inventory.reorder_level);

    if (quantity <= 0) {
      return 'out-of-stock';
    }

    if (quantity <= reorderLevel) {
      return 'low-stock';
    }

    return 'in-stock';
  }

  getStockStatusLabel(): string {
    switch (this.getStockStatus()) {
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

  formatPrice(value: number | string): string {
    return Number(value).toFixed(2);
  }

  viewBranch(): void {
    if (!this.inventory?.branch_id) {
      return;
    }

    this.router.navigate([
      '/branches',
      this.inventory.branch_id,
    ]);
  }

  back(): void {
    this.router.navigate(['/inventory']);
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}