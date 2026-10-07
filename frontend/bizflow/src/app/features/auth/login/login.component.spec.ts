import { of, throwError } from 'rxjs';
import { By } from '@angular/platform-browser';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { LoginComponent } from './login.component';
import { Router,RouterLink } from '@angular/router';
import { provideRouter } from '@angular/router';
import { AuthService, LoginResponse } from '../../../core/services/auth.service';

describe('LoginComponent', () => {
  let component: LoginComponent;
  let fixture: ComponentFixture<LoginComponent>;  
  let authService: {
  login: ReturnType<typeof vi.fn>;
};

let router: Router;

beforeEach(async () => {
  authService = {
    login: vi.fn(),
  };

  await TestBed.configureTestingModule({
    imports: [LoginComponent],
    providers: [
      provideRouter([]),
      {
        provide: AuthService,
        useValue: authService,
      },
    ],
  }).compileComponents();

  router = TestBed.inject(Router);

  vi.spyOn(router, 'navigate');

  fixture = TestBed.createComponent(LoginComponent);
  component = fixture.componentInstance;

  fixture.detectChanges();
});

  it('logs in with the entered email and password', () => {
    const response: LoginResponse = {
      success: true,
      message: 'Login successful.',
      token: 'test-token',
      user: {
        id: 1,
        name: 'BizFlow Owner',
        email: 'owner@bizflow.test',
        tenant_id: 1,
        role: 'owner'
      },
    };

    authService.login.mockReturnValue(of(response));

    component.email = 'owner@bizflow.test';
    component.password = 'password';

    component.login();

    expect(authService.login).toHaveBeenCalledWith(
      'owner@bizflow.test',
      'password',
    );
  });

  it('displays an error when login fails', () => {
  authService.login.mockReturnValue(
    throwError(() => ({
      error: {
        message: 'Invalid credentials.',
      },
    })),
  );

  component.email = 'wrong@bizflow.test';
  component.password = 'wrong-password';

  component.login();

  expect(component.errorMessage).toBe('Invalid credentials.');
});
it('submits the login form with the entered credentials', () => {
  authService.login.mockReturnValue(
    of({
      success: true,
      message: 'Login successful.',
      token: 'test-token',
      user: {
        id: 1,
        name: 'BizFlow Owner',
        email: 'owner@bizflow.test',
        tenant_id: 1,
      },
    }),
  );

  const emailInput = fixture.nativeElement.querySelector(
    '#email',
  ) as HTMLInputElement;

  const passwordInput = fixture.nativeElement.querySelector(
    '#password',
  ) as HTMLInputElement;

  emailInput.value = 'owner@bizflow.test';
  passwordInput.value = 'password';

  emailInput.dispatchEvent(new Event('input'));
  passwordInput.dispatchEvent(new Event('input'));

  fixture.detectChanges();

  const form = fixture.nativeElement.querySelector('form') as HTMLFormElement;

  form.dispatchEvent(new Event('submit'));

  expect(authService.login).toHaveBeenCalledWith(
    'owner@bizflow.test',
    'password',
  );
});
it('navigates to the dashboard after successful login', () => {
  authService.login.mockReturnValue(
    of({
      success: true,
      message: 'Login successful.',
      token: 'test-token',
      user: {
        id: 1,
        name: 'BizFlow Owner',
        email: 'owner@bizflow.test',
        tenant_id: 1,
      },
    }),
  );

  component.email = 'owner@bizflow.test';
  component.password = 'password';

  component.login();

  expect(router.navigate).toHaveBeenCalledWith(['/dashboard']);
});
it('shows pending approval message when business is pending', () => {
  authService.login.mockReturnValue(
    throwError(() => ({
      status: 403,
      error: {
        code: 'TENANT_PENDING',
        message: 'Your business is awaiting platform approval.',
      },
    })),
  );

  component.email = 'pending@example.com';
  component.password = 'password';

  component.login();

  expect(component.errorMessage).toBe(
    'Your business is awaiting platform approval. You will be able to access Bizflow once your business has been approved.',
  );

  expect(router.navigate).not.toHaveBeenCalled();
});

it('shows rejected message when business is rejected', () => {
  authService.login.mockReturnValue(
    throwError(() => ({
      status: 403,
      error: {
        code: 'TENANT_REJECTED',
        message: 'Your business registration was rejected.',
      },
    })),
  );

  component.email = 'rejected@example.com';
  component.password = 'password';

  component.login();

  expect(component.errorMessage).toBe(
    'Your business registration was rejected. Please contact Bizflow support for more information.',
  );

  expect(router.navigate).not.toHaveBeenCalled();
});

it('shows suspended message when business is suspended', () => {
  authService.login.mockReturnValue(
    throwError(() => ({
      status: 403,
      error: {
        code: 'TENANT_SUSPENDED',
        message: 'Your business account has been suspended.',
      },
    })),
  );

  component.email = 'suspended@example.com';
  component.password = 'password';

  component.login();

  expect(component.errorMessage).toBe(
    'Your business account has been suspended. Please contact Bizflow support.',
  );

  expect(router.navigate).not.toHaveBeenCalled();
});

});