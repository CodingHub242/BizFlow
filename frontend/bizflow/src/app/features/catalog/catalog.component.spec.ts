import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { vi } from 'vitest';

import { Router } from '@angular/router';

import { CatalogComponent } from './catalog.component';

import {
  CatalogApi,
  CatalogItem,
} from '../../core/services/catalog-api';

describe('CatalogComponent', () => {
  let component: CatalogComponent;
  let fixture: ComponentFixture<CatalogComponent>;

  let catalogApi: {
    list: ReturnType<typeof vi.fn>;
    archived: ReturnType<typeof vi.fn>;
  };

  let router: {
    navigate: ReturnType<typeof vi.fn>;
  };

  beforeEach(async () => {
   catalogApi = {
      list: vi.fn(),
      archived: vi.fn(),
    };

    catalogApi.list.mockReturnValue({
      subscribe: vi.fn(),
    });

    router = {
      navigate: vi.fn(),
    };

    await TestBed.configureTestingModule({
      imports: [CatalogComponent],
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

    fixture = TestBed.createComponent(CatalogComponent);
    component = fixture.componentInstance;

    fixture.detectChanges();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('should load the first page of catalog items when the page initializes', () => {
    expect(catalogApi.list).toHaveBeenCalledWith({
      page: 1,
      search: '',
      type: undefined,
      isActive: undefined,
      perPage: 15,
    });
  });

  it('should store catalog items returned by the API', () => {
    const items: CatalogItem[] = [
      {
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
      },
    ];

    catalogApi.list.mockReturnValue({
      subscribe: (observer: {
        next: (response: unknown) => void;
      }) => {
        observer.next({
          success: true,
          data: items,
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 15,
            total: 1,
          },
        });

        return {
          unsubscribe: vi.fn(),
        };
      },
    });

    fixture = TestBed.createComponent(CatalogComponent);
    component = fixture.componentInstance;

    fixture.detectChanges();

    expect(component.catalogItems).toEqual(items);
  });

  it('should store catalog pagination metadata returned by the API', () => {
    catalogApi.list.mockReturnValue({
      subscribe: (observer: {
        next: (response: unknown) => void;
      }) => {
        observer.next({
          success: true,
          data: [],
          meta: {
            current_page: 2,
            last_page: 4,
            per_page: 15,
            total: 52,
          },
        });

        return {
          unsubscribe: vi.fn(),
        };
      },
    });

    fixture = TestBed.createComponent(CatalogComponent);
    component = fixture.componentInstance;

    fixture.detectChanges();

    expect(component.pagination).toEqual({
      current_page: 2,
      last_page: 4,
      per_page: 15,
      total: 52,
    });
  });

  it('should render product and service names returned by the API', () => {
    const items: CatalogItem[] = [
      {
        id: 1,
        name: 'Laptop',
        type: 'product',
        sku: 'LAP-001',
        cost_price: '3000.00',
        selling_price: '4000.00',
        tax_rate: '15.00',
        track_inventory: true,
        is_active: true,
      },
      {
        id: 2,
        name: 'Interior Design',
        type: 'service',
        sku: null,
        cost_price: '500.00',
        selling_price: '1500.00',
        tax_rate: '15.00',
        track_inventory: false,
        is_active: true,
      },
    ];

    catalogApi.list.mockReturnValue({
      subscribe: (observer: {
        next: (response: unknown) => void;
      }) => {
        observer.next({
          success: true,
          data: items,
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 15,
            total: 2,
          },
        });

        return {
          unsubscribe: vi.fn(),
        };
      },
    });

    fixture = TestBed.createComponent(CatalogComponent);
    component = fixture.componentInstance;

    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;

    expect(element.textContent).toContain('Laptop');
    expect(element.textContent).toContain('Interior Design');
    expect(element.textContent).toContain('Product');
    expect(element.textContent).toContain('Service');
  });

  it('should display an empty state when there are no catalog items', () => {
    catalogApi.list.mockReturnValue({
      subscribe: (observer: {
        next: (response: unknown) => void;
      }) => {
        observer.next({
          success: true,
          data: [],
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 15,
            total: 0,
          },
        });

        return {
          unsubscribe: vi.fn(),
        };
      },
    });

    fixture = TestBed.createComponent(CatalogComponent);
    component = fixture.componentInstance;

    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;

    expect(element.textContent).toContain(
      'No products or services yet',
    );

    expect(element.textContent).toContain(
      'Add your first product or service to get started.',
    );
  });

  it('should search catalog items from the first page after the debounce period', () => {
    vi.useFakeTimers();

    catalogApi.list.mockReturnValue({
      subscribe: vi.fn(),
    });

    fixture = TestBed.createComponent(CatalogComponent);
    component = fixture.componentInstance;

    fixture.detectChanges();

    catalogApi.list.mockClear();

    const element: HTMLElement = fixture.nativeElement;

    const input = element.querySelector(
      'input[aria-label="Search products and services"]',
    ) as HTMLInputElement;

    input.value = 'Laptop';
    input.dispatchEvent(new Event('input'));

    fixture.detectChanges();

    expect(catalogApi.list).not.toHaveBeenCalled();

    vi.advanceTimersByTime(300);

    expect(catalogApi.list).toHaveBeenCalledWith({
      page: 1,
      search: 'Laptop',
      type: undefined,
      isActive: undefined,
      perPage: 15,
    });

    vi.useRealTimers();
  });

  it('should load the requested page while preserving the search', () => {
    catalogApi.list.mockReturnValue({
      subscribe: vi.fn(),
    });

    fixture = TestBed.createComponent(CatalogComponent);
    component = fixture.componentInstance;

    fixture.detectChanges();

    catalogApi.list.mockClear();

    component.search = 'Laptop';

    component.loadPage(2);

    expect(catalogApi.list).toHaveBeenCalledWith({
      page: 2,
      search: 'Laptop',
      type: undefined,
      isActive: undefined,
      perPage: 15,
    });
  });

  it('should filter catalog items by product type', () => {
    catalogApi.list.mockReturnValue({
      subscribe: vi.fn(),
    });

    fixture = TestBed.createComponent(CatalogComponent);
    component = fixture.componentInstance;

    fixture.detectChanges();

    catalogApi.list.mockClear();

    component.type = 'product';

    component.filterCatalog();

    expect(catalogApi.list).toHaveBeenCalledWith({
      page: 1,
      search: '',
      type: 'product',
      isActive: undefined,
      perPage: 15,
    });
  });

  it('should filter catalog items by service type', () => {
    catalogApi.list.mockReturnValue({
      subscribe: vi.fn(),
    });

    fixture = TestBed.createComponent(CatalogComponent);
    component = fixture.componentInstance;

    fixture.detectChanges();

    catalogApi.list.mockClear();

    component.type = 'service';

    component.filterCatalog();

    expect(catalogApi.list).toHaveBeenCalledWith({
      page: 1,
      search: '',
      type: 'service',
      isActive: undefined,
      perPage: 15,
    });
  });

  it('should filter catalog items by active status', () => {
    catalogApi.list.mockReturnValue({
      subscribe: vi.fn(),
    });

    fixture = TestBed.createComponent(CatalogComponent);
    component = fixture.componentInstance;

    fixture.detectChanges();

    catalogApi.list.mockClear();

    component.isActive = true;

    component.filterCatalog();

    expect(catalogApi.list).toHaveBeenCalledWith({
      page: 1,
      search: '',
      type: undefined,
      isActive: true,
      perPage: 15,
    });
  });

  it('should filter catalog items by inactive status', () => {
    catalogApi.list.mockReturnValue({
      subscribe: vi.fn(),
    });

    fixture = TestBed.createComponent(CatalogComponent);
    component = fixture.componentInstance;

    fixture.detectChanges();

    catalogApi.list.mockClear();

    component.isActive = false;

    component.filterCatalog();

    expect(catalogApi.list).toHaveBeenCalledWith({
      page: 1,
      search: '',
      type: undefined,
      isActive: false,
      perPage: 15,
    });
  });

  it('should preserve search and filters when changing pages', () => {
    catalogApi.list.mockReturnValue({
      subscribe: vi.fn(),
    });

    fixture = TestBed.createComponent(CatalogComponent);
    component = fixture.componentInstance;

    fixture.detectChanges();

    catalogApi.list.mockClear();

    component.search = 'Laptop';
    component.type = 'product';
    component.isActive = true;

    component.loadPage(2);

    expect(catalogApi.list).toHaveBeenCalledWith({
      page: 2,
      search: 'Laptop',
      type: 'product',
      isActive: true,
      perPage: 15,
    });
  });

  it('should load the next page when the next button is clicked', () => {
    catalogApi.list.mockReturnValue({
      subscribe: vi.fn(),
    });

    fixture = TestBed.createComponent(CatalogComponent);
    component = fixture.componentInstance;

    component.catalogItems = [
      {
        id: 1,
        name: 'Laptop',
        type: 'product',
        sku: 'LAP-001',
        cost_price: '3000',
        selling_price: '4000',
        tax_rate: '15',
        track_inventory: true,
        is_active: true,
      },
    ];

    component.pagination = {
      current_page: 1,
      last_page: 3,
      per_page: 15,
      total: 45,
    };

    fixture.detectChanges();

    catalogApi.list.mockClear();

    const nextButton = fixture.nativeElement.querySelector(
      '[aria-label="Next page"]',
    ) as HTMLButtonElement;

    nextButton.click();

   expect(catalogApi.list).toHaveBeenLastCalledWith({
  page: 2,
  search: '',
  type: undefined,
  isActive: undefined,
  perPage: 15,
});
  });

  it('should navigate to the selected catalog item details page', () => {
    component.viewCatalogItem(1);

    expect(router.navigate).toHaveBeenCalledWith([
      '/catalog',
      1,
    ]);
  });

  it('should navigate to the add catalog item page', () => {
    component.addCatalogItem();

    expect(router.navigate).toHaveBeenCalledWith([
      '/catalog/add',
    ]);
  });
  it('should load archived catalog items when archived view is selected', () => {
  catalogApi.archived.mockReturnValue(
    of({
      success: true,
      data: [
        {
          id: 10,
          name: 'Archived Product',
          type: 'product',
          sku: 'ARCH-001',
          description: null,
          unit: 'piece',
          cost_price: 50,
          selling_price: 80,
          tax_rate: 0,
          track_inventory: true,
          is_active: true,
          deleted_at: '2026-10-02T10:00:00Z',
        },
      ],
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: 1,
      },
    }),
  );

  component.setViewMode('archived');

  expect(catalogApi.archived).toHaveBeenCalledWith({
    page: 1,
    search: '',
    type: undefined,
    perPage: 15,
  });

  expect(component.catalogItems).toHaveLength(1);
  expect(component.catalogItems[0].name).toBe('Archived Product');
});
it('should switch back to active catalog items', () => {
  catalogApi.list.mockReturnValue(
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

  component.viewMode = 'archived';

  component.setViewMode('active');

  expect(component.viewMode).toBe('active');

  expect(catalogApi.list).toHaveBeenCalledWith({
    page: 1,
    search: '',
    type: undefined,
    isActive: undefined,
    perPage: 15,
  });
});
it('should reset search and type filters when switching catalog view', () => {
  catalogApi.archived.mockReturnValue(
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

  component.search = 'old product';
  component.type = 'product';

  component.setViewMode('archived');

  expect(component.search).toBe('');
  expect(component.type).toBe('');
  expect(component.viewMode).toBe('archived');
});
});