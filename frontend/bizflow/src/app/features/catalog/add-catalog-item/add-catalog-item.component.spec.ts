import { ComponentFixture, TestBed } from '@angular/core/testing';

import { vi } from 'vitest';

import { Router } from '@angular/router';

import { AddCatalogItemComponent } from './add-catalog-item.component';

import { CatalogApi } from '../../../core/services/catalog-api';

describe('AddCatalogItemComponent', () => {
  let component: AddCatalogItemComponent;
  let fixture: ComponentFixture<AddCatalogItemComponent>;

  let catalogApi: {
    create: ReturnType<typeof vi.fn>;
  };

  let router: {
    navigate: ReturnType<typeof vi.fn>;
  };

  beforeEach(async () => {
    catalogApi = {
      create: vi.fn(),
    };

    router = {
      navigate: vi.fn(),
    };

    await TestBed.configureTestingModule({
      imports: [AddCatalogItemComponent],
      providers: [
        {
          provide: CatalogApi,
          useValue: catalogApi,
        },
        {
          provide: Router,
          useValue: router,
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(
      AddCatalogItemComponent,
    );

    component = fixture.componentInstance;

    fixture.detectChanges();
  });

  it('should create the component', () => {
    expect(component).toBeTruthy();
  });

  it('should display the add product and service form', () => {
    const element: HTMLElement = fixture.nativeElement;

    expect(element.textContent).toContain(
      'Add Product / Service',
    );

    expect(element.textContent).toContain(
      'Name',
    );

    expect(element.textContent).toContain(
      'Cost Price',
    );

    expect(element.textContent).toContain(
      'Selling Price',
    );
  });

  it('should require a name before creating an item', () => {
    component.name = '';

    component.cost_price = 100;
    component.selling_price = 150;

    component.save();

    expect(catalogApi.create).not.toHaveBeenCalled();

    expect(component.errorMessage).toBe(
      'Name is required.',
    );
  });

  it('should require a cost price', () => {
    component.name = 'Laptop';

    component.cost_price = null;
    component.selling_price = 4000;

    component.save();

    expect(catalogApi.create).not.toHaveBeenCalled();

    expect(component.errorMessage).toBe(
      'Cost price is required.',
    );
  });

  it('should reject a negative cost price', () => {
    component.name = 'Laptop';

    component.cost_price = -100;
    component.selling_price = 4000;

    component.save();

    expect(catalogApi.create).not.toHaveBeenCalled();

    expect(component.errorMessage).toBe(
      'Cost price is required.',
    );
  });

  it('should require a selling price', () => {
    component.name = 'Laptop';

    component.cost_price = 3000;
    component.selling_price = null;

    component.save();

    expect(catalogApi.create).not.toHaveBeenCalled();

    expect(component.errorMessage).toBe(
      'Selling price is required.',
    );
  });

  it('should reject a negative selling price', () => {
    component.name = 'Laptop';

    component.cost_price = 3000;
    component.selling_price = -500;

    component.save();

    expect(catalogApi.create).not.toHaveBeenCalled();

    expect(component.errorMessage).toBe(
      'Selling price is required.',
    );
  });

  it('should create a product with the correct request data', () => {
    catalogApi.create.mockReturnValue({
      subscribe: (observer: {
        next: () => void;
      }) => {
        observer.next();

        return {
          unsubscribe: vi.fn(),
        };
      },
    });

    component.name = 'Laptop';
    component.type = 'product';
    component.sku = 'LAP-001';
    component.description = 'Business laptop';
    component.unit = 'piece';
    component.cost_price = 3000;
    component.selling_price = 4000;
    component.tax_rate = 15;
    component.track_inventory = true;
    component.is_active = true;

    component.save();

    expect(catalogApi.create).toHaveBeenCalledWith({
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
  });

  it('should create a service with inventory tracking disabled', () => {
    catalogApi.create.mockReturnValue({
      subscribe: (observer: {
        next: () => void;
      }) => {
        observer.next();

        return {
          unsubscribe: vi.fn(),
        };
      },
    });

    component.name = 'Interior Design';
    component.type = 'service';
    component.sku = '';
    component.description = 'Interior design service';
    component.unit = 'project';
    component.cost_price = 500;
    component.selling_price = 1500;
    component.tax_rate = 15;
    component.track_inventory = true;
    component.is_active = true;

    component.save();

    expect(catalogApi.create).toHaveBeenCalledWith({
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
  });

  it('should convert an empty SKU to null', () => {
    catalogApi.create.mockReturnValue({
      subscribe: (observer: {
        next: () => void;
      }) => {
        observer.next();

        return {
          unsubscribe: vi.fn(),
        };
      },
    });

    component.name = 'Plumbing Service';
    component.type = 'service';
    component.sku = '   ';
    component.cost_price = 300;
    component.selling_price = 800;

    component.save();

    expect(catalogApi.create).toHaveBeenCalledWith(
      expect.objectContaining({
        sku: null,
      }),
    );
  });

  it('should trim text fields before creating an item', () => {
    catalogApi.create.mockReturnValue({
      subscribe: (observer: {
        next: () => void;
      }) => {
        observer.next();

        return {
          unsubscribe: vi.fn(),
        };
      },
    });

    component.name = '  Laptop  ';
    component.type = 'product';
    component.sku = '  LAP-001  ';
    component.description = '  Business laptop  ';
    component.unit = '  piece  ';
    component.cost_price = 3000;
    component.selling_price = 4000;

    component.save();

    expect(catalogApi.create).toHaveBeenCalledWith(
      expect.objectContaining({
        name: 'Laptop',
        sku: 'LAP-001',
        description: 'Business laptop',
        unit: 'piece',
      }),
    );
  });

  it('should navigate to catalog after successful creation', () => {
    catalogApi.create.mockReturnValue({
      subscribe: (observer: {
        next: () => void;
      }) => {
        observer.next();

        return {
          unsubscribe: vi.fn(),
        };
      },
    });

    component.name = 'Laptop';
    component.cost_price = 3000;
    component.selling_price = 4000;

    component.save();

    expect(router.navigate).toHaveBeenCalledWith([
      '/catalog',
    ]);
  });

  it('should set submitting while creating an item', () => {
    let observer:
      | {
          next?: () => void;
        }
      | undefined;

    catalogApi.create.mockReturnValue({
      subscribe: (currentObserver: {
        next?: () => void;
      }) => {
        observer = currentObserver;

        return {
          unsubscribe: vi.fn(),
        };
      },
    });

    component.name = 'Laptop';
    component.cost_price = 3000;
    component.selling_price = 4000;

    component.save();

    expect(component.submitting).toBe(true);

    observer?.next?.();

    expect(component.submitting).toBe(false);
  });

  it('should handle an API error message', () => {
    catalogApi.create.mockReturnValue({
      subscribe: (observer: {
        error: (error: unknown) => void;
      }) => {
        observer.error({
          error: {
            message: 'The SKU has already been taken.',
          },
        });

        return {
          unsubscribe: vi.fn(),
        };
      },
    });

    component.name = 'Laptop';
    component.type = 'product';
    component.sku = 'LAP-001';
    component.cost_price = 3000;
    component.selling_price = 4000;

    component.save();

    expect(component.errorMessage).toBe(
      'The SKU has already been taken.',
    );

    expect(component.submitting).toBe(false);
  });

  it('should use a generic error when the API does not provide a message', () => {
    catalogApi.create.mockReturnValue({
      subscribe: (observer: {
        error: (error: unknown) => void;
      }) => {
        observer.error({});

        return {
          unsubscribe: vi.fn(),
        };
      },
    });

    component.name = 'Laptop';
    component.cost_price = 3000;
    component.selling_price = 4000;

    component.save();

    expect(component.errorMessage).toBe(
      'Unable to create catalog item.',
    );

    expect(component.submitting).toBe(false);
  });

  it('should disable inventory tracking when changing to service', () => {
    component.type = 'product';
    component.track_inventory = true;

    component.type = 'service';

    component.onTypeChange();

    expect(component.track_inventory).toBe(false);
  });

  it('should navigate back to catalog when cancel is clicked', () => {
    component.cancel();

    expect(router.navigate).toHaveBeenCalledWith([
      '/catalog',
    ]);
  });
});