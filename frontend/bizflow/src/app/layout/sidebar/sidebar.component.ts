import { Component, OnInit } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {walletOutline,gridOutline,peopleOutline,cubeOutline,barChartOutline,settingsOutline,layersOutline,cartOutline,documentTextOutline} from 'ionicons/icons';

@Component({
  selector: 'app-sidebar',
  templateUrl: './sidebar.component.html',
  styleUrls: ['./sidebar.component.scss'],
  standalone: true,
  imports: [RouterLink, RouterLinkActive, IonIcon],
})
export class SidebarComponent  {

  constructor() {
    addIcons({
      'wallet-outline' : walletOutline,
      'grid-outline' : gridOutline,
      'people-outline' : peopleOutline,
      'cube-outline' : cubeOutline,
      'bar-chart-outline' : barChartOutline,
      'settings-outline' : settingsOutline,
      'layers-outline' : layersOutline,
      'cart-outline' : cartOutline,
      'document-text-outline' : documentTextOutline
  });
      
  }

}
