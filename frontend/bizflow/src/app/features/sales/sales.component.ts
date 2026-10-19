import { Component, OnInit,OnDestroy, inject,ChangeDetectorRef,DestroyRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { finalize, forkJoin } from 'rxjs';
import { Subject, debounceTime, distinctUntilChanged,takeUntil } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { SearchableSelectComponent,SearchableSelectOption } from '../../shared/components/searchable-select/searchable-select.component';
import { SalesService, SalesOrder, OrderStatus,PaymentStatus, OrderPaymentMethod,RecordSalesPaymentRequest } from '../../core/services/sales.service';
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
  selectedOrder: SalesOrder | null = null;
  detailsLoading = false;
  processingFulfillment = false;
  recordingPayment = false;
  editingOrderId: number | null = null;
  deletingOrderId: number | null = null;

  paymentForm = {
    amount: null as number | null,
    method: 'cash' as OrderPaymentMethod,
    reference: '',
    paid_at: new Date().toISOString().slice(0, 16),
    notes: '',
  };

  totalpaid = 0;

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
      page,
      search: this.search.trim() || undefined,
      per_page: 15,
    })
    .pipe(finalize(() => {
      this.loading = false;
      this.cdr.markForCheck();
    }))
    .subscribe({
      next: (response) => {
        this.orders = response.data;
        this.page = response.current_page;
        this.lastPage = response.last_page;
        this.total = response.total;
        this.cdr.markForCheck();
      },
      error: () => {
        this.errorMessage = 'Unable to load sales orders.';
        this.cdr.markForCheck();
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

  openOrderDetails(order: SalesOrder): void {
    this.selectedOrder = null;
    this.detailsLoading = true;
    this.errorMessage = '';

    this.salesService.get(order.id)
      .pipe(finalize(() => {
        this.detailsLoading = false;
        this.cdr.markForCheck();
      }))
      .subscribe({
        next: (response) => {
          this.selectedOrder = response.data;
          this.paymentForm = {
            amount: null,
            method: 'cash',
            reference: '',
            paid_at: new Date().toISOString().slice(0, 16),
            notes: '',
          };

          this.totalpaid = (this.selectedOrder.payments ?? []).reduce((sum, payment) => sum + Number(payment.amount), 0);
          this.cdr.markForCheck();
        },
        error: () => {
          this.errorMessage = 'Unable to load order details.';
          this.cdr.markForCheck();
        },
      });
  }

  closeOrderDetails(): void {
    this.selectedOrder = null;
  }

 get outstandingBalance(): number {
  if (!this.selectedOrder) return 0;

  return Math.max(0,Number(this.selectedOrder.total) - this.totalpaid);
}

  recordPayment(): void {
    const order = this.selectedOrder;
    const amount = Number(this.paymentForm.amount);

    if (!order || !Number.isFinite(amount) || amount <= 0) {
      this.errorMessage = 'Enter a valid payment amount.';
      return;
    }

    if (amount > this.outstandingBalance) {
      this.errorMessage = 'Payment cannot exceed the outstanding balance.';
      return;
    }

    const payload: RecordSalesPaymentRequest = {
      amount,
      method: this.paymentForm.method,
      paid_at: new Date(this.paymentForm.paid_at).toISOString(),
      reference: this.paymentForm.reference.trim() || null,
      notes: this.paymentForm.notes.trim() || null,
    };

    this.recordingPayment = true;
    this.errorMessage = '';

    this.salesService.recordPayment(order.id, payload)
      .pipe(finalize(() => {
        this.recordingPayment = false;
        this.cdr.markForCheck();
      }))
      .subscribe({
        next: () => {
          this.successMessage = 'Payment recorded successfully.';
          this.openOrderDetails(order);
          this.loadOrders(this.page);

          this.cdr.detectChanges();
        },
        error: (error) => {
          this.errorMessage =
            error?.error?.message || 'Unable to record payment.';

            this.cdr.detectChanges();
        },
      });
  }

  fulfillOrder(): void {
    const order = this.selectedOrder;

    if (!order || this.processingFulfillment) return;

    this.processingFulfillment = true;
    this.errorMessage = '';

    this.salesService.fulfill(order.id)
      .pipe(finalize(() => {
        this.processingFulfillment = false;
        this.cdr.markForCheck();
      }))
      .subscribe({
        next: () => {
          this.successMessage = 'Order fulfillment processed.';
          this.openOrderDetails(order);
          this.loadOrders(this.page);
        },
        error: (error) => {
          this.errorMessage =
            error?.error?.message || 'Unable to fulfill this order.';
        },
      });
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
  this.editingOrderId = null;

 
 // console.log(this.branches[0].id);

    this.orderForm = {
      branch_id: this.branches.length
        ? Number(this.branches[0].id)
        : null,
      customer_id: null,
      order_number: `SALE-${Date.now()}`,
      discount: 0,
      tax: 0,
      notes: '',
    };

    this.orderItems = [];
    this.addOrderItem();
    this.showCreateForm = true;
  }

  createOrder(): void {
    this.errorMessage = '';
    this.successMessage = '';

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
      branch_id: this.orderForm.branch_id ? Number(this.orderForm.branch_id) : null,
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

canEditOrder(order: SalesOrder): boolean {
  return (
    order.status === 'draft' &&
    order.payment_status === 'unpaid' &&
    !(order.payments?.length)
  );
}

canDeleteOrder(order: SalesOrder): boolean {
  return (
    order.status === 'draft' &&
    order.payment_status === 'unpaid' &&
    !(order.payments?.length) &&
    !(order.fulfillmentRequests?.length)
  );
}

openEditForm(order: SalesOrder): void {
  this.errorMessage = '';
  this.successMessage = '';

  if (!this.canEditOrder(order)) {
    this.errorMessage =
      'Only draft, unpaid orders without payments can be edited.';
    return;
  }

  this.salesService.get(order.id).subscribe({
    next: (response) => {
      const currentOrder = response.data;

      if (!this.canEditOrder(currentOrder)) {
        this.errorMessage =
          'This order is no longer eligible for editing.';
        return;
      }

      this.editingOrderId = currentOrder.id;

      this.orderForm = {
       branch_id: currentOrder.branch_id != null
        ? Number(currentOrder.branch_id)
        : null,
        customer_id: currentOrder.customer_id
          ? Number(currentOrder.customer_id)
          : null,
        order_number: currentOrder.order_number,
        discount: Number(currentOrder.discount ?? 0),
        tax: Number(currentOrder.tax ?? 0),
        notes: currentOrder.notes ?? '',
      };

      this.orderItems = (currentOrder.items ?? []).map((item) => ({
        catalog_item_id: Number(item.catalog_item_id),
        quantity: Number(item.quantity),
        discount: Number(item.discount ?? 0),
        tax: Number(item.tax ?? 0),
      }));

      if (!this.orderItems.length) {
        this.addOrderItem();
      }

      this.selectedOrder = null;
      this.showCreateForm = true;
      this.cdr.markForCheck();
      this.cdr.detectChanges();
    },
    error: (error) => {
      this.errorMessage =
        error?.error?.message || 'Unable to load this order for editing.';
      this.cdr.markForCheck();
    },
  });
}

updateOrder(): void {
  const orderId = this.editingOrderId;

  if (orderId === null || this.saving) {
    return;
  }

  this.errorMessage = '';
  this.successMessage = '';

  if (!this.orderForm.order_number.trim()) {
    this.errorMessage = 'Enter an order number.';
    return;
  }

  if (
    !this.orderItems.length ||
    this.orderItems.some(
      (item) =>
        !item.catalog_item_id ||
        !Number.isFinite(Number(item.quantity)) ||
        Number(item.quantity) <= 0 ||
        Number(item.discount) < 0 ||
        Number(item.tax) < 0,
    )
  ) {
    this.errorMessage = 'Add valid order items and quantities.';
    return;
  }

  this.saving = true;

  this.salesService.update(orderId, {
    branch_id: this.orderForm.branch_id ? Number(this.orderForm.branch_id) : null,
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
    .pipe(finalize(() => {
      this.saving = false;
      this.cdr.markForCheck();
    }))
    .subscribe({
      next: () => {
        this.showCreateForm = false;
        this.editingOrderId = null;
        this.successMessage = 'Sales order updated successfully.';

        this.loadOrders(this.page);
        this.cdr.markForCheck();
        this.cdr.detectChanges();
      },
      error: (error) => {
        this.errorMessage =
          error?.error?.message || 'Unable to update the sales order.';
        this.cdr.markForCheck();
      },
    });
}

deleteOrder(order: SalesOrder): void {
  if (this.deletingOrderId !== null) {
    return;
  }

  if (!this.canDeleteOrder(order)) {
    this.errorMessage =
      'Only eligible draft orders without payment or fulfillment history can be deleted.';
    return;
  }

  const confirmed = window.confirm(
    `Delete sales order "${order.order_number}"? This action cannot be undone.`,
  );

  if (!confirmed) {
    return;
  }

  this.errorMessage = '';
  this.successMessage = '';
  this.deletingOrderId = order.id;

  this.salesService.delete(order.id)
    .pipe(finalize(() => {
      this.deletingOrderId = null;
      this.cdr.markForCheck();
    }))
    .subscribe({
      next: () => {
        if (this.selectedOrder?.id === order.id) {
          this.selectedOrder = null;
        }

        this.successMessage = 'Sales order deleted successfully.';

        // Return to the previous page if the current page becomes empty.
        const nextPage =
          this.orders.length === 1 && this.page > 1
            ? this.page - 1
            : this.page;

        this.loadOrders(nextPage);
        this.cdr.markForCheck();
        this.cdr.detectChanges();
      },
      error: (error) => {
        this.errorMessage =
          error?.error?.message || 'Unable to delete this sales order.';
        this.cdr.markForCheck();
      },
    });
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