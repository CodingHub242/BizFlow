import {ChangeDetectorRef,Component,DestroyRef,OnInit,inject,signal} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import {RoleApi,Role,AssignEmployeeRoleRequest} from '../../../core/services/role-api';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {EmployeesApiService,Employee} from '../../../core/services/employees-api.service';
import {BranchApi,Branch} from '../../../core/services/branch-api';
import {SearchableSelectComponent,SearchableSelectOption} from '../../../shared/components/searchable-select/searchable-select.component';
import { AuthService,AuthUser } from '../../../core/services/auth.service';

@Component({
  selector: 'app-employee-details',
  standalone: true,
  templateUrl: './employee-details.component.html',
  styleUrls: ['./employee-details.component.scss'],
  imports: [
    CommonModule,
    FormsModule,
    SearchableSelectComponent,
  ],
})
export class EmployeeDetailsComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly api = inject(EmployeesApiService);
  private readonly branchApi = inject(BranchApi);
  private readonly destroyRef = inject(DestroyRef);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly roleApi = inject(RoleApi);
  private readonly authService = inject(AuthService);
   readonly currentUser = signal<AuthUser | null>(null);
  //private readonly routerLink = inject(RouterLink);

  roles: Role[] = [];
  selectedRole = '';
  currentRole = '';

  loadingRoles = false;
  savingRole = false;

  roleErrorMessage = '';
  roleSuccessMessage = '';
 

  employee: Employee | null = null;
  branchOptions: SearchableSelectOption[] = [];

  loading = false;
  saving = false;
  loadingBranches = false;
  editMode = false;

  errorMessage = '';
  branchErrorMessage = '';
  successMessage = '';

  form = {
    branch_id: null as number | null,
    employee_number: '',
    phone: null as string | null,
    job_title: null as string | null,
    employment_status: 'active' as
      | 'active'
      | 'inactive'
      | 'suspended',
    hired_at: null as string | null,
  };

  ngOnInit(): void {
    this.loadBranches();

    this.route.paramMap
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(params => {
        const id = Number(params.get('id'));

        if (!Number.isInteger(id) || id <= 0) {
          this.errorMessage = 'Invalid employee ID.';
          return;
        }

        this.loadEmployee(id);
      });

      this.loadRoles();

       this.authService.currentUser$
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(user => {
        this.currentUser.set(user);
      });
  }

  loadRoles(): void {
  this.loadingRoles = true;
  this.roleErrorMessage = '';

  this.roleApi
    .list()
    .pipe(
      takeUntilDestroyed(this.destroyRef),
      finalize(() => {
        this.loadingRoles = false;
        this.cdr.detectChanges();
      }),
    )
    .subscribe({
      next: response => {
        // Owner and Administrator are protected system roles
        // and must not be assignable through this screen.
        this.roles = response.data.filter(
          role =>
            role.name !== 'Owner' &&
            role.name !== 'Administrator',
        );

        this.cdr.detectChanges();
      },
      error: error => {
        this.roleErrorMessage =
          error?.error?.message ?? 'Unable to load roles.';

        this.cdr.detectChanges();
      },
    });
}

saveRole(): void {
  if (
    !this.employee ||
    !this.selectedRole ||
    this.savingRole
  ) {
    return;
  }

  this.savingRole = true;
  this.roleErrorMessage = '';
  this.roleSuccessMessage = '';

  const payload: AssignEmployeeRoleRequest = {
    role: this.selectedRole,
  };

  this.roleApi
    .assignToEmployee(this.employee.id, payload)
    .pipe(
      takeUntilDestroyed(this.destroyRef),
      finalize(() => {
        this.savingRole = false;
        this.cdr.detectChanges();
      }),
    )
    .subscribe({
      next: response => {
        this.currentRole = this.selectedRole;
        this.roleSuccessMessage = response.message;

        this.cdr.detectChanges();
      },
      error: error => {
        const errors = error?.error?.errors;

        this.roleErrorMessage = errors
          ? Object.values(errors).flat().join(' ')
          : error?.error?.message ??
            'Unable to update employee role.';

        this.cdr.detectChanges();
      },
    });
}

  loadEmployee(id: number): void {
    this.loading = true;
    this.errorMessage = '';
    this.successMessage = '';

    this.api.get(id)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.loading = false;
          this.cdr.detectChanges();
        }),
      )
      .subscribe({
        next: response => {
          this.employee = response.data;
          this.form = {
            branch_id: response.data.branch_id,
            employee_number: response.data.employee_number,
            phone: response.data.phone,
            job_title: response.data.job_title,
            employment_status: response.data.employment_status,
            hired_at: response.data.hired_at,
          };
          this.currentRole = response.data.role ?? '';
          this.selectedRole = this.currentRole;

          this.cdr.detectChanges();
        },
        error: error => {
          this.errorMessage =
            error?.error?.message ?? 'Unable to load employee details.';

            this.cdr.detectChanges();
        },
      });
  }

  loadBranches(): void {
    this.loadingBranches = true;

    this.branchApi.list({
      page: 1,
      isActive: true,
      perPage: 100,
    })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.loadingBranches = false;
          this.cdr.detectChanges();
        }),
      )
      .subscribe({
        next: response => {
          this.branchOptions = response.data.map((branch: Branch) => ({
            value: branch.id,
            label: branch.name,
            description: branch.code,
          }));

          this.cdr.detectChanges();
        },
        error: () => {
          this.branchErrorMessage = 'Unable to load branches.';

          this.cdr.detectChanges();
        },
      });
  }

  startEditing(): void {
    if (!this.employee) return;

    this.errorMessage = '';
    this.successMessage = '';
    this.editMode = true;
  }

  cancelEditing(): void {
    if (!this.employee) return;

    this.form = {
      branch_id: this.employee.branch_id,
      employee_number: this.employee.employee_number,
      phone: this.employee.phone,
      job_title: this.employee.job_title,
      employment_status: this.employee.employment_status,
      hired_at: this.employee.hired_at,
    };

    this.editMode = false;
    this.errorMessage = '';
  }

  saveChanges(): void {
    if (!this.employee || this.saving) return;

    this.saving = true;
    this.errorMessage = '';
    this.successMessage = '';

    const payload = {
      branch_id: this.form.branch_id || null,
      employee_number: this.form.employee_number.trim(),
      phone: this.form.phone?.trim() || null,
      job_title: this.form.job_title?.trim() || null,
      employment_status: this.form.employment_status,
      hired_at: this.form.hired_at || null,
    };

    this.api.update(this.employee.id, payload)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.saving = false;
          this.cdr.detectChanges();
        }),
      )
      .subscribe({
        next: response => {
          this.employee = response.data;
          this.form = {
            branch_id: response.data.branch_id,
            employee_number: response.data.employee_number,
            phone: response.data.phone,
            job_title: response.data.job_title,
            employment_status: response.data.employment_status,
            hired_at: response.data.hired_at,
          };

          this.editMode = false;
          this.successMessage = response.message;

          this.cdr.detectChanges();
        },
        error: error => {
          const errors = error?.error?.errors;

          this.errorMessage = errors
            ? Object.values(errors).flat().join(' ')
            : error?.error?.message ?? 'Unable to update employee.';

            this.cdr.detectChanges();
        },
      });
  }

    get canUpdateEmployee(): boolean {
      let user = this.currentUser();
      if(this.employee?.user_id!=user?.id){
        return this.authService.hasPermission('employees.update');
      }
      else{
        return false;
      }
  }
  
   get canUpdateRole(): boolean {
    return this.authService.hasPermission('roles.update');
  }

  goBack(): void {
    this.router.navigateByUrl('/employees');
  }
}
