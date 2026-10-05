import { TestBed } from '@angular/core/testing';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideHttpClient } from '@angular/common/http';
import { AuthService } from './auth.service';

describe('AuthService', () => {
  let service: AuthService;
  let httpTesting: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        AuthService,
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    service = TestBed.inject(AuthService);
    httpTesting = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpTesting.verify();
    localStorage.clear();
  });

  it('logs in and stores the returned token', () => {
    const response = {
      success: true,
      message: 'Login successful.',
      token: 'test-token',
      user: {
        id: 1,
        name: 'BizFlow Owner',
        email: 'owner@bizflow.test',
        tenant_id: 1,
        role: 'Owner'
      },
    };

    service.login('owner@bizflow.test', 'password').subscribe(result => {
      expect(result).toEqual(response);
      expect(localStorage.getItem('bizflow_token')).toBe('test-token');
    });

    const request = httpTesting.expectOne('http://127.0.0.1:8000/api/login');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      email: 'owner@bizflow.test',
      password: 'password',
    });

    request.flush(response);
  });
  it('logs out and removes the stored token', () => {
  localStorage.setItem('bizflow_token', 'test-token');

  service.logout();

  expect(localStorage.getItem('bizflow_token')).toBeNull();
});
});