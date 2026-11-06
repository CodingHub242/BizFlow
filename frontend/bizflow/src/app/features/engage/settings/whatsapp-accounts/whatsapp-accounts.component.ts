import { CommonModule } from '@angular/common';
import { Component, OnInit, inject,ChangeDetectorRef } from '@angular/core';
import { finalize } from 'rxjs';
import {
  EngageService,
  EngageWhatsAppAccount,
  EngageWhatsAppNumber,
} from '../../../../core/services/engage.service';

@Component({
  selector: 'app-whatsapp-accounts',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './whatsapp-accounts.component.html',
  styleUrl: './whatsapp-accounts.component.scss',
})
export class WhatsappAccountsComponent implements OnInit {
  private readonly engageService = inject(EngageService);
  private readonly cdr = inject(ChangeDetectorRef);

  accounts: EngageWhatsAppAccount[] = [];

  loading = false;
  errorMessage = '';
  successMessage = '';

  currentPage = 1;
  lastPage = 1;
  totalAccounts = 0;

  busyAction = '';

  ngOnInit(): void {
    this.loadAccounts();
  }

  loadAccounts(page = this.currentPage): void {
  this.loading = true;
  this.errorMessage = '';
  this.successMessage = '';

  this.engageService
    .getWhatsAppAccounts(page, 20)
    .pipe(finalize(() => (this.loading = false)))
    .subscribe({
      next: (response) => {
        this.loading = false;
        const pagination = response?.data;

        this.accounts = pagination?.data ?? [];
        this.currentPage = pagination.current_page;
        this.lastPage = pagination.last_page;
        this.totalAccounts = pagination.total;

        this.cdr.detectChanges();
      },
      error: (error) => {
        this.errorMessage =
          error?.error?.message ??
          'Unable to load WhatsApp accounts. Please try again.';

        this.cdr.detectChanges();
      },
    });
}

  setDefaultNumber(number: EngageWhatsAppNumber): void {
    if (number.is_default || number.status !== 'active') {
      return;
    }

    this.busyAction = `default-${number.id}`;
    this.clearMessages();

    this.engageService
      .setDefaultWhatsAppNumber(number.id)
      .pipe(finalize(() => (this.busyAction = '')))
      .subscribe({
        next: () => {
          this.successMessage = 'Default WhatsApp number updated.';
          this.loadAccounts(this.currentPage);

          this.cdr.detectChanges();
        },
        error: (error) => {
          this.errorMessage =
            error?.error?.message ??
            'Unable to set the default WhatsApp number.';

          this.cdr.detectChanges();
        },
      });
  }

  toggleAccountStatus(account: EngageWhatsAppAccount): void {
    const newStatus =
      account.status === 'active' ? 'inactive' : 'active';

    this.busyAction = `account-${account.id}`;
    this.clearMessages();

    this.engageService
      .updateWhatsAppAccountStatus(account.id, newStatus)
      .pipe(finalize(() => (this.busyAction = '')))
      .subscribe({
        next: () => {
          this.successMessage = `WhatsApp account ${newStatus === 'active' ? 'activated' : 'deactivated'} successfully.`;
          this.loadAccounts(this.currentPage);

          this.cdr.detectChanges();
        },
        error: (error) => {
          this.errorMessage =
            error?.error?.message ??
            'Unable to update the WhatsApp account status.';

          this.cdr.detectChanges();
        },
      });
  }

  toggleNumberStatus(number: EngageWhatsAppNumber): void {
    const newStatus =
      number.status === 'active' ? 'inactive' : 'active';

    this.busyAction = `number-${number.id}`;
    this.clearMessages();

    this.engageService
      .updateWhatsAppNumberStatus(number.id, newStatus)
      .pipe(finalize(() => (this.busyAction = '')))
      .subscribe({
        next: () => {
          this.successMessage = `WhatsApp number ${newStatus === 'active' ? 'activated' : 'deactivated'} successfully.`;
          this.loadAccounts(this.currentPage);

          this.cdr.detectChanges();
        },
        error: (error) => {
          this.errorMessage =
            error?.error?.message ??
            'Unable to update the WhatsApp number status.';

          this.cdr.detectChanges();
        },
      });
  }

  previousPage(): void {
    if (this.currentPage > 1) {
      this.loadAccounts(this.currentPage - 1);
    }
  }

  nextPage(): void {
    if (this.currentPage < this.lastPage) {
      this.loadAccounts(this.currentPage + 1);
    }
  }

  trackAccount(
    _index: number,
    account: EngageWhatsAppAccount
  ): number {
    return account.id;
  }

  trackNumber(
    _index: number,
    number: EngageWhatsAppNumber
  ): number {
    return number.id;
  }

  getNumberCount(account: EngageWhatsAppAccount): number {
    return account.numbers?.length ?? 0;
  }

  private clearMessages(): void {
    this.errorMessage = '';
    this.successMessage = '';
  }
}
