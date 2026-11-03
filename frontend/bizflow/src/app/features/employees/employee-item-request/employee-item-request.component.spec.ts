import { ComponentFixture, TestBed } from '@angular/core/testing';

import { EmployeeItemRequestComponent } from './employee-item-request.component';

describe('EmployeeItemRequestComponent', () => {
  let component: EmployeeItemRequestComponent;
  let fixture: ComponentFixture<EmployeeItemRequestComponent>;

  beforeEach(() => {
    fixture = TestBed.createComponent(EmployeeItemRequestComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
