import { Component, OnInit, ChangeDetectorRef,inject, } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import {FormBuilder,ReactiveFormsModule,Validators,} from '@angular/forms';
import {CatalogApi,CatalogItem,CatalogItemType,} from '../../../core/services/catalog-api';

@Component({
  selector: 'app-catalog-details',
  templateUrl: './catalog-details.component.html',
  styleUrls: ['./catalog-details.component.scss'],
  imports: [ReactiveFormsModule],
  standalone: true,
})
export class CatalogDetailsComponent  {
  private readonly route = inject(ActivatedRoute);
  private readonly catalogApi = inject(CatalogApi);
  private readonly router = inject(Router);
  private readonly formBuilder = inject(FormBuilder);
  private readonly changeDetector = inject(ChangeDetectorRef);

  readonly form = this.formBuilder.group({
    name: ['', Validators.required],
    type: ['product' as CatalogItemType, Validators.required],
    sku: [''],
    description: [''],
    unit: [''],
    cost_price: [0, [Validators.required, Validators.min(0)]],
    selling_price: [0, [Validators.required, Validators.min(0)]],
    tax_rate: [0, [Validators.min(0)]],
    track_inventory: [true],
    is_active: [true],
  });

  catalogItem: CatalogItem | null = null;
  isRestoring = false;
  isEditing = false;
  isSaving = false;
  errorMessage = '';
  isDeleting = false;
  showDeleteConfirmation = false;

  constructor() { }

  ngOnInit(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));

    if (!Number.isNaN(id) && id > 0) {
      this.catalogApi.get(id).subscribe({
        next: response => {
          this.catalogItem = response.data;
          this.changeDetector.detectChanges();
        },
        error: error => {
          this.errorMessage =
            error?.error?.message ||
            'Unable to load this product or service.';
          this.changeDetector.detectChanges();
        },
      });
    }
  }

  backToCatalog(): void {
    this.router.navigate(['/catalog']);
  }

  startEditing(): void {
    if (!this.catalogItem) {
      return;
    }

    this.errorMessage = '';

    this.form.patchValue({
      name: this.catalogItem.name,
      type: this.catalogItem.type,
      sku: this.catalogItem.sku ?? '',
      description: this.catalogItem.description ?? '',
      unit: this.catalogItem.unit ?? '',
      cost_price: Number(this.catalogItem.cost_price),
      selling_price: Number(this.catalogItem.selling_price),
      tax_rate: this.catalogItem.tax_rate
        ? Number(this.catalogItem.tax_rate)
        : 0,
      track_inventory:
        this.catalogItem.type === 'product'
          ? this.catalogItem.track_inventory
          : false,
      is_active: this.catalogItem.is_active,
    });

    this.isEditing = true;
  }

  cancelEditing(): void {
    this.isEditing = false;
    this.errorMessage = '';
  }

  onTypeChange(): void {
    if (this.form.controls.type.value === 'service') {
      this.form.patchValue({
        track_inventory: false,
      });
    }
  }

  updateCatalogItem(): void {
    if (!this.catalogItem || this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.errorMessage = '';

    const value = this.form.getRawValue();

    const data = {
      name: value.name?.trim() ?? '',
      type: value.type ?? 'product',
      sku: value.sku?.trim() || null,
      description: value.description?.trim() || null,
      unit: value.unit?.trim() || null,
      cost_price: Number(value.cost_price),
      selling_price: Number(value.selling_price),
      tax_rate: value.tax_rate === null
        ? null
        : Number(value.tax_rate),
      track_inventory:
        value.type === 'product'
          ? Boolean(value.track_inventory)
          : false,
      is_active: Boolean(value.is_active),
    };

    this.isSaving = true;

    this.catalogApi.update(this.catalogItem.id, data).subscribe({
      next: response => {
        this.catalogItem = response.data;
        this.isEditing = false;
        this.isSaving = false;

        this.changeDetector.detectChanges();
      },

      error: error => {
        this.isSaving = false;

        if (error?.status === 403) {
          this.errorMessage =
            error?.error?.message ||
            'You do not have permission to change the product cost.';
        } else if (error?.error?.message) {
          this.errorMessage = error.error.message;
        } else {
          this.errorMessage =
            'Unable to update this product or service.';
        }

        this.changeDetector.detectChanges();
      },
    });
  }

  confirmDelete(): void {
  this.showDeleteConfirmation = true;
  this.errorMessage = '';
}

cancelDelete(): void {
  this.showDeleteConfirmation = false;
}

restoreCatalogItem(): void {
  if (!this.catalogItem) {
    return;
  }

  this.errorMessage = '';
  this.isRestoring = true;

  this.catalogApi.restore(this.catalogItem.id).subscribe({
    next: response => {
      this.catalogItem = response.data;
      this.isRestoring = false;
      this.changeDetector.detectChanges();
    },
    error: error => {
      this.isRestoring = false;

      if (error?.error?.message) {
        this.errorMessage = error.error.message;
      } else {
        this.errorMessage =
          'Unable to restore this product or service.';
      }

      this.changeDetector.detectChanges();
    },
  });
}

deleteCatalogItem(): void {
  if (!this.catalogItem) {
    return;
  }

  this.errorMessage = '';
  this.isDeleting = true;

  this.catalogApi.delete(this.catalogItem.id).subscribe({
    next: () => {
      this.isDeleting = false;
      this.showDeleteConfirmation = false;

      this.router.navigate(['/catalog']);
    },

    error: error => {
      this.isDeleting = false;

      if (error?.error?.message) {
        this.errorMessage = error.error.message;
      } else {
        this.errorMessage =
          'Unable to delete this product or service.';
      }

      this.showDeleteConfirmation = false;
      this.changeDetector.detectChanges();
    },
  });
}

}
