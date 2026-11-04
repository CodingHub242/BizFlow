import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';

import { API_URL } from '../config/api.config';

import {
  EmployeeItemRequest,
  EmployeeItemRequestListResponse,
  EmployeeItemRequestResponse,
  CreateEmployeeItemRequest,
  ApproveEmployeeItemRequest,
  RejectEmployeeItemRequest,
    IssueEmployeeItemRequestPayload,
    ReturnEmployeeItemRequestPayload
} from './employee-item-request.models';

export interface EmployeeItemRequestListParams {
  page?: number;
  per_page?: number;
  search?: string;
  status?: string;
  branch_id?: number;
  employee_id?: number;
}

@Injectable({
  providedIn: 'root',
})
export class EmployeeItemApiService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = API_URL;

  private readonly endpoint =
    `${this.apiUrl}/employee-item-requests`;

  list(
  params: EmployeeItemRequestListParams = {}
): Observable<EmployeeItemRequestListResponse> {
  let httpParams = new HttpParams();

  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      httpParams = httpParams.set(key, String(value));
    }
  });

  return this.http.get<EmployeeItemRequestListResponse>(
    `${API_URL}/employee-item-requests`,
    { params: httpParams }
  );
}

  get(requestId: number): Observable<EmployeeItemRequestResponse> {
    return this.http.get<EmployeeItemRequestResponse>(
      `${this.endpoint}/${requestId}`,
    );
  }

  create(
    data: CreateEmployeeItemRequest,
  ): Observable<EmployeeItemRequestResponse> {
    return this.http.post<EmployeeItemRequestResponse>(
      this.endpoint,
      data,
    );
  }

  approve(
    requestId: number,
    data?: ApproveEmployeeItemRequest,
  ): Observable<EmployeeItemRequestResponse> {
    return this.http.post<EmployeeItemRequestResponse>(
      `${this.endpoint}/${requestId}/approve`,
      data ?? {},
    );
  }

  reject(
    requestId: number,
    data: RejectEmployeeItemRequest,
  ): Observable<EmployeeItemRequestResponse> {
    return this.http.post<EmployeeItemRequestResponse>(
      `${this.endpoint}/${requestId}/reject`,
      data,
    );
  }

  cancel(
    requestId: number,
  ): Observable<EmployeeItemRequestResponse> {
    return this.http.post<EmployeeItemRequestResponse>(
      `${this.endpoint}/${requestId}/cancel`,
      {},
    );
  }


issue(
  requestId: number,
  payload: IssueEmployeeItemRequestPayload
): Observable<EmployeeItemRequestResponse> {
  return this.http.post<EmployeeItemRequestResponse>(
    `${API_URL}/employee-item-requests/${requestId}/issue`,
    payload
  );
}

returnItems(
  requestId: number,
  payload: ReturnEmployeeItemRequestPayload
): Observable<EmployeeItemRequestResponse> {
  return this.http.post<EmployeeItemRequestResponse>(
    `${API_URL}/employee-item-requests/${requestId}/returns`,
    payload
  );
}
}