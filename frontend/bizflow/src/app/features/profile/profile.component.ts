import {ChangeDetectorRef,Component,DestroyRef,OnInit,inject,signal} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import { AuthService, AuthUser } from '../../core/services/auth.service';

@Component({
  selector: 'app-profile',
  standalone: true,
  templateUrl: './profile.component.html',
  styleUrls: ['./profile.component.scss'],
  imports: [CommonModule, FormsModule],
})
export class ProfileComponent implements OnInit {
  private readonly authService = inject(AuthService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly cdr = inject(ChangeDetectorRef);

  readonly user = signal<AuthUser | null>(null);

  form = {
    name: '',
    email: '',
  };

  loading = true;
  saving = false;
  errorMessage = '';
  successMessage = '';

  ngOnInit(): void {
    this.authService.currentUser$
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(user => {
        this.user.set(user);

        if (user) {
          this.form = {
            name: user.name ?? '',
            email: user.email ?? '',
          };
          this.loading = false;
          this.cdr.markForCheck();
        } else {
          this.loadCurrentUser();
        }
      });
  }

  private loadCurrentUser(): void {
    this.loading = true;

    this.authService
      .getCurrentUser()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.loading = false;
          this.cdr.markForCheck();
        }),
      )
      .subscribe({
        next: user => {
          this.user.set(user);
          this.form = {
            name: user.name ?? '',
            email: user.email ?? '',
          };
        },
        error: error => {
          this.errorMessage =
            error?.error?.message ?? 'Unable to load your profile.';
        },
      });
  }

  saveProfile(): void {
    if (this.saving) return;

    const name = this.form.name.trim();
    const email = this.form.email.trim();

    if (!name || !email) {
      this.errorMessage = 'Name and email are required.';
      return;
    }

    this.saving = true;
    this.errorMessage = '';
    this.successMessage = '';

    this.authService
      .updateProfile({ name, email })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.saving = false;
          this.cdr.markForCheck();
        }),
      )
      .subscribe({
        next: response => {
          this.successMessage = response.message;
          this.form = {
            name: response.data.name,
            email: response.data.email,
          };
          this.cdr.markForCheck();
        },
        error: error => {
          const validationErrors = error?.error?.errors;

          this.errorMessage = validationErrors
            ? Object.values(validationErrors).flat().join(' ')
            : error?.error?.message ?? 'Unable to update your profile.';

          this.cdr.markForCheck();
        },
      });
  }
}
