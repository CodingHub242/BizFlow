import {ChangeDetectorRef,Component,DestroyRef,OnInit,inject,signal} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { AuthService, AuthUser } from '../../core/services/auth.service';

type PasswordResetStep = 'request' | 'verify' | 'reset';

@Component({
  selector: 'app-profile',
  standalone: true,
  templateUrl: './profile.component.html',
  styleUrls: ['./profile.component.scss'],
  imports: [CommonModule, FormsModule]
})
export class ProfileComponent implements OnInit {
  private readonly authService = inject(AuthService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly cdr = inject(ChangeDetectorRef);

  readonly user = signal<AuthUser | null>(null);

  form = {
    name: ''
  };

  otp = '';
  newPassword = '';
  confirmPassword = '';
  resetToken = '';

  resetStep: PasswordResetStep = 'request';

  loading = true;
  saving = false;
  requestingOtp = false;
  verifyingOtp = false;
  resettingPassword = false;

  errorMessage = '';
  successMessage = '';
  passwordError = '';

  ngOnInit(): void {
    this.authService.currentUser$
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(user => {
        this.user.set(user);

        if (user) {
          this.form = { name: user.name ?? '' };
          this.loading = false;
          this.cdr.markForCheck();
        } else if (this.authService.getToken()) {
            this.loadCurrentUser();
          } else {
            this.loading = false;
            this.cdr.markForCheck();
          }
      });
  }

  private loadCurrentUser(): void {
    this.loading = true;

    this.authService.getCurrentUser()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.loading = false;
          this.cdr.markForCheck();
        })
      )
      .subscribe({
        next: user => {
          this.user.set(user);
          this.form = { name: user.name ?? '' };
        },
        error: error => {
          this.errorMessage =
            error?.error?.message ?? 'Unable to load your profile.';
        }
      });
  }

  saveProfile(): void {
    if (this.saving) return;

    const name = this.form.name.trim();

    if (!name) {
      this.errorMessage = 'Please enter your name.';
      return;
    }

    this.saving = true;
    this.errorMessage = '';
    this.successMessage = '';

    this.authService.updateProfile({ name })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.saving = false;
          this.cdr.markForCheck();
        })
      )
      .subscribe({
        next: response => {
          this.form.name = response.data.name;
          this.successMessage = response.message;
        },
        error: error => {
          this.errorMessage = this.getErrorMessage(
            error,
            'Unable to update your profile.'
          );
        }
      });
  }

  requestOtp(): void {
    if (this.requestingOtp) return;

    this.requestingOtp = true;
    this.errorMessage = '';
    this.successMessage = '';

    this.authService.requestPasswordResetOtp()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.requestingOtp = false;
          this.cdr.markForCheck();
        })
      )
      .subscribe({
        next: response => {
          this.resetStep = 'verify';
          this.otp = '';
          this.resetToken = '';
          this.successMessage = response.message;
        },
        error: error => {
          this.errorMessage = this.getErrorMessage(
            error,
            'Unable to send the verification code.'
          );
        }
      });
  }

  verifyOtp(): void {
    if (this.verifyingOtp) return;

    const otp = this.otp.trim();

    if (!/^\d{6}$/.test(otp)) {
      this.errorMessage = 'Enter the six-digit verification code.';
      return;
    }

    this.verifyingOtp = true;
    this.errorMessage = '';
    this.successMessage = '';

    this.authService.verifyPasswordResetOtp(otp)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.verifyingOtp = false;
          this.cdr.markForCheck();
        })
      )
      .subscribe({
        next: response => {
          this.resetToken = response.reset_token;
          this.resetStep = 'reset';
          this.successMessage = response.message;
        },
        error: error => {
          this.errorMessage = this.getErrorMessage(
            error,
            'The verification code could not be verified.'
          );
        }
      });
  }

  submitNewPassword(): void {
    if (this.resettingPassword) return;

    this.errorMessage = '';
    this.successMessage = '';
    this.passwordError = '';

    if (this.newPassword.length < 12) {
      this.passwordError =
        'Your password must contain at least 12 characters.';
      return;
    }

    if (this.newPassword !== this.confirmPassword) {
      this.passwordError = 'The passwords do not match.';
      return;
    }

    if (!this.resetToken) {
      this.errorMessage =
        'Your reset authorization is missing. Request a new code.';
      return;
    }

    this.resettingPassword = true;

    this.authService.resetPassword(
      this.resetToken,
      this.newPassword,
      this.confirmPassword
    )
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.resettingPassword = false;
          this.cdr.markForCheck();
        })
      )
      .subscribe({
        next: response => {
          this.successMessage = response.message;

          // The backend revokes all Sanctum tokens after a reset.
          this.authService.logout();

          this.otp = '';
          this.resetToken = '';
          this.newPassword = '';
          this.confirmPassword = '';
          this.resetStep = 'request';
        },
        error: error => {
          this.errorMessage = this.getErrorMessage(
            error,
            'Unable to reset your password.'
          );
        }
      });
  }

  startPasswordResetAgain(): void {
    this.resetStep = 'request';
    this.otp = '';
    this.resetToken = '';
    this.newPassword = '';
    this.confirmPassword = '';
    this.errorMessage = '';
    this.passwordError = '';
    this.successMessage = '';
  }

  private getErrorMessage(error: any, fallback: string): string {
    const validationErrors = error?.error?.errors;

    if (validationErrors) {
      return Object.values(validationErrors)
        .flat()
        .join(' ');
    }

    return error?.error?.message ?? fallback;
  }
}
