import { TestBed } from '@angular/core/testing';
import { vi } from 'vitest';
import { AuthService } from './auth.service';
import { InactivityService } from './inactivity.service';

describe('InactivityService', () => {
  let service: InactivityService;

  let authService: {
    getToken: ReturnType<typeof vi.fn>;
    logout: ReturnType<typeof vi.fn>;
  };

  beforeEach(() => {
    authService = {
      getToken: vi.fn(),
      logout: vi.fn(),
    };

    TestBed.configureTestingModule({
      providers: [
        InactivityService,
        {
          provide: AuthService,
          useValue: authService,
        },
      ],
    });

    service = TestBed.inject(InactivityService);
  });

  afterEach(() => {
  service.stop();
  vi.useRealTimers();
});

 it('logs out after the inactivity timeout', () => {
  vi.useFakeTimers();

  authService.getToken.mockReturnValue('test-token');

  service.start(1000);

  vi.advanceTimersByTime(1000);

  expect(authService.logout).toHaveBeenCalled();

  vi.useRealTimers();
});
it('resets the inactivity timer when user activity occurs', () => {
  vi.useFakeTimers();

  authService.getToken.mockReturnValue('test-token');

  service.start(1000);

  vi.advanceTimersByTime(800);

  service.reset();

  vi.advanceTimersByTime(800);

  expect(authService.logout).not.toHaveBeenCalled();

  vi.advanceTimersByTime(200);

  expect(authService.logout).toHaveBeenCalled();
});
it('resets the timer when browser activity occurs', () => {
  vi.useFakeTimers();

  authService.getToken.mockReturnValue('test-token');

  service.start(1000);

  vi.advanceTimersByTime(800);

  window.dispatchEvent(new Event('mousemove'));

  vi.advanceTimersByTime(800);

  expect(authService.logout).not.toHaveBeenCalled();

  vi.advanceTimersByTime(200);

  expect(authService.logout).toHaveBeenCalled();
});
});