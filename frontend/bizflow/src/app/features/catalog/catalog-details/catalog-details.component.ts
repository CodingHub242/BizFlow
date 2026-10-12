import {
  Component,
  OnInit,
  OnDestroy,
  ChangeDetectorRef,
  inject,
} from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import {
  FormBuilder,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import {
  CatalogApi,
  CatalogItem,
  CatalogItemType,
} from '../../../core/services/catalog-api';

@Component({
  selector: 'app-catalog-details',
  templateUrl: './catalog-details.component.html',
  styleUrls: ['./catalog-details.component.scss'],
  imports: [ReactiveFormsModule],
  standalone: true,
})
export class CatalogDetailsComponent implements OnInit, OnDestroy {
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

  // Image editing state
  selectedImageFile: File | null = null;
  selectedImagePreviewUrl: string | null = null;
  imageError = '';
  removeExistingImage = false;

  /**
   * Returns the image that should currently be displayed.
   * A local preview takes priority over the saved image.
   */
  get editingImageUrl(): string | null {
    if (this.selectedImagePreviewUrl) {
      return this.selectedImagePreviewUrl;
    }

    if (this.removeExistingImage) {
      return null;
    }

    return this.catalogItem?.image_url ?? null;
  }

  get hasSavedImage(): boolean {
    return Boolean(this.catalogItem?.image_url);
  }

  ngOnInit(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));

    if (!Number.isInteger(id) || id <= 0) {
      this.errorMessage = 'Invalid product or service ID.';
      return;
    }

    this.catalogApi.get(id).subscribe({
      next: (response) => {
        this.catalogItem = response.data;
        this.changeDetector.detectChanges();
      },
      error: (error) => {
        this.errorMessage =
          error?.error?.message ||
          'Unable to load this product or service.';
        this.changeDetector.detectChanges();
      },
    });
  }

  ngOnDestroy(): void {
    this.revokeImagePreview();
  }

  backToCatalog(): void {
    this.router.navigate(['/catalog']);
  }

  startEditing(): void {
    if (!this.catalogItem) {
      return;
    }

    this.discardImageChanges();
    this.errorMessage = '';
    this.imageError = '';

    this.form.patchValue({
      name: this.catalogItem.name,
      type: this.catalogItem.type,
      sku: this.catalogItem.sku ?? '',
      description: this.catalogItem.description ?? '',
      unit: this.catalogItem.unit ?? '',
      cost_price: Number(this.catalogItem.cost_price),
      selling_price: Number(this.catalogItem.selling_price),
      tax_rate: this.catalogItem.tax_rate != null
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
    if (this.isSaving) {
      return;
    }

    this.discardImageChanges();
    this.isEditing = false;
    this.errorMessage = '';
    this.imageError = '';
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
      this.imageError =
        'Choose a JPEG, PNG, or WebP image.';
      input.value = '';
      return;
    }

    if (file.size > 5 * 1024 * 1024) {
      this.imageError = 'The image must be 5 MB or smaller.';
      input.value = '';
      return;
    }

    this.revokeImagePreview();

    this.selectedImageFile = file;
    this.selectedImagePreviewUrl = URL.createObjectURL(file);
    this.removeExistingImage = false;

    this.changeDetector.detectChanges();
  }

  /**
   * Cancel the replacement selection and return to the saved image.
   */
  cancelImageChange(input: HTMLInputElement): void {
    if (this.isSaving) {
      return;
    }

    this.selectedImageFile = null;
    this.revokeImagePreview();
    this.removeExistingImage = false;
    this.imageError = '';
    input.value = '';

    this.changeDetector.detectChanges();
  }

  /**
   * Stage removal. The saved image is deleted only after Save Changes.
   */
  markImageForRemoval(): void {
    if (this.isSaving || !this.catalogItem?.image_url) {
      return;
    }

    this.selectedImageFile = null;
    this.revokeImagePreview();
    this.removeExistingImage = true;
    this.imageError = '';

    this.changeDetector.detectChanges();
  }

  /**
   * Undo a staged image removal.
   */
  undoImageRemoval(): void {
    if (this.isSaving) {
      return;
    }

    this.removeExistingImage = false;
    this.imageError = '';

    this.changeDetector.detectChanges();
  }

  private revokeImagePreview(): void {
    if (this.selectedImagePreviewUrl) {
      URL.revokeObjectURL(this.selectedImagePreviewUrl);
      this.selectedImagePreviewUrl = null;
    }
  }

  private discardImageChanges(): void {
    this.selectedImageFile = null;
    this.revokeImagePreview();
    this.removeExistingImage = false;
  }

  onTypeChange(): void {
    if (this.form.controls.type.value === 'service') {
      this.form.patchValue({
        track_inventory: false,
      });
    }
  }

  updateCatalogItem(): void {
    if (!this.catalogItem || this.form.invalid || this.isSaving) {
      this.form.markAllAsTouched();
      return;
    }

    this.errorMessage = '';
    this.imageError = '';

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

    const itemId = this.catalogItem.id;
    const replacementFile = this.selectedImageFile;
    const shouldRemoveImage =
      this.removeExistingImage && this.hasSavedImage;

    this.isSaving = true;

    // Save catalog details first. Image operations only happen
    // after the catalog update succeeds.
    this.catalogApi.update(itemId, data).subscribe({
      next: (response) => {
        const updatedItem = response.data;

        if (replacementFile) {
          this.catalogApi
            .uploadImage(itemId, replacementFile)
            .subscribe({
              next: (imageResponse) => {
                this.finishImageSave(imageResponse.data);

                this.changeDetector.detectChanges();
              },
              error: (error) => {
                // Basic details were saved, but the existing
                // image was not intentionally removed.
                this.catalogItem = updatedItem;
                this.isSaving = false;
                this.imageError =
                  error?.error?.message ||
                  'Product details were saved, but the image upload failed. Please try again.';
                this.changeDetector.detectChanges();
              },
            });

          return;
        }

        if (shouldRemoveImage) {
          this.catalogApi.deleteImage(itemId).subscribe({
            next: (imageResponse) => {
              this.finishImageSave(imageResponse.data);
            },
            error: (error) => {
              this.catalogItem = updatedItem;
              this.isSaving = false;
              this.imageError =
                error?.error?.message ||
                'Product details were saved, but the image could not be removed. Please try again.';
              this.changeDetector.detectChanges();
            },
          });

          return;
        }

        // No image operation was requested.
        this.finishImageSave(updatedItem);
      },

      error: (error) => {
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

  private finishImageSave(item: CatalogItem): void {
    this.catalogItem = item;
    this.discardImageChanges();
    this.isEditing = false;
    this.isSaving = false;
    this.errorMessage = '';
    this.imageError = '';

    this.changeDetector.detectChanges();
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
      next: (response) => {
        this.catalogItem = response.data;
        this.isRestoring = false;
        this.changeDetector.detectChanges();
      },
      error: (error) => {
        this.isRestoring = false;

        this.errorMessage =
          error?.error?.message ||
          'Unable to restore this product or service.';

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
      error: (error) => {
        this.isDeleting = false;

        this.errorMessage =
          error?.error?.message ||
          'Unable to delete this product or service.';

        this.showDeleteConfirmation = false;
        this.changeDetector.detectChanges();
      },
    });
  }
}