import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

import { API_URL } from '../config/api.config';

export interface Role {
  id: number;
  tenant_id: number;
  name: string;
  guard_name: string;
  created_at: string;
}

export interface RoleResponse {
  success: boolean;
  message: string;
   data: Role & {
    permissions: string[];
  };
}

export interface CreateRoleRequest {
  name: string;
  permissions: string[];
}

export interface RoleListResponse {
  success: boolean;
  data: Role[];
}

export interface AssignEmployeeRoleRequest {
  role: string;
}

export interface AssignEmployeeRoleResponse {
  success: boolean;
  message: string;
}

export interface PermissionOption {
  name: string;
  module: string;
}

export interface PermissionGroup {
  key: string;
  label: string;
  permissions: PermissionOption[];
}

export interface PermissionCatalogResponse {
  success: boolean;
  data: PermissionGroup[];
}

@Injectable({
  providedIn: 'root',
})
export class RoleApi {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = API_URL;

  list(): Observable<RoleListResponse> {
  return this.http.get<RoleListResponse>(
    `${this.apiUrl}/roles`,
  );
}

  create(payload: CreateRoleRequest): Observable<RoleResponse> {
    return this.http.post<RoleResponse>(
      `${this.apiUrl}/roles`,
      payload,
    );
  }

  assignToEmployee(employeeId: number,payload: AssignEmployeeRoleRequest,): Observable<AssignEmployeeRoleResponse> {
    return this.http.put<AssignEmployeeRoleResponse>(
        `${this.apiUrl}/employees/${employeeId}/role`,
        payload,
    );
  }

  permissionCatalog(): Observable<PermissionCatalogResponse> {
    return this.http.get<PermissionCatalogResponse>(
        `${this.apiUrl}/roles/permissions`,
    );
  }
}
