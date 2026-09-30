import { ComponentFixture, TestBed } from '@angular/core/testing';
import { AddCustomerComponent } from './add-customer.component';
import { Router } from '@angular/router';
import { CustomerApi } from '../../../core/services/customer-api';

describe('AddCustomerComponent', () => {
  let fixture: ComponentFixture<AddCustomerComponent>;
  let component: AddCustomerComponent;
  let customerApi: { create: ReturnType<typeof vi.fn> };
  let router: { navigate: ReturnType<typeof vi.fn> };

  beforeEach(async () => {
   
    customerApi = {
      create: vi.fn(),
    };

    router = {
      navigate: vi.fn(),
    };

    await TestBed.configureTestingModule({
      imports: [AddCustomerComponent],
      providers: [
        {
          provide: CustomerApi,
          useValue: customerApi,
        },
        { provide: Router, useValue: router },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(AddCustomerComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should submit the entered customer information', () => {
    customerApi.create.mockReturnValue({
      subscribe: vi.fn(),
    });

    component.form.setValue({
      name: 'John Doe',
      email: 'john@example.com',
      phone: '0244000000',
      company_name: 'John Trading',
      address: 'Accra',
      city: 'Accra',
      country: 'Ghana',
      notes: 'Important customer',
    });

    component.submit();

    expect(customerApi.create).toHaveBeenCalledWith({
      name: 'John Doe',
      email: 'john@example.com',
      phone: '0244000000',
      company_name: 'John Trading',
      address: 'Accra',
      city: 'Accra',
      country: 'Ghana',
      notes: 'Important customer',
    });
  });
  it('should not create a customer when the name is missing', () => {
  component.form.setValue({
    name: '',
    email: 'john@example.com',
    phone: '0244000000',
    company_name: 'John Trading',
    address: 'Accra',
    city: 'Accra',
    country: 'Ghana',
    notes: '',
  });

  component.submit();

  expect(customerApi.create).not.toHaveBeenCalled();
  expect(component.form.controls.name.touched).toBe(true);
  expect(component.form.controls.name.invalid).toBe(true);
});
it('should navigate to customers after successful creation', () => {
  customerApi.create.mockReturnValue({
    subscribe: (callbacks: {
      next: (response: unknown) => void;
    }) => {
      callbacks.next({
        success: true,
        message: 'Customer created successfully.',
        data: {
          id: 1,
          name: 'John Doe',
        },
      });

      return {
        unsubscribe: vi.fn(),
      };
    },
  });

  component.form.setValue({
    name: 'John Doe',
    email: 'john@example.com',
    phone: '0244000000',
    company_name: 'John Trading',
    address: 'Accra',
    city: 'Accra',
    country: 'Ghana',
    notes: 'Important customer',
  });

  component.submit();

  expect(router.navigate).toHaveBeenCalledWith(['/customers']);
});
});
