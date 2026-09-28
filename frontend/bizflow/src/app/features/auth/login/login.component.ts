import { Component, OnInit,inject } from '@angular/core';
import { AuthService } from '../../../core/services/auth.service';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';


@Component({
  selector: 'app-login',
  templateUrl: './login.component.html',
  styleUrls: ['./login.component.scss'],
  imports: [FormsModule],
})
export class LoginComponent  {
 private readonly authService = inject(AuthService);
 private readonly router = inject(Router);

  email = '';
  password = '';
  errorMessage = '';

  constructor() { }

   login(): void {
     this.errorMessage = '';
    this.authService.login(this.email, this.password).subscribe({
      next: () => {
        this.router.navigate(['/dashboard']);
      },
       error: error => {
        this.errorMessage =
          error?.error?.message ?? 'Unable to sign in. Please try again.';
      },
    });
  }

}
