import { Component } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';

import {
  arrowBackOutline,
  chatbubblesOutline,
  sendOutline,
  documentTextOutline,
  gitNetworkOutline,
  peopleOutline,
  analyticsOutline,
  settingsOutline,
} from 'ionicons/icons';

@Component({
  selector: 'app-engage-sidebar',
  standalone: true,
  imports: [
    RouterLink,
    RouterLinkActive,
    IonIcon,
  ],
  templateUrl: './engage-sidebar.component.html',
  styleUrls: ['./engage-sidebar.component.scss'],
})
export class EngageSidebarComponent {

  constructor() {
    addIcons({
      'arrow-back-outline': arrowBackOutline,
      'chatbubbles-outline': chatbubblesOutline,
      'send-outline': sendOutline,
      'document-text-outline': documentTextOutline,
      'git-network-outline': gitNetworkOutline,
      'people-outline': peopleOutline,
      'analytics-outline': analyticsOutline,
      'settings-outline': settingsOutline,
    });
  }
}