
import { Component, OnInit, inject, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AlertController } from '@ionic/angular';
import { IonicModule} from '@ionic/angular/lazy';
import { ActivatedRoute, Router } from '@angular/router';
import { finalize } from 'rxjs';

import { EmployeeItemApiService } from
  '../../../core/services/employee-item-api';

import {
  EmployeeItemRequest,
  EmployeeItemRequestStatus,
} from '../../../core/services/employee-item-request.models';
import { AuthService } from '../../../core/services/auth.service';

@Component({
  selector: 'app-employee-item-request-details',
  standalone: true,
  templateUrl: './employee-item-request-details.component.html',
  styleUrls: ['./employee-item-request-details.component.scss'],
  imports: [
    CommonModule,
    FormsModule,
    IonicModule,
  ],
})
export class EmployeeItemRequestDetailsComponent implements OnInit {
  private readonly api = inject(EmployeeItemApiService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly alertController = inject(AlertController);
  private readonly auth = inject(AuthService);
  private readonly cdr = inject(ChangeDetectorRef);

  request: EmployeeItemRequest | null = null;

  loading = false;
  processing = false;

  approvedQuantities: Record<number, number> = {};

  ngOnInit(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));

    if (!Number.isInteger(id) || id <= 0) {
      this.router.navigate(['/employee-item-requests']);
      return;
    }

    this.loadRequest(id);
  }

  loadRequest(id?: number): void {
    const requestId = id ?? this.request?.id;

    if (!requestId) return;

    this.loading = true;

    this.api.get(requestId)
      .pipe(finalize(() => this.loading = false))
      .subscribe({
        next: response => {
          this.loading = false;
          this.request = response.data;

          this.approvedQuantities = {};

          for (const item of response.data.items ?? []) {
            this.approvedQuantities[item.id] =
              item.quantity_approved ?? item.quantity_requested;
          }

          this.cdr.detectChanges();
        },
        error: error => {
          this.loading = false;
          console.error('Failed to load item request', error);
          this.showError(
            error?.error?.message ||
            'Unable to load this employee item request.'
          );

          this.cdr.detectChanges();
        },
      });
  }

  get isPending(): boolean {
    return this.request?.status === 'pending';
  }

  getStatusLabel(status: EmployeeItemRequestStatus): string {
    const labels: Record<EmployeeItemRequestStatus, string> = {
      PENDING: 'Pending',
      APPROVED: 'Approved',
      PARTIALLY_APPROVED: 'Partially approved',
      REJECTED: 'Rejected',
      PARTIALLY_ISSUED: 'Partially issued',
      ISSUED: 'Issued',
      CANCELLED: 'Cancelled',
      pending: 'Pending',
      approved: 'Approved',
      partially_approved: 'Partially approved',
      rejected: 'Rejected',
      partially_issued: 'Partially issued',
      issued: 'Issued',
      cancelled: 'Cancelled',
    };

    return labels[status] ?? status;
  }

  getStatusColor(status: EmployeeItemRequestStatus): string {
    const colors: Record<EmployeeItemRequestStatus, string> = {
      PENDING: 'warning',
      APPROVED: 'success',
      PARTIALLY_APPROVED: 'tertiary',
      REJECTED: 'danger',
      PARTIALLY_ISSUED: 'secondary',
      ISSUED: 'success',
      CANCELLED: 'medium',
      pending: 'warning',
      approved: 'success',
      partially_approved: 'tertiary',
      rejected: 'danger',
      partially_issued: 'secondary',
      issued: 'success',
      cancelled: 'medium',
    };

    return colors[status] ?? 'medium';
  }

  getEmployeeName(): string {
    return this.request?.employee?.user?.name ?? 'Unknown employee';
  }

  getItemName(item: EmployeeItemRequest['items'][number]): string {
    return item.catalog_item?.name ?? `Catalog item #${item.catalog_item_id}`;
  }

  getTotalRequested(): number {
    return this.request?.items.reduce(
      (total, item) => total + Number(item.quantity_requested),
      0
    ) ?? 0;
  }

  getTotalApproved(): number {
    return this.request?.items.reduce(
      (total, item) => total + Number(item.quantity_approved ?? 0),
      0
    ) ?? 0;
  }

  approveRequest(): void {
    if (!this.request || !this.isPending || this.processing) return;

    const invalidQuantity = this.request.items.some(item => {
      const quantity = Number(this.approvedQuantities[item.id]);

      return (
        !Number.isInteger(quantity) ||
        quantity < 0 ||
        quantity > Number(item.quantity_requested)
      );
    });

    if (invalidQuantity) {
      this.showError(
        'Approved quantities must be whole numbers between zero and the requested quantity.'
      );
      this.processing = false;
      return;
    }

    const approvedQuantities: Record<number, number> = {};

    for (const item of this.request.items) {
      approvedQuantities[item.id] =
        Number(this.approvedQuantities[item.id]);
    }

    this.processing = true;

    this.api.approve(this.request.id, {
      approved_quantities: approvedQuantities,
    })
      .pipe(finalize(() => this.processing = false))
      .subscribe({
        next: async response => {
          this.processing = false;
          this.request = response.data;
          await this.showSuccess(
            response.message || 'Request processed successfully.'
          );
          this.loadRequest();

          this.cdr.detectChanges();
        },
        error: error => {
          this.processing = false;
          console.error('Failed to approve request', error);
          this.showError(
            error?.error?.message ||
            'Unable to approve this request.'
          );

          this.cdr.detectChanges();
        },
      });
  }

  async rejectRequest(): Promise<void> {
    if (!this.request || !this.isPending || this.processing) return;

    const alert = await this.alertController.create({
      header: 'Reject request',
      message: 'Please provide a reason for rejecting this request.',
      inputs: [
        {
          name: 'rejection_reason',
          type: 'textarea',
          placeholder: 'Enter rejection reason...',
          attributes: {
            maxlength: 1000,
          },
        },
      ],
      buttons: [
        {
          text: 'Cancel',
          cssClass: 'alert-button-secondary',
          role: 'cancel',
        },
        {
          text: 'Reject request',
          cssClass: 'alert-button-danger',
          role: 'confirm',
          handler: data => {
            const reason = String(data.rejection_reason ?? '').trim();

            if (!reason) {
              this.showError('A rejection reason is required.');
              return false;
            }

            this.submitRejection(reason);
            return true;
          },
        },
      ],
    });

    await alert.present();
  }

  private submitRejection(reason: string): void {
    if (!this.request) return;

    this.processing = true;

    this.api.reject(this.request.id, {
      rejection_reason: reason,
    })
      .pipe(finalize(() => this.processing = false))
      .subscribe({
        next: async response => {
          this.request = response.data;
          await this.showSuccess(
            response.message || 'Request rejected successfully.'
          );
          this.loadRequest();

          this.cdr.detectChanges();
        },
        error: error => {
          console.error('Failed to reject request', error);
          this.showError(
            error?.error?.message ||
            'Unable to reject this request.'
          );

          this.cdr.detectChanges();
        },
      });
  }

  backToRequests(): void {
    this.router.navigate(['/employees-item']);
  }

  async showError(message: string): Promise<void> {
    const alert = await this.alertController.create({
      header: 'Unable to continue',
      message,
      buttons: ['OK'],
    });

    await alert.present();
  }

  async showSuccess(message: string): Promise<void> {
    const alert = await this.alertController.create({
      header: 'Success',
      message,
      buttons: ['OK'],
    });

    await alert.present();
  }

  get canApprove(): boolean {
  return this.auth.hasPermission('employee_item_requests.approve');
}
}
