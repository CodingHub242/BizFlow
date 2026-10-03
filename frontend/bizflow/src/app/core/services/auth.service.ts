import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap,BehaviorSubject} from 'rxjs';
import { API_URL } from '../config/api.config';

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  tenant_id: number;
  role: string; // Add the role property to the AuthUser interface so that it can be used in the TopbarComponent
}

export interface LoginResponse {
  success: boolean;
  message: string;
  token: string;
  user: AuthUser;
}

@Injectable({
  providedIn: 'root',
})
export class AuthService {
  private readonly http = inject(HttpClient);
  private currentUserSubject = new BehaviorSubject<any>(
    this.getStoredUser()
  );
  currentUser$ = this.currentUserSubject.asObservable();
  
  private readonly apiUrl = API_URL;
  private readonly tokenKey = 'bizflow_token';


  private getStoredUser() {
  const user = localStorage.getItem('bizflow_user');
  return user ? JSON.parse(user) : null;
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

  getToken(): string | null {
    return localStorage.getItem(this.tokenKey);
  }

  logout(): void {
    localStorage.removeItem(this.tokenKey);
  }
}