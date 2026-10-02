import { Component, OnInit,inject } from '@angular/core';
import {FormsModule} from '@angular/forms';
import {Router} from '@angular/router';
import {CatalogApi,CreateCatalogItemRequest,CatalogItemType,} from '../../../core/services/catalog-api';

@Component({
  selector: 'app-add-catalog-item',
  templateUrl: './add-catalog-item.component.html',
  styleUrls: ['./add-catalog-item.component.scss'],
  imports: [FormsModule],
})
export class AddCatalogItemComponent {
  private readonly catalogApi = inject(CatalogApi);

  private readonly router = inject(Router);

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

    this.catalogApi.create(data).subscribe({
      next: () => {
        this.submitting = false;

        this.router.navigate(['/catalog']);
      },

      error: error => {
        this.submitting = false;

        if (error?.error?.message) {
          this.errorMessage = error.error.message;
        } else {
          this.errorMessage =
            'Unable to create catalog item.';
        }
      },
    });
  }

  cancel(): void {
    this.router.navigate(['/catalog']);
  }

  onTypeChange(): void {
    if (this.type === 'service') {
      this.track_inventory = false;
    }
  }

}
