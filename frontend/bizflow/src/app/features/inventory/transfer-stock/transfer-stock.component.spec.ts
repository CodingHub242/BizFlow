import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { TransferStockComponent } from './transfer-stock.component';
import { InventoryApi } from '../../../core/services/inventory-api.service';
import { BranchApi } from '../../../core/services/branch-api';
import { CatalogApi } from '../../../core/services/catalog-api';

describe('TransferStockComponent', () => {
  let component: TransferStockComponent;
  let fixture: ComponentFixture<TransferStockComponent>;

  let inventoryApi: {
    list: ReturnType<typeof vi.fn>;
    transfer: ReturnType<typeof vi.fn>;
  };

  let branchApi: {
    list: ReturnType<typeof vi.fn>;
  };

  let catalogApi: {
    list: ReturnType<typeof vi.fn>;
  };

  beforeEach(async () => {
    inventoryApi = {
      list: vi.fn((params?: { branchId?: number }) => {
        const branchId = params?.branchId ?? 1;

        return of({
          success: true,
          data: [
            {
              id: branchId * 100,
              branch_id: branchId,
              catalog_item_id: 5,
              quantity: branchId === 1 ? 20 : 5,
              reorder_level: 2,
            },
          ],
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 100,
            total: 1,
          },
        });
      }),

      transfer: vi.fn().mockReturnValue(
        of({
          success: true,
          message: 'Stock transferred successfully.',
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
              name: 'Accra North',
              code: 'ACC-N01',
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
              name: 'Laptop',
              sku: 'LAP-001',
              type: 'product',
              selling_price: '2500.00',
              track_inventory: true,
              is_active: true,
            },
          ],
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 100,
            total: 1,
          },
        }),
      ),
    };

    await TestBed.configureTestingModule({
      imports: [TransferStockComponent],
      providers: [
        { provide: InventoryApi, useValue: inventoryApi },
        { provide: BranchApi, useValue: branchApi },
        { provide: CatalogApi, useValue: catalogApi },
        provideRouter([]),
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(TransferStockComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  function selectValidTransfer(): void {
    component.sourceBranchId = 1;
    component.destinationBranchId = 2;
    component.catalogItemId = 5;
    component.onSelectionChanged();
  }

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should load branches and inventory-tracked products', () => {
    expect(branchApi.list).toHaveBeenCalled();
    expect(catalogApi.list).toHaveBeenCalled();
    expect(component.branches).toHaveLength(2);
    expect(component.catalogItems).toHaveLength(1);
  });

  it('should load source and destination stock balances', () => {
    selectValidTransfer();

    expect(inventoryApi.list).toHaveBeenCalledTimes(2);
    expect(component.sourceQuantity).toBe(20);
    expect(component.destinationQuantity).toBe(5);
    expect(component.balancesLoaded).toBe(true);
  });

  it('should reject transfer to the same branch', () => {
    component.sourceBranchId = 1;
    component.destinationBranchId = 1;
    component.catalogItemId = 5;

    component.submit();

    expect(inventoryApi.transfer).not.toHaveBeenCalled();
    expect(component.error).toContain('different');
  });

  it('should reject a quantity of zero', () => {
    selectValidTransfer();
    component.quantity = 0;

    component.submit();

    expect(inventoryApi.transfer).not.toHaveBeenCalled();
    expect(component.error).toContain('greater than zero');
  });

  it('should reject a quantity greater than available stock', () => {
    selectValidTransfer();
    component.quantity = 21;

    component.submit();

    expect(inventoryApi.transfer).not.toHaveBeenCalled();
    expect(component.error).toContain('exceed available source stock');
  });

  it('should submit a valid transfer', () => {
    selectValidTransfer();

    component.quantity = 10;
    component.notes = 'Restocking Kumasi';
    component.submit();

    expect(inventoryApi.transfer).toHaveBeenCalledWith({
      catalog_item_id: 5,
      source_branch_id: 1,
      destination_branch_id: 2,
      quantity: 10,
      notes: 'Restocking Kumasi',
    });

    expect(component.success).toBe(
      'Stock transferred successfully.',
    );
  });

  it('should show a backend transfer error', () => {
    selectValidTransfer();

    inventoryApi.transfer.mockReturnValueOnce(
      throwError(() => ({
        error: {
          message: 'Insufficient stock at source branch.',
        },
      })),
    );

    component.quantity = 10;
    component.submit();

    expect(component.error).toBe(
      'Insufficient stock at source branch.',
    );
  });

  it('should handle inventory balance lookup failures', () => {
    inventoryApi.list.mockReturnValueOnce(
      throwError(() => new Error('Network error')),
    );

    selectValidTransfer();

    expect(component.balancesLoaded).toBe(false);
    expect(component.balanceError).toContain(
      'Unable to check branch stock balances',
    );
    expect(inventoryApi.transfer).not.toHaveBeenCalled();
  });
});