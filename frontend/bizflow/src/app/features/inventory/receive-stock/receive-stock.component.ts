import {ChangeDetectorRef,Component,OnDestroy,OnInit,inject} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import {InventoryApi,ReceiveStockRequest,} from '../../../core/services/inventory-api.service';
import { Branch, BranchApi } from '../../../core/services/branch-api';
import {CatalogApi,CatalogItem,} from '../../../core/services/catalog-api';

@Component({
  selector: 'app-receive-stock',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './receive-stock.component.html',
  styleUrl: './receive-stock.component.scss',
})
export class ReceiveStockComponent implements OnInit, OnDestroy {
  private readonly inventoryApi = inject(InventoryApi);
  private readonly branchApi = inject(BranchApi);
  private readonly catalogApi = inject(CatalogApi);
  private readonly router = inject(Router);
  private readonly cdr = inject(ChangeDetectorRef);

  private readonly destroy$ = new Subject<void>();

  branches: Branch[] = [];
  catalogItems: CatalogItem[] = [];

  branchId: number | null = null;
  catalogItemId: number | null = null;
  quantity: number | null = null;

  referenceType = '';
  referenceId: number | null = null;
  notes = '';

  loading = false;
  loadingBranches = false;
  loadingCatalogItems = false;

  error = '';
  success = '';


  branchSearch = '';
  productSearch = '';

  showCreateBranch = false;

  newBranch = {
    name: '',
    code: '',
    address: '',
    phone: '',
    email: '',
  };

  creatingBranch = false;
  branchCreateError = '';

  ngOnInit(): void {
    this.loadBranches();
    this.loadCatalogItems();
  }

  loadBranches(): void {
    this.loadingBranches = true;

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
          this.error = 'Unable to load branches. Please try again.';
          this.cdr.markForCheck();
        },
      });
  }

  loadCatalogItems(): void {
    this.loadingCatalogItems = true;

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
          this.error =
            'Unable to load products. Please try again.';
          this.cdr.markForCheck();
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

    if (
      this.quantity === null ||
      !Number.isFinite(this.quantity) ||
      this.quantity <= 0
    ) {
      this.error = 'Quantity must be greater than zero.';
      return;
    }

    const payload: ReceiveStockRequest = {
      branch_id: this.branchId,
      catalog_item_id: this.catalogItemId,
      quantity: this.quantity,
    };

    if (this.referenceType.trim()) {
      payload.reference_type = this.referenceType.trim();
    }

    if (this.referenceId !== null) {
      payload.reference_id = this.referenceId;
    }

    if (this.notes.trim()) {
      payload.notes = this.notes.trim();
    }

    this.loading = true;

    this.inventoryApi
      .receive(payload)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.loading = false;
          this.success =
            response.message ||
            'Stock received successfully.';
          this.cdr.markForCheck();

          this.cdr.detectChanges();
        },
        error: () => {
          this.loading = false;
          this.error =
            'Unable to receive stock. Please try again.';
          this.cdr.markForCheck();
        },
      });
  }

  cancel(): void {
    this.router.navigate(['/inventory']);
  }

  get filteredBranches(): Branch[] {
  const search = this.branchSearch.trim().toLowerCase();

  if (!search) {
    return this.branches;
  }

  return this.branches.filter((branch) =>
    branch.name.toLowerCase().includes(search) ||
    branch.code.toLowerCase().includes(search),
  );
}

get filteredCatalogItems(): CatalogItem[] {
  const search = this.productSearch.trim().toLowerCase();

  if (!search) {
    return this.catalogItems;
  }

  return this.catalogItems.filter((item) =>
    item.name.toLowerCase().includes(search) ||
    (item.sku ?? '').toLowerCase().includes(search),
  );
}

openCreateBranch(): void {
    this.branchCreateError = '';

    this.newBranch = {
      name: '',
      code: '',
      address: '',
      phone: '',
      email: '',
    };

    this.showCreateBranch = true;
  }

closeCreateBranch(): void {
    if (this.creatingBranch) {
      return;
    }

    this.showCreateBranch = false;
  }

  createBranch(): void {
  this.branchCreateError = '';

  const name = this.newBranch.name.trim();
  const code = this.newBranch.code.trim();

  if (!name) {
    this.branchCreateError = 'Branch name is required.';
    return;
  }

  if (!code) {
    this.branchCreateError = 'Branch code is required.';
    return;
  }

  this.creatingBranch = true;

  this.branchApi
    .create({
      name,
      code,
      address: this.newBranch.address.trim() || undefined,
      phone: this.newBranch.phone.trim() || undefined,
      email: this.newBranch.email.trim() || undefined,
    })
    .pipe(takeUntil(this.destroy$))
    .subscribe({
      next: (response) => {
        const branch = response.data;

        this.branches = [
          branch,
          ...this.branches.filter(
            (existing) => existing.id !== branch.id,
          ),
        ];

        this.branchId = branch.id;

        this.branchSearch = '';

        this.creatingBranch = false;
        this.showCreateBranch = false;

        this.cdr.markForCheck();

        this.cdr.detectChanges();
      },

      error: (error) => {
        this.creatingBranch = false;

        this.branchCreateError =
          error?.error?.message ||
          'Unable to create branch. Please try again.';

        this.cdr.markForCheck();
      },
    });
}

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}