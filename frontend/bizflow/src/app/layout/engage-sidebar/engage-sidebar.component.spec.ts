import { ComponentFixture, TestBed } from '@angular/core/testing';

import { EngageSidebarComponent } from './engage-sidebar.component';

describe('EngageSidebarComponent', () => {
  let component: EngageSidebarComponent;
  let fixture: ComponentFixture<EngageSidebarComponent>;

  beforeEach(() => {
    fixture = TestBed.createComponent(EngageSidebarComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
