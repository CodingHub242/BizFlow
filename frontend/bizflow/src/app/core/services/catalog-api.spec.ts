import { TestBed } from '@angular/core/testing';

import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import { provideHttpClient } from '@angular/common/http';

import {
  CatalogApi,
  CatalogItem,
} from './catalog-api';

import { API_URL } from '../config/api.config';

describe('CatalogApi', () => {
  let service: CatalogApi;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        CatalogApi,
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    service = TestBed.inject(CatalogApi);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should fetch catalog items', () => {
    service.list().subscribe();

    const request = httpMock.expectOne(
      `${API_URL}/catalog-items`,
    );

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

  it('should fetch catalog items with pagination and search parameters', () => {
    service.list({
      page: 2,
      search: 'Laptop',
      perPage: 25,
    }).subscribe();

    const request = httpMock.expectOne(
      `${API_URL}/catalog-items?page=2&search=Laptop&per_page=25`,
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

  it('should fetch catalog items filtered by type', () => {
    service.list({
      type: 'product',
    }).subscribe();

    const request = httpMock.expectOne(
      `${API_URL}/catalog-items?type=product`,
    );

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

  it('should fetch catalog items filtered by active status', () => {
    service.list({
      isActive: true,
    }).subscribe();

    const request = httpMock.expectOne(
      `${API_URL}/catalog-items?is_active=true`,
    );

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

  it('should fetch catalog items with all supported filters', () => {
    service.list({
      page: 2,
      search: 'Laptop',
      type: 'product',
      isActive: true,
      perPage: 25,
    }).subscribe();

    const request = httpMock.expectOne(
      `${API_URL}/catalog-items?page=2&search=Laptop&type=product&is_active=true&per_page=25`,
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

  it('should create a product', () => {
    const product: CatalogItem = {
      id: 1,
      name: 'Laptop',
      type: 'product',
      sku: 'LAP-001',
      description: 'Business laptop',
      unit: 'piece',
      cost_price: '3000.00',
      selling_price: '4000.00',
      tax_rate: '15.00',
      track_inventory: true,
      is_active: true,
      created_at: '2026-10-02T10:00:00.000000Z',
      updated_at: '2026-10-02T10:00:00.000000Z',
    };

    service.create({
      name: 'Laptop',
      type: 'product',
      sku: 'LAP-001',
      description: 'Business laptop',
      unit: 'piece',
      cost_price: 3000,
      selling_price: 4000,
      tax_rate: 15,
      track_inventory: true,
      is_active: true,
    }).subscribe(response => {
      expect(response).toEqual({
        success: true,
        message: 'Catalog item created successfully.',
        data: product,
      });
    });

    const request = httpMock.expectOne(
      `${API_URL}/catalog-items`,
    );

    expect(request.request.method).toBe('POST');

    expect(request.request.body).toEqual({
      name: 'Laptop',
      type: 'product',
      sku: 'LAP-001',
      description: 'Business laptop',
      unit: 'piece',
      cost_price: 3000,
      selling_price: 4000,
      tax_rate: 15,
      track_inventory: true,
      is_active: true,
    });

    request.flush({
      success: true,
      message: 'Catalog item created successfully.',
      data: product,
    });
  });

  it('should create a service', () => {
    const serviceItem: CatalogItem = {
      id: 2,
      name: 'Interior Design',
      type: 'service',
      sku: null,
      description: 'Interior design service',
      unit: 'project',
      cost_price: '500.00',
      selling_price: '1500.00',
      tax_rate: '15.00',
      track_inventory: false,
      is_active: true,
    };

    service.create({
      name: 'Interior Design',
      type: 'service',
      sku: null,
      description: 'Interior design service',
      unit: 'project',
      cost_price: 500,
      selling_price: 1500,
      tax_rate: 15,
      track_inventory: false,
      is_active: true,
    }).subscribe(response => {
      expect(response.data).toEqual(serviceItem);
    });

    const request = httpMock.expectOne(
      `${API_URL}/catalog-items`,
    );

    expect(request.request.method).toBe('POST');

    expect(request.request.body).toEqual({
      name: 'Interior Design',
      type: 'service',
      sku: null,
      description: 'Interior design service',
      unit: 'project',
      cost_price: 500,
      selling_price: 1500,
      tax_rate: 15,
      track_inventory: false,
      is_active: true,
    });

    request.flush({
      success: true,
      message: 'Catalog item created successfully.',
      data: serviceItem,
    });
  });

  it('should fetch a catalog item by id', () => {
    const item: CatalogItem = {
      id: 1,
      name: 'Laptop',
      type: 'product',
      sku: 'LAP-001',
      description: 'Business laptop',
      unit: 'piece',
      cost_price: '3000.00',
      selling_price: '4000.00',
      tax_rate: '15.00',
      track_inventory: true,
      is_active: true,
    };

    service.get(1).subscribe(response => {
      expect(response.data).toEqual(item);
    });

    const request = httpMock.expectOne(
      `${API_URL}/catalog-items/1`,
    );

    expect(request.request.method).toBe('GET');

    request.flush({
      success: true,
      data: item,
    });
  });

  it('should update a catalog item', () => {
    const item: CatalogItem = {
      id: 1,
      name: 'Updated Laptop',
      type: 'product',
      sku: 'LAP-001',
      description: 'Updated description',
      unit: 'piece',
      cost_price: '3000.00',
      selling_price: '4500.00',
      tax_rate: '15.00',
      track_inventory: true,
      is_active: true,
    };

    service.update(1, {
      name: 'Updated Laptop',
      selling_price: 4500,
      description: 'Updated description',
    }).subscribe(response => {
      expect(response.data).toEqual(item);
    });

    const request = httpMock.expectOne(
      `${API_URL}/catalog-items/1`,
    );

    expect(request.request.method).toBe('PUT');

    expect(request.request.body).toEqual({
      name: 'Updated Laptop',
      selling_price: 4500,
      description: 'Updated description',
    });

    request.flush({
      success: true,
      message: 'Catalog item updated successfully.',
      data: item,
    });
  });

  it('should delete a catalog item', () => {
    service.delete(1).subscribe(response => {
      expect(response).toEqual({
        success: true,
        message: 'Catalog item deleted successfully.',
      });
    });

    const request = httpMock.expectOne(
      `${API_URL}/catalog-items/1`,
    );

    expect(request.request.method).toBe('DELETE');

    request.flush({
      success: true,
      message: 'Catalog item deleted successfully.',
    });
  });

  it('should restore a deleted catalog item', () => {
    const restoredItem: CatalogItem = {
      id: 1,
      name: 'Laptop',
      type: 'product',
      sku: 'LAP-001',
      description: 'Business laptop',
      unit: 'piece',
      cost_price: '3000.00',
      selling_price: '4000.00',
      tax_rate: '15.00',
      track_inventory: true,
      is_active: true,
    };

    service.restore(1).subscribe(response => {
      expect(response.data).toEqual(restoredItem);
    });

    const request = httpMock.expectOne(
      `${API_URL}/catalog-items/1/restore`,
    );

    expect(request.request.method).toBe('POST');

    expect(request.request.body).toEqual({});

    request.flush({
      success: true,
      message: 'Catalog item restored successfully.',
      data: restoredItem,
    });
  });
});

