import { Component, DestroyRef, inject, signal } from '@angular/core';
import { Router, NavigationEnd } from '@angular/router';
import { filter } from 'rxjs/operators';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { IonIcon } from '@ionic/angular';
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
  readonly isEngageWorkspace = signal(false);

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

      this.updateWorkspace(this.router.url);

      this.router.events
        .pipe(
          filter((event): event is NavigationEnd =>
            event instanceof NavigationEnd
          ),
          takeUntilDestroyed(this.destroyRef)
        )
        .subscribe(event => {
          this.updateWorkspace(event.urlAfterRedirects);
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

  private updateWorkspace(url: string): void {
    const path = url.split('?')[0].split('#')[0];

    this.isEngageWorkspace.set(
      path === '/engage' || path.startsWith('/engage/')
    );
  }

  // get isEngageWorkspace(): boolean {
  //   // Determine if the current route is part of the Engage workspace detectChanges everytime the route changes
  //   return this.router.url.split('?')[0].startsWith('/engage');
  // }
}