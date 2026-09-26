import { Component, OnInit } from '@angular/core';
import { IonIcon } from '@ionic/angular';

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

   constructor() {
    addIcons({
      notificationsOutline,
      searchOutline,
      chevronDownOutline,
    });
  }

}
