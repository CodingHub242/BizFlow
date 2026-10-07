import { Component, OnInit,inject,ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import {FormBuilder,ReactiveFormsModule,Validators} from '@angular/forms';
import {AuthService,BusinessRegistrationRequest} from '../../../core/services/auth.service';

@Component({
  selector: 'app-register',
  templateUrl: './register.component.html',
  styleUrls: ['./register.component.scss'],
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule,RouterLink],
})
export class RegisterComponent {
 private readonly fb = inject(FormBuilder);
  private readonly authService = inject(AuthService);
  private readonly changeDetector = inject(ChangeDetectorRef);

  isSubmitting = false;
  registrationComplete = false;
  errorMessage = '';
  fieldErrors: Record<string, string[]> = {};

  readonly registerForm = this.fb.nonNullable.group({
    business_name: ['', [Validators.required, Validators.maxLength(255)]],
    business_email: ['', [Validators.required, Validators.email, Validators.maxLength(255)]],
    business_phone: ['', [Validators.required, Validators.maxLength(30)]],
    business_type: ['', [Validators.required, Validators.maxLength(100)]],

    owner_name: ['', [Validators.required, Validators.maxLength(255)]],
    owner_email: ['', [Validators.required, Validators.email, Validators.maxLength(255)]],

    password: ['', [Validators.required, Validators.minLength(8)]],
    password_confirmation: ['', [Validators.required]],
  });

  get f() {
    return this.registerForm.controls;
  }
  constructor() { }

 submit(): void {
  this.errorMessage = '';
  this.fieldErrors = {};

  if (this.registerForm.invalid) {
    this.registerForm.markAllAsTouched();
    return;
  }

  const payload: BusinessRegistrationRequest =
    this.registerForm.getRawValue();

  if (payload.password !== payload.password_confirmation) {
    this.fieldErrors = {
      password_confirmation: ['Passwords do not match.'],
    };

    this.registerForm.controls.password_confirmation.markAsTouched();
    return;
  }

  this.isSubmitting = true;

 this.authService.registerBusiness(payload).subscribe({
  next: response => {
    //console.log('🔥 COMPONENT NEXT:', response);

    this.isSubmitting = false;
    this.registrationComplete = true;

    this.changeDetector.detectChanges();
  },

  error: error => {
    console.error('🔥 COMPONENT ERROR:', error);

    this.isSubmitting = false;

    if (error.status === 422 && error.error?.errors) {
      this.fieldErrors = error.error.errors;
      return;
    }

    this.errorMessage =
      error.error?.message ??
      'Unable to complete registration. Please try again.';
  },

  complete: () => {
    console.log('🔥 COMPONENT COMPLETE');
  },
});
}

  hasFieldError(field: string): boolean {
    const control = this.registerForm.get(field);

    return !!(
      control &&
      control.invalid &&
      (control.touched || control.dirty)
    );
  }

  getFieldError(field: string): string | null {
    const errors = this.fieldErrors[field];

    if (errors?.length) {
      return errors[0];
    }

    const control = this.registerForm.get(field);

    if (!control || !control.errors) {
      return null;
    }

    if (control.errors['required']) {
      return 'This field is required.';
    }
    if (control.errors['email']) {
      return 'Please enter a valid email address.';
    }

    if (control.errors['minlength']) {
      return `Minimum ${control.errors['minlength'].requiredLength} characters.`;
    }

    if (control.errors['maxlength']) {
      return 'This field is too long.';
    }

    return null;
  }

}
