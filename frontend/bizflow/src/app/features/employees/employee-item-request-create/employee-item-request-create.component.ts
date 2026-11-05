
import { Component, OnInit, inject,ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AlertController } from '@ionic/angular';
import { IonicModule } from '@ionic/angular/lazy';
import { Router } from '@angular/router';
import { forkJoin, finalize } from 'rxjs';

import { EmployeeItemApiService } from
  '../../../core/services/employee-item-api';

import { BranchApi, Branch } from
  '../../../core/services/branch-api';

import { CatalogApi, CatalogItem } from
  '../../../core/services/catalog-api';

import {
  CreateEmployeeItemRequest,
  CreateEmployeeItemRequestItem,
} from '../../../core/services/employee-item-request.models';

// Adjust this import to your actual searchable-select component location.
import { SearchableSelectComponent } from
  '../../../shared/components/searchable-select/searchable-select.component';

interface RequestItemForm {
  catalog_item_id: number | null;
  quantity_requested: number;
  notes: string;
}

@Component({
  selector: 'app-employee-item-request-create',
  standalone: true,
  templateUrl: './employee-item-request-create.component.html',
  styleUrls: ['./employee-item-request-create.component.scss'],
  imports: [
    CommonModule,
    FormsModule,
    IonicModule,
    SearchableSelectComponent,
  ],
})
export class EmployeeItemRequestCreateComponent implements OnInit {
  private readonly api = inject(EmployeeItemApiService);
  private readonly branchApi = inject(BranchApi);
  private readonly catalogApi = inject(CatalogApi);
  private readonly router = inject(Router);
  private readonly alertController = inject(AlertController);
  private readonly cdr = inject(ChangeDetectorRef);

  branches: Branch[] = [];
  catalogItems: CatalogItem[] = [];

  loading = false;
  submitting = false;

  branchId: number | null = null;
  purpose = '';
  notes = '';

  items: RequestItemForm[] = [
    {
      catalog_item_id: null,
      quantity_requested: 1,
      notes: '',
    },
  ];

  ngOnInit(): void {
    this.loadFormData();
  }

  loadFormData(): void {
    this.loading = true;

    forkJoin({
      branches: this.branchApi.list({
        page: 1,
        perPage: 100,
        isActive: true,
      }),
      catalog: this.catalogApi.list({
        page: 1,
        perPage: 100,
        isActive: true,
        type: 'product',
      }),
    })
      .pipe(finalize(() => this.loading = false))
      .subscribe({
        next: response => {
          this.branches = response.branches.data ?? [];
          this.catalogItems = response.catalog.data ?? [];

          this.loading = false;
          this.cdr.detectChanges();
        },
        error: error => {
          console.error('Failed to load request form data', error);
          this.showError(
            error?.error?.message ||
            'Unable to load branches and catalog items.'
          );

          this.loading = false;
          this.cdr.detectChanges();
        },
      });
  }

  get branchOptions() {
    return this.branches.map(branch => ({
      value: branch.id,
      label: branch.name,
      description: branch.code,
    }));
  }

  get catalogOptions() {
    return this.catalogItems.map(item => ({
      value: item.id,
      label: item.name,
      description: [
        item.sku ? `SKU: ${item.sku}` : null,
        item.type,
        item.unit,
      ].filter(Boolean).join(' · '),
    }));
  }

  addItem(): void {
    this.items.push({
      catalog_item_id: null,
      quantity_requested: 1,
      notes: '',
    });
  }

  removeItem(index: number): void {
    if (this.items.length <= 1) {
      return;
    }

    this.items.splice(index, 1);
  }

  getSelectedCatalogItem(itemId: number | null): CatalogItem | undefined {
    return this.catalogItems.find(item => item.id === itemId);
  }

  isDuplicateItem(index: number): boolean {
    const currentId = this.items[index]?.catalog_item_id;

    if (!currentId) {
      return false;
    }

    return this.items.some(
      (item, itemIndex) =>
        itemIndex !== index && item.catalog_item_id === currentId
    );
  }

  get canSubmit(): boolean {
    return (
      !this.loading &&
      !this.submitting &&
      this.items.length > 0 &&
      this.items.every(item =>
        item.catalog_item_id !== null &&
        Number.isInteger(Number(item.quantity_requested)) &&
        Number(item.quantity_requested) > 0
      ) &&
      !this.items.some((_, index) => this.isDuplicateItem(index))
    );
  }

  submitRequest(): void {
    if (!this.canSubmit) {
      this.showError(
        'Select an item for every row, enter a valid quantity and remove duplicate items.'
      );
      return;
    }

    const payload: CreateEmployeeItemRequest = {
      branch_id: this.branchId,
      purpose: this.purpose.trim() || null,
      notes: this.notes.trim() || null,
      items: this.items.map(item => {
        const requestItem: CreateEmployeeItemRequestItem = {
          catalog_item_id: item.catalog_item_id!,
          quantity_requested: Number(item.quantity_requested),
          notes: item.notes.trim() || null,
        };

        return requestItem;
      }),
    };

    this.submitting = true;

    this.api.create(payload)
      .pipe(finalize(() => this.submitting = false))
      .subscribe({
        next: async response => {
          const alert = await this.alertController.create({
            header: 'Request submitted',
            message: response.message ||
              'Your employee item request has been submitted successfully.',
            buttons: [
              {
                text: 'View requests',
                cssClass: 'alert-button-danger',
                handler: () => {
                  this.router.navigate(['/employees-item-request']);
                },
              },
            ],
          });

          await alert.present();

          this.cdr.detectChanges();
        },
        error: error => {
          console.error('Failed to create item request', error);
          this.showError(
            error?.error?.message ||
            'Unable to submit the item request. Please try again.'
          );
          this.cdr.detectChanges();
        },
      });
  }

  cancel(): void {
    this.router.navigate(['/employee-item-requests']);
  }

  async showError(message: string): Promise<void> {
    const alert = await this.alertController.create({
      header: 'Unable to continue',
      message,
      buttons: ['OK'],
    });

    await alert.present();
  }
}
