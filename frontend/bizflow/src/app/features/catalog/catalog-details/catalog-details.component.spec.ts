import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router } from '@angular/router';
import { of, throwError } from 'rxjs';

import { CatalogDetailsComponent } from './catalog-details.component';
import { CatalogApi,CatalogItem } from '../../../core/services/catalog-api';

const mockCatalogItem: CatalogItem = {
  id: 1,
  name: 'Premium Rice',
  type: 'product',
  sku: 'RICE-001',
  description: 'Premium quality rice',
  unit: 'bag',
  cost_price: 100,
  selling_price: 150,
  tax_rate: 0,
  track_inventory: true,
  is_active: true,
  deleted_at: null,
};

describe('CatalogDetailsComponent', () => {
  let fixture: ComponentFixture<CatalogDetailsComponent>;
  let component: CatalogDetailsComponent;

  let router: {
    navigate: ReturnType<typeof vi.fn>;
  };

  let catalogApi: {
    get: ReturnType<typeof vi.fn>;
    update: ReturnType<typeof vi.fn>;
    delete: ReturnType<typeof vi.fn>;
    restore: ReturnType<typeof vi.fn>;
  };

  beforeEach(async () => {
    catalogApi = {
      get: vi.fn(),
      update: vi.fn(),
      delete: vi.fn(),
      restore: vi.fn(),
    };

    router = {
      navigate: vi.fn(),
    };

    await TestBed.configureTestingModule({
      imports: [CatalogDetailsComponent],

      providers: [
        {
          provide: CatalogApi,
          useValue: catalogApi,
        },

        {
          provide: ActivatedRoute,
          useValue: {
            snapshot: {
              paramMap: {
                get: (key: string) =>
                  key === 'id' ? '1' : null,
              },
            },
          },
        },

        {
          provide: Router,
          useValue: router,
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(CatalogDetailsComponent);
    component = fixture.componentInstance;
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should load the catalog item using the id from the route', () => {
    catalogApi.get.mockReturnValue(
      of({
        success: true,
        data: {
          id: 1,
          name: 'Office Chair',
          type: 'product',
          sku: 'CHAIR-001',
          description: 'Ergonomic office chair',
          unit: 'piece',
          cost_price: 500,
          selling_price: 750,
          tax_rate: 15,
          track_inventory: true,
          is_active: true,
        },
      }),
    );

    fixture.detectChanges();

    expect(catalogApi.get).toHaveBeenCalledWith(1);
    expect(component.catalogItem?.name).toBe('Office Chair');
  });

  it('should navigate back to catalog', () => {
    component.backToCatalog();

    expect(router.navigate).toHaveBeenCalledWith(['/catalog']);
  });

  it('should enter edit mode when editing a catalog item', () => {
    component.catalogItem = {
      id: 1,
      name: 'Office Chair',
      type: 'product',
      sku: 'CHAIR-001',
      description: 'Ergonomic office chair',
      unit: 'piece',
      cost_price: 500,
      selling_price: 750,
      tax_rate: 15,
      track_inventory: true,
      is_active: true,
    };

    expect(component.isEditing).toBe(false);

    component.startEditing();

    expect(component.isEditing).toBe(true);
  });

  it('should populate the edit form with the current catalog item', () => {
    component.catalogItem = {
      id: 1,
      name: 'Office Chair',
      type: 'product',
      sku: 'CHAIR-001',
      description: 'Ergonomic office chair',
      unit: 'piece',
      cost_price: 500,
      selling_price: 750,
      tax_rate: 15,
      track_inventory: true,
      is_active: true,
    };

    component.startEditing();

    expect(component.form.getRawValue()).toEqual({
      name: 'Office Chair',
      type: 'product',
      sku: 'CHAIR-001',
      description: 'Ergonomic office chair',
      unit: 'piece',
      cost_price: 500,
      selling_price: 750,
      tax_rate: 15,
      track_inventory: true,
      is_active: true,
    });
  });

  it('should force inventory tracking off when the item type is service', () => {
    component.form.patchValue({
      type: 'product',
      track_inventory: true,
    });

    component.form.patchValue({
      type: 'service',
    });

    component.onTypeChange();

    expect(component.form.controls.track_inventory.value).toBe(false);
  });

  it('should cancel edit mode', () => {
    component.isEditing = true;

    component.cancelEditing();

    expect(component.isEditing).toBe(false);
    expect(component.errorMessage).toBe('');
  });

  it('should update a catalog item with the edited form values', () => {
    component.catalogItem = {
      id: 1,
      name: 'Office Chair',
      type: 'product',
      sku: 'CHAIR-001',
      description: 'Ergonomic office chair',
      unit: 'piece',
      cost_price: 500,
      selling_price: 750,
      tax_rate: 15,
      track_inventory: true,
      is_active: true,
    };

    catalogApi.update.mockReturnValue(
      of({
        success: true,
        message: 'Catalog item updated successfully.',
        data: {
          id: 1,
          name: 'Premium Office Chair',
          type: 'product',
          sku: 'CHAIR-001',
          description: 'Premium ergonomic office chair',
          unit: 'piece',
          cost_price: 550,
          selling_price: 850,
          tax_rate: 15,
          track_inventory: true,
          is_active: true,
        },
      }),
    );

    component.startEditing();

    component.form.patchValue({
      name: 'Premium Office Chair',
      description: 'Premium ergonomic office chair',
      cost_price: 550,
      selling_price: 850,
    });

    component.updateCatalogItem();

    expect(catalogApi.update).toHaveBeenCalledWith(1, {
      name: 'Premium Office Chair',
      type: 'product',
      sku: 'CHAIR-001',
      description: 'Premium ergonomic office chair',
      unit: 'piece',
      cost_price: 550,
      selling_price: 850,
      tax_rate: 15,
      track_inventory: true,
      is_active: true,
    });
  });

  it('should update the displayed catalog item and exit edit mode after a successful update', () => {
    component.catalogItem = {
      id: 1,
      name: 'Office Chair',
      type: 'product',
      sku: 'CHAIR-001',
      description: 'Ergonomic office chair',
      unit: 'piece',
      cost_price: 500,
      selling_price: 750,
      tax_rate: 15,
      track_inventory: true,
      is_active: true,
    };

    catalogApi.update.mockReturnValue(
      of({
        success: true,
        message: 'Catalog item updated successfully.',
        data: {
          id: 1,
          name: 'Premium Office Chair',
          type: 'product',
          sku: 'CHAIR-001',
          description: 'Premium ergonomic office chair',
          unit: 'piece',
          cost_price: 550,
          selling_price: 850,
          tax_rate: 15,
          track_inventory: true,
          is_active: true,
        },
      }),
    );

    component.startEditing();

    component.form.patchValue({
      name: 'Premium Office Chair',
      cost_price: 550,
      selling_price: 850,
    });

    component.updateCatalogItem();

    expect(component.catalogItem?.name).toBe(
      'Premium Office Chair',
    );

    expect(component.catalogItem?.cost_price).toBe(550);

    expect(component.catalogItem?.selling_price).toBe(850);

    expect(component.isEditing).toBe(false);
    expect(component.isSaving).toBe(false);
  });

  it('should display the backend permission error when cost price update is forbidden', () => {
    component.catalogItem = {
      id: 1,
      name: 'Office Chair',
      type: 'product',
      sku: 'CHAIR-001',
      description: 'Ergonomic office chair',
      unit: 'piece',
      cost_price: 500,
      selling_price: 750,
      tax_rate: 15,
      track_inventory: true,
      is_active: true,
    };

    catalogApi.update.mockReturnValue(
      throwError(() => ({
        status: 403,
        error: {
          message:
            'You do not have permission to change product cost.',
        },
      })),
    );

    component.startEditing();

    component.form.patchValue({
      cost_price: 550,
    });

    component.updateCatalogItem();

    expect(component.errorMessage).toBe(
      'You do not have permission to change product cost.',
    );

    expect(component.isSaving).toBe(false);
  });

  it('should display a generic error when the update fails without a message', () => {
    component.catalogItem = {
      id: 1,
      name: 'Office Chair',
      type: 'product',
      sku: 'CHAIR-001',
      description: 'Ergonomic office chair',
      unit: 'piece',
      cost_price: 500,
      selling_price: 750,
      tax_rate: 15,
      track_inventory: true,
      is_active: true,
    };

    catalogApi.update.mockReturnValue(
      throwError(() => ({
        status: 500,
        error: {},
      })),
    );

    component.startEditing();

    component.updateCatalogItem();

    expect(component.errorMessage).toBe(
      'Unable to update this product or service.',
    );

    expect(component.isSaving).toBe(false);
  });
  it('should show the delete confirmation', () => {
  component.catalogItem = {
    id: 1,
    name: 'Office Chair',
    type: 'product',
    sku: 'CHAIR-001',
    description: 'Ergonomic office chair',
    unit: 'piece',
    cost_price: 500,
    selling_price: 750,
    tax_rate: 15,
    track_inventory: true,
    is_active: true,
  };

  expect(component.showDeleteConfirmation).toBe(false);

  component.confirmDelete();

  expect(component.showDeleteConfirmation).toBe(true);
  expect(component.errorMessage).toBe('');
});

it('should cancel delete confirmation', () => {
  component.showDeleteConfirmation = true;

  component.cancelDelete();

  expect(component.showDeleteConfirmation).toBe(false);
});

it('should delete the catalog item and return to the catalog', () => {
  component.catalogItem = {
    id: 1,
    name: 'Office Chair',
    type: 'product',
    sku: 'CHAIR-001',
    description: 'Ergonomic office chair',
    unit: 'piece',
    cost_price: 500,
    selling_price: 750,
    tax_rate: 15,
    track_inventory: true,
    is_active: true,
  };

  catalogApi.delete = vi.fn().mockReturnValue(
    of({
      success: true,
      message: 'Catalog item deleted successfully.',
    }),
  );

  component.showDeleteConfirmation = true;

  component.deleteCatalogItem();

  expect(catalogApi.delete).toHaveBeenCalledWith(1);

  expect(router.navigate).toHaveBeenCalledWith([
    '/catalog',
  ]);

  expect(component.isDeleting).toBe(false);
  expect(component.showDeleteConfirmation).toBe(false);
});

it('should display an API error when deleting the catalog item fails', () => {
  component.catalogItem = {
    id: 1,
    name: 'Office Chair',
    type: 'product',
    sku: 'CHAIR-001',
    description: 'Ergonomic office chair',
    unit: 'piece',
    cost_price: 500,
    selling_price: 750,
    tax_rate: 15,
    track_inventory: true,
    is_active: true,
  };

  catalogApi.delete = vi.fn().mockReturnValue(
    throwError(() => ({
      status: 422,
      error: {
        message: 'This catalog item cannot be deleted.',
      },
    })),
  );

  component.showDeleteConfirmation = true;

  component.deleteCatalogItem();

  expect(component.errorMessage).toBe(
    'This catalog item cannot be deleted.',
  );

  expect(component.isDeleting).toBe(false);
  expect(component.showDeleteConfirmation).toBe(false);
});

it('should display a generic error when deleting without an API message', () => {
  component.catalogItem = {
    id: 1,
    name: 'Office Chair',
    type: 'product',
    sku: 'CHAIR-001',
    description: 'Ergonomic office chair',
    unit: 'piece',
    cost_price: 500,
    selling_price: 750,
    tax_rate: 15,
    track_inventory: true,
    is_active: true,
  };

  catalogApi.delete = vi.fn().mockReturnValue(
    throwError(() => ({
      status: 500,
      error: {},
    })),
  );

  component.deleteCatalogItem();

  expect(component.errorMessage).toBe(
    'Unable to delete this product or service.',
  );

  expect(component.isDeleting).toBe(false);
});
it('should show restore action for an archived catalog item', () => {
  catalogApi.get.mockReturnValue(
    of({
      success: true,
      message: 'Catalog item retrieved successfully.',
      data: {
        ...mockCatalogItem,
        deleted_at: '2026-10-02T10:00:00Z',
      },
    }),
  );

  fixture.detectChanges();

  const element: HTMLElement = fixture.nativeElement;

  expect(element.textContent).toContain('Restore');
});
it('should restore an archived catalog item', () => {
  catalogApi.get.mockReturnValue(
    of({
      success: true,
      message: 'Catalog item retrieved successfully.',
      data: {
        ...mockCatalogItem,
        deleted_at: '2026-10-02T10:00:00Z',
      },
    }),
  );

  catalogApi.restore.mockReturnValue(
    of({
      success: true,
      message: 'Catalog item restored successfully.',
      data: {
        ...mockCatalogItem,
        deleted_at: null,
      },
    }),
  );

  fixture.detectChanges();

  component.restoreCatalogItem();

  expect(catalogApi.restore).toHaveBeenCalledWith(mockCatalogItem.id);
  expect(component.catalogItem?.deleted_at).toBeNull();
  expect(component.isRestoring).toBe(false);
});
it('should display an error when restoring a catalog item fails', () => {
  catalogApi.get.mockReturnValue(
    of({
      success: true,
      message: 'Catalog item retrieved successfully.',
      data: {
        ...mockCatalogItem,
        deleted_at: '2026-10-02T10:00:00Z',
      },
    }),
  );

  catalogApi.restore.mockReturnValue(
    throwError(() => ({
      status: 500,
      error: {
        message: 'Unable to restore catalog item.',
      },
    })),
  );

  fixture.detectChanges();

  component.restoreCatalogItem();

  expect(component.isRestoring).toBe(false);
  expect(component.errorMessage).toBe(
    'Unable to restore catalog item.',
  );
});
});