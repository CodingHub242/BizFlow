import { TestBed } from '@angular/core/testing';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { provideHttpClient } from '@angular/common/http';

import {
  BranchApi,
} from './branch-api';

import { API_URL } from '../config/api.config';

describe('BranchApi', () => {
  let service: BranchApi;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        BranchApi,
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    service = TestBed.inject(BranchApi);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  it('should list branches', () => {
    service.list().subscribe();

    const req = httpMock.expectOne(
      `${API_URL}/branches`,
    );

    expect(req.request.method).toBe('GET');

    req.flush({
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

  it('should list branches with filters', () => {
    service.list({
      page: 2,
      search: 'Accra',
      isActive: true,
      perPage: 25,
    }).subscribe();

    const req = httpMock.expectOne(
      `${API_URL}/branches?page=2&search=Accra&is_active=true&per_page=25`,
    );

    expect(req.request.method).toBe('GET');

    expect(req.request.params.get('page')).toBe('2');
    expect(req.request.params.get('search')).toBe('Accra');
    expect(req.request.params.get('is_active')).toBe('true');
    expect(req.request.params.get('per_page')).toBe('25');

    req.flush({
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

  it('should get a branch by id', () => {
    service.get(5).subscribe();

    const req = httpMock.expectOne(
      `${API_URL}/branches/5`,
    );

    expect(req.request.method).toBe('GET');

    req.flush({
      success: true,
      message: 'Branch retrieved successfully.',
      data: {
        id: 5,
        name: 'Accra Main',
        code: 'ACC-01',
        address: 'Accra',
        phone: '0240000000',
        email: 'accra@example.com',
        is_active: true,
      },
    });
  });

  it('should return branch data from the list response', () => {
    const branches = [
      {
        id: 1,
        name: 'Accra Main',
        code: 'ACC-01',
        is_active: true,
      },
      {
        id: 2,
        name: 'Kumasi Branch',
        code: 'KMS-01',
        is_active: true,
      },
    ];

    service.list().subscribe((response) => {
      expect(response.success).toBe(true);
      expect(response.data).toEqual(branches);
      expect(response.data.length).toBe(2);
    });

    const req = httpMock.expectOne(
      `${API_URL}/branches`,
    );

    req.flush({
      success: true,
      data: branches,
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: 2,
      },
    });
  });
});