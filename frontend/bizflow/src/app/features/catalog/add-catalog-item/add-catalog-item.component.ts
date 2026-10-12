import { Component, OnInit,inject,OnDestroy } from '@angular/core';
import {FormsModule} from '@angular/forms';
import {Router} from '@angular/router';
import {CatalogApi,CreateCatalogItemRequest,CatalogItemType,} from '../../../core/services/catalog-api';

@Component({
  selector: 'app-add-catalog-item',
  templateUrl: './add-catalog-item.component.html',
  styleUrls: ['./add-catalog-item.component.scss'],
  imports: [FormsModule],
})
export class AddCatalogItemComponent implements OnDestroy {
  private readonly catalogApi = inject(CatalogApi);

  private readonly router = inject(Router);

  selectedImage: File | null = null;
  imagePreviewUrl: string | null = null;
  imageError = '';

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

  constructor() { }

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
      next: () => {
        this.submitting = false;
        this.revokeImagePreview();
        this.router.navigate(['/catalog']);
      },
      error: (error) => {
        this.submitting = false;

        this.errorMessage =
          error?.error?.message ??
          'Unable to create catalog item. Please try again.';
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

  onTypeChange(): void {
    if (this.type === 'service') {
      this.track_inventory = false;
    }
  }

  ngOnDestroy(): void {
  this.revokeImagePreview();
}

}
