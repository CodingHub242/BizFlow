import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';

import { RegisterComponent } from './register.component';
import {
  AuthService,
  BusinessRegistrationResponse,
} from '../../../core/services/auth.service';

describe('RegisterComponent', () => {
  let component: RegisterComponent;
  let fixture: ComponentFixture<RegisterComponent>;
  let authService: {
    registerBusiness: ReturnType<typeof vi.fn>;
  };

  beforeEach(async () => {
    authService = {
      registerBusiness: vi.fn(),
    };

    await TestBed.configureTestingModule({
      imports: [RegisterComponent],
      providers: [
        provideRouter([]),
        {
          provide: AuthService,
          useValue: authService,
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(RegisterComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should start with an invalid form', () => {
    expect(component.registerForm.invalid).toBe(true);
  });

  it('should require all registration fields', () => {
    component.registerForm.markAllAsTouched();

    expect(component.registerForm.controls.business_name.hasError('required')).toBe(true);
    expect(component.registerForm.controls.business_email.hasError('required')).toBe(true);
    expect(component.registerForm.controls.business_phone.hasError('required')).toBe(true);
    expect(component.registerForm.controls.business_type.hasError('required')).toBe(true);
    expect(component.registerForm.controls.owner_name.hasError('required')).toBe(true);
    expect(component.registerForm.controls.owner_email.hasError('required')).toBe(true);
    expect(component.registerForm.controls.password.hasError('required')).toBe(true);
    expect(component.registerForm.controls.password_confirmation.hasError('required')).toBe(true);
  });

  it('should reject an invalid business email', () => {
    component.registerForm.controls.business_email.setValue('invalid-email');

    expect(
      component.registerForm.controls.business_email.hasError('email')
    ).toBe(true);
  });

  it('should reject passwords shorter than 8 characters', () => {
    component.registerForm.controls.password.setValue('1234567');

    expect(
      component.registerForm.controls.password.hasError('minlength')
    ).toBe(true);
  });

  it('should reject mismatched passwords', () => {
    component.registerForm.setValue({
      business_name: 'Test Business',
      business_email: 'business@example.com',
      business_phone: '0240000000',
      business_type: 'Retail',
      owner_name: 'John Doe',
      owner_email: 'john@example.com',
      password: 'password123',
      password_confirmation: 'different123',
    });

    component.submit();

    expect(authService.registerBusiness).not.toHaveBeenCalled();
    expect(
      component.fieldErrors['password_confirmation']
    ).toEqual(['Passwords do not match.']);
  });

  it('should submit a valid registration', () => {
    const response: BusinessRegistrationResponse = {
      message: 'Business registration submitted successfully.',
      data: {
        tenant: {
          id: 1,
          name: 'Test Business',
          status: 'pending',
        },
        owner: {
          id: 1,
          name: 'John Doe',
          email: 'john@example.com',
        },
      },
    };

    authService.registerBusiness.mockReturnValue(of(response));

    component.registerForm.setValue({
      business_name: 'Test Business',
      business_email: 'business@example.com',
      business_phone: '0240000000',
      business_type: 'Retail',
      owner_name: 'John Doe',
      owner_email: 'john@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    component.submit();

    expect(authService.registerBusiness).toHaveBeenCalledWith({
      business_name: 'Test Business',
      business_email: 'business@example.com',
      business_phone: '0240000000',
      business_type: 'Retail',
      owner_name: 'John Doe',
      owner_email: 'john@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    expect(component.registrationComplete).toBe(true);
    expect(component.isSubmitting).toBe(false);
  });

  it('should handle Laravel validation errors', () => {
    authService.registerBusiness.mockReturnValue(
      throwError(() => ({
        status: 422,
        error: {
          message: 'The given data was invalid.',
          errors: {
            business_email: ['The business email has already been taken.'],
            owner_email: ['The owner email has already been taken.'],
          },
        },
      }))
    );

    component.registerForm.setValue({
      business_name: 'Test Business',
      business_email: 'business@example.com',
      business_phone: '0240000000',
      business_type: 'Retail',
      owner_name: 'John Doe',
      owner_email: 'john@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    component.submit();

    expect(component.isSubmitting).toBe(false);
    expect(component.registrationComplete).toBe(false);

    expect(component.fieldErrors['business_email']).toEqual([
      'The business email has already been taken.',
    ]);

    expect(component.fieldErrors['owner_email']).toEqual([
      'The owner email has already been taken.',
    ]);

    expect(component.errorMessage).toBe('');
  });

  it('should handle a generic server error', () => {
    authService.registerBusiness.mockReturnValue(
      throwError(() => ({
        status: 500,
        error: {
          message: 'Server error.',
        },
      }))
    );

    component.registerForm.setValue({
      business_name: 'Test Business',
      business_email: 'business@example.com',
      business_phone: '0240000000',
      business_type: 'Retail',
      owner_name: 'John Doe',
      owner_email: 'john@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    component.submit();

    expect(component.isSubmitting).toBe(false);
    expect(component.registrationComplete).toBe(false);
    expect(component.errorMessage).toBe('Server error.');
  });

  it('should not submit an invalid form', () => {
    component.submit();

    expect(authService.registerBusiness).not.toHaveBeenCalled();
    expect(component.isSubmitting).toBe(false);
  });
});