import {ChangeDetectorRef,Component,OnDestroy,OnInit,inject} from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { FormsModule } from '@angular/forms';
import {Invoice,InvoicesApi,InvoicePayment,InvoiceLine
} from '../../../core/services/invoices-api.service';


@Component({
  selector: 'app-invoice-details',
  standalone: true,
  imports: [CommonModule,FormsModule],
  templateUrl: './invoice-details.component.html',
  styleUrl: './invoice-details.component.scss',
})
export class InvoiceDetailsComponent implements OnInit, OnDestroy {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly invoicesApi = inject(InvoicesApi);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly destroy$ = new Subject<void>();

   // Xavier Media is the invoice issuer.
  // Replace the empty values with verified business details.
  readonly issuer = {
    name: 'Xavier Media',
    tagline: 'Creative. Digital. Professional.',
    address: 'Dzorwulu 3 Crescent',
    phone: '+233277977000 / +233277977111',
    email: 'info@siobhangroup.com',
    website: 'https://siobhangroup.com',
    taxId: '',
    paymentInstructions: [
      'Bank Account Name: Siobhan Test,',
      'Account Number: 344554433344,',
      'MOMO: 45454534',
    ].join('\n'),
  };


  showPaymentForm = false;
  savingPayment = false;
  actionLoading = false;
  actionError = '';
  actionMessage = '';
  payments?: InvoicePayment[];

  paymentForm = {
    amount: 0,
    method: 'mobile_money' as
      | 'cash'
      | 'bank_transfer'
      | 'mobile_money'
      | 'card'
      | 'cheque'
      | 'other',
    reference: '',
    notes: '',
    paid_at: new Date().toISOString().slice(0, 10),
  };

  readonly paymentMethods = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'mobile_money', label: 'Mobile Money' },
    { value: 'card', label: 'Card' },
    { value: 'cheque', label: 'Cheque' },
    { value: 'other', label: 'Other' },
  ];


  invoice: Invoice | null = null;
  loading = false;
  error = '';

  ngOnInit(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));

    if (!Number.isInteger(id) || id <= 0) {
      this.error = 'Invalid invoice ID.';
      return;
    }

    this.loadInvoice(id);
  }

  loadInvoice(id: number): void {
    this.loading = true;
    this.error = '';

    this.invoicesApi
      .get(id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.invoice = response.data;
          this.loading = false;
          //console.log(this.invoice);
          this.cdr.markForCheck();
          this.cdr.detectChanges();
        },
        error: (err) => {
          this.invoice = null;
          this.loading = false;
          this.error =
            err?.error?.message ?? 'Unable to load this invoice.';
          this.cdr.markForCheck();
        },
      });
  }

  balanceDue(invoice: Invoice): number {
    if (invoice.balance_due != null) {
      return Math.max(0, Number(invoice.balance_due));
    }

    return Math.max(
      0,
      Number(invoice.total || 0) -
        Number(invoice.net_paid || 0) -
        Number(invoice.credit_applied || 0),
    );
  }

  formatMoney(value: number | string | null | undefined): string {
    return `GH₵ ${Number(value ?? 0).toLocaleString('en-GH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })}`;
  }

  statusLabel(value: string | null | undefined): string {
    return String(value ?? 'Unknown')
      .replace(/[_-]+/g, ' ')
      .replace(/\b\w/g, (letter) => letter.toUpperCase());
  }

   itemName(item: InvoiceLine): string {
    return item.item_name ||
      item.name ||
      item.catalog_item?.name ||
      item.description ||
      'Item';
  }

  lineTotal(item: InvoiceLine): number {
    if (item.line_total != null) {
      return Number(item.line_total);
    }

    return Math.max(
      0,
      Number(item.quantity) * Number(item.unit_price) -
        Number(item.discount ?? 0) +
        Number(item.tax ?? 0),
    );
  }


  openPaymentForm(): void {
    if (!this.invoice) return;

    this.actionError = '';
    this.actionMessage = '';
    this.paymentForm.amount = this.balanceDue(this.invoice);
    this.paymentForm.paid_at = new Date().toISOString().slice(0, 10);
    this.showPaymentForm = true;
  }

  submitPayment(): void {
    if (!this.invoice || this.savingPayment) return;

    const amount = Number(this.paymentForm.amount);
    const balance = this.balanceDue(this.invoice);

    if (!Number.isFinite(amount) || amount <= 0) {
      this.actionError = 'Enter a payment amount greater than zero.';
      return;
    }

    if (amount > balance) {
      this.actionError =
        'The payment cannot exceed the outstanding balance.';
      return;
    }

    this.savingPayment = true;
    this.actionError = '';
    this.actionMessage = '';

    this.invoicesApi.recordPayment(this.invoice.id, {
      amount,
      method: this.paymentForm.method,
      reference: this.paymentForm.reference.trim() || undefined,
      notes: this.paymentForm.notes.trim() || undefined,
      paid_at: this.paymentForm.paid_at || undefined,
    })
    .pipe(takeUntil(this.destroy$))
    .subscribe({
      next: () => {
        this.savingPayment = false;
        this.showPaymentForm = false;
        this.actionMessage = 'Payment recorded successfully.';
        this.cdr.detectChanges();

        
        this.loadInvoice(this.invoice!.id);

       
      },
      error: (err) => {
        this.savingPayment = false;
        this.actionError =
          err?.error?.message ?? 'Unable to record payment.';
        this.cdr.markForCheck();
        this.cdr.detectChanges();
      },
    });
  }

  issueInvoice(): void {
    if (!this.invoice || this.actionLoading) return;

    if (!confirm('Issue this invoice?')) return;

    this.actionLoading = true;
    this.actionError = '';
    this.actionMessage = '';

    this.invoicesApi.issue(this.invoice.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          const id = this.invoice!.id;
          this.actionLoading = false;
          this.actionMessage = 'Invoice issued successfully.';
          this.loadInvoice(id);
        },
        error: (err) => {
          this.actionLoading = false;
          this.actionError =
            err?.error?.message ?? 'Unable to issue invoice.';
          this.cdr.markForCheck();
        },
      });
  }

  cancelInvoice(): void {
    if (!this.invoice || this.actionLoading) return;

    if (!confirm('Cancel this invoice? This action may not be reversible.')) {
      return;
    }

    this.actionLoading = true;
    this.actionError = '';
    this.actionMessage = '';

    this.invoicesApi.cancel(this.invoice.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          const id = this.invoice!.id;
          this.actionLoading = false;
          this.actionMessage = 'Invoice cancelled successfully.';
          this.loadInvoice(id);
        },
        error: (err) => {
          this.actionLoading = false;
          this.actionError =
            err?.error?.message ?? 'Unable to cancel invoice.';
          this.cdr.markForCheck();
        },
      });
  }


   paymentMethod(method?: string | null): string {
    return String(method || 'Payment')
      .replace(/[_-]+/g, ' ')
      .replace(/\b\w/g, letter => letter.toUpperCase());
  }

  printInvoice(): void {
    window.print();
  }

  goBack(): void {
    this.router.navigate(['/invoices']);
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}
