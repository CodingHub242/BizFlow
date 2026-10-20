
import { CommonModule } from '@angular/common';
import { Component, OnInit,inject,ChangeDetectorRef } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs';

import {
  ExpenseReportApi,
  ExpenseSummary,
  CategoryExpenseReport,
  BranchExpenseReport,
  PaymentMethodExpenseReport,
} from '../../core/services/expense-report-api';

@Component({
  selector: 'app-reports',
  standalone: true,
  templateUrl: './reports.component.html',
  styleUrls: ['./reports.component.scss'],
  imports: [CommonModule, FormsModule],
})
export class ReportsComponent implements OnInit {
  private readonly cdr = inject(ChangeDetectorRef);
  loading = false;
  errorMessage = '';

  dateFrom = '';
  dateTo = '';

  summary: ExpenseSummary | null = null;
  categories: CategoryExpenseReport[] = [];
  branches: BranchExpenseReport[] = [];
  paymentMethods: PaymentMethodExpenseReport[] = [];

  constructor(private readonly reportApi: ExpenseReportApi) {}

  ngOnInit(): void {
    this.loadReports();
  }

  loadReports(): void {
    this.errorMessage = '';

    if (this.dateFrom && this.dateTo && this.dateFrom > this.dateTo) {
      this.errorMessage = 'The start date must be before the end date.';
      return;
    }

    this.loading = true;

    this.reportApi.loadAll({
      date_from: this.dateFrom || undefined,
      date_to: this.dateTo || undefined,
    })
      .pipe(finalize(() => (this.loading = false)))
      .subscribe({
        next: response => {
          this.summary = response.summary.data;
          this.categories = response.categories.data;
          this.branches = response.branches.data;
          this.paymentMethods = response.paymentMethods.data;

          this.loading = false;

          this.cdr.detectChanges();
        },
        error: error => {
          console.error('Unable to load expense reports:', error);
          this.errorMessage =
            error?.error?.message ||
            'Unable to load expense reports. Check your access and try again.';

             this.cdr.detectChanges();
        },
      });
  }

  get categoryMax(): number {
    return Math.max(0, ...this.categories.map(item => Number(item.total)));
  }

  get branchMax(): number {
    return Math.max(0, ...this.branches.map(item => Number(item.total)));
  }

  get paymentMax(): number {
    return Math.max(0, ...this.paymentMethods.map(item => Number(item.total)));
  }

  barWidth(value: number, max: number): number {
    return max > 0 ? Math.max(0, Math.min(100, (Number(value) / max) * 100)) : 0;
  }

  money(value: number | string | null | undefined): string {
    return new Intl.NumberFormat('en-GH', {
      style: 'currency',
      currency: 'GHS',
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(Number(value ?? 0));
  }

  paymentLabel(value: string): string {
    const labels: Record<string, string> = {
      cash: 'Cash',
      bank_transfer: 'Bank transfer',
      mobile_money: 'Mobile money',
      card: 'Card',
      other: 'Other',
    };

    return labels[value] ?? value;
  }

  get comparisonLabel(): string {
    if (!this.summary) return '';

    switch (this.summary.comparison) {
      case 'increased':
        return 'Higher than last month';
      case 'decreased':
        return 'Lower than last month';
      case 'unchanged':
        return 'Same as last month';
      default:
        return 'No previous-month baseline';
    }
  }

  exportCsv(): void {
    if (!this.summary) return;

    const rows: (string | number)[][] = [
      ['Bizflow Expense Report'],
      ['Generated at', new Date().toISOString()],
      ['Date from', this.dateFrom || 'All dates'],
      ['Date to', this.dateTo || 'All dates'],
      [],
      ['Expense summary', 'Amount (GHS)'],
      ['Total expenses', this.summary.total_expenses],
      ['Current month', this.summary.current_month],
      ['Previous month', this.summary.previous_month],
      ['Change percentage', this.summary.change_percentage ?? 'N/A'],
      [],
      ['Category', 'Expense count', 'Total (GHS)'],
      ...this.categories.map(item => [
        item.category_name, item.expense_count, item.total,
      ]),
      [],
      ['Branch', 'Expense count', 'Total (GHS)'],
      ...this.branches.map(item => [
        item.branch_name, item.expense_count, item.total,
      ]),
      [],
      ['Payment method', 'Expense count', 'Total (GHS)'],
      ...this.paymentMethods.map(item => [
        this.paymentLabel(item.payment_method),
        item.expense_count,
        item.total,
      ]),
    ];

    const csv = rows
      .map(row => row.map(value => this.csvCell(value)).join(','))
      .join('\r\n');

    const blob = new Blob(['\uFEFF' + csv], {
      type: 'text/csv;charset=utf-8;',
    });

    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');

    anchor.href = url;
    anchor.download = `bizflow-expense-report-${new Date()
      .toISOString().slice(0, 10)}.csv`;

    anchor.click();
    URL.revokeObjectURL(url);
  }

  private csvCell(value: string | number): string {
    // Prevent CSV formula injection in user-controlled text fields.
    let text = String(value);

    if (/^[\s]*[=+\-@]/.test(text)) {
      text = `'${text}`;
    }

    return `"${text.replace(/"/g, '""')}"`;
  }
}
