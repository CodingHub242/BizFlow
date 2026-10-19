import { Component, OnInit,OnDestroy, inject,ChangeDetectorRef,DestroyRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { finalize, forkJoin } from 'rxjs';
import { Subject, debounceTime, distinctUntilChanged,takeUntil } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { SearchableSelectComponent,SearchableSelectOption } from '../../shared/components/searchable-select/searchable-select.component';
import { SalesService, SalesOrder, OrderStatus,PaymentStatus } from '../../core/services/sales.service';
import { BranchApi, Branch } from '../../core/services/branch-api';
import { CustomerApi, Customer } from '../../core/services/customer-api';
import { CatalogApi, CatalogItem } from '../../core/services/catalog-api';

@Component({
  selector: 'app-sales',
  templateUrl: './sales.component.html',
  styleUrls: ['./sales.component.scss'],
  standalone: true,
  imports: [CommonModule, FormsModule,SearchableSelectComponent],
})
export class SalesComponent implements OnInit {
  private readonly salesService = inject(SalesService);
  private readonly branchApi = inject(BranchApi);
  private readonly customerApi = inject(CustomerApi);
  private readonly catalogApi = inject(CatalogApi);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly destroyRef = inject(DestroyRef);
  private readonly destroy$ = new Subject<void>();
  private readonly searchSubject = new Subject<string>();

  orders: SalesOrder[] = [];
  branches: Branch[] = [];
  customers: Customer[] = [];
  catalogItems: CatalogItem[] = [];
  private currentPage = 1;  

  loading = false;
  saving = false;
  errorMessage = '';
  successMessage = '';

  search = '';
  page = 1;
  lastPage = 1;
  total = 0;

  showCreateForm = false;

  orderForm = {
    branch_id: null as number | null,
    customer_id: null as number | null,
    order_number: '',
    discount: 0,
    tax: 0,
    notes: '',
  };

  orderItems: Array<{
    catalog_item_id: number;
    quantity: number;
    discount: number;
    tax: number;
  }> = [];

  ngOnInit(): void {
    this.loadReferenceData();

     this.searchSubject.pipe(
            debounceTime(300),
            distinctUntilChanged(),
            takeUntil(this.destroy$),
          )
          .subscribe((search) => {
            this.search = search.trim();
           this.loadOrders(1);
          });

    this.loadOrders();
  }

  loadReferenceData(): void {
    forkJoin({
      branches: this.branchApi.list({ isActive: true, perPage: 100 }),
      customers: this.customerApi.list({ perPage: 100 }),
      catalog: this.catalogApi.list({ isActive: true, perPage: 100 }),
    }).subscribe({
      next: (result) => {
        this.branches = result.branches.data;
        this.customers = result.customers.data;
        this.catalogItems = result.catalog.data;

        if (!this.orderForm.branch_id && this.branches.length) {
          this.orderForm.branch_id = this.branches[0].id;
        }
      },
      error: () => {
        this.errorMessage = 'Unable to load branches, customers, or catalog items.';
      },
    });
  }

  loadOrders(page = 1): void {
    this.loading = true;
    this.errorMessage = '';

    this.salesService.list({
      page: this.page,
      search: this.search.trim() || undefined,
      per_page: 15,
    })
    .pipe(finalize(() => (this.loading = false)))
    .subscribe({
      next: (response) => {
        this.orders = response.data;
        this.page = response.current_page;
        this.lastPage = response.last_page;
        this.total = response.total;
        this.loading = false;
        console.log(this.orders.length);
        this.cdr.markForCheck();
        this.cdr.detectChanges();
      },
      error: () => {
        this.errorMessage = 'Unable to load sales orders.';

         this.cdr.detectChanges();
      },
    });
  }

  searchOrders(value:string): void {
   this.searchSubject.next(value);
  }

  changePage(direction: -1 | 1): void {
    const nextPage = this.page + direction;

    if (nextPage < 1 || nextPage > this.lastPage) {
      return;
    }

    this.page = nextPage;
    this.loadOrders();
  }

  addOrderItem(): void {
    this.orderItems.push({
      catalog_item_id: 0,
      quantity: 1,
      discount: 0,
      tax: 0,
    });
  }

  removeOrderItem(index: number): void {
    this.orderItems.splice(index, 1);
  }

  getCatalogItem(id: number): CatalogItem | undefined {
    return this.catalogItems.find((item) => item.id === Number(id));
  }

  getLineTotal(item: {
    catalog_item_id: number;
    quantity: number;
    discount: number;
    tax: number;
  }): number {
    const product = this.getCatalogItem(item.catalog_item_id);

    if (!product) {
      return 0;
    }

    return Math.max(
      0,
      Number(product.selling_price) * Number(item.quantity) -
        Number(item.discount || 0) +
        Number(item.tax || 0),
    );
  }

  get estimatedTotal(): number {
    return Math.max(
      0,
      this.orderItems.reduce(
        (sum, item) => sum + this.getLineTotal(item),
        0,
      ) -
        Number(this.orderForm.discount || 0) +
        Number(this.orderForm.tax || 0),
    );
  }

  openCreateForm(): void {
    this.errorMessage = '';
    this.successMessage = '';
    this.orderForm.order_number = `SALE-${Date.now()}`;
    this.orderItems = [];
    this.addOrderItem();
    this.showCreateForm = true;
  }

  createOrder(): void {
    this.errorMessage = '';
    this.successMessage = '';

    if (!this.orderForm.branch_id) {
      this.errorMessage = 'Select a branch.';
      return;
    }

    if (!this.orderForm.order_number.trim()) {
      this.errorMessage = 'Enter an order number.';
      return;
    }

    if (
      !this.orderItems.length ||
      this.orderItems.some(
        (item) =>
          !item.catalog_item_id ||
          Number(item.quantity) <= 0 ||
          Number(item.discount) < 0 ||
          Number(item.tax) < 0,
      )
    ) {
      this.errorMessage = 'Add valid order items and quantities.';
      return;
    }

    this.saving = true;

    this.salesService.create({
      branch_id: Number(this.orderForm.branch_id),
      customer_id: this.orderForm.customer_id
        ? Number(this.orderForm.customer_id)
        : null,
      order_number: this.orderForm.order_number.trim(),
      discount: Number(this.orderForm.discount || 0),
      tax: Number(this.orderForm.tax || 0),
      notes: this.orderForm.notes.trim() || null,
      items: this.orderItems.map((item) => ({
        catalog_item_id: Number(item.catalog_item_id),
        quantity: Number(item.quantity),
        discount: Number(item.discount || 0),
        tax: Number(item.tax || 0),
      })),
    })
    .pipe(finalize(() => (this.saving = false)))
    .subscribe({
      next: () => {
        this.showCreateForm = false;
        this.successMessage = 'Sales order created successfully.';
        this.page = 1;

        this.cdr.detectChanges();
        
        this.loadOrders();
      },
      error: (error) => {
        this.errorMessage =
          error?.error?.message ||
          'Unable to create the sales order. Check the entered details.';
      },
    });
  }

  statusLabel(status: OrderStatus): string {
    return status.replaceAll('_', ' ');
  }

  pstatusLabel(status: PaymentStatus): string {
    return status.replaceAll('_', ' ');
  }

 get customerOptions(): SearchableSelectOption[] {
  return [
    {
      value: 0,
      label: 'Walk-in Customer',
      description: 'Sale without a registered customer',
    },
    ...this.customers.map(customer => ({
      value: Number(customer.id),
      label: customer.company_name
        ? `${customer.company_name} — ${customer.name}`
        : customer.name,
      description: customer.phone || customer.email || undefined,
    })),
  ];
}

get branchOptions(): SearchableSelectOption[] {
  return this.branches.map(branch => ({
    value: Number(branch.id),
    label: branch.name,
    description: branch.code || branch.address || undefined,
  }));
}

get catalogOptions(): SearchableSelectOption[] {
  return this.catalogItems.map(item => ({
    value: Number(item.id),
    label: item.name,
    description: [
      item.type === 'service' ? 'Service' : 'Product',
      item.sku || null,
      `Price: GHS ${Number(item.selling_price).toFixed(2)}`,
    ].filter(Boolean).join(' · '),
  }));
}

  formatAmount(value: number | string | null | undefined): string {
    return Number(value ?? 0).toLocaleString('en-GH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}