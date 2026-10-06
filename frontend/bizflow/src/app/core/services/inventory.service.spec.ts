import { TestBed } from '@angular/core/testing';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { provideHttpClient } from '@angular/common/http';

import { InventoryApi, ReceiveStockRequest } from './inventory-api.service';
import { API_URL } from '../config/api.config';

describe('InventoryApi', () => {
  let service: InventoryApi;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        InventoryApi,
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    service = TestBed.inject(InventoryApi);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  it('should list inventory', () => {
    service.list().subscribe();

    const req = httpMock.expectOne(
      `${API_URL}/inventory`,
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

  it('should list inventory with filters', () => {
    service.list({
      page: 2,
      branchId: 5,
      catalogItemId: 10,
      perPage: 25,
    }).subscribe();

    const req = httpMock.expectOne(
      `${API_URL}/inventory?page=2&branch_id=5&catalog_item_id=10&per_page=25`,
    );

    expect(req.request.method).toBe('GET');

    expect(req.request.params.get('page')).toBe('2');
    expect(req.request.params.get('branch_id')).toBe('5');
    expect(req.request.params.get('catalog_item_id')).toBe('10');
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

  it('should get inventory details', () => {
    service.get(15).subscribe();

    const req = httpMock.expectOne(
      `${API_URL}/inventory/15`,
    );

    expect(req.request.method).toBe('GET');

    req.flush({
      success: true,
      message: 'Inventory retrieved successfully.',
      data: {
        id: 15,
        branch_id: 1,
        catalog_item_id: 2,
        quantity: '25.000',
        reorder_level: '10.000',
      },
    });
  });

  it('should list inventory movements', () => {
    service.movements().subscribe();

    const req = httpMock.expectOne(
      `${API_URL}/inventory/movements`,
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

  it('should list inventory movements with filters', () => {
    service.movements({
      page: 2,
      branchId: 3,
      catalogItemId: 7,
      type: 'SALE',
      perPage: 20,
    }).subscribe();

    const req = httpMock.expectOne(
      `${API_URL}/inventory/movements?page=2&branch_id=3&catalog_item_id=7&type=SALE&per_page=20`,
    );

    expect(req.request.method).toBe('GET');

    expect(req.request.params.get('page')).toBe('2');
    expect(req.request.params.get('branch_id')).toBe('3');
    expect(req.request.params.get('catalog_item_id')).toBe('7');
    expect(req.request.params.get('type')).toBe('SALE');
    expect(req.request.params.get('per_page')).toBe('20');

    req.flush({
      success: true,
      data: [],
      meta: {
        current_page: 2,
        last_page: 2,
        per_page: 20,
        total: 20,
      },
    });
  });

  it('should check inventory availability', () => {
    service.availability(3, 7, 5).subscribe();

    const req = httpMock.expectOne(
      `${API_URL}/inventory/availability?branch_id=3&catalog_item_id=7&quantity=5`,
    );

    expect(req.request.method).toBe('GET');

    expect(req.request.params.get('branch_id')).toBe('3');
    expect(req.request.params.get('catalog_item_id')).toBe('7');
    expect(req.request.params.get('quantity')).toBe('5');

    req.flush({
      success: true,
      data: {
        catalog_item_id: 7,
        branch_id: 3,
        requested_quantity: 5,
        available_quantity: 20,
        shortfall_quantity: 0,
        can_fulfill: true,
        tracks_inventory: true,
      },
    });
  });

  it('should receive stock', () => {
    const payload = {
      branch_id: 3,
      catalog_item_id: 7,
      quantity: 10,
      reference_type: 'purchase',
      reference_id: 100,
      notes: 'Initial stock',
    };

    service.receive(payload).subscribe();

    const req = httpMock.expectOne(
      `${API_URL}/inventory/receive`,
    );

    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual(payload);

    req.flush({
      success: true,
      message: 'Stock received successfully.',
      data: {
        id: 15,
        branch_id: 3,
        catalog_item_id: 7,
        quantity: '30.000',
        reorder_level: '10.000',
      },
    });
  });

  it('should adjust stock', () => {
    const payload = {
      branch_id: 3,
      catalog_item_id: 7,
      quantity: -5,
      notes: 'Damaged stock',
    };

    service.adjust(payload).subscribe();

    const req = httpMock.expectOne(
      `${API_URL}/inventory/adjust`,
    );

    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual(payload);

    req.flush({
      success: true,
      message: 'Stock adjusted successfully.',
      data: {
        id: 15,
        branch_id: 3,
        catalog_item_id: 7,
        quantity: '25.000',
        reorder_level: '10.000',
      },
    });
  });

  it('should transfer stock', () => {
    const payload = {
      catalog_item_id: 7,
      source_branch_id: 3,
      destination_branch_id: 4,
      quantity: 8,
      notes: 'Transfer to new branch',
    };

    service.transfer(payload).subscribe();

    const req = httpMock.expectOne(
      `${API_URL}/inventory/transfer`,
    );

    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual(payload);

    req.flush({
      success: true,
      message: 'Stock transferred successfully.',
      data: {
        id: 20,
        branch_id: 4,
        catalog_item_id: 7,
        quantity: '18.000',
        reorder_level: '10.000',
      },
    });
  });
  it('should list inventory with search parameters', () => {
  service.list({
    page: 1,
    search: 'engine',
    branchId: 2,
    perPage: 15,
  }).subscribe();

  const req = httpMock.expectOne(
    (request) =>
      request.url === `${API_URL}/inventory` &&
      request.params.get('page') === '1' &&
      request.params.get('search') === 'engine' &&
      request.params.get('branch_id') === '2' &&
      request.params.get('per_page') === '15',
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
it('should receive stock with reference and notes', () => {
  const payload: ReceiveStockRequest = {
    branch_id: 1,
    catalog_item_id: 5,
    quantity: 10,
    reference_type: 'purchase',
    reference_id: 123,
    notes: 'Received from supplier',
  };

  service.receive(payload).subscribe();

  const req = httpMock.expectOne(
    `${API_URL}/inventory/receive`,
  );

  expect(req.request.method).toBe('POST');
  expect(req.request.body).toEqual(payload);

  req.flush({
    success: true,
    message: 'Stock received successfully.',
    data: {
      id: 1,
      branch_id: 1,
      catalog_item_id: 5,
      quantity: 10,
      reorder_level: 5,
    },
  });
});
});
