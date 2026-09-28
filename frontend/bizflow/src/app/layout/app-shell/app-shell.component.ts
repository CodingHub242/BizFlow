import { Component, OnInit,inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { InactivityService } from '../../core/services/inactivity.service';

import { SidebarComponent } from '../sidebar/sidebar.component';
import { TopbarComponent } from '../topbar/topbar.component';

@Component({
  selector: 'app-app-shell',
  templateUrl: './app-shell.component.html',
  styleUrls: ['./app-shell.component.scss'],
  standalone: true,
  imports: [
    RouterOutlet,
    SidebarComponent,
    TopbarComponent,
  ],
})
export class AppShellComponent  implements OnInit {
private readonly inactivityService = inject(InactivityService);
  constructor() { }

  ngOnInit(): void {
    this.inactivityService.start();
  }

  ngOnDestroy(): void {
    this.inactivityService.stop();
  }

}
