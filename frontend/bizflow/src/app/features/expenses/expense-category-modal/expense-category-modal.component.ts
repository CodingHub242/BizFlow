import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Output } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs';

import {
  Category,
  CategoryApi,
} from '../../../core/services/category-api';

@Component({
  selector: 'app-expense-category-modal',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './expense-category-modal.component.html',
  styleUrl: './expense-category-modal.component.scss',
})
export class ExpenseCategoryModalComponent {
  @Output() created = new EventEmitter<Category>();
  @Output() cancelled = new EventEmitter<void>();

  name = '';
  description = '';
  saving = false;
  errorMessage = '';

  constructor(private readonly categoryApi: CategoryApi) {}

  submit(): void {
    const name = this.name.trim();

    if (!name) {
      this.errorMessage = 'Enter a category name.';
      return;
    }

    this.errorMessage = '';
    this.saving = true;

    this.categoryApi.create({
      name,
      description: this.description.trim() || null,
      is_active: true,
    })
      .pipe(finalize(() => (this.saving = false)))
      .subscribe({
        next: (response) => {
          this.created.emit(response.data);
        },
        error: (error) => {
          this.errorMessage =
            error.error?.message ||
            error.error?.errors?.name?.[0] ||
            'Unable to create category. Please try again.';
        },
      });
  }
}