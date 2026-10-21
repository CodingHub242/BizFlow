
import {
  ChangeDetectorRef,
  Component,
  OnDestroy,
  OnInit,
  inject,
} from '@angular/core';
import { CommonModule, CurrencyPipe, DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';

import {
  Invoice,
  InvoicesApi,
} from '../../core/services/invoices-api.service';

@Component({
  selector: 'app-invoices',
  standalone: true,
  imports: [CommonModule, FormsModule, CurrencyPipe, DatePipe],
  templateUrl: './invoices.component.html',
  styleUrl: './invoices.component.scss',
})
export class InvoicesComponent implements OnInit, OnDestroy {
  private readonly invoicesApi = inject(InvoicesApi);
  private readonly router = inject(Router);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly destroy$ = new Subject<void>();

  invoices: Invoice[] = [];
  loading = false;
  error = '';
  search = '';
  selectedStatus = '';
  selectedPaymentStatus = '';

  currentPage = 1;
  lastPage = 1;
  totalItems = 0;
  perPage = 15;
  invoiceTotal = 0;

  businessOutstandingTotal = 0;

  


  ngOnInit(): void {
    this.loadInvoices();
  }




loadInvoices(page = 1): void {
  this.loading = true;
  this.error = '';

  this.invoicesApi
    .list(page, this.perPage, {
      search: this.search,
      status: this.selectedStatus,
      payment_status: this.selectedPaymentStatus,
    })
    .pipe(takeUntil(this.destroy$))
    .subscribe({
      next: (response) => {
        const result = response.data;

        this.invoices = Array.isArray(result)
          ? result
          : result.data ?? [];

        const nestedMeta = Array.isArray(result)
          ? undefined
          : result;

        this.currentPage =
          response.meta?.current_page ??
          nestedMeta?.current_page ??
          page;

        this.lastPage =
          response.meta?.last_page ??
          nestedMeta?.last_page ??
          1;

        this.totalItems =
          response.meta?.total ??
          nestedMeta?.total ??
          this.invoices.length;

        this.perPage =
          response.meta?.per_page ??
          nestedMeta?.per_page ??
          this.perPage;

        this.invoiceTotal = Number(
          response.summary?.matching_invoice_total ?? 0,
        );

        this.businessOutstandingTotal = Number(
          response.summary?.outstanding_invoice_total ?? 0,
        );

        this.loading = false;
        this.cdr.markForCheck();
        this.cdr.detectChanges();
      },
      error: (err) => {
        this.invoices = [];
        this.totalItems = 0;
        this.invoiceTotal = 0;
        this.lastPage = 1;
        this.loading = false;
        this.error =
          err?.error?.message ??
          'Unable to load invoices. Please try again.';

        this.cdr.markForCheck();
        this.cdr.detectChanges();
      },
    });
}



  // loadInvoices(): void {
  //   this.loading = true;
  //   this.error = '';

  //   this.invoicesApi
  //     .list()
  //     .pipe(takeUntil(this.destroy$))
  //     .subscribe({
  //       next: (response) => {
  //         const result = response.data;

  //         // Support either a direct array or a nested Laravel paginator.
  //         if (Array.isArray(result)) {
  //           this.invoices = result;
  //           this.currentPage = response.meta?.current_page ?? 1;
  //           this.lastPage = response.meta?.last_page ?? 1;
  //           this.totalItems = response.meta?.total ?? result.length;
  //           this.businessOutstandingTotal = Number(
  //             response.summary?.outstanding_invoice_total ?? 0,
  //           );
  //         } else {
  //           this.invoices = result.data ?? [];
  //           this.currentPage =
  //             result.current_page ?? response.meta?.current_page ?? 1;
  //           this.lastPage =
  //             result.last_page ?? response.meta?.last_page ?? 1;
  //           this.totalItems =
  //             result.total ?? response.meta?.total ?? this.invoices.length;

  //           this.businessOutstandingTotal = Number(
  //             response.summary?.outstanding_invoice_total ?? 0,
  //           );
  //         }

  //         this.loading = false;
  //         this.cdr.markForCheck();
  //       },
  //       error: (err) => {
  //         this.invoices = [];
  //         this.loading = false;
  //         this.error =
  //           err?.error?.message ?? 'Unable to load invoices. Please try again.';
  //         this.cdr.markForCheck();
  //       },
  //     });
  // }

  onFiltersChanged(): void {
  this.currentPage = 1;
  this.loadInvoices(1);
}

  get filteredInvoices(): Invoice[] {
    const query = this.search.trim().toLowerCase();

    return this.invoices.filter((invoice) => {
      const customerName =
        invoice.customer?.company_name ||
        invoice.customer?.name ||
        '';

      const matchesSearch =
        !query ||
        invoice.invoice_number?.toLowerCase().includes(query) ||
        customerName.toLowerCase().includes(query) ||
        String(invoice.id).includes(query);

      const matchesStatus =
        !this.selectedStatus ||
        this.normalizeStatus(invoice.status) === this.selectedStatus;

      const matchesPayment =
        !this.selectedPaymentStatus ||
        this.normalizeStatus(invoice.payment_status) ===
          this.selectedPaymentStatus;

      return matchesSearch && matchesStatus && matchesPayment;
    });
  }

  get outstandingTotal(): number {
    return this.filteredInvoices.reduce(
      (sum, invoice) => sum + this.balanceDue(invoice),
      0,
    );
  }

//   get invoiceTotal(): number {
//   return this.filteredInvoices.reduce(
//     (sum, invoice) => sum + Number(invoice.total || 0),
//     0,
//   );
// }
  balanceDue(invoice: Invoice): number {
    if (invoice.balance_due !== undefined && invoice.balance_due !== null) {
      return Math.max(0, Number(invoice.balance_due));
    }

    return Math.max(
      0,
      Number(invoice.total || 0) -
        Number(invoice.net_paid || 0) -
        Number(invoice.credit_applied || 0),
    );
  }

  amountPaid(invoice: Invoice): number {
    if (invoice.net_paid !== undefined && invoice.net_paid !== null) {
      return Number(invoice.net_paid);
    }

    return Math.max(
      0,
      Number(invoice.total || 0) - this.balanceDue(invoice),
    );
  }

  normalizeStatus(value: string | null | undefined): string {
    return String(value ?? '').toLowerCase().replace(/[\s_]+/g, '-');
  }

  statusLabel(value: string | null | undefined): string {
    return String(value ?? 'unknown')
      .replace(/[_-]+/g, ' ')
      .replace(/\b\w/g, (letter) => letter.toUpperCase());
  }

  statusClass(value: string | null | undefined): string {
    return this.normalizeStatus(value);
  }

  formatMoney(value: number | string | null | undefined): string {
    return `GH₵ ${Number(value ?? 0).toLocaleString('en-GH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })}`;
  }

  viewInvoice(invoice: Invoice): void {
    this.router.navigate(['/invoices', invoice.id]);
  }

  issueInvoice(invoice: Invoice): void {
    if (!confirm(`Issue invoice ${invoice.invoice_number}?`)) {
      return;
    }

    this.invoicesApi
      .issue(invoice.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadInvoices(),
        error: (err) => {
          this.error =
            err?.error?.message ?? 'Unable to issue this invoice.';
          this.cdr.markForCheck();
        },
      });
  }

  cancelInvoice(invoice: Invoice): void {
    if (!confirm(`Cancel invoice ${invoice.invoice_number}?`)) {
      return;
    }

    this.invoicesApi
      .cancel(invoice.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadInvoices(),
        error: (err) => {
          this.error =
            err?.error?.message ?? 'Unable to cancel this invoice.';
          this.cdr.markForCheck();
        },
      });
  }

 loadPage(page: number): void {
    if (
      page < 1 ||
      page > this.lastPage ||
      page === this.currentPage ||
      this.loading
    ) {
      return;
    }

    this.loadInvoices(page);
  }

 

  get displayedInvoices(): Invoice[] {
    return this.filteredInvoices;
  }

  get displayLastPage(): number {
   return this.lastPage;
  }

  refresh(): void {
    this.loadInvoices();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}
