import { Component, OnInit,inject,ChangeDetectorRef } from '@angular/core';
import { IonIcon } from '@ionic/angular';
import { Router } from '@angular/router';
import { AuthService,AuthUser } from '../../core/services/auth.service';
import { AsyncPipe } from '@angular/common';
import { addIcons } from 'ionicons';
import {notificationsOutline,searchOutline,chevronDownOutline} from 'ionicons/icons';


@Component({
  selector: 'app-topbar',
  templateUrl: './topbar.component.html',
  styleUrls: ['./topbar.component.scss'],
  standalone: true,
  imports: [IonIcon,AsyncPipe],
})
export class TopbarComponent   {
public readonly authService = inject(AuthService);
private readonly router = inject(Router);
private readonly changeDetector = inject(ChangeDetectorRef);
isProfileMenuOpen = false;
LoggedInUser: any;
Initials: string = '';

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
    //remove user data from local storage
    localStorage.removeItem('bizflow_user');
    this.router.navigate(['/login']);
  }

  toggleProfileMenu(): void {
  this.isProfileMenuOpen = !this.isProfileMenuOpen;
}

}
