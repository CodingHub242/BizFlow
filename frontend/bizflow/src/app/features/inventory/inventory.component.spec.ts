import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { of, throwError } from 'rxjs';
import {
  Branch,
  BranchApi,
} from '../../core/services/branch-api';

import { InventoryComponent } from './inventory.component';
import {
  InventoryApi,
  Inventory,
} from '../../core/services/inventory-api.service';

describe('InventoryComponent', () => {
  let component: InventoryComponent;
  let fixture: ComponentFixture<InventoryComponent>;
  let branchApi: {
  list: ReturnType<typeof vi.fn>;
};
  let inventoryApi: {
    list: ReturnType<typeof vi.fn>;
  };

  const inventory: Inventory[] = [
    {
      id: 1,
      branch_id: 1,
      catalog_item_id: 1,
      quantity: '25.000',
      reorder_level: '10.000',
      branch: {
        id: 1,
        name: 'Accra Main',
        code: 'ACC-01',
      },
      catalog_item: {
        id: 1,
        name: 'Office Chair',
        type: 'product',
        sku: 'PRD-001',
        selling_price: '120.00',
        track_inventory: true,
        is_active: true,
      },
    },
    {
      id: 2,
      branch_id: 2,
      catalog_item_id: 2,
      quantity: '5.000',
      reorder_level: '10.000',
      branch: {
        id: 2,
        name: 'Kumasi Branch',
        code: 'KMS-01',
      },
      catalog_item: {
        id: 2,
        name: 'Office Desk',
        type: 'product',
        sku: 'PRD-002',
        selling_price: '450.00',
        track_inventory: true,
        is_active: true,
      },
    },
  ];

  beforeEach(async () => {
    inventoryApi = {
      list: vi.fn().mockReturnValue(
        of({
          success: true,
          data: inventory,
          meta: {
            current_page: 1,
            last_page: 2,
            per_page: 15,
            total: 30,
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
        per_page: 15,
        total: 2,
      },
    }),
  ),
};

    await TestBed.configureTestingModule({
      imports: [InventoryComponent],
      providers: [
        provideRouter([]),
        {
          provide: InventoryApi,
          useValue: inventoryApi,
        },
        {
          provide: BranchApi,
          useValue: branchApi,
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(InventoryComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should load inventory on initialization', () => {
    expect(inventoryApi.list).toHaveBeenCalled();
    expect(component.inventory).toEqual(inventory);
  });

  it('should store pagination metadata', () => {
    expect(component.currentPage).toBe(1);
    expect(component.lastPage).toBe(2);
    expect(component.totalItems).toBe(30);
  });

  it('should calculate in-stock status correctly', () => {
    expect(component.getStockStatus(inventory[0])).toBe('in-stock');
  });

  it('should calculate low-stock status correctly', () => {
    expect(component.getStockStatus(inventory[1])).toBe('low-stock');
  });

  it('should calculate out-of-stock status correctly', () => {
    const item: Inventory = {
      ...inventory[0],
      quantity: '0.000',
    };

    expect(component.getStockStatus(item)).toBe('out-of-stock');
  });

  it('should navigate to inventory details', () => {
    const router = TestBed.inject(Router);

    const navigateSpy = vi.spyOn(router, 'navigate');

    component.viewInventory(15);

    expect(navigateSpy).toHaveBeenCalledWith([
      '/inventory',
      15,
    ]);
  });

 it('should load another inventory page', () => {
  component.loadPage(2);

  expect(inventoryApi.list).toHaveBeenCalledWith(
    expect.objectContaining({
      page: 2,
    }),
  );
});

  it('should handle empty inventory', () => {
    inventoryApi.list.mockReturnValueOnce(
      of({
        success: true,
        data: [],
        meta: {
          current_page: 1,
          last_page: 1,
          per_page: 15,
          total: 0,
        },
      }),
    );

    component.loadInventory();

    expect(component.inventory).toEqual([]);
    expect(component.totalItems).toBe(0);
  });

  it('should handle inventory loading errors', () => {
    inventoryApi.list.mockReturnValueOnce(
      throwError(() => new Error('Failed to load inventory')),
    );

    component.loadInventory();

    expect(component.loading).toBe(false);
    expect(component.error).toBeTruthy();
  });
  it('should load branches on initialization', () => {
  expect(branchApi.list).toHaveBeenCalled();
  expect(component.branches.length).toBe(2);
});

it('should store available branches', () => {
  expect(component.branches).toEqual([
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
  ]);
});

it('should filter inventory by selected branch', () => {
  component.selectedBranchId = 2;

  component.filterInventory();

  expect(inventoryApi.list).toHaveBeenCalledWith(
    expect.objectContaining({
      page: 1,
      branchId: 2,
    }),
  );
});

it('should clear the branch filter', () => {
  component.selectedBranchId = 2;

  component.filterInventory();

  component.selectedBranchId = null;

  component.filterInventory();

  expect(inventoryApi.list).toHaveBeenLastCalledWith(
    expect.objectContaining({
      page: 1,
    }),
  );
});

it('should handle branch loading errors', () => {
  branchApi.list.mockReturnValueOnce(
    throwError(() => new Error('Failed to load branches')),
  );

  component.loadBranches();

  expect(component.branches).toEqual([]);
});
it('should search inventory by search term', () => {
  vi.useFakeTimers();

  component.searchInventoryItems('engine');

  vi.advanceTimersByTime(300);

  expect(inventoryApi.list).toHaveBeenLastCalledWith(
    expect.objectContaining({
      page: 1,
      search: 'engine',
    }),
  );

  vi.useRealTimers();
});
it('should search inventory with the selected branch', () => {
  vi.useFakeTimers();

  component.selectedBranchId = 2;

  component.searchInventoryItems('engine');

  vi.advanceTimersByTime(300);

  expect(inventoryApi.list).toHaveBeenLastCalledWith(
    expect.objectContaining({
      page: 1,
      search: 'engine',
      branchId: 2,
    }),
  );

  vi.useRealTimers();
});
it('should show all inventory when no stock status is selected', () => {
  expect(component.filteredInventory).toEqual(component.inventory);
});
it('should filter inventory by in-stock status', () => {
  component.inventory = [
    {
      id: 1,
      branch_id: 1,
      catalog_item_id: 1,
      quantity: 20,
      reorder_level: 5,
    },
    {
      id: 2,
      branch_id: 1,
      catalog_item_id: 2,
      quantity: 0,
      reorder_level: 5,
    },
  ];

  component.selectedStockStatus = 'in-stock';

  expect(component.filteredInventory).toHaveLength(1);
  expect(component.filteredInventory[0].id).toBe(1);
});
it('should filter inventory by low-stock status', () => {
  component.inventory = [
    {
      id: 1,
      branch_id: 1,
      catalog_item_id: 1,
      quantity: 3,
      reorder_level: 5,
    },
    {
      id: 2,
      branch_id: 1,
      catalog_item_id: 2,
      quantity: 20,
      reorder_level: 5,
    },
  ];

  component.selectedStockStatus = 'low-stock';

  expect(component.filteredInventory).toHaveLength(1);
  expect(component.filteredInventory[0].id).toBe(1);
});
it('should filter inventory by out-of-stock status', () => {
  component.inventory = [
    {
      id: 1,
      branch_id: 1,
      catalog_item_id: 1,
      quantity: 0,
      reorder_level: 5,
    },
    {
      id: 2,
      branch_id: 1,
      catalog_item_id: 2,
      quantity: 20,
      reorder_level: 5,
    },
  ];

  component.selectedStockStatus = 'out-of-stock';

  expect(component.filteredInventory).toHaveLength(1);
  expect(component.filteredInventory[0].id).toBe(1);
});
it('should filter stock status without reloading inventory', () => {
  inventoryApi.list.mockClear();

  component.selectedStockStatus = 'low-stock';

  expect(component.filteredInventory).toEqual(
    component.inventory.filter(
      (item) => component.getStockStatus(item) === 'low-stock',
    ),
  );

  expect(inventoryApi.list).not.toHaveBeenCalled();
});
});