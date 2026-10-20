
import { CommonModule } from '@angular/common';
import { Component, OnInit, inject,ChangeDetectorRef } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  Expense,
  ExpenseApi,
  ExpensePaymentMethod,
} from '../../core/services/expense-api';
import {
  SearchableSelectComponent,
  SearchableSelectOption,
} from '../../shared/components/searchable-select/searchable-select.component';
import { BranchApi } from '../../core/services/branch-api';
import { ExpenseFormComponent } from './expense-form/expense-form.component';

@Component({
  selector: 'app-expenses',
  templateUrl: './expenses.component.html',
  styleUrls: ['./expenses.component.scss'],
  standalone: true,
  imports: [CommonModule, FormsModule,ExpenseFormComponent,SearchableSelectComponent],
})
export class ExpensesComponent implements OnInit {
  private readonly cdr = inject(ChangeDetectorRef);
  expenses: Expense[] = [];
  branchId: number | null = null;
  branchOptions: SearchableSelectOption[] = [];
  loadingBranches = false;
  branchError = '';

  loading = false;
  deletingId: number | null = null;
  errorMessage = '';
  successMessage = '';

  search = '';
  paymentMethod: ExpensePaymentMethod | '' = '';
  dateFrom = '';
  dateTo = '';

  currentPage = 1;
  lastPage = 1;
  perPage = 15;
  total = 0;

  readonly paymentMethods: {
    value: ExpensePaymentMethod;
    label: string;
  }[] = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'mobile_money', label: 'Mobile money' },
    { value: 'card', label: 'Card' },
    { value: 'other', label: 'Other' },
  ];

  constructor(private readonly expenseApi: ExpenseApi,private readonly branchApi: BranchApi) {}

  ngOnInit(): void {
    this.loadExpenses();
    this.loadBranches();
  }

  loadBranches(): void {
    this.loadingBranches = true;
    this.branchError = '';

    this.branchApi.list({ isActive: true, perPage: 100 }).subscribe({
      next: (response) => {
        this.branchOptions = response.data.map((branch) => ({
          value: branch.id,
          label: branch.name,
          description: branch.code || undefined,
        }));

        this.loadingBranches = false;

        this.cdr.markForCheck();
      },
      error: () => {
        this.branchError = 'Unable to load branches.';
        this.loadingBranches = false;

        this.cdr.markForCheck();
      },
    });
  }

  loadExpenses(page = 1): void {
    this.loading = true;
    this.errorMessage = '';

    this.expenseApi.list({
      page,
      per_page: this.perPage,
      search: this.search.trim() || undefined,
      payment_method: this.paymentMethod,
      date_from: this.dateFrom || undefined,
      date_to: this.dateTo || undefined,
    }).subscribe({
      next: (response) => {
        this.expenses = response.data ?? [];
        this.currentPage = response.meta?.current_page ?? page;
        this.lastPage = response.meta?.last_page ?? 1;
        this.total = response.meta?.total ?? this.expenses.length;
        this.loading = false;

         this.cdr.detectChanges();
      },
      error: (error) => {
        this.loading = false;
        this.errorMessage =
          error?.error?.message ?? 'Unable to load expenses.';

          this.cdr.detectChanges();
      },
    });
  }

  applyFilters(): void {
    this.loadExpenses(1);
  }

  clearFilters(): void {
    this.search = '';
    this.paymentMethod = '';
    this.dateFrom = '';
    this.dateTo = '';
    this.loadExpenses(1);
  }

  changePage(page: number): void {
    if (page < 1 || page > this.lastPage || this.loading) {
      return;
    }

    this.loadExpenses(page);
  }

 deleteExpense(expense: Expense): void {
  const confirmed = window.confirm(
    `Delete the expense "${expense.description}"? This action cannot be undone.`
  );

  if (!confirmed) {
    return;
  }

  this.expenseApi.delete(expense.id).subscribe({
    next: () => {
      this.loadExpenses();
    },
    error: (error) => {
      console.error('Failed to delete expense:', error);
      this.errorMessage =
        error.error?.message || 'Unable to delete expense.';
    },
  });
}

  formatPaymentMethod(method: ExpensePaymentMethod): string {
    return this.paymentMethods.find(
      (item) => item.value === method,
    )?.label ?? method;
  }

  showForm = false;
editingExpense: Expense | null = null;

openCreateForm(): void {
  this.editingExpense = null;
  this.showForm = true;
}

openEditForm(expense: Expense): void {
  this.editingExpense = expense;
  this.showForm = true;
}

onExpenseSaved(): void {
  this.showForm = false;
  this.editingExpense = null;
  this.loadExpenses(1);
}

closeExpenseForm(): void {
  this.showForm = false;
  this.editingExpense = null;
}


}
