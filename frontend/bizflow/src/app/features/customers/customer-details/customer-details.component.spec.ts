import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router } from '@angular/router';
import { of } from 'rxjs';

import { CustomerDetailsComponent } from './customer-details.component';
import { CustomerApi } from '../../../core/services/customer-api';

describe('CustomerDetailsComponent', () => {
  let fixture: ComponentFixture<CustomerDetailsComponent>;
  let component: CustomerDetailsComponent;
  let router: { navigate: ReturnType<typeof vi.fn> };
  let customerApi: {
  get: ReturnType<typeof vi.fn>;
  update: ReturnType<typeof vi.fn>;
};

  beforeEach(async () => {
    customerApi = {
  get: vi.fn(),
  update: vi.fn(),
};
    router = {
  navigate: vi.fn(),
};

    await TestBed.configureTestingModule({
      imports: [CustomerDetailsComponent],
      providers: [
        {
          provide: CustomerApi,
          useValue: customerApi,
        },
        {
          provide: ActivatedRoute,
          useValue: {
            snapshot: {
              paramMap: {
                get: (key: string) => key === 'id' ? '1' : null,
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

    fixture = TestBed.createComponent(CustomerDetailsComponent);
    component = fixture.componentInstance;
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should load the customer using the id from the route', () => {
    customerApi.get.mockReturnValue(
      of({
        success: true,
        data: {
          id: 1,
          name: 'Abena Osei',
          email: 'abena@example.com',
          phone: '0244000000',
        },
      }),
    );

    fixture.detectChanges();

    expect(customerApi.get).toHaveBeenCalledWith(1);
    expect(component.customer?.name).toBe('Abena Osei');
  });

  it('should navigate back to customers', () => {
  component.backToCustomers();

  expect(router.navigate).toHaveBeenCalledWith(['/customers']);
});
it('should enter edit mode when edit customer is clicked', () => {
  expect(component.isEditing).toBe(false);

  component.startEditing();

  expect(component.isEditing).toBe(true);
});
it('should populate the edit form with the current customer', () => {
  customerApi.get.mockReturnValue(
    of({
      success: true,
      data: {
        id: 1,
        name: 'Abena Osei',
        email: 'abena@example.com',
        phone: '0244000000',
        company_name: 'Abena Trading',
        address: 'Dzorwulu',
        city: 'Accra',
        country: 'Ghana',
        notes: 'Important customer',
      },
    }),
  );

  fixture.detectChanges();

  component.startEditing();

  expect(component.form.getRawValue()).toEqual({
    name: 'Abena Osei',
    email: 'abena@example.com',
    phone: '0244000000',
    company_name: 'Abena Trading',
    address: 'Dzorwulu',
    city: 'Accra',
    country: 'Ghana',
    notes: 'Important customer',
  });
});
it('should update the customer with the edited form values', () => {
  customerApi.get.mockReturnValue(
    of({
      success: true,
      data: {
        id: 1,
        name: 'Abena Osei',
        email: 'abena@example.com',
        phone: '0244000000',
        company_name: 'Abena Trading',
        address: 'Dzorwulu',
        city: 'Accra',
        country: 'Ghana',
        notes: 'Important customer',
      },
    }),
  );

  customerApi.update = vi.fn().mockReturnValue(
    of({
      success: true,
      message: 'Customer updated successfully.',
      data: {
        id: 1,
        name: 'Abena Osei Updated',
      },
    }),
  );

  fixture.detectChanges();

  component.startEditing();

  component.form.patchValue({
    name: 'Abena Osei Updated',
    email: 'updated@example.com',
    phone: '0200000000',
    company_name: 'Updated Trading',
    address: 'East Legon',
    city: 'Accra',
    country: 'Ghana',
    notes: 'Updated customer notes',
  });

  component.updateCustomer();

  expect(customerApi.update).toHaveBeenCalledWith(1, {
    name: 'Abena Osei Updated',
    email: 'updated@example.com',
    phone: '0200000000',
    company_name: 'Updated Trading',
    address: 'East Legon',
    city: 'Accra',
    country: 'Ghana',
    notes: 'Updated customer notes',
  });
});
it('should update the displayed customer and exit edit mode after a successful update', () => {
  customerApi.get.mockReturnValue(
    of({
      success: true,
      data: {
        id: 1,
        name: 'Abena Osei',
        email: 'abena@example.com',
        phone: '0244000000',
        company_name: 'Abena Trading',
        address: 'Dzorwulu',
        city: 'Accra',
        country: 'Ghana',
        notes: 'Important customer',
      },
    }),
  );

  customerApi.update.mockReturnValue(
    of({
      success: true,
      message: 'Customer updated successfully.',
      data: {
        id: 1,
        name: 'Abena Osei Updated',
        email: 'updated@example.com',
        phone: '0200000000',
        company_name: 'Updated Trading',
        address: 'East Legon',
        city: 'Accra',
        country: 'Ghana',
        notes: 'Updated customer notes',
      },
    }),
  );

  fixture.detectChanges();

  component.startEditing();

  component.form.patchValue({
    name: 'Abena Osei Updated',
    email: 'updated@example.com',
    phone: '0200000000',
    company_name: 'Updated Trading',
    address: 'East Legon',
    city: 'Accra',
    country: 'Ghana',
    notes: 'Updated customer notes',
  });

  component.updateCustomer();

  expect(component.customer?.name).toBe('Abena Osei Updated');
  expect(component.customer?.email).toBe('updated@example.com');
  expect(component.customer?.company_name).toBe('Updated Trading');
  expect(component.customer?.address).toBe('East Legon');
  expect(component.isEditing).toBe(false);
});
});