import { Component, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { CustomerApi } from '../../../core/services/customer-api';
import { Router } from '@angular/router';

@Component({
  selector: 'app-add-customer',
  standalone: true,
  imports: [ReactiveFormsModule],
  templateUrl: './add-customer.component.html',
  styleUrl: './add-customer.component.scss',
})
export class AddCustomerComponent {
  private readonly formBuilder = inject(FormBuilder);
  private readonly customerApi = inject(CustomerApi);
  private readonly router = inject(Router);

  readonly form = this.formBuilder.group({
    name: ['', Validators.required],
    email: [''],
    phone: [''],
    company_name: [''],
    address: [''],
    city: [''],
    country: [''],
    notes: [''],
  });

    submit(): void {
        if (this.form.invalid) {
            this.form.markAllAsTouched();
            return;
        }

        const value = this.form.getRawValue();

        this.customerApi.create({
            name: value.name ?? '',
            email: value.email,
            phone: value.phone,
            company_name: value.company_name,
            address: value.address,
            city: value.city,
            country: value.country,
            notes: value.notes,
        }).subscribe({
            next: () => {
                this.router.navigate(['/customers']);
            },
        });
    }
}