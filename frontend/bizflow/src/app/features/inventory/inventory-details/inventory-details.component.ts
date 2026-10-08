import {ChangeDetectorRef,Component,OnDestroy,OnInit,inject} from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';

import {Inventory,InventoryApi} from '../../../core/services/inventory-api.service';

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

          this.cdr.markForCheck();
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