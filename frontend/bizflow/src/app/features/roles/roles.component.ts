import { CommonModule } from '@angular/common';
import {ChangeDetectorRef,Component,DestroyRef,OnInit,inject} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize } from 'rxjs';
import {RoleApi,Role,PermissionGroup} from '../../core/services/role-api';
import { AuthService } from '../../core/services/auth.service';

@Component({
  selector: 'app-roles',
  standalone: true,
  templateUrl: './roles.component.html',
  styleUrls: ['./roles.component.scss'],
  imports: [CommonModule, FormsModule],
})
export class RolesComponent implements OnInit {
  private readonly roleApi = inject(RoleApi);
  private readonly auth = inject(AuthService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly cdr = inject(ChangeDetectorRef);

  roles: Role[] = [];
  permissionGroups: PermissionGroup[] = [];
  selectedPermissions = new Set<string>();

  roleName = '';
  loading = false;
  saving = false;
  showCreateForm = false;

  errorMessage = '';
  successMessage = '';
  formError = '';

  readonly canViewRoles = this.auth.hasPermission('roles.view');
  readonly canCreateRoles = this.auth.hasPermission('roles.create');

  ngOnInit(): void {
    if (!this.canViewRoles) {
      this.errorMessage = 'You do not have permission to view roles.';
      return;
    }

    this.loadRoles();
    this.loadPermissionCatalog();
  }

  loadRoles(): void {
    this.loading = true;
    this.errorMessage = '';

    this.roleApi
      .list()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.loading = false;
          this.cdr.detectChanges();
        }),
      )
      .subscribe({
        next: response => {
          this.roles = response.data;
          this.cdr.detectChanges();
        },
        error: error => {
          this.errorMessage =
            error?.error?.message ?? 'Unable to load roles.';
          this.cdr.detectChanges();
        },
      });
  }

  loadPermissionCatalog(): void {
    this.roleApi
      .permissionCatalog()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: response => {
          this.permissionGroups = response.data;
          this.cdr.detectChanges();
        },
        error: error => {
          this.errorMessage =
            error?.error?.message ??
            'Unable to load the permission catalogue.';
          this.cdr.detectChanges();
        },
      });
  }

  togglePermission(name: string, checked: boolean): void {
    if (checked) {
      this.selectedPermissions.add(name);
    } else {
      this.selectedPermissions.delete(name);
    }

    // Trigger change detection for the updated Set.
    this.selectedPermissions = new Set(this.selectedPermissions);
  }

  isPermissionSelected(name: string): boolean {
    return this.selectedPermissions.has(name);
  }

  isGroupFullySelected(group: PermissionGroup): boolean {
    return (
      group.permissions.length > 0 &&
      group.permissions.every(permission =>
        this.selectedPermissions.has(permission.name),
      )
    );
  }

  toggleGroup(group: PermissionGroup, checked: boolean): void {
    for (const permission of group.permissions) {
      if (checked) {
        this.selectedPermissions.add(permission.name);
      } else {
        this.selectedPermissions.delete(permission.name);
      }
    }

    this.selectedPermissions = new Set(this.selectedPermissions);
  }

  openCreateForm(): void {
    if (!this.canCreateRoles) return;

    this.resetForm();
    this.showCreateForm = true;
    this.errorMessage = '';
    this.successMessage = '';
  }

  cancelCreate(): void {
    if (this.saving) return;

    this.showCreateForm = false;
    this.resetForm();
  }

  createRole(): void {
    if (!this.canCreateRoles || this.saving) return;

    const name = this.roleName.trim();
    const permissions = [...this.selectedPermissions];

    if (!name) {
      this.formError = 'Enter a role name.';
      return;
    }

    if (permissions.length === 0) {
      this.formError = 'Select at least one permission.';
      return;
    }

    this.saving = true;
    this.formError = '';
    this.errorMessage = '';
    this.successMessage = '';

    this.roleApi
      .create({ name, permissions })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.saving = false;
          this.cdr.detectChanges();
        }),
      )
      .subscribe({
        next: response => {
          this.successMessage =
            response.message || 'Role created successfully.';

          this.showCreateForm = false;
          this.resetForm();
          this.loadRoles();
          this.cdr.detectChanges();
        },
        error: error => {
          const validationErrors = error?.error?.errors;

          this.formError = validationErrors
            ? Object.values(validationErrors).flat().join(' ')
            : error?.error?.message ?? 'Unable to create role.';

          this.cdr.detectChanges();
        },
      });
  }

  isSystemRole(role: Role): boolean {
    return role.tenant_id === null;
  }

  trackGroup(_index: number, group: PermissionGroup): string {
    return group.key;
  }

  trackRole(_index: number, role: Role): number {
    return role.id;
  }

  private resetForm(): void {
    this.roleName = '';
    this.selectedPermissions = new Set<string>();
    this.formError = '';
  }
}
