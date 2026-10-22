import { Component, OnInit,OnDestroy, inject,ChangeDetectorRef,DestroyRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { finalize, forkJoin } from 'rxjs';
import { Subject, debounceTime, distinctUntilChanged,takeUntil } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {InventoryApis,InventoryAvailability} from '../../core/services/inventory-api';
import { InventoryApi } from '../../core/services/inventory-api.service';
import { SearchableSelectComponent,SearchableSelectOption } from '../../shared/components/searchable-select/searchable-select.component';
import { SalesService, SalesOrder, OrderStatus,PaymentStatus, OrderPaymentMethod,RecordSalesPaymentRequest,FulfillmentRequest } from '../../core/services/sales.service';
import { BranchApi, Branch } from '../../core/services/branch-api';
import { CustomerApi, Customer } from '../../core/services/customer-api';
import { CatalogApi, CatalogItem } from '../../core/services/catalog-api';
import { IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {walletOutline,gridOutline,peopleOutline,cubeOutline,colorFilterOutline,barChartOutline,settingsOutline,layersOutline,cartOutline,documentTextOutline, logOutOutline} from 'ionicons/icons';


interface AlternativeBranchStock {
  branchId: number;
  branchName: string;
  availableQuantity: number;
}

interface LineAvailabilityState {
  loading: boolean;
  error: string | null;
  result: InventoryAvailability | null;
}

@Component({
  selector: 'app-sales',
  templateUrl: './sales.component.html',
  styleUrls: ['./sales.component.scss'],
  standalone: true,
  imports: [CommonModule, FormsModule,SearchableSelectComponent,IonIcon],
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
  private readonly inventoryApis = inject(InventoryApis);
  private readonly inventoryApi = inject(InventoryApi);
  alternativeBranchLineIndex: number | null = null;
  alternativeBranchesLoading = false;
  alternativeBranchesError = '';
  alternativeBranchResults: AlternativeBranchStock[] = [];
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
  //alternativeBranchLineIndex: number | null = null;
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
  preparingFulfillment = false;
  lineAvailability: Record<
  number,
  {
    loading: boolean;
    error: string | null;
    result: InventoryAvailability | null;
  }
> = {};

stockActionMode: 'receive' | 'transfer' | null = null;
stockActionLineIndex: number | null = null;
stockActionQuantity: number | null = null;
stockActionSourceBranchId: number | null = null;
stockActionNotes = '';
stockActionLoading = false;
stockActionError = '';

 constructor() {
    addIcons({
      'wallet-outline' : walletOutline,
      'grid-outline' : gridOutline,
      'color-filter-outline' : colorFilterOutline,
      'people-outline' : peopleOutline,
      'cube-outline' : cubeOutline,
      'bar-chart-outline' : barChartOutline,
      'settings-outline' : settingsOutline,
      'layers-outline' : layersOutline,
      'cart-outline' : cartOutline,
      'log-out-outline': logOutOutline,
      'document-text-outline' : documentTextOutline
  });
      
  }

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
          this.detailsLoading = false;
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

    if (!order || this.processingFulfillment) {
      return;
    }

    this.processingFulfillment = true;
    this.errorMessage = '';
    this.successMessage = '';

    this.salesService.fulfill(order.id)
      .pipe(
        finalize(() => {
          this.processingFulfillment = false;
          this.cdr.markForCheck();
        }),
      )
      .subscribe({
        next: (response) => {
          this.selectedOrder = response.data;
          this.successMessage = response.message;
          this.processingFulfillment = false;
          // Reload the order so the displayed status and quantities
          // reflect the server's latest fulfillment state.
         // this.openOrderDetails(response.data);
         this.cdr.detectChanges();
          this.loadOrders(this.page);
        },
        error: (error) => {
          this.errorMessage =
            error?.error?.message ||
            'Unable to fulfill this order.';
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
    !(order.payments?.length) &&
    !(order.fulfillment_requests?.length)
  );
}

canDeleteOrder(order: SalesOrder): boolean {
  return (
    order.status === 'draft' &&
    order.payment_status === 'unpaid' &&
    !(order.payments?.length) &&
    !(order.fulfillment_requests?.length)
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
    next: (response:any) => {
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

      this.orderItems = (currentOrder.items ?? []).map((item:any) => ({
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
    error: (error:any) => {
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

showAlternativeBranchesFor(index: number): void {
  // if (this.alternativeBranchLineIndex === index) {
  //   this.alternativeBranchLineIndex = null;
  //   return;
  // }
  if (this.alternativeBranchLineIndex === index) {
  this.alternativeBranchLineIndex = null;
  this.showAlternativeBranchesFor(index);
}

  this.alternativeBranchLineIndex = index;
  this.alternativeBranchResults = [];
  this.alternativeBranchesError = '';

  const item = this.orderItems[index];
  const currentBranchId = Number(this.orderForm.branch_id);
  const catalogItemId = Number(item?.catalog_item_id);
  const quantity = Number(item?.quantity);

  if (!item || !currentBranchId || !catalogItemId || quantity <= 0) {
    this.alternativeBranchesError =
      'Select a branch, product, and valid quantity first.';
    return;
  }

  const otherBranches = this.branches.filter(
    branch => Number(branch.id) !== currentBranchId,
  );

  if (!otherBranches.length) {
    this.alternativeBranchesError = 'No other branches are available.';
    return;
  }

  this.alternativeBranchesLoading = true;

  forkJoin(
    otherBranches.map(branch =>
      this.inventoryApis.checkAvailability({
        branch_id: Number(branch.id),
        catalog_item_id: catalogItemId,
        quantity,
      }),
    ),
  )
    .pipe(
      finalize(() => {
        this.alternativeBranchesLoading = false;
        this.cdr.markForCheck();
      }),
      takeUntilDestroyed(this.destroyRef),
    )
    .subscribe({
      next: responses => {
        // Ignore results if the user changed the line or selected branch.
        const currentItem = this.orderItems[index];

        if (
          !currentItem ||
          this.alternativeBranchLineIndex !== index ||
          Number(currentItem.catalog_item_id) !== catalogItemId ||
          Number(currentItem.quantity) !== quantity ||
          Number(this.orderForm.branch_id) !== currentBranchId
        ) {
          return;
        }

        this.alternativeBranchResults = responses
          .map((response, i) => ({
            branchId: Number(otherBranches[i].id),
            branchName: otherBranches[i].name,
            availableQuantity: response.data.tracks_inventory
              ? Number(response.data.available_quantity ?? 0)
              : 0,
            tracksInventory: response.data.tracks_inventory,
          }))
          .filter(result => result.tracksInventory)
          .map(({ branchId, branchName, availableQuantity }) => ({
            branchId,
            branchName,
            availableQuantity,
          }))
          .sort((a, b) => b.availableQuantity - a.availableQuantity);

          this.cdr.detectChanges();
      },
      error: () => {
        this.alternativeBranchesError =
          'Unable to compare branch stock. Please try again.';

          this.cdr.detectChanges();
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

fulfillmentProgress(request: FulfillmentRequest): number {
  const requested = Number(request.requested_quantity);
  const fulfilled = Number(request.fulfilled_quantity);

  if (requested <= 0) return 0;

  return Math.min(100, Math.max(0, fulfilled / requested * 100));
}

  formatAmount(value: number | string | null | undefined): string {
    return Number(value ?? 0).toLocaleString('en-GH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  checkLineAvailability(index: number): void {
  const item = this.orderItems[index];
  const branchId = Number(this.orderForm.branch_id);
  const catalogItemId = Number(item?.catalog_item_id);
  const quantity = Number(item?.quantity);

  // Clear stale availability whenever the inputs are invalid.
  if (
    !item ||
    !Number.isInteger(branchId) ||
    branchId <= 0 ||
    !Number.isInteger(catalogItemId) ||
    catalogItemId <= 0 ||
    !Number.isFinite(quantity) ||
    quantity <= 0
  ) {
    delete this.lineAvailability[index];
    return;
  }

  this.lineAvailability[index] = {
    loading: true,
    error: null,
    result: null,
  };

  this.inventoryApis
    .checkAvailability({
      branch_id: branchId,
      catalog_item_id: catalogItemId,
      quantity,
    })
    .subscribe({
      next: (response:any) => {
        // Ignore a response if the line changed while the request was running.
        const current = this.orderItems[index];

        if (
          !current ||
          Number(this.orderForm.branch_id) !== branchId ||
          Number(current.catalog_item_id) !== catalogItemId ||
          Number(current.quantity) !== quantity
        ) {
          return;
        }

        this.lineAvailability[index] = {
          loading: false,
          error: null,
          result: response.data,
        };

        this.cdr.markForCheck();
        this.cdr.detectChanges();
      },
      error: () => {
        this.lineAvailability[index] = {
          loading: false,
          error: 'Unable to check stock. Please retry.',
          result: null,
        };

        this.cdr.markForCheck();
      },
    });
}

onBranchChanged(): void {
  this.lineAvailability = {};
  this.alternativeBranchLineIndex = null;
  this.alternativeBranchResults = [];
  this.alternativeBranchesError = '';

  this.orderItems.forEach((_, index) => {
    this.checkLineAvailability(index);
  });
}

onCatalogItemChanged(index: number): void {
  this.checkLineAvailability(index);
}

onQuantityChanged(index: number): void {
  this.checkLineAvailability(index);
}

hasAvailableStock(value: number | string | null | undefined): boolean {
  return Number(value ?? 0) > 0;
}

/**
 * Reduce the requested quantity to the currently available quantity.
 * Availability is advisory; the backend still rechecks during fulfillment.
 */
useAvailableQuantity(index: number): void {
  const stock = this.lineAvailability[index]?.result;
  const item = this.orderItems[index];

  if (!stock?.tracks_inventory || !item) return;

  const available = Number(stock.available_quantity);

  if (!Number.isFinite(available) || available <= 0) {
    this.errorMessage = 'No stock is available at this branch.';
    return;
  }

  item.quantity = Math.min(Number(item.quantity), available);
  this.checkLineAvailability(index);
  this.cdr.markForCheck();
}

/**
 * Switch the entire sale to another branch.
 * The salesperson must understand that this changes the order branch,
 * not that stock has been transferred between branches.
 */
useBranch(branchId: number): void {
  if (!Number.isInteger(branchId) || branchId <= 0) return;

  this.orderForm.branch_id = branchId;
  this.onBranchChanged();
  this.successMessage =
    'Branch changed. Stock availability is being checked again.';
  this.cdr.markForCheck();
}

/** Branches other than the currently selected branch. */
get alternativeBranchOptions(): SearchableSelectOption[] {
  const currentBranchId = Number(this.orderForm.branch_id);

  return this.branches
    .filter(branch => Number(branch.id) !== currentBranchId)
    .map(branch => ({
      value: Number(branch.id),
      label: branch.name,
      description: branch.code || branch.address || undefined,
    }));
}

prepareFulfillmentRequests(): void {
  const order = this.selectedOrder;

  if (!order || this.preparingFulfillment) return;

  this.preparingFulfillment = true;
  this.errorMessage = '';
  this.successMessage = '';

  this.salesService.prepareFulfillment(order.id)
    .pipe(
      finalize(() => {
        this.preparingFulfillment = false;
        this.cdr.markForCheck();
      })
    )
    .subscribe({
      next: (response) => {
        this.selectedOrder = response.data;
       // console.log(this.selectedOrder);
        this.successMessage = response.message;
       // this.loadOrders(this.page);
        this.cdr.detectChanges();
      },
      error: (error) => {
        this.errorMessage =
          error?.error?.message ||
          'Unable to prepare fulfillment requests.';

          this.cdr.detectChanges();
      }
    });
}

fulfillmentOutstanding(
  request: {
    requested_quantity: string | number;
    fulfilled_quantity: string | number;
  },
): number {
  return Math.max(
    0,
    Number(request.requested_quantity) -
      Number(request.fulfilled_quantity),
  );
}

fulfillmentStatusLabel(status: string): string {
  return status.replaceAll('_', ' ');
}

getFulfillmentItemName(orderItemId: number): string {
  const item = this.selectedOrder?.items?.find(
    (orderItem) => orderItem.id === orderItemId,
  );

  return item?.catalog_item?.name ?? 'Order item';
}
formatQuantity(value: number | string | null | undefined): string {
  return Number(value ?? 0).toLocaleString('en-GH', {
    maximumFractionDigits: 3,
  });
}

openStockAction(
  mode: 'receive' | 'transfer',
  index: number,
  sourceBranchId?: number,
): void {
  const item = this.orderItems[index];
  const destinationBranchId = Number(this.orderForm.branch_id);

  if (!item || !destinationBranchId || !item.catalog_item_id) {
    this.errorMessage = 'Select a branch and product first.';
    return;
  }

  this.stockActionMode = mode;
  this.stockActionLineIndex = index;
  this.stockActionQuantity =
    mode === 'transfer'
      ? Number(this.lineAvailability[index]?.result?.shortfall_quantity ?? item.quantity)
      : Number(this.lineAvailability[index]?.result?.shortfall_quantity ?? item.quantity);

  this.stockActionSourceBranchId = sourceBranchId ?? null;
  this.stockActionNotes =
    mode === 'receive'
      ? 'Stock received from sales shortage workflow'
      : 'Stock transfer to resolve sales shortage';

  this.stockActionError = '';
}

get selectedSalesBranchName(): string {
  const branchId = Number(this.orderForm.branch_id);
  return this.branches.find(
    branch => Number(branch.id) === branchId,
  )?.name ?? 'Selected branch';
}

cancelStockAction(): void {
  if (this.stockActionLoading) return;

  this.stockActionMode = null;
  this.stockActionLineIndex = null;
  this.stockActionQuantity = null;
  this.stockActionSourceBranchId = null;
  this.stockActionNotes = '';
  this.stockActionError = '';
}

submitStockAction(): void {
  const mode = this.stockActionMode;
  const index = this.stockActionLineIndex;
  const item = index === null ? undefined : this.orderItems[index];
  const destinationBranchId = Number(this.orderForm.branch_id);
  const quantity = Number(this.stockActionQuantity);

  if (
    !mode ||
    index === null ||
    !item ||
    !Number.isInteger(destinationBranchId) ||
    destinationBranchId <= 0
  ) {
    this.stockActionError = 'Select a valid product and destination branch.';
    return;
  }

  if (!Number.isFinite(quantity) || quantity <= 0) {
    this.stockActionError = 'Enter a quantity greater than zero.';
    return;
  }

  const catalogItemId = Number(item.catalog_item_id);
  if (!Number.isInteger(catalogItemId) || catalogItemId <= 0) {
    this.stockActionError = 'Select a valid product.';
    return;
  }

  if (mode === 'transfer') {
    const sourceBranchId = Number(this.stockActionSourceBranchId);

    if (
      !Number.isInteger(sourceBranchId) ||
      sourceBranchId <= 0 ||
      sourceBranchId === destinationBranchId
    ) {
      this.stockActionError = 'Choose a different source branch.';
      return;
    }

    const source = this.alternativeBranchResults.find(
      branch => branch.branchId === sourceBranchId,
    );

    if (source && quantity > source.availableQuantity) {
      this.stockActionError =
        `Only ${this.formatQuantity(source.availableQuantity)} units are currently available at the source branch.`;
      return;
    }
  }

  this.stockActionLoading = true;
  this.stockActionError = '';
  this.errorMessage = '';
  this.successMessage = '';

  const notes = this.stockActionNotes.trim() || null;

  const request$ =
    mode === 'receive'
      ? this.inventoryApi.receive({
          branch_id: destinationBranchId,
          catalog_item_id: catalogItemId,
          quantity,
          notes,
        })
      : this.inventoryApi.transfer({
          catalog_item_id: catalogItemId,
          source_branch_id: Number(this.stockActionSourceBranchId),
          destination_branch_id: destinationBranchId,
          quantity,
          notes,
        });

  request$
    .pipe(
      finalize(() => {
        this.stockActionLoading = false;
        this.cdr.markForCheck();
      }),
      takeUntilDestroyed(this.destroyRef),
    )
    .subscribe({
      next: (response:any) => {
        this.successMessage =
          response.message ||
          (mode === 'receive'
            ? 'Stock received successfully.'
            : 'Stock transferred successfully.');

        this.stockActionLoading = false;
        this.stockActionMode = null;
        this.stockActionLineIndex = null;
        this.stockActionQuantity = null;
        this.stockActionSourceBranchId = null;
        this.stockActionNotes = '';
        this.stockActionError = '';

        // Recheck stock for the current sales branch.
        this.checkLineAvailability(index);

        // Refresh the comparison panel if it is open for this line.
        if (this.alternativeBranchLineIndex === index) {
          this.showAlternativeBranchesFor(index);
        }

        this.cdr.detectChanges();
      },
      error: (error:any) => {
        this.stockActionLoading = false;
        this.stockActionError =
          error?.error?.message ||
          error?.error?.errors?.quantity?.[0] ||
          (error?.status === 403
            ? 'You do not have permission to perform this stock operation.'
            : 'Unable to complete the stock operation. Please try again.');

        this.cdr.markForCheck();
      },
    });
}

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}