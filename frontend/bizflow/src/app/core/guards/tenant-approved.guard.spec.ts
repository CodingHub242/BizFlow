import { TestBed } from '@angular/core/testing';
import { provideRouter, Router, UrlTree } from '@angular/router';
import { firstValueFrom, isObservable, of, throwError } from 'rxjs';

import { tenantApprovedGuard } from './tenant-approved.guard';
import { AuthService, AuthUser } from '../services/auth.service';

describe('tenantApprovedGuard', () => {
  let authService: {
    getCurrentUser: ReturnType<typeof vi.fn>;
  };

  let router: Router;

  beforeEach(() => {
    authService = {
      getCurrentUser: vi.fn(),
    };

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        {
          provide: AuthService,
          useValue: authService,
        },
      ],
    });

    router = TestBed.inject(Router);
  });

  async function resolveGuardResult(): Promise<true | UrlTree> {
    const result = TestBed.runInInjectionContext(() =>
      tenantApprovedGuard(
        {} as never,
        {} as never,
      ),
    );

    if (isObservable(result)) {
      return await firstValueFrom(result) as true | UrlTree;
    }

    return result as true | UrlTree;
  }

  it('allows an approved tenant', async () => {
    const user: AuthUser = {
      id: 1,
      name: 'Bizflow Owner',
      email: 'owner@bizflow.test',
      tenant_id: 1,
      role: 'Owner',
      tenant: {
        id: 1,
        name: 'Bizflow Test Business',
        status: 'approved',
      },
    };

    authService.getCurrentUser.mockReturnValue(of(user));

    const result = await resolveGuardResult();

    expect(result).toBe(true);
  });

  it('blocks a pending tenant', async () => {
    const user: AuthUser = {
      id: 1,
      name: 'Pending Owner',
      email: 'pending@bizflow.test',
      tenant_id: 1,
      role: 'Owner',
      tenant: {
        id: 1,
        name: 'Pending Business',
        status: 'pending',
      },
    };

    authService.getCurrentUser.mockReturnValue(of(user));

    const result = await resolveGuardResult();

    expect(result).toEqual(router.parseUrl('/login'));
  });

  it('blocks a rejected tenant', async () => {
    const user: AuthUser = {
      id: 1,
      name: 'Rejected Owner',
      email: 'rejected@bizflow.test',
      tenant_id: 1,
      role: 'Owner',
      tenant: {
        id: 1,
        name: 'Rejected Business',
        status: 'rejected',
      },
    };

    authService.getCurrentUser.mockReturnValue(of(user));

    const result = await resolveGuardResult();

    expect(result).toEqual(router.parseUrl('/login'));
  });

  it('blocks a suspended tenant', async () => {
    const user: AuthUser = {
      id: 1,
      name: 'Suspended Owner',
      email: 'suspended@bizflow.test',
      tenant_id: 1,
      role: 'Owner',
      tenant: {
        id: 1,
        name: 'Suspended Business',
        status: 'suspended',
      },
    };

    authService.getCurrentUser.mockReturnValue(of(user));

    const result = await resolveGuardResult();

    expect(result).toEqual(router.parseUrl('/login'));
  });

  it('blocks access when the current-user request fails', async () => {
    authService.getCurrentUser.mockReturnValue(
      throwError(() => new Error('Unable to load user')),
    );

    const result = await resolveGuardResult();

    expect(result).toEqual(router.parseUrl('/login'));
  });
});