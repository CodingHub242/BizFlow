import { Component, OnInit,inject,ChangeDetectorRef } from '@angular/core';
import { AuthService } from '../../../core/services/auth.service';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';


@Component({
  selector: 'app-login',
  templateUrl: './login.component.html',
  styleUrls: ['./login.component.scss'],
  imports: [FormsModule],
  standalone: true,
})
export class LoginComponent  {
 private readonly authService = inject(AuthService);
 private readonly router = inject(Router);
 private readonly changeDetector = inject(ChangeDetectorRef);

  email = '';
  password = '';
  errorMessage : string = '';
  disabled = false;

  constructor() { 
    if(this.email=='' && this.password=='')
    {
        this.disabled = true;
    }
  }

   login(): void {
    this.errorMessage = '';
    this.authService.login(this.email, this.password).subscribe({
      next: () => {
        this.router.navigate(['/dashboard']);
      },
       error: error => {
        // console.log(this.errorMessage);
        // console.log(error.error.message);
        this.errorMessage = error?.error?.message ?? 'Unable to sign in. Please try again.';
        this.changeDetector.detectChanges();
      },
    });
  }

}
