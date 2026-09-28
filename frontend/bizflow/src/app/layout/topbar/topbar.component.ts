import { Component, OnInit,inject } from '@angular/core';
import { IonIcon } from '@ionic/angular';
import { Router } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';
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
export class TopbarComponent   {
private readonly authService = inject(AuthService);
private readonly router = inject(Router);
isProfileMenuOpen = false;

   constructor() {
    addIcons({
      notificationsOutline,
      searchOutline,
      chevronDownOutline,
    });
  }

  logout(): void {
    this.isProfileMenuOpen = false;
    this.authService.logout();
    this.router.navigate(['/login']);
  }

  toggleProfileMenu(): void {
  this.isProfileMenuOpen = !this.isProfileMenuOpen;
}

}
