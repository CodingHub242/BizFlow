import { TestBed } from '@angular/core/testing';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { provideHttpClient } from '@angular/common/http';

import { CustomerApi } from './customer-api';
import { API_URL } from '../config/api.config';

describe('CustomerApi', () => {
  let service: CustomerApi;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        CustomerApi,
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    service = TestBed.inject(CustomerApi);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should fetch customers', () => {
    service.list().subscribe();

    const request = httpMock.expectOne(`${API_URL}/customers`);

    expect(request.request.method).toBe('GET');

    request.flush({
      success: true,
      data: [],
       meta: {
            current_page: 1,
            last_page: 1,
            per_page: 15,
            total: 0,
        },
    });
  });
  it('should fetch customers with pagination and search parameters', () => {
    service.list({
        page: 2,
        search: 'John',
        perPage: 25,
    }).subscribe();

  const request = httpMock.expectOne(
    `${API_URL}/customers?page=2&search=John&per_page=25`,
  );

  expect(request.request.method).toBe('GET');

  request.flush({
    success: true,
    data: [],
    meta: {
      current_page: 2,
      last_page: 2,
      per_page: 25,
      total: 25,
    },
  });
});
it('should create a customer', () => {
  const customer = {
    id: 1,
    tenant_id: 1,
    name: 'John Doe',
    email: 'john@example.com',
    phone: '0244000000',
    company_name: 'John Trading',
    address: 'Accra',
    city: 'Accra',
    country: 'Ghana',
    notes: 'Important customer',
    is_active: true,
  };

  service.create({
    name: 'John Doe',
    email: 'john@example.com',
    phone: '0244000000',
    company_name: 'John Trading',
    address: 'Accra',
    city: 'Accra',
    country: 'Ghana',
    notes: 'Important customer',
  }).subscribe(response => {
    expect(response).toEqual({
      success: true,
      message: 'Customer created successfully.',
      data: customer,
    });
  });

  const request = httpMock.expectOne(`${API_URL}/customers`);

  expect(request.request.method).toBe('POST');
  expect(request.request.body).toEqual({
    name: 'John Doe',
    email: 'john@example.com',
    phone: '0244000000',
    company_name: 'John Trading',
    address: 'Accra',
    city: 'Accra',
    country: 'Ghana',
    notes: 'Important customer',
  });

  request.flush({
    success: true,
    message: 'Customer created successfully.',
    data: customer,
  });
});
});