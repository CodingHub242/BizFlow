import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { DashboardComponent } from './dashboard.component';
import {DashboardApiService,DashboardResponse,DashboardData} from '../../core/services/dashboard-api';

describe('DashboardComponent', () => {
  let component: DashboardComponent;
  let fixture: ComponentFixture<DashboardComponent>;

  const dashboardResponse: DashboardResponse = {
    success: true,
    data: {
      sales_total: 31500,
      product_sales: 27000,
      services_rendered: 4500,
      expense_total: 9000,
      gross_profit: 15850,
      outstanding_invoice_total: 12350,
      inventory_value: 32000,
      customer_count: 517,
      sales_count: 243,
      low_stock_count: 4,
     sales_trend: [
        {
          date: '2026-09-01',
          sales: 10000,
        },
        {
          date: '2026-09-05',
          sales: 18000,
        },
        {
          date: '2026-09-10',
          sales: 12500,
        },
        {
          date: '2026-09-15',
          sales: 24000,
        },
        {
          date: '2026-09-20',
          sales: 31500,
        },
      ],
      sales_by_branch: [],
      payments_by_method: [],
      low_stock_items: [
        {
          id: 99,
          name: 'Low Stock Product',
          sku: 'LOW-001',
          quantity: 3,
          reorder_level: 10,
        },
      ],
      outstanding_invoices: [
      {
        id: 501,
        invoice_number: 'INV-LIVE-001',
        customer_name: 'ABC Enterprise',
        total: 3500,
        paid: 1500,
        outstanding: 2000,
        due_at: '2026-10-05T00:00:00.000000Z',
      },
    ],
    },
  };

 const dashboardApiMock = {
  getDashboard: () => of(dashboardResponse),
};

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [DashboardComponent],
      providers: [
        {
          provide: DashboardApiService,
          useValue: dashboardApiMock,
        },
      ],
    });

    fixture = TestBed.createComponent(DashboardComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should load dashboard data from the API service', () => {

    expect(component.dashboardData).toEqual(
      dashboardResponse.data,
    );

    expect(component.dashboardData.sales_total).toBe(31500);
    expect(component.dashboardData.product_sales).toBe(27000);
    expect(component.dashboardData.services_rendered).toBe(4500);
    expect(component.dashboardData.customer_count).toBe(517);
    expect(component.dashboardData.sales_count).toBe(243);
  });
  it('should display live dashboard KPI values', () => {
  const compiled = fixture.nativeElement as HTMLElement;

  expect(compiled.textContent).toContain('GH₵ 31,500.00');
  expect(compiled.textContent).toContain('243');
  expect(compiled.textContent).toContain('GH₵ 12,350.00');
  expect(compiled.textContent).toContain('517');
});
it('should display live low stock items', () => {
  const compiled = fixture.nativeElement as HTMLElement;

  expect(compiled.textContent).toContain('Low Stock Product');
  expect(compiled.textContent).toContain('SKU: LOW-001');
  expect(compiled.textContent).toContain('3 left');
});
it('should display live outstanding invoices', () => {
  const compiled = fixture.nativeElement as HTMLElement;

  expect(compiled.textContent).toContain('INV-LIVE-001');
  expect(compiled.textContent).toContain('ABC Enterprise');
  expect(compiled.textContent).toContain('GH₵ 2,000.00');
});

it('should expose sales trend data for the chart', () => {
  expect(component.dashboardData.sales_trend).toHaveLength(5);

  expect(component.dashboardData.sales_trend[0]).toEqual({
    date: '2026-09-01',
    sales: 10000,
  });

  expect(component.dashboardData.sales_trend[4]).toEqual({
    date: '2026-09-20',
    sales: 31500,
  });
});
it('should generate chart points from sales trend data', () => {
  expect(component.salesChartPoints).toHaveLength(5);

  expect(component.salesChartPoints[0]).toMatchObject({
    x: 0,
  });

  expect(component.salesChartPoints[4]).toMatchObject({
    x: 700,
  });

  expect(component.salesChartPoints.every((point) =>
    typeof point.x === 'number' &&
    typeof point.y === 'number'
  )).toBe(true);
});
it('should render the sales trend chart points', () => {
  const compiled = fixture.nativeElement as HTMLElement;

  const circles = compiled.querySelectorAll(
    '.sales-line circle',
  );

  expect(circles.length).toBe(5);
});
});