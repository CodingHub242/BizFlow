import { Component, OnInit,inject,ChangeDetectorRef } from '@angular/core';
import { AuthService } from '../../../core/services/auth.service';
import { FormsModule } from '@angular/forms';
import { Router,RouterLink } from '@angular/router';


@Component({
  selector: 'app-login',
  templateUrl: './login.component.html',
  styleUrls: ['./login.component.scss'],
  imports: [FormsModule,RouterLink],
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
  isSubmitting = false;

  constructor() { 
    if(this.email=='' && this.password=='')
    {
        this.disabled = true;
    }
  }

   login(): void {
    this.errorMessage = '';
    this.isSubmitting = true;
    this.authService.login(this.email, this.password).subscribe({
      next: () => {
        this.isSubmitting = false;
        this.router.navigate(['/dashboard']);
      },
     error: (error) => {
        this.isSubmitting = false;

        const code = error.error?.code;

        if (error.status === 403) {
          switch (code) {
            case 'TENANT_PENDING':
              this.errorMessage =
                'Your business is awaiting platform approval. You will be able to access Bizflow once your business has been approved.';
              break;

            case 'TENANT_REJECTED':
              this.errorMessage =
                'Your business registration was rejected. Please contact Bizflow support for more information.';
              break;

            case 'TENANT_SUSPENDED':
              this.errorMessage =
                'Your business account has been suspended. Please contact Bizflow support.';
              break;

            default:
              this.errorMessage =
                error.error?.message ??
                'You are not currently allowed to access Bizflow.';
          }
        } else {
          this.errorMessage =
            error.error?.message ??
            'Unable to sign in. Please check your credentials and try again.';
        }

        this.changeDetector.detectChanges();
      }
    });
  }

}
