import { ComponentFixture, TestBed } from '@angular/core/testing';
import { vi } from 'vitest';
import { Router } from '@angular/router';
import { CustomersComponent } from './customers.component';
import { CustomerApi } from '../../core/services/customer-api';

describe('CustomersComponent', () => {
  let component: CustomersComponent;
  let fixture: ComponentFixture<CustomersComponent>;

  let customerApi: {
    list: ReturnType<typeof vi.fn>;
  };

  let router: { navigate: ReturnType<typeof vi.fn> };

  beforeEach(async () => {
    customerApi = {
      list: vi.fn(),
    };

    customerApi.list.mockReturnValue({
      subscribe: vi.fn(),
    });

    router = {
  navigate: vi.fn(),
};

    await TestBed.configureTestingModule({
      imports: [CustomersComponent],
      providers: [
        {
          provide: CustomerApi,
          useValue: customerApi,
        },
        { provide: Router, useValue: router },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(CustomersComponent);
    component = fixture.componentInstance;

    fixture.detectChanges();
  });
  afterEach(() => {
  vi.useRealTimers();
});

  it('should load the first page of customers when the page initializes', () => {
    expect(customerApi.list).toHaveBeenCalledWith({
      page: 1,
      search: '',
      perPage: 15,
    });
  });
  it('should store customers returned by the API', () => {
  const customers = [
    {
      id: 1,
      tenant_id: 1,
      name: 'John Mensah',
      email: 'john@example.com',
      phone: '0240000000',
      address: null,
      city: null,
      country: 'Ghana',
      notes: null,
      is_active: true,
    },
  ];

  customerApi.list.mockReturnValue({
    subscribe: (observer: {
      next: (response: unknown) => void;
    }) => {
      observer.next({
        success: true,
        data: customers,
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

  fixture = TestBed.createComponent(CustomersComponent);
  component = fixture.componentInstance;

  fixture.detectChanges();

  expect(component.customers).toEqual(customers);
});
it('should store customer pagination metadata returned by the API', () => {
  customerApi.list.mockReturnValue({
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

  fixture = TestBed.createComponent(CustomersComponent);
  component = fixture.componentInstance;

  fixture.detectChanges();

  expect(component.pagination).toEqual({
    current_page: 2,
    last_page: 4,
    per_page: 15,
    total: 52,
  });
});
it('should render customer names returned by the API', () => {
  const customers = [
    {
      id: 1,
      tenant_id: 1,
      name: 'John Mensah',
      email: 'john@example.com',
      phone: '0240000000',
      address: null,
      city: null,
      country: 'Ghana',
      notes: null,
      is_active: true,
    },
    {
      id: 2,
      tenant_id: 1,
      name: 'Ama Boateng',
      email: 'ama@example.com',
      phone: '0550000000',
      address: null,
      city: null,
      country: 'Ghana',
      notes: null,
      is_active: true,
    },
  ];

  customerApi.list.mockReturnValue({
    subscribe: (observer: {
      next: (response: unknown) => void;
    }) => {
      observer.next({
        success: true,
        data: customers,
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

  fixture = TestBed.createComponent(CustomersComponent);
  component = fixture.componentInstance;

  fixture.detectChanges();

  const element: HTMLElement = fixture.nativeElement;

  expect(element.textContent).toContain('John Mensah');
  expect(element.textContent).toContain('Ama Boateng');
});
it('should display an empty state when there are no customers', () => {
  customerApi.list.mockReturnValue({
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

  fixture = TestBed.createComponent(CustomersComponent);
  component = fixture.componentInstance;

  fixture.detectChanges();

  const element: HTMLElement = fixture.nativeElement;

  expect(element.textContent).toContain('No customers yet');
  expect(element.textContent).toContain(
    'Add your first customer to get started.',
  );
});
it('should search customers from the first page after the debounce period', () => {
  vi.useFakeTimers();

  customerApi.list.mockReturnValue({
    subscribe: vi.fn(),
  });

  fixture = TestBed.createComponent(CustomersComponent);
  component = fixture.componentInstance;

  fixture.detectChanges();

  // Ignore the initial page load.
  customerApi.list.mockClear();

  const element: HTMLElement = fixture.nativeElement;

  const input = element.querySelector(
    'input[aria-label="Search customers"]',
  ) as HTMLInputElement;

  input.value = 'John';
  input.dispatchEvent(new Event('input'));

  fixture.detectChanges();

  // No search request before 300ms.
  expect(customerApi.list).not.toHaveBeenCalled();

  vi.advanceTimersByTime(300);

  expect(customerApi.list).toHaveBeenCalledWith({
    page: 1,
    search: 'John',
    perPage: 15,
  });

  vi.useRealTimers();
});
it('should load the requested customer page while preserving the search', () => {
  customerApi.list.mockReturnValue({
    subscribe: vi.fn(),
  });

  fixture = TestBed.createComponent(CustomersComponent);
  component = fixture.componentInstance;

  fixture.detectChanges();

  customerApi.list.mockClear();

  component.search = 'John';

  component.loadPage(2);

  expect(customerApi.list).toHaveBeenCalledWith({
    page: 2,
    search: 'John',
    perPage: 15,
  });
});
it('should load the next page when the next button is clicked', () => {
  customerApi.list.mockReturnValue({
    subscribe: vi.fn(),
  });

  fixture = TestBed.createComponent(CustomersComponent);
  component = fixture.componentInstance;

  component.customers = [
    {
      id: 1,
      name: 'John Doe',
      email: 'john@example.com',
      phone: '0240000000',
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

  customerApi.list.mockClear();

  const nextButton = fixture.nativeElement.querySelector(
    '[aria-label="Next page"]',
  ) as HTMLButtonElement;

  nextButton.click();

  expect(customerApi.list).toHaveBeenLastCalledWith({
    page: 2,
    search: '',
    perPage: 15,
  });
});
it('should navigate to the selected customer details page', () => {
  component.customers = [
    {
      id: 1,
      name: 'Abena Osei',
      email: 'abena@example.com',
      phone: '0244000000',
    },
  ];

  component.viewCustomer(1);

  expect(router.navigate).toHaveBeenCalledWith(['/customers', 1]);
});
});