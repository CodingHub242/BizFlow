import {
  Component,
  OnInit,
  OnDestroy,
  inject,
  signal,
} from '@angular/core';

import {
  Router,
  RouterOutlet,
  NavigationEnd,
} from '@angular/router';

import { filter, Subscription } from 'rxjs';

import { InactivityService } from '../../core/services/inactivity.service';

import { SidebarComponent } from '../sidebar/sidebar.component';
import { TopbarComponent } from '../topbar/topbar.component';
import { EngageSidebarComponent } from '../engage-sidebar/engage-sidebar.component';

@Component({
  selector: 'app-app-shell',
  templateUrl: './app-shell.component.html',
  styleUrls: ['./app-shell.component.scss'],
  standalone: true,
  imports: [
    RouterOutlet,
    SidebarComponent,
    TopbarComponent,
    EngageSidebarComponent,
  ],
})
export class AppShellComponent implements OnInit, OnDestroy {

  private readonly inactivityService = inject(InactivityService);
  private readonly router = inject(Router);

  private routerSubscription?: Subscription;

  readonly isEngageWorkspace = signal(false);

  ngOnInit(): void {
    this.inactivityService.start();

    // Handle the current URL, including direct page refreshes.
    this.updateWorkspace(this.router.url);

    this.routerSubscription = this.router.events
      .pipe(
        filter(event => event instanceof NavigationEnd),
      )
      .subscribe(event => {
        this.updateWorkspace((event as NavigationEnd).urlAfterRedirects);
      });
  }

  private updateWorkspace(url: string): void {
    this.isEngageWorkspace.set(
      url.split('?')[0].split('#')[0] === '/engage' ||
      url.split('?')[0].split('#')[0].startsWith('/engage/')
    );
  }

  ngOnDestroy(): void {
    this.inactivityService.stop();
    this.routerSubscription?.unsubscribe();
  }
}