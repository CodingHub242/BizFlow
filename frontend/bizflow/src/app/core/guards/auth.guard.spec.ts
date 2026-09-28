import { TestBed } from '@angular/core/testing';
import { ActivatedRouteSnapshot, RouterStateSnapshot } from '@angular/router';
import { Router } from '@angular/router';

import { AuthService } from '../../core/services/auth.service';
import { authGuard } from '../../core/guards/auth.guard';

describe('authGuard', () => {
  let authService: {
    getToken: ReturnType<typeof vi.fn>;
  };

  let router: {
    parseUrl: ReturnType<typeof vi.fn>;
  };

  beforeEach(() => {
    authService = {
      getToken: vi.fn(),
    };

    router = {
      parseUrl: vi.fn(),
    };

    TestBed.configureTestingModule({
      providers: [
        {
          provide: AuthService,
          useValue: authService,
        },
        {
          provide: Router,
          useValue: router,
        },
      ],
    });
  });

  it('allows navigation when a token exists', () => {
    authService.getToken.mockReturnValue('test-token');

    const result = TestBed.runInInjectionContext(() =>
      authGuard(
        {} as ActivatedRouteSnapshot,
        {} as RouterStateSnapshot,
      ),
    );

    expect(result).toBe(true);
  });

  it('redirects to login when no token exists', () => {
    authService.getToken.mockReturnValue(null);

    const loginUrlTree = {};
    router.parseUrl.mockReturnValue(loginUrlTree);

    const result = TestBed.runInInjectionContext(() =>
      authGuard(
        {} as ActivatedRouteSnapshot,
        {} as RouterStateSnapshot,
      ),
    );

    expect(router.parseUrl).toHaveBeenCalledWith('/login');
    expect(result).toBe(loginUrlTree);
  });
});