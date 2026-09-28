import { Injectable, inject } from '@angular/core';

import { AuthService } from './auth.service';

@Injectable({
  providedIn: 'root',
})
export class InactivityService {
  private readonly authService = inject(AuthService);

  private timeoutId: ReturnType<typeof setTimeout> | null = null;
  private timeoutMs = 15 * 60 * 1000;

  private readonly activityEvents = [
    'mousemove',
    'keydown',
    'click',
    'scroll',
    'touchstart',
  ];

  start(timeoutMs = 15 * 60 * 1000): void {
    this.timeoutMs = timeoutMs;

    this.removeActivityListeners();

    if (!this.authService.getToken()) {
      return;
    }

    this.addActivityListeners();
    this.reset();
  }

  reset(): void {
  if (this.timeoutId !== null) {
    clearTimeout(this.timeoutId);
    this.timeoutId = null;
  }

  if (!this.authService.getToken()) {
    return;
  }

  this.timeoutId = setTimeout(() => {
    this.removeActivityListeners();
    this.authService.logout();
    this.timeoutId = null;
  }, this.timeoutMs);
}

  stop(): void {
    if (this.timeoutId !== null) {
      clearTimeout(this.timeoutId);
      this.timeoutId = null;
    }

    this.removeActivityListeners();
  }

  private addActivityListeners(): void {
    for (const event of this.activityEvents) {
      window.addEventListener(event, this.handleActivity);
    }
  }

  private removeActivityListeners(): void {
    for (const event of this.activityEvents) {
      window.removeEventListener(event, this.handleActivity);
    }
  }

  private readonly handleActivity = (): void => {
    this.reset();
  };
}