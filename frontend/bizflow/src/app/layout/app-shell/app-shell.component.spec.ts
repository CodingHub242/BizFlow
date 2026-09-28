import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { vi } from 'vitest';

import { AppShellComponent } from './app-shell.component';
import { InactivityService } from '../../core/services/inactivity.service';

describe('AppShellComponent', () => {
  let component: AppShellComponent;
  let fixture: ComponentFixture<AppShellComponent>;

  let inactivityService: {
    start: ReturnType<typeof vi.fn>;
    stop: ReturnType<typeof vi.fn>;
  };

  beforeEach(async () => {
    inactivityService = {
      start: vi.fn(),
      stop: vi.fn(),
    };

    await TestBed.configureTestingModule({
      imports: [AppShellComponent],
      providers: [
        provideRouter([]),
        {
          provide: InactivityService,
          useValue: inactivityService,
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(AppShellComponent);

    component = fixture.componentInstance;

    await fixture.whenStable();
  });

  it('should render the application shell structure', () => {
    const element: HTMLElement = fixture.nativeElement;

    expect(element.querySelector('app-sidebar')).not.toBeNull();
    expect(element.querySelector('app-topbar')).not.toBeNull();
    expect(element.querySelector('router-outlet')).not.toBeNull();
  });

  it('should start inactivity monitoring when the app shell is created', () => {
    expect(inactivityService.start).toHaveBeenCalled();
  });
  it('should stop inactivity monitoring when the app shell is destroyed', () => {
  fixture.destroy();

  expect(inactivityService.stop).toHaveBeenCalled();
});
});