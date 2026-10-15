import { Component, DestroyRef, inject, signal } from '@angular/core';
import { IonIcon } from '@ionic/angular';
import { Router } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { AuthService, AuthUser } from '../../core/services/auth.service';
import { addIcons } from 'ionicons';
import {
  notificationsOutline,
  searchOutline,
  chevronDownOutline,
} from 'ionicons/icons';

@Component({
  selector: 'app-topbar',
  templateUrl: './topbar.component.html',
  styleUrls: ['./topbar.component.scss'],
  standalone: true,
  imports: [IonIcon],
})
export class TopbarComponent {
  public readonly authService = inject(AuthService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  readonly currentUser = signal<AuthUser | null>(null);

  isProfileMenuOpen = false;

  constructor() {
    addIcons({
      notificationsOutline,
      searchOutline,
      chevronDownOutline,
    });

    this.authService.currentUser$
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(user => {
        this.currentUser.set(user);
      });
  }

  logout(): void {
    this.isProfileMenuOpen = false;
    this.authService.logout();
    localStorage.removeItem('bizflow_user');
    this.router.navigate(['/login']);
  }

  toggleProfileMenu(): void {
    this.isProfileMenuOpen = !this.isProfileMenuOpen;
  }
}