import { Component, OnInit, inject,ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { IonicModule } from '@ionic/angular/lazy';
import {
  //IonicModule,
  AlertController,
} from '@ionic/angular';
import { Router } from '@angular/router';
import { finalize } from 'rxjs';

import { EmployeeItemApiService } from
  '../../../core/services/employee-item-api';

import {
  EmployeeItemRequest,
  EmployeeItemRequestStatus,
  EmployeeItemRequestPagination,
} from '../../../core/services/employee-item-request.models';
import { addIcons } from 'ionicons';
import {
  arrowDownOutline,
  addOutline,
  refreshOutline,
  cubeOutline,
  businessOutline,
  layersOutline,
  documentTextOutline,
  calendarOutline,
  chevronForwardOutline,
  chevronBackOutline,
  closeCircleOutline,
  fileTrayOutline,
  peopleOutline,
  cardOutline,
  receiptOutline,
  walletOutline,
  alertCircleOutline,
  arrowForwardOutline,
  ellipsisHorizontalOutline,
} from 'ionicons/icons';


@Component({
  selector: 'app-employees-item-request',
  templateUrl: './employees-item-request.component.html',
  styleUrls: ['./employees-item-request.component.scss'],
  imports: [
    CommonModule,
    FormsModule,
    IonicModule,
  ],
})
export class EmployeesItemRequestComponent  implements OnInit {
 private api = inject(EmployeeItemApiService);
  private router = inject(Router);
  private alertController = inject(AlertController);
  private cdr = inject(ChangeDetectorRef);

  requests: EmployeeItemRequest[] = [];

  loading = false;
  search = '';
  selectedStatus = '';

  pagination: EmployeeItemRequestPagination = {
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
  };

  statuses: {
    value: EmployeeItemRequestStatus;
    label: string;
  }[] = [
    { value: 'PENDING', label: 'Pending' },
    { value: 'APPROVED', label: 'Approved' },
    { value: 'PARTIALLY_APPROVED', label: 'Partially Approved' },
    { value: 'REJECTED', label: 'Rejected' },
    { value: 'PARTIALLY_ISSUED', label: 'Partially Issued' },
    { value: 'ISSUED', label: 'Issued' },
    { value: 'CANCELLED', label: 'Cancelled' },
  ];

   constructor() { 
     addIcons({
      arrowDownOutline,
      refreshOutline,
      addOutline,
      closeCircleOutline,
      chevronForwardOutline,
      chevronBackOutline,
      businessOutline,
      layersOutline,
      calendarOutline,
      cubeOutline,
      cardOutline,
      receiptOutline,
      fileTrayOutline,
      documentTextOutline,
      peopleOutline,
      walletOutline,
      alertCircleOutline,
      arrowForwardOutline,
      ellipsisHorizontalOutline,
    });
  }


  ngOnInit(): void {
    this.loadRequests();
  }

  loadRequests(page = 1): void {
    this.loading = true;

    this.api.list({
      page,
      per_page: this.pagination.per_page,
      search: this.search.trim() || undefined,
      status: this.selectedStatus || undefined,
    })
      .pipe(finalize(() => this.loading = false))
      .subscribe({
        next: response => {
          this.loading = false;
          this.requests = response.data ?? [];
          this.pagination = response.meta;

          this.cdr.detectChanges();
        },
        error: error => {
          this.loading = false;
          console.error('Failed to load employee item requests', error);
          this.showError(
            error?.error?.message ||
            'Unable to load employee item requests.'
          );
          this.cdr.detectChanges();
        },
      });
  }

  onSearchChange(): void {
    this.loadRequests(1);
  }

  onStatusChange(): void {
    this.loadRequests(1);
  }

  clearFilters(): void {
    this.search = '';
    this.selectedStatus = '';
    this.loadRequests(1);
  }

  previousPage(): void {
    if (this.pagination.current_page > 1) {
      this.loadRequests(this.pagination.current_page - 1);
    }
  }

  nextPage(): void {
    if (this.pagination.current_page < this.pagination.last_page) {
      this.loadRequests(this.pagination.current_page + 1);
    }
  }

  getStatusLabel(status: string): string {
    return this.statuses.find(item => item.value === status)?.label
      ?? status.replaceAll('_', ' ');
  }

  getStatusColor(status: string): string {
    const colors: Record<string, string> = {
      PENDING: 'warning',
      APPROVED: 'success',
      PARTIALLY_APPROVED: 'tertiary',
      REJECTED: 'danger',
      PARTIALLY_ISSUED: 'secondary',
      ISSUED: 'success',
      CANCELLED: 'medium',
    };

    return colors[status] ?? 'medium';
  }

  getEmployeeName(request: EmployeeItemRequest): string {
    return request.employee?.user?.name ?? 'Unknown employee';
  }

  getItemCount(request: EmployeeItemRequest): number {
    return request.items?.length ?? 0;
  }

  openRequest(request: EmployeeItemRequest): void {
    this.router.navigate([
      '/employee-item-requests',
      request.id,
    ]);
  }

  createRequest(): void {
    this.router.navigate(['/employees-item-request/create']);
  }

  async showError(message: string): Promise<void> {
    const alert = await this.alertController.create({
      header: 'Error',
      message,
      buttons: ['OK'],
    });

    await alert.present();
  }
}