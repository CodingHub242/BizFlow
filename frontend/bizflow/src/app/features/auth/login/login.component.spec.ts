import { of, throwError } from 'rxjs';
import { By } from '@angular/platform-browser';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { LoginComponent } from './login.component';
import { Router } from '@angular/router';
import { AuthService, LoginResponse } from '../../../core/services/auth.service';

describe('LoginComponent', () => {
  let component: LoginComponent;
  let fixture: ComponentFixture<LoginComponent>;  
  let authService: {
    login: ReturnType<typeof vi.fn>;
  };
  let router: {
    navigate: ReturnType<typeof vi.fn>;
  };

  beforeEach(async () => {
    authService = {
      login: vi.fn(),
    };
    router = {
        navigate: vi.fn(),
      };

    await TestBed.configureTestingModule({
      imports: [LoginComponent],
      providers: [
        {
          provide: AuthService,
          useValue: authService,
        },
        {
          provide: Router,
          useValue: router,
        },
      ],
    }).compileComponents();

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
});