
import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, OnChanges, Output, SimpleChanges,inject,ChangeDetectorRef, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs';
import {
  ExpenseCategoryModalComponent,
} from '../expense-category-modal/expense-category-modal.component';
import { CategoryApi, Category } from '../../../core/services/category-api';
import { ExpenseApi, Expense, ExpensePayload, ExpensePaymentMethod } from '../../../core/services/expense-api';
import { BranchApi } from '../../../core/services/branch-api';
import { SearchableSelectComponent, SearchableSelectOption } from '../../../shared/components/searchable-select/searchable-select.component';

@Component({
  selector: 'app-expense-form',
  standalone: true,
  imports: [CommonModule, FormsModule, SearchableSelectComponent,ExpenseCategoryModalComponent],
  templateUrl: './expense-form.component.html',
  styleUrl: './expense-form.component.scss',
})
export class ExpenseFormComponent implements OnInit, OnChanges {
  @Input() expense: Expense | null = null;
  @Output() saved = new EventEmitter<void>();
  @Output() cancelled = new EventEmitter<void>();
  private readonly cdr = inject(ChangeDetectorRef);
  showCategoryModal = false;

  branchId: number | null = null;
  categoryId: number | null = null;
  amount: number | null = null;
  paymentMethod: ExpensePaymentMethod = 'cash';
  reference = '';
  expenseDate = new Date().toISOString().slice(0, 10);
  description = '';
  notes = '';

  branchOptions: SearchableSelectOption[] = [];
  loadingBranches = false;
  saving = false;
  errorMessage = '';

  categoryOptions: SearchableSelectOption[] = [];
  loadingCategories = false;

  readonly paymentMethods: { value: ExpensePaymentMethod; label: string }[] = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'mobile_money', label: 'Mobile money' },
    { value: 'card', label: 'Card' },
    { value: 'other', label: 'Other' },
  ];

  constructor(
    private readonly expenseApi: ExpenseApi,
    private readonly branchApi: BranchApi,
    private readonly categoryApi: CategoryApi,
  ) {}

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['expense']) {
      this.populateForm();
    }
  }

  private populateForm(): void {
    if (!this.expense) {
      this.branchId = null;
      this.categoryId = null;
      this.amount = null;
      this.paymentMethod = 'cash';
      this.reference = '';
      this.expenseDate = new Date().toISOString().slice(0, 10);
      this.description = '';
      this.notes = '';
      this.errorMessage = '';
      return;
    }

    this.branchId = this.expense.branch_id;
    this.categoryId = this.expense.category_id;
    this.amount = Number(this.expense.amount);
    this.paymentMethod = this.expense.payment_method;
    this.reference = this.expense.reference ?? '';
    this.expenseDate = String(this.expense.expense_date).slice(0, 10);
    this.description = this.expense.description;
    this.notes = this.expense.notes ?? '';
    this.errorMessage = '';
  }

  ngOnInit(): void {
    this.loadBranches();
    this.loadCategories();
  }

  loadBranches(): void {
    this.loadingBranches = true;

    this.branchApi.list({ isActive: true, perPage: 100 })
      .pipe(finalize(() => this.loadingBranches = false))
      .subscribe({
        next: response => {
          this.branchOptions = response.data.map(branch => ({
            value: branch.id,
            label: branch.name,
          })
          
        );

        this.loadingBranches = false;
        this.cdr.detectChanges();
        
        },
        error: () => {
          this.errorMessage = 'Unable to load branches.';
          this.loadingBranches = false;

        },
      });
  }

  loadCategories(): void {
  this.loadingCategories = true;

  this.categoryApi.list()
    .pipe(finalize(() => this.loadingCategories = false))
    .subscribe({
      next: response => {
        this.categoryOptions = response.data.map(category => ({
          value: category.id,
          label: category.name,
          description: category.description ?? undefined,
        }));

        this.loadingCategories = false;
        this.cdr.detectChanges();
      },
      error: () => {
        this.errorMessage = 'Unable to load expense categories.';

        this.loadingCategories = false;
        this.cdr.detectChanges();
      },
    });
}

 submit(): void {
  this.errorMessage = '';

  if (this.branchId === null) {
    this.errorMessage = 'Please select a branch.';
    return;
  }

  const amount = Number(this.amount);

  if (
    this.amount === null ||
    !Number.isFinite(amount) ||
    amount <= 0
  ) {
    this.errorMessage = 'Enter an amount greater than zero.';
    return;
  }

  const description = this.description.trim();

  if (!description) {
    this.errorMessage = 'Description is required.';
    return;
  }

  const payload: ExpensePayload = {
    branch_id: this.branchId,
    category_id: this.categoryId ?? null,
    amount,
    payment_method: this.paymentMethod,
    reference: this.reference.trim() || null,
    expense_date: this.expenseDate,
    description,
    notes: this.notes.trim() || null,
  };

  this.saving = true;

  const request = this.expense
    ? this.expenseApi.update(this.expense.id, payload)
    : this.expenseApi.create(payload);

  request
    .pipe(finalize(() => (this.saving = false)))
    .subscribe({
      next: () => {
        this.saved.emit();
      },
      error: (error) => {
        const validationErrors = Object.values(
          error?.error?.errors ?? {}
        )
          .flat()
          .filter((message): message is string =>
            typeof message === 'string'
          )
          .join(' ');

        this.errorMessage =
          error?.error?.message ||
          validationErrors ||
          'Unable to save expense. Please try again.';
      },
    });
}

  openCategoryModal(): void {
  this.errorMessage = '';
  this.showCategoryModal = true;
}

closeCategoryModal(): void {
  this.showCategoryModal = false;
}

onCategoryCreated(category: Category): void {
  // Add the new category to the existing dropdown options.
  this.categoryOptions = [
    ...this.categoryOptions.filter(
      option => option.value !== category.id
    ),
    {
      value: category.id,
      label: category.name,
      description: category.description ?? undefined,
    },
  ].sort((a, b) => a.label.localeCompare(b.label));

  // Select it automatically.
  this.categoryId = category.id;

  // Close the modal without resetting the expense form.
  this.showCategoryModal = false;
}
}
