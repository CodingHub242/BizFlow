
import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { API_URL } from '../config/api.config';

export interface Category {
  id: number;
  name: string;
  description: string | null;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface CategoryListResponse {
  success: boolean;
  data: Category[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface CategoryPayload {
  name: string;
  description?: string | null;
  is_active?: boolean;
}

@Injectable({
  providedIn: 'root',
})
export class CategoryApi {
  private readonly apiUrl = `${API_URL}/categories`;

  constructor(private readonly http: HttpClient) {}

  list(search = '', perPage = 100): Observable<CategoryListResponse> {
    let params = new HttpParams().set('per_page', String(perPage));

    if (search.trim()) {
      params = params.set('search', search.trim());
    }

    return this.http.get<CategoryListResponse>(this.apiUrl, { params });
  }

  create(payload: CategoryPayload): Observable<{
  success: boolean;
  data: Category;
  message?: string;
}> {
  return this.http.post<{
    success: boolean;
    data: Category;
    message?: string;
  }>(this.apiUrl, payload);
}
}
