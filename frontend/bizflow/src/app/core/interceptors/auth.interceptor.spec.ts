import { TestBed } from '@angular/core/testing';
import {HttpClient,provideHttpClient,withInterceptors,} from '@angular/common/http';
import {HttpTestingController,provideHttpClientTesting,} from '@angular/common/http/testing';
import { authInterceptor } from './auth.interceptor';

describe('authInterceptor', () => {
  let http: HttpClient;
  let httpTesting: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
      ],
    });

    http = TestBed.inject(HttpClient);
    httpTesting = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpTesting.verify();
    localStorage.clear();
  });

  it('adds the bearer token to authenticated requests', () => {
    localStorage.setItem('bizflow_token', 'test-token');

    http.get('/api/dashboard').subscribe();

    const request = httpTesting.expectOne('/api/dashboard');

    expect(request.request.headers.get('Authorization'))
      .toBe('Bearer test-token');

    expect(request.request.headers.get('Accept'))
      .toBe('application/json');

    request.flush({});
  });
});