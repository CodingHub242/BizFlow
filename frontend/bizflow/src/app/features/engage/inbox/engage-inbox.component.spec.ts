import { ComponentFixture, TestBed } from '@angular/core/testing';

import { EngageInboxComponent } from '../engage-inbox.component';

describe('EngageInboxComponent', () => {
  let component: EngageInboxComponent;
  let fixture: ComponentFixture<EngageInboxComponent>;

  beforeEach(() => {
    fixture = TestBed.createComponent(EngageInboxComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
