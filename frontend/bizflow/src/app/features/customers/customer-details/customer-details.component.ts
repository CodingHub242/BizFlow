import { Component, OnInit, inject,ChangeDetectorRef } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { Router } from '@angular/router';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Customer, CustomerApi } from '../../../core/services/customer-api';

@Component({
  selector: 'app-customer-details',
  standalone: true,
  imports: [ReactiveFormsModule],
  templateUrl: './customer-details.component.html',
  styleUrl: './customer-details.component.scss',
})
export class CustomerDetailsComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly customerApi = inject(CustomerApi);
  private readonly router = inject(Router);
  private readonly formBuilder = inject(FormBuilder);
  private readonly changeDetector = inject(ChangeDetectorRef);
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

  customer: Customer | null = null;
  isEditing = false;

  ngOnInit(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));

    console.log(id);

    if (!Number.isNaN(id) && id > 0) {
      this.customerApi.get(id).subscribe({
        next: (response) => {
          this.customer = response.data;
          this.changeDetector.detectChanges();
          console.log(this.customer);
        },
      });
    }
  }

  backToCustomers(): void {
    this.router.navigate(['/customers']);
  }

startEditing(): void {
  if (this.customer) {
    this.form.patchValue({
      name: this.customer.name,
      email: this.customer.email ?? '',
      phone: this.customer.phone ?? '',
      company_name: this.customer.company_name ?? '',
      address: this.customer.address ?? '',
      city: this.customer.city ?? '',
      country: this.customer.country ?? '',
      notes: this.customer.notes ?? '',
    });
  }

  this.isEditing = true;
}

cancelEditing(): void {
  this.isEditing = false;
}

  updateCustomer(): void {
      if (!this.customer || this.form.invalid) {
        this.form.markAllAsTouched();
        return;
      }

      const value = this.form.getRawValue();

    this.customerApi.update(this.customer.id, {
      name: value.name ?? '',
      email: value.email,
      phone: value.phone,
      company_name: value.company_name,
      address: value.address,
      city: value.city,
      country: value.country,
      notes: value.notes,
    }).subscribe({
      next: (response) => {
        this.customer = response.data;
        this.isEditing = false;
        this.changeDetector.detectChanges();
      },
    });
  }
}