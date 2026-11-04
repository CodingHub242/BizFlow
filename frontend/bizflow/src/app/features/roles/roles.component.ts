import { CommonModule } from '@angular/common';
import {
  ChangeDetectorRef,
  Component,
  DestroyRef,
  OnInit,
  inject,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize } from 'rxjs';

import {
  RoleApi,
  Role,
  PermissionGroup,
} from '../../core/services/role-api';
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

  // Custom role creation state
  selectedPermissions = new Set<string>();
  roleName = '';
  showCreateForm = false;

  // Existing role editing state
  editingRole: Role | null = null;
  editingPermissions = new Set<string>();

  loading = false;
  saving = false;
  savingPermissions = false;

  errorMessage = '';
  successMessage = '';
  formError = '';
  editError = '';

  readonly canViewRoles = this.auth.hasPermission('roles.view');
  readonly canCreateRoles = this.auth.hasPermission('roles.create');
  readonly canUpdateRoles = this.auth.hasPermission('roles.update');

  ngOnInit(): void {
    if (!this.canViewRoles) {
      this.errorMessage = 'You do not have permission to view roles.';
      return;
    }

    this.loadRoles();

    if (this.canCreateRoles || this.canUpdateRoles) {
      this.loadPermissionCatalog();
    }
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

  // Shared permission selection helpers

  togglePermission(
    name: string,
    checked: boolean,
    target: 'create' | 'edit' = 'create',
  ): void {
    const permissions =
      target === 'edit'
        ? this.editingPermissions
        : this.selectedPermissions;

    if (checked) {
      permissions.add(name);
    } else {
      permissions.delete(name);
    }

    if (target === 'edit') {
      this.editingPermissions = new Set(permissions);
    } else {
      this.selectedPermissions = new Set(permissions);
    }
  }

  isPermissionSelected(
    name: string,
    target: 'create' | 'edit' = 'create',
  ): boolean {
    const permissions =
      target === 'edit'
        ? this.editingPermissions
        : this.selectedPermissions;

    return permissions.has(name);
  }

  isGroupFullySelected(
    group: PermissionGroup,
    target: 'create' | 'edit' = 'create',
  ): boolean {
    const permissions =
      target === 'edit'
        ? this.editingPermissions
        : this.selectedPermissions;

    return (
      group.permissions.length > 0 &&
      group.permissions.every(permission =>
        permissions.has(permission.name),
      )
    );
  }

  toggleGroup(
    group: PermissionGroup,
    checked: boolean,
    target: 'create' | 'edit' = 'create',
  ): void {
    const permissions =
      target === 'edit'
        ? this.editingPermissions
        : this.selectedPermissions;

    for (const permission of group.permissions) {
      if (checked) {
        permissions.add(permission.name);
      } else {
        permissions.delete(permission.name);
      }
    }

    if (target === 'edit') {
      this.editingPermissions = new Set(permissions);
    } else {
      this.selectedPermissions = new Set(permissions);
    }
  }

  // Custom role creation

  openCreateForm(): void {
    if (!this.canCreateRoles || this.saving || this.savingPermissions) {
      return;
    }

    this.cancelEdit();

    this.resetCreateForm();
    this.showCreateForm = true;
    this.errorMessage = '';
    this.successMessage = '';
  }

  cancelCreate(): void {
    if (this.saving) return;

    this.showCreateForm = false;
    this.resetCreateForm();
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
          this.resetCreateForm();
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

  // Existing role permission editing

  openEditPermissions(role: Role): void {
    if (
      !this.canUpdateRoles ||
      this.saving ||
      this.savingPermissions
    ) {
      return;
    }

    this.showCreateForm = false;
    this.resetCreateForm();

    this.editingRole = role;
    this.editingPermissions = new Set(role.permissions ?? []);

    this.editError = '';
    this.errorMessage = '';
    this.successMessage = '';
  }

  cancelEdit(): void {
    if (this.savingPermissions) return;

    this.editingRole = null;
    this.editingPermissions = new Set();
    this.editError = '';
  }

  saveRolePermissions(): void {
    if (
      !this.canUpdateRoles ||
      !this.editingRole ||
      this.savingPermissions
    ) {
      return;
    }

    const roleId = this.editingRole.id;
    const permissions = [...this.editingPermissions];

    this.savingPermissions = true;
    this.editError = '';
    this.errorMessage = '';
    this.successMessage = '';

    this.roleApi
      .updatePermissions(roleId, { permissions })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.savingPermissions = false;
          this.cdr.detectChanges();
        }),
      )
      .subscribe({
        next: response => {
          const updatedRole = response.data;

          this.roles = this.roles.map(role =>
            role.id === updatedRole.id ? updatedRole : role,
          );

          this.editingRole = null;
          this.editingPermissions = new Set();

          this.successMessage =
            response.message || 'Role permissions updated successfully.';

          this.cdr.detectChanges();
        },
        error: error => {
          const validationErrors = error?.error?.errors;

          this.editError = validationErrors
            ? Object.values(validationErrors).flat().join(' ')
            : error?.error?.message ??
              'Unable to update role permissions.';

          this.cdr.detectChanges();
        },
      });
  }

  // Display helpers

  isSystemRole(role: Role): boolean {
    return (
      role.tenant_id !== null &&
      [
        'Owner',
        'Administrator',
        'Manager',
        'Salesperson',
        'Cashier',
        'Inventory Officer',
        'Accountant',
      ].includes(role.name)
    );
  }

  trackGroup(_index: number, group: PermissionGroup): string {
    return group.key;
  }

  trackRole(_index: number, role: Role): number {
    return role.id;
  }

  private resetCreateForm(): void {
    this.roleName = '';
    this.selectedPermissions = new Set<string>();
    this.formError = '';
  }
}