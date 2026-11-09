import { CommonModule } from '@angular/common';
import { Component, OnInit, inject,ChangeDetectorRef } from '@angular/core';
import { finalize } from 'rxjs';
import {
  EngageService,
  EngageWhatsAppAccount,
  EngageWhatsAppNumber,
} from '../../../../core/services/engage.service';
import { FacebookSdkService } from '../../../../core/services/facebook-sdk.service';
import { environment } from '../../../../../environments/environment';
import {WhatsAppEmbeddedSignupService,WhatsAppSignupMode,WhatsAppSignupResult} from '../../../../core/services/whatsapp-embedded-signup.service';

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
  private readonly facebookSdk = inject(FacebookSdkService);

  private readonly embeddedSignup = inject(WhatsAppEmbeddedSignupService);

  facebookSdkReady = false;
  connectingMode: WhatsAppSignupMode | null = null;

  pendingSignupResult: WhatsAppSignupResult | null = null;

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
     this.initializeFacebookSdk();//initialize the Facebook SDK when the component is initialized
  }

  private async initializeFacebookSdk(): Promise<void> {
    try {
      await this.embeddedSignup.initialize();

      this.facebookSdkReady = true;
      this.cdr.detectChanges();

      console.log('WhatsApp Embedded Signup is ready.');
    } catch (error) {
      console.error('WhatsApp SDK initialization failed:', error);

      this.errorMessage =
        'Unable to initialize WhatsApp connection. Please refresh and try again.';

      this.cdr.detectChanges();
    }
  }

  async connectWhatsApp(mode: WhatsAppSignupMode): Promise<void> {
    if (!this.facebookSdkReady || this.connectingMode) {
      return;
    }

    this.clearMessages();
    this.pendingSignupResult = null;
    this.connectingMode = mode;

    try {
      const result = await this.embeddedSignup.launch(mode);

      this.pendingSignupResult = result;

      this.successMessage =
        'Meta onboarding completed. WhatsApp account details received.';

      console.log('WhatsApp signup completed:', {
        wabaId: result.wabaId,
        phoneNumberId: result.phoneNumberId,
        businessId: result.businessId,
      });

      this.cdr.detectChanges();

    } catch (error) {
      console.error('WhatsApp signup failed:', error);

      this.errorMessage =
        error instanceof Error
          ? error.message
          : 'WhatsApp onboarding failed. Please try again.';

    } finally {
      this.connectingMode = null;
      this.cdr.detectChanges();
    }
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
        this.loading = false;
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

//   async testFacebookSdk(): Promise<void> {
//   try {
//     const fb = await this.facebookSdk.getFacebookSdk();

//     console.log('Facebook SDK loaded successfully:', fb);
//     console.log('Meta App ID:', environment.meta.appId);

//   } catch (error) {
//     console.error('Facebook SDK loading failed:', error);
//   }
// }
}
