import { CommonModule } from '@angular/common';
import {ChangeDetectorRef,Component,DestroyRef,OnInit,inject} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs';
import { BranchApi, Branch } from '../../core/services/branch-api';
import { SearchableSelectComponent, SearchableSelectOption } from '../../shared/components/searchable-select/searchable-select.component';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import {
  EmployeesApiService,
  Employee,
  CreateEmployeeAccountRequest,
} from '../../core/services/employees-api.service';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-employees',
  standalone: true,
  templateUrl: './employees.component.html',
  styleUrls: ['./employees.component.scss'],
  imports: [CommonModule,FormsModule,SearchableSelectComponent,RouterLink],
})
export class EmployeesComponent implements OnInit {
  private readonly api = inject(EmployeesApiService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly branchApi = inject(BranchApi);
  

branchOptions: SearchableSelectOption[] = [];
loadingBranches = false;
branchErrorMessage = '';

  employees: Employee[] = [];
  filteredEmployees: Employee[] = [];

  loading = false;
  saving = false;
  showCreateForm = false;

  search = '';
  errorMessage = '';
  successMessage = '';

  currentPage = 1;
  lastPage = 1;
  total = 0;

  form: CreateEmployeeAccountRequest = this.emptyForm();

  ngOnInit(): void {
    this.loadEmployees();
     this.loadBranches();
  }

  loadBranches(): void {
  this.loadingBranches = true;

  this.branchApi.list({
    page: 1,
    isActive: true,
    perPage: 100,
  }).pipe(
    finalize(() => {
      this.loadingBranches = false;
    }),
    takeUntilDestroyed(this.destroyRef),
  ).subscribe({
    next: (response) => {
      this.branchOptions = response.data.map((branch: Branch) => ({
        value: branch.id,
        label: branch.name,
        description: branch.code,
      }));
      this.cdr.detectChanges();
    },
    error: () => {
      this.branchErrorMessage = 'Unable to load branches. You can still create an employee without assigning a branch.';
      this.cdr.detectChanges();
    },
  });
}

  private emptyForm(): CreateEmployeeAccountRequest {
    return {
      name: '',
      email: '',
      password: '',
      employee_number: '',
      branch_id: null,
      phone: null,
      job_title: null,
      employment_status: 'active',
      hired_at: null,
    };
  }

  loadEmployees(page = 1): void {
    this.loading = true;
    this.errorMessage = '';

    this.api.list(page)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.loading = false;
          this.cdr.markForCheck();
        })
      )
      .subscribe({
        next: response => {
          this.employees = response.data;
          this.currentPage = response.meta.current_page;
          this.lastPage = response.meta.last_page;
          this.total = response.meta.total;
          this.applySearch();

          this.cdr.detectChanges();
        },
        error: error => {
          this.errorMessage =
            error?.error?.message ??
            'Unable to load employees.';

            this.cdr.detectChanges();
        },
      });
  }

  applySearch(): void {
  const term = this.search.trim().toLowerCase();

  if (!term) {
    this.filteredEmployees = [...this.employees];
    return;
  }

  this.filteredEmployees = this.employees.filter(employee =>
    [
      employee.name,
      employee.email,
      employee.employee_number,
      employee.job_title,
      employee.branch_name,
      employee.employment_status,
    ].some(value => value?.toLowerCase().includes(term)),
  );
}

  createEmployee(): void {
    if (this.saving) return;

    this.saving = true;
    this.errorMessage = '';
    this.successMessage = '';

   const payload: CreateEmployeeAccountRequest = {
    ...this.form,
    name: this.form.name.trim(),
    email: this.form.email.trim(),
    employee_number: this.form.employee_number.trim(),
    branch_id: this.form.branch_id || null,
    phone: this.form.phone?.trim() || null,
    job_title: this.form.job_title?.trim() || null,
    hired_at: this.form.hired_at || null,
  };

    this.api.createAccount(payload)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.saving = false;
          this.cdr.detectChanges();
        })
      )
      .subscribe({
        next: response => {
          this.successMessage = response.message;
          this.showCreateForm = false;
          this.form = this.emptyForm();
          this.cdr.detectChanges();

          this.loadEmployees(this.currentPage);
        },
        error: error => {
          const validationErrors = error?.error?.errors;
          this.errorMessage = validationErrors
            ? Object.values(validationErrors).flat().join(' ')
            : error?.error?.message ??
              'Unable to create employee account.';

              this.cdr.detectChanges();
        },
      });
  }
}
