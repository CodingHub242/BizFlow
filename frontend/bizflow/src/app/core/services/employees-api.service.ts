
import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { API_URL } from '../config/api.config';

export interface Employee {
  id: number;
  user_id: number;
  name: string | null;
  email: string | null;
  branch_id: number | null;
  branch_name: string | null;
  employee_number: string;
  phone: string | null;
  job_title: string | null;
  employment_status: 'active' | 'inactive' | 'suspended';
  hired_at: string | null;
  created_at: string;
}

export interface EmployeePage {
  success: boolean;
  data: Employee[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface CreateEmployeeAccountRequest {
  name: string;
  email: string;
  password: string;
  employee_number: string;
  branch_id: number | null;
  phone: string | null;
  job_title: string | null;
  employment_status: 'active' | 'inactive' | 'suspended';
  hired_at: string | null;
}

export interface EmployeeResponse {
  success: boolean;
  message: string;
  data: Employee;
}

@Injectable({ providedIn: 'root' })
export class EmployeesApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = `${API_URL}/employees`;
  //private readonly baseUrl = '/api/employees';

  list(page = 1): Observable<EmployeePage> {
    return this.http.get<EmployeePage>(
      `${this.baseUrl}?page=${page}`
    );
  }

  createAccount(
    payload: CreateEmployeeAccountRequest
  ): Observable<EmployeeResponse> {
    return this.http.post<EmployeeResponse>(
      `${this.baseUrl}/accounts`,
      payload
    );
  }
}
