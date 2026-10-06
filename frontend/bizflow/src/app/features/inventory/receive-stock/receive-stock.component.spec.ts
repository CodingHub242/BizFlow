import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';
import { describe, expect, it, beforeEach, vi } from 'vitest';

import { ReceiveStockComponent } from './receive-stock.component';
import { InventoryApi } from '../../../core/services/inventory-api.service';
import { BranchApi } from '../../../core/services/branch-api';
import { CatalogApi } from '../../../core/services/catalog-api';

describe('ReceiveStockComponent', () => {
  let component: ReceiveStockComponent;
  let fixture: ComponentFixture<ReceiveStockComponent>;

  let inventoryApi: {
    receive: ReturnType<typeof vi.fn>;
  };

  let branchApi: {
    list: ReturnType<typeof vi.fn>;
  };

  let catalogApi: {
    list: ReturnType<typeof vi.fn>;
  };

  beforeEach(async () => {
    inventoryApi = {
      receive: vi.fn().mockReturnValue(
        of({
          success: true,
          message: 'Stock received successfully.',
          data: {
            id: 1,
            branch_id: 1,
            catalog_item_id: 5,
            quantity: 10,
            reorder_level: 5,
          },
        }),
      ),
    };

    branchApi = {
      list: vi.fn().mockReturnValue(
        of({
          success: true,
          data: [
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
          ],
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 100,
            total: 2,
          },
        }),
      ),
    };

    catalogApi = {
      list: vi.fn().mockReturnValue(
        of({
          success: true,
          data: [
            {
              id: 5,
              name: 'Engine Oil',
              sku: 'ENG-500',
              type: 'product',
              selling_price: '120.00',
              track_inventory: true,
              is_active: true,
            },
            {
              id: 6,
              name: 'Brake Pads',
              sku: 'BRAKE-001',
              type: 'product',
              selling_price: '350.00',
              track_inventory: true,
              is_active: true,
            },
          ],
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 100,
            total: 2,
          },
        }),
      ),
    };

    await TestBed.configureTestingModule({
      imports: [ReceiveStockComponent],
      providers: [
        {
          provide: InventoryApi,
          useValue: inventoryApi,
        },
        {
          provide: BranchApi,
          useValue: branchApi,
        },
        {
          provide: CatalogApi,
          useValue: catalogApi,
        },
        provideRouter([]),
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(ReceiveStockComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should load active branches on initialization', () => {
    expect(branchApi.list).toHaveBeenCalled();

    expect(component.branches.length).toBe(2);
  });

  it('should load active inventory-tracked catalog items on initialization', () => {
    expect(catalogApi.list).toHaveBeenCalled();

    expect(component.catalogItems.length).toBe(2);
  });

  it('should submit a valid receive stock request', () => {
    component.branchId = 1;
    component.catalogItemId = 5;
    component.quantity = 10;
    component.referenceType = 'purchase';
    component.referenceId = 123;
    component.notes = 'Received from supplier';

    component.submit();

    expect(inventoryApi.receive).toHaveBeenCalledWith({
      branch_id: 1,
      catalog_item_id: 5,
      quantity: 10,
      reference_type: 'purchase',
      reference_id: 123,
      notes: 'Received from supplier',
    });
  });

  it('should not submit when branch is missing', () => {
    component.catalogItemId = 5;
    component.quantity = 10;

    component.submit();

    expect(inventoryApi.receive).not.toHaveBeenCalled();
    expect(component.error).toBeTruthy();
  });

  it('should not submit when catalog item is missing', () => {
    component.branchId = 1;
    component.quantity = 10;

    component.submit();

    expect(inventoryApi.receive).not.toHaveBeenCalled();
    expect(component.error).toBeTruthy();
  });

  it('should not submit when quantity is zero', () => {
    component.branchId = 1;
    component.catalogItemId = 5;
    component.quantity = 0;

    component.submit();

    expect(inventoryApi.receive).not.toHaveBeenCalled();
    expect(component.error).toBeTruthy();
  });

  it('should not submit when quantity is negative', () => {
    component.branchId = 1;
    component.catalogItemId = 5;
    component.quantity = -5;

    component.submit();

    expect(inventoryApi.receive).not.toHaveBeenCalled();
    expect(component.error).toBeTruthy();
  });

  it('should handle receive stock success', () => {
    component.branchId = 1;
    component.catalogItemId = 5;
    component.quantity = 10;

    component.submit();

    expect(component.success).toBe(
      'Stock received successfully.',
    );
  });

  it('should handle receive stock errors', () => {
    inventoryApi.receive.mockReturnValueOnce(
      throwError(() => new Error('Receive failed')),
    );

    component.branchId = 1;
    component.catalogItemId = 5;
    component.quantity = 10;

    component.submit();

    expect(component.error).toBe(
      'Unable to receive stock. Please try again.',
    );
  });
  it('should render the branch selector', () => {
  const select = fixture.nativeElement.querySelector(
    '#branch',
  ) as HTMLSelectElement;

  expect(select).toBeTruthy();
});

it('should render the product selector', () => {
  const select = fixture.nativeElement.querySelector(
    '#product',
  ) as HTMLSelectElement;

  expect(select).toBeTruthy();
});

it('should render the quantity input', () => {
  const input = fixture.nativeElement.querySelector(
    '#quantity',
  ) as HTMLInputElement;

  expect(input).toBeTruthy();
});

it('should render reference and notes fields', () => {
  expect(
    fixture.nativeElement.querySelector('#referenceType'),
  ).toBeTruthy();

  expect(
    fixture.nativeElement.querySelector('#referenceId'),
  ).toBeTruthy();

  expect(
    fixture.nativeElement.querySelector('#notes'),
  ).toBeTruthy();
});

it('should render the receive stock button', () => {
  const buttons = Array.from(
    fixture.nativeElement.querySelectorAll('button'),
  ) as HTMLButtonElement[];

  expect(
    buttons.some((button) =>
      button.textContent?.includes('Receive Stock'),
    ),
  ).toBe(true);
});
});