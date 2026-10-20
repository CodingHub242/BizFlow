import { Component, OnInit,inject,OnDestroy,ChangeDetectorRef } from '@angular/core';
import {FormsModule} from '@angular/forms';
import { SearchableSelectComponent,SearchableSelectOption } from '../../../shared/components/searchable-select/searchable-select.component';
import {Router} from '@angular/router';
import { Subject, debounceTime, distinctUntilChanged, takeUntil } from 'rxjs';
import {Branch,BranchApi} from '../../../core/services/branch-api';
import {CatalogApi,CreateCatalogItemRequest,CatalogItemType,} from '../../../core/services/catalog-api';

@Component({
  selector: 'app-add-catalog-item',
  templateUrl: './add-catalog-item.component.html',
  styleUrls: ['./add-catalog-item.component.scss'],
  imports: [FormsModule,SearchableSelectComponent],
})
export class AddCatalogItemComponent implements OnInit, OnDestroy {
  private readonly catalogApi = inject(CatalogApi);
  private readonly branchApi = inject(BranchApi);
  private readonly router = inject(Router);
  private readonly cdr = inject(ChangeDetectorRef);

  private readonly destroy$ = new Subject<void>();
  private readonly searchSubject = new Subject<string>();

  selectedImage: File | null = null;
  imagePreviewUrl: string | null = null;
  imageError = '';

  branches: Branch[] = [];

  loadingBranches = false;
  branchError = '';

  openingBranchId: number | null = null;
  openingQuantity: number | null = null;

  

   name = '';

  type: CatalogItemType = 'product';

  sku = '';

  description = '';

  unit = '';

  cost_price: number | null = null;

  selling_price: number | null = null;

  tax_rate: number | null = 0;

  track_inventory = true;

  is_active = true;

  submitting = false;

  errorMessage = '';

  ngOnInit(): void {
    this.loadBranches();
  }

  loadBranches(): void {
    this.branchApi.list({
        isActive: true,
        perPage: 100,
      }).pipe(takeUntil(this.destroy$)).subscribe({
        next: (response) => {
          this.branches = response.data;
          this.loadingBranches = false;
          this.cdr.markForCheck();
        },
  
        error: () => {
          this.branches = [];
          this.loadingBranches = false;
          this.branchError = 'Unable to load branches. Please try again.';
          this.cdr.markForCheck();
        },
      });
  }

  //managing search and selectors
  get branchOptions(): SearchableSelectOption[] {
    return this.branches.map((branch) => ({
      value: branch.id,
      label: branch.name,
      description: branch.code || undefined,
    }));
  }

  save(): void {
    this.errorMessage = '';

    if (!this.name.trim()) {
      this.errorMessage = 'Name is required.';
      return;
    }

    if (this.cost_price === null || this.cost_price < 0) {
      this.errorMessage = 'Cost price is required.';
      return;
    }

    if (
      this.selling_price === null ||
      this.selling_price < 0
    ) {
      this.errorMessage = 'Selling price is required.';
      return;
    }

    const hasOpeningQuantity = this.openingQuantity !== null;
    const hasOpeningBranch = this.openingBranchId !== null;

    if (hasOpeningQuantity !== hasOpeningBranch) {
      this.errorMessage =
        'Select a branch and enter an opening quantity, or leave both empty.';
      return;
    }

    if (hasOpeningQuantity && (!Number.isFinite(this.openingQuantity) || this.openingQuantity! < 0)) 
    {
      this.errorMessage =
        'Opening quantity must be zero or greater.';
      return;
    }

    if (this.type === 'service' && (hasOpeningBranch || hasOpeningQuantity)) 
    {
      this.errorMessage =
        'Opening inventory is only available for products.';
      return;
    }

    if (this.type === 'product' && !this.track_inventory && (hasOpeningBranch || hasOpeningQuantity)) 
    {
      this.errorMessage =
        'Enable inventory tracking to initialize opening stock.';
      return;
    }

    const data: CreateCatalogItemRequest = {
      name: this.name.trim(),
      type: this.type,
      sku: this.sku.trim() || null,
      description: this.description.trim() || null,
      unit: this.unit.trim() || null,
      cost_price: this.cost_price,
      selling_price: this.selling_price,
      tax_rate: this.tax_rate ?? 0,
      track_inventory:
        this.type === 'product'
          ? this.track_inventory
          : false,
      is_active: this.is_active,
    };

    this.submitting = true;

    const request$ = this.selectedImage ? this.catalogApi.create(data, this.selectedImage) : this.catalogApi.create(data);

    request$.subscribe({
      next: (response) => {
        const createdItem = response.data;

        // No opening stock requested: preserve existing behavior.
        if (this.openingBranchId === null || this.openingQuantity === null) 
        {
          this.submitting = false;
          this.revokeImagePreview();
          this.router.navigate(['/catalog']);

          this.cdr.detectChanges();

          return;
        }

        // Initialize stock only after catalog creation succeeds.
        this.catalogApi.setOpeningStock({
          branch_id: this.openingBranchId,
          catalog_item_id: createdItem.id,
          quantity: this.openingQuantity,
          notes: 'Opening stock during product creation',
        }).subscribe({
          next: () => {
            this.submitting = false;
            this.revokeImagePreview();
            this.router.navigate(['/catalog']);

            this.cdr.detectChanges();
          },
          error: (error) => {
            this.submitting = false;

            this.errorMessage =
              'Product created successfully, but opening stock could not be initialized. ' +
              (error?.error?.message ??
                'Please open the product inventory and resolve this separately.');

              this.cdr.detectChanges();
          },
        });
      },
      error: (error) => {
        this.submitting = false;

        this.errorMessage =
          error?.error?.message ??
          'Unable to create catalog item. Please try again.';

        this.cdr.detectChanges();
      },
    });

    // this.catalogApi.create(data).subscribe({
    //   next: () => {
    //     this.submitting = false;

    //     this.router.navigate(['/catalog']);
    //   },

    //   error: error => {
    //     this.submitting = false;

    //     if (error?.error?.message) {
    //       this.errorMessage = error.error.message;
    //     } else {
    //       this.errorMessage =
    //         'Unable to create catalog item.';
    //     }
    //   },
    // });
  }

  private revokeImagePreview(): void {
  if (this.imagePreviewUrl) {
    URL.revokeObjectURL(this.imagePreviewUrl);
    this.imagePreviewUrl = null;
  }
}
onImageSelected(event: Event): void {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];

  this.imageError = '';

  if (!file) {
    return;
  }

  const allowedTypes = [
    'image/jpeg',
    'image/png',
    'image/webp',
  ];

  if (!allowedTypes.includes(file.type)) {
    this.imageError = 'Choose a JPEG, PNG, or WebP image.';
    input.value = '';
    return;
  }

  if (file.size > 5 * 1024 * 1024) {
    this.imageError = 'The image must be 5 MB or smaller.';
    input.value = '';
    return;
  }

  this.revokeImagePreview();

  this.selectedImage = file;
  this.imagePreviewUrl = URL.createObjectURL(file);
}

removeSelectedImage(input: HTMLInputElement): void {
  this.selectedImage = null;
  this.imageError = '';
  this.revokeImagePreview();
  input.value = '';
}

  cancel(): void {
    this.router.navigate(['/catalog']);
  }

  onTrackInventoryChange(enabled: boolean): void {
    this.track_inventory = enabled;

    if (!enabled) {
      this.openingBranchId = null;
      this.openingQuantity = null;
    }
  }

  onTypeChange(): void {
  if (this.type === 'service') {
    this.track_inventory = false;
    this.openingBranchId = null;
    this.openingQuantity = null;
  }
}

ngOnDestroy(): void {
  this.destroy$.next();
  this.destroy$.complete();

  this.revokeImagePreview();
}

}
