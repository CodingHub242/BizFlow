import { ComponentFixture, TestBed } from '@angular/core/testing';

import { EmployeeItemRequestCreateComponent } from './employee-item-request-create.component';

describe('EmployeeItemRequestCreateComponent', () => {
  let component: EmployeeItemRequestCreateComponent;
  let fixture: ComponentFixture<EmployeeItemRequestCreateComponent>;

  beforeEach(() => {
    fixture = TestBed.createComponent(EmployeeItemRequestCreateComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
