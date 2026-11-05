import { ComponentFixture, TestBed } from '@angular/core/testing';

import { EmployeeItemRequestDetailsComponent } from './employee-item-request-details.component';

describe('EmployeeItemRequestDetailsComponent', () => {
  let component: EmployeeItemRequestDetailsComponent;
  let fixture: ComponentFixture<EmployeeItemRequestDetailsComponent>;

  beforeEach(() => {
    fixture = TestBed.createComponent(EmployeeItemRequestDetailsComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
