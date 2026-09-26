import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { AppShellComponent } from './app-shell.component';

describe('AppShellComponent', () => {
  let component: AppShellComponent;
  let fixture: ComponentFixture<AppShellComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AppShellComponent],
      providers: [provideRouter([])],
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
});