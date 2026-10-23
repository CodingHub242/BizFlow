
import {ChangeDetectorRef,Component,OnDestroy,OnInit,inject} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, forkJoin, takeUntil } from 'rxjs';

import {InvoicesApi,CreateInvoiceRequest,CreateInvoiceLineRequest} from '../../../core/services/invoices-api.service';
import { BranchApi, Branch } from '../../../core/services/branch-api';
import { CustomerApi, Customer } from '../../../core/services/customer-api';
import { CatalogApi, CatalogItem } from '../../../core/services/catalog-api';
import {SearchableSelectComponent,SearchableSelectOption} from '../../../shared/components/searchable-select/searchable-select.component';

interface InvoiceFormLine {
  catalog_item_id: number | null;
  description: string;
  quantity: number;
  unit_price: number;
  discount: number;
  tax: number;
}

@Component({
  selector: 'app-create-invoice',
  standalone: true,
  imports: [CommonModule, FormsModule, SearchableSelectComponent],
  templateUrl: './create-invoice.component.html',
  styleUrl: './create-invoice.component.scss',
})
export class CreateInvoiceComponent implements OnInit, OnDestroy {
  private readonly invoicesApi = inject(InvoicesApi);
  private readonly branchApi = inject(BranchApi);
  private readonly customerApi = inject(CustomerApi);
  private readonly catalogApi = inject(CatalogApi);
  private readonly router = inject(Router);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly destroy$ = new Subject<void>();

  branches: Branch[] = [];
  customers: Customer[] = [];
  catalogItems: CatalogItem[] = [];

  branchOptions: SearchableSelectOption[] = [];
  customerOptions: SearchableSelectOption[] = [];
  catalogOptions: SearchableSelectOption[] = [];

  branchId: number | null = null;
  customerId: number | null = null;
  invoiceNumber = this.generateInvoiceNumber();
  dueAt = '';
  notes = '';

  items: InvoiceFormLine[] = [this.newLine()];

  loading = true;
  saving = false;
  error = '';
  success = '';

  ngOnInit(): void {
    this.loadFormOptions();
  }

  private loadFormOptions(): void {
    this.loading = true;
    this.error = '';

    forkJoin({
      branches: this.branchApi.list({ page: 1, perPage: 100, isActive: true }),
      customers: this.customerApi.list({ page: 1, perPage: 100 }),
      catalog: this.catalogApi.list({
        page: 1,
        perPage: 100,
        isActive: true,
      }),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ branches, customers, catalog }) => {
          this.branches = branches.data ?? [];
          this.customers = customers.data ?? [];
          this.catalogItems = (catalog.data ?? []).filter(
            item => item.is_active,
          );

          this.branchOptions = this.branches.map(branch => ({
            value: branch.id,
            label: `${branch.name} (${branch.code})`,
          }));

          this.customerOptions = this.customers.map(customer => ({
            value: customer.id,
            label: customer.company_name
              ? `${customer.company_name} — ${customer.name}`
              : customer.name,
            description: customer.phone ?? customer.email ?? undefined,
          }));

          this.catalogOptions = this.catalogItems.map(item => ({
            value: item.id,
            label: item.name,
            description: `${item.type === 'service' ? 'Service' : 'Product'} · ${this.formatMoney(item.selling_price)}`,
          }));

          if (this.branches.length === 1) {
            this.branchId = this.branches[0].id;
          }

          this.loading = false;
          this.cdr.markForCheck();
        },
        error: err => {
          this.loading = false;
          this.error =
            err?.error?.message ??
            'Unable to load branches, customers, and catalog items. Please try again.';
          this.cdr.markForCheck();
        },
      });
  }

  private generateInvoiceNumber(): string {
    const now = new Date();
    const date = [
      now.getFullYear(),
      String(now.getMonth() + 1).padStart(2, '0'),
      String(now.getDate()).padStart(2, '0'),
    ].join('');

    const suffix = Math.random().toString(36).slice(2, 7).toUpperCase();
    return `INV-${date}-${suffix}`;
  }

  private newLine(): InvoiceFormLine {
    return {
      catalog_item_id: null,
      description: '',
      quantity: 1,
      unit_price: 0,
      discount: 0,
      tax: 0,
    };
  }

  addLine(): void {
    this.items.push(this.newLine());
  }

  removeLine(index: number): void {
    if (this.items.length <= 1) {
      return;
    }

    this.items.splice(index, 1);
  }

  onCatalogItemChanged(index: number, catalogItemId: number | null): void {
    const line = this.items[index];
    if (!line) return;

    line.catalog_item_id = catalogItemId;

    if (catalogItemId === null) {
      // Preserve the description and price so the user can make this
      // a manual line without losing what they already entered.
      return;
    }

    const catalogItem = this.catalogItems.find(
      item => item.id === catalogItemId,
    );

    if (!catalogItem) return;

    line.description = catalogItem.name;
    line.unit_price = Number(catalogItem.selling_price) || 0;

    const taxRate = Number(catalogItem.tax_rate ?? 0) || 0;
    line.tax = Math.round(
      line.quantity * line.unit_price * taxRate,
    ) / 100;
  }

  onQuantityChanged(index: number): void {
    const line = this.items[index];
    if (!line) return;

    const catalogItem = this.catalogItems.find(
      item => item.id === line.catalog_item_id,
    );

    if (catalogItem) {
      const taxRate = Number(catalogItem.tax_rate ?? 0) || 0;
      line.tax = Math.round(
        line.quantity * line.unit_price * taxRate,
      ) / 100;
    }
  }

  lineSubtotal(line: InvoiceFormLine): number {
    return Math.max(0, Number(line.quantity || 0) * Number(line.unit_price || 0));
  }

  lineTotal(line: InvoiceFormLine): number {
    return Math.max(
      0,
      this.lineSubtotal(line) -
        Number(line.discount || 0) +
        Number(line.tax || 0),
    );
  }

  get subtotal(): number {
    return this.items.reduce((sum, line) => sum + this.lineSubtotal(line), 0);
  }

  get totalDiscount(): number {
    return this.items.reduce(
      (sum, line) => sum + Number(line.discount || 0),
      0,
    );
  }

  get totalTax(): number {
    return this.items.reduce((sum, line) => sum + Number(line.tax || 0), 0);
  }

  get total(): number {
    return this.items.reduce((sum, line) => sum + this.lineTotal(line), 0);
  }

  formatMoney(value: number | string | null | undefined): string {
    return `GH₵ ${Number(value ?? 0).toLocaleString('en-GH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })}`;
  }

  submit(): void {
    this.error = '';
    this.success = '';

    if (!this.branchId) {
      this.error = 'Please select a branch.';
      return;
    }

    if (!this.invoiceNumber.trim()) {
      this.error = 'Please enter an invoice number.';
      return;
    }

    if (!this.items.length) {
      this.error = 'Add at least one invoice line.';
      return;
    }

    for (let i = 0; i < this.items.length; i++) {
      const line = this.items[i];

      if (!line.catalog_item_id && !line.description.trim()) {
        this.error = `Enter a description or select a catalog item for line ${i + 1}.`;
        return;
      }

      if (!Number.isFinite(line.quantity) || line.quantity <= 0) {
        this.error = `Quantity for line ${i + 1} must be greater than zero.`;
        return;
      }

      if (!Number.isFinite(line.unit_price) || line.unit_price < 0) {
        this.error = `Unit price for line ${i + 1} cannot be negative.`;
        return;
      }

      if (
        !Number.isFinite(line.discount) ||
        line.discount < 0 ||
        line.discount > this.lineSubtotal(line)
      ) {
        this.error = `Discount for line ${i + 1} must be between zero and its line subtotal.`;
        return;
      }

      if (!Number.isFinite(line.tax) || line.tax < 0) {
        this.error = `Tax for line ${i + 1} cannot be negative.`;
        return;
      }
    }

    const payload: CreateInvoiceRequest = {
      branch_id: this.branchId,
      customer_id: this.customerId,
      invoice_number: this.invoiceNumber.trim(),
      due_at: this.dueAt || null,
      notes: this.notes.trim() || null,
      items: this.items.map(
        (line): CreateInvoiceLineRequest => ({
          catalog_item_id: line.catalog_item_id,
          ...(line.catalog_item_id
            ? {}
            : { description: line.description.trim() }),
          quantity: Number(line.quantity),
          unit_price: Number(line.unit_price),
          discount: Number(line.discount || 0),
          tax: Number(line.tax || 0),
        }),
      ),
    };

    this.saving = true;

    this.invoicesApi
      .create(payload)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: response => {
          this.saving = false;
          this.cdr.markForCheck();

          if (response.data?.id) {
            this.router.navigate(['/invoices', response.data.id]);
          } else {
            this.router.navigate(['/invoices']);
          }
        },
        error: err => {
          this.saving = false;
          this.error =
            err?.error?.message ??
            this.firstValidationError(err?.error?.errors) ??
            'Unable to create the invoice. Please check your details and try again.';
          this.cdr.markForCheck();
        },
      });
  }

  private firstValidationError(
    errors: Record<string, string[]> | undefined,
  ): string | null {
    if (!errors) return null;

    const first = Object.values(errors).find(
      messages => Array.isArray(messages) && messages.length > 0,
    );

    return first?.[0] ?? null;
  }

  cancel(): void {
    this.router.navigate(['/invoices']);
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}
