import { Component, OnInit, inject,ChangeDetectorRef } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { RouterLink } from '@angular/router';

import {
  DashboardApiService,
  DashboardActivityItem,
  DashboardActivityType,
} from '../../core/services/dashboard-api';

@Component({
  selector: 'app-activity',
  standalone: true,
  imports: [DatePipe, DecimalPipe, RouterLink],
  templateUrl: './activity.component.html',
  styleUrls: ['./activity.component.scss'],
})
export class ActivityComponent implements OnInit {
  private readonly dashboardApi = inject(DashboardApiService);
  private readonly changedetect = inject(ChangeDetectorRef);

  activities: DashboardActivityItem[] = [];
  selectedType: DashboardActivityType | 'all' = 'all';

  currentPage = 1;
  lastPage = 1;
  total = 0;
  loading = false;
  errorMessage = '';

  ngOnInit(): void {
    this.loadActivity(1);
  }

  loadActivity(page = 1): void {
    this.loading = true;
    this.errorMessage = '';

    this.dashboardApi.getActivity({
      page,
      perPage: 20,
      type: this.selectedType === 'all'
        ? undefined
        : this.selectedType,
    }).subscribe({
      next: response => {
        if (!response.success) {
          this.errorMessage = 'Could not load activity history.';
          this.loading = false;

          this.changedetect.detectChanges();
          return;
        }

        this.activities = response.data;
        this.currentPage = response.meta.current_page;
        this.lastPage = response.meta.last_page;
        this.total = response.meta.total;
        this.loading = false;

        this.changedetect.detectChanges();
      },
      error: error => {
        this.errorMessage =
          error?.error?.message ??
          'Could not load activity history. Please try again.';

        this.loading = false;

        this.changedetect.detectChanges();
      },
    });
  }

  onTypeChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    if (
      value === 'all' ||
      value === 'invoice' ||
      value === 'payment' ||
      value === 'expense'
    ) {
      this.selectedType = value;
      this.loadActivity(1);
    }
  }

  getTypeLabel(type: DashboardActivityType): string {
    switch (type) {
      case 'invoice':
        return 'Invoice';
      case 'payment':
        return 'Payment';
      case 'expense':
        return 'Expense';
    }
  }

  trackActivity(
    _index: number,
    activity: DashboardActivityItem
  ): string {
    return `${activity.type}-${activity.id}`;
  }
}