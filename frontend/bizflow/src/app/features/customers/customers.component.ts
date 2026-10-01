import { Component, OnInit,inject, OnDestroy,ChangeDetectorRef } from '@angular/core';
import { CustomerApi, Customer,CustomerPagination } from '../../core/services/customer-api';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, Subscription } from 'rxjs';
import { map } from 'rxjs/operators';
import { debounceTime, distinctUntilChanged } from 'rxjs/operators';

@Component({
  selector: 'app-customers',
  templateUrl: './customers.component.html',
  styleUrls: ['./customers.component.scss'],
  standalone: true,
  imports: [FormsModule],
})
export class CustomersComponent  implements OnInit {
 private readonly customerApi = inject(CustomerApi);
 private readonly searchSubject = new Subject<string>();
 private readonly searchSubscription: Subscription;
 private readonly router = inject(Router);
 private readonly changeDetector = inject(ChangeDetectorRef);

 readonly Math = Math;
 customers: Customer[] = [];
 pagination: CustomerPagination = {
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0,
};
search = '';
 
  constructor() { 
     this.searchSubscription = this.searchSubject
    .pipe(
      debounceTime(300),
      distinctUntilChanged(),
    )
    .subscribe(search => {
      this.loadCustomers(1, search);
    });
  }

  ngOnInit(): void {
    this.loadCustomers();
  }

  private loadCustomers( page = 1,search = this.search): void 
  {
    this.customerApi.list({page,search,perPage: 15,}).subscribe({
        next: response => {
        this.customers = response.data;
        //console.log(this.customers);
        this.pagination = response.meta;
        this.changeDetector.detectChanges();
      },
    });
  }

  loadPage(page: number): void {
    this.loadCustomers(page, this.search);
  }

  searchCustomers(): void {
   this.searchSubject.next(this.search);
  }

  addCustomer(): void {
    this.router.navigate(['/customers/add']);
  }

  viewCustomer(id: any): void {
    this.router.navigate(['/customers', id]);
  }

  ngOnDestroy(): void {
    this.searchSubscription.unsubscribe();
  }

}
