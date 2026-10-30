import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap,BehaviorSubject} from 'rxjs';
import { API_URL } from '../config/api.config';
import { Router } from '@angular/router';

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  tenant_id: number;
  role: string; // Add the role property to the AuthUser interface so that it can be used in the TopbarComponent
  permissions: string[];
  tenant?: {
    id: number;
    name: string;
    status: string;
  };
}

export interface LoginResponse {
  success: boolean;
  message: string;
  token: string;
  user: AuthUser;
}

export interface BusinessRegistrationRequest {
  business_name: string;
  business_email: string;
  business_phone: string;
  business_type: string;
  owner_name: string;
  owner_email: string;
  password: string;
  password_confirmation: string;
}

export interface BusinessRegistrationResponse {
  message: string;
  data: {
    tenant: {
      id: number;
      name: string;
      status: string;
    };
    owner: {
      id: number;
      name: string;
      email: string;
    };
  };
}

export interface UpdateProfileRequest {
  name: string;
}

export interface PasswordResetResponse {
  success: boolean;
  message: string;
}

export interface VerifyPasswordResetOtpResponse extends PasswordResetResponse {
  reset_token: string;
}

export interface UpdateProfileResponse {
  success: boolean;
  message: string;
  data: {
    id: number;
    name: string;
    email: string;
  };
}

@Injectable({
  providedIn: 'root',
})
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  private currentUserSubject = new BehaviorSubject<AuthUser | null>(this.getStoredUser());
  currentUser$ = this.currentUserSubject.asObservable();
  
  private readonly apiUrl = API_URL;
  private readonly tokenKey = 'bizflow_token';


  private getStoredUser() {
  const user = localStorage.getItem('bizflow_user');
  return user ? JSON.parse(user) as AuthUser : null;
}
  login(email: string, password: string): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>(`${this.apiUrl}/login`, {
        email,
        password,
      })
      .pipe(
        tap(response => {
          localStorage.setItem(this.tokenKey, response.token);
          localStorage.setItem('bizflow_user', JSON.stringify(response.user));
         // this.LoggedInUser = response.user;

          // Notify every component that the user has changed
          this.currentUserSubject.next(response.user);

        }),
      );
  }

 registerBusiness(
  payload: BusinessRegistrationRequest
): Observable<BusinessRegistrationResponse> {
  return this.http
    .post<BusinessRegistrationResponse>(
      `${this.apiUrl}/onboarding/register`,
      payload
    )
    .pipe(
      tap({
        next: response => {
         // console.log('🔥 AUTH SERVICE NEXT:', response);
        },
        error: error => {
          //console.error('🔥 AUTH SERVICE ERROR:', error);
        },
        complete: () => {
         // console.log('🔥 AUTH SERVICE COMPLETE');
        },
      })
    );
}

  getToken(): string | null {
    return localStorage.getItem(this.tokenKey);
  }

  getCurrentUser(): Observable<AuthUser> {
  return this.http.get<AuthUser>(`${this.apiUrl}/user`).pipe(
    tap(user => {
      localStorage.setItem('bizflow_user', JSON.stringify(user));
      this.currentUserSubject.next(user);
    }),
  );
}

hasPermission(permission: string): boolean {
  const user = this.currentUserSubject.value;

  return user?.permissions?.includes(permission) ?? false;
}

hasAnyPermission(permissions: string[]): boolean {
  return permissions.some(permission =>
    this.hasPermission(permission)
  );
}


updateProfile(
  payload: UpdateProfileRequest
): Observable<UpdateProfileResponse> {
  return this.http
    .put<UpdateProfileResponse>(
      `${this.apiUrl}/user/profile`,
      payload
    )
    .pipe(
      tap(response => {
        const currentUser = this.currentUserSubject.value;

        if (currentUser) {
          const updatedUser: AuthUser = {
            ...currentUser,
            name: response.data.name,
            email: response.data.email
          };

          localStorage.setItem(
            'bizflow_user',
            JSON.stringify(updatedUser)
          );

          this.currentUserSubject.next(updatedUser);
        }
      })
    );
}



requestPasswordResetOtp(): Observable<PasswordResetResponse> {
  return this.http.post<PasswordResetResponse>(
    `${this.apiUrl}/user/password-reset/request-otp`,
    {}
  );
}

verifyPasswordResetOtp(
  otp: string
): Observable<VerifyPasswordResetOtpResponse> {
  return this.http.post<VerifyPasswordResetOtpResponse>(
    `${this.apiUrl}/user/password-reset/verify-otp`,
    { otp }
  );
}

resetPassword(
  resetToken: string,
  password: string,
  passwordConfirmation: string
): Observable<PasswordResetResponse> {
  return this.http.post<PasswordResetResponse>(
    `${this.apiUrl}/user/password-reset/reset`,
    {
      reset_token: resetToken,
      password,
      password_confirmation: passwordConfirmation
    }
  );
}


logout(): void {
  localStorage.removeItem(this.tokenKey);
  localStorage.removeItem('bizflow_user');
  this.currentUserSubject.next(null);

  this.router.navigateByUrl('login');
}

}