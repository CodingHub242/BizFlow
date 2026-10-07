import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { catchError, map, of } from 'rxjs';

import { AuthService } from '../services/auth.service';

export const tenantApprovedGuard: CanActivateFn = () => {
  const authService = inject(AuthService);
  const router = inject(Router);

  return authService.getCurrentUser().pipe(
    map(user => {
      const status = user.tenant?.status;

      if (status === 'approved') {
        return true;
      }

      if (status === 'pending') {
        return router.parseUrl('/login');
      }

      if (status === 'rejected' || status === 'suspended') {
        return router.parseUrl('/login');
      }

      return router.parseUrl('/login');
    }),
    catchError(() => {
      return of(router.parseUrl('/login'));
    }),
  );
};