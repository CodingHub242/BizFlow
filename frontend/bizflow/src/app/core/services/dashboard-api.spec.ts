import { TestBed } from '@angular/core/testing';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { provideHttpClient } from '@angular/common/http';

import { DashboardApiService } from './dashboard-api';

describe('DashboardApiService', () => {
  let service: DashboardApiService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        DashboardApiService,
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    service = TestBed.inject(DashboardApiService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should retrieve the dashboard summary with date filters', () => {
    service.getDashboard('2026-09-01', '2026-09-30').subscribe((response:any) => {
      expect(response.success).toBe(true);
      expect(response.data.sales_total).toBe(24850);
      expect(response.data.product_sales).toBe(20000);
      expect(response.data.services_rendered).toBe(4850);
      expect(response.data.customer_count).toBe(342);
      expect(response.data.sales_count).toBe(186);
    });

    const request = httpMock.expectOne(
      (req) =>
        req.url === '/api/dashboard' &&
        req.params.get('date_from') === '2026-09-01' &&
        req.params.get('date_to') === '2026-09-30',
    );

    expect(request.request.method).toBe('GET');

    request.flush({
      success: true,
      data: {
        sales_total: 24850,
        product_sales: 20000,
        services_rendered: 4850,
        expense_total: 9000,
        gross_profit: 15850,
        outstanding_invoice_total: 8420,
        inventory_value: 32000,
        customer_count: 342,
        sales_count: 186,
        low_stock_count: 4,
        sales_trend: [],
        sales_by_branch: [],
        payments_by_method: [],
        low_stock_items: [],
        outstanding_invoices: [],
      },
    });
  });
});