import { Component, OnInit, inject,ChangeDetectorRef } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs';
import { AuthService } from '../../core/services/auth.service';
import {SettingsApi,BusinessProfile,UpdateBusinessProfileRequest} from '../../core/services/settings-api';

@Component({
  selector: 'app-settings',
  templateUrl: './settings.component.html',
  styleUrls: ['./settings.component.scss'],
  imports: [FormsModule],
})
export class SettingsComponent implements OnInit {
  private readonly settingsApi = inject(SettingsApi);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly authService = inject(AuthService);

  profile: BusinessProfile | null = null;

  form: UpdateBusinessProfileRequest = {
    name: '',
    email: null,
    phone: null,
    business_type: null,
  };

  loading = false;
  saving = false;
  errorMessage = '';
  successMessage = '';

  logoPreview: string | null = null;
  selectedLogo: File | null = null;
  uploadingLogo = false;
  logoError = '';

  ngOnInit(): void {
    this.loadProfile();
  }

  loadProfile(): void {
    this.loading = true;
    this.errorMessage = '';

    this.settingsApi.getBusinessProfile()
      .pipe(finalize(() => (this.loading = false)))
      .subscribe({
        next: (response) => {
          this.profile = response.data;

          this.form = {
            name: response.data.name ?? '',
            email: response.data.email,
            phone: response.data.phone,
            business_type: response.data.business_type,
          };

          this.cdr.markForCheck();
        },
        error: (error) => {
          this.errorMessage =
            error?.error?.message ??
            'Unable to load your business profile.';

            this.cdr.markForCheck();
        },
      });
  }

  saveProfile(): void {
    if (!this.form.name.trim() || this.saving) {
      return;
    }

    this.saving = true;
    this.errorMessage = '';
    this.successMessage = '';

    const payload: UpdateBusinessProfileRequest = {
      name: this.form.name.trim(),
      email: this.form.email?.trim() || null,
      phone: this.form.phone?.trim() || null,
      business_type: this.form.business_type?.trim() || null,
    };

    this.settingsApi.updateBusinessProfile(payload)
      .pipe(finalize(() => (this.saving = false)))
      .subscribe({
        next: (response) => {
          this.profile = response.data;
          this.successMessage =
            response.message ?? 'Business profile saved successfully.';

            this.cdr.markForCheck();
        },
        error: (error) => {
          this.errorMessage =
            error?.error?.message ??
            'Unable to save your business profile.';

            this.cdr.markForCheck();
        },
      });
  }

  onLogoSelected(event: Event): void {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];

  this.logoError = '';
  this.successMessage = '';
  this.errorMessage = '';

  if (!file) {
    return;
  }

  const allowedTypes = [
    'image/jpeg',
    'image/png',
    'image/webp',
  ];

  if (!allowedTypes.includes(file.type)) {
    this.logoError = 'Choose a JPG, PNG, or WebP image.';
    input.value = '';
    return;
  }

  if (file.size > 2 * 1024 * 1024) {
    this.logoError = 'The logo must not exceed 2 MB.';
    input.value = '';
    return;
  }

  this.selectedLogo = file;

  const reader = new FileReader();

  reader.onload = () => {
    this.logoPreview = reader.result as string;
    this.cdr.markForCheck();
  };

  reader.readAsDataURL(file);

  this.cdr.detectChanges();
}

uploadLogo(): void {
    if (!this.selectedLogo || this.uploadingLogo) {
      return;
    }

    this.uploadingLogo = true;
    this.logoError = '';
    this.errorMessage = '';
    this.successMessage = '';

    this.settingsApi.uploadBusinessLogo(this.selectedLogo)
      .pipe(finalize(() => {
        this.uploadingLogo = false;
        this.cdr.markForCheck();
      }))
      .subscribe({
       next: (response) => {
          if (this.profile) {
            this.profile = {
              ...this.profile,
              logo_path: response.data.logo_path,
              logo_url: response.data.logo_url,
            };
          }

          this.selectedLogo = null;
          this.logoPreview = null;
          this.successMessage = response.message;

          //this.cdr.markForCheck();

          this.cdr.detectChanges();
        },
        error: (error) => {
          this.logoError =
            error?.error?.errors?.logo?.[0] ??
            error?.error?.message ??
            'Unable to upload the business logo.';

          this.cdr.detectChanges();
        },
      });
  }

  get canViewSettings(): boolean {
    return this.authService.hasPermission('settings.view');
  }

  get canUpdateSettings(): boolean {
    return this.authService.hasPermission('settings.update');
  }

  get canUpdateLogo(): boolean {
    return this.authService.hasPermission('settings.update_logo');
  }
}
