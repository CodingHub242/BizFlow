import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

import { API_URL } from '../config/api.config';

export interface BusinessProfile {
  id: number;
  name: string;
  slug: string;
  email: string | null;
  phone: string | null;
  business_type: string | null;
  logo_path: string | null;
  logo_url: string | null;
}

export interface UpdateBusinessProfileRequest {
  name: string;
  email: string | null;
  phone: string | null;
  business_type: string | null;
}

export interface BusinessProfileResponse {
  success: boolean;
  data: BusinessProfile;
  message?: string;
}

@Injectable({
  providedIn: 'root',
})
export class SettingsApi {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = API_URL;

  getBusinessProfile(): Observable<BusinessProfileResponse> {
    return this.http.get<BusinessProfileResponse>(
      `${this.apiUrl}/settings/business-profile`,
    );
  }

  updateBusinessProfile(
    data: UpdateBusinessProfileRequest,
  ): Observable<BusinessProfileResponse> {
    return this.http.put<BusinessProfileResponse>(
      `${this.apiUrl}/settings/business-profile`,
      data,
    );
  }

    uploadBusinessLogo(file: File): Observable<{success: boolean;message: string;data: {logo_path: string;logo_url: string;};}> 
    {
        const formData = new FormData();
        formData.append('logo', file);

        return this.http.post<{
            success: boolean;
            message: string;
            data: {
            logo_path: string;
            logo_url: string;
            };
        }>(
            `${this.apiUrl}/settings/business-profile/logo`,
            formData,
        );
    }
}
