import { ComponentFixture, TestBed } from '@angular/core/testing';

import { EmployeesItemRequestComponent } from './employees-item-request.component';

describe('EmployeesItemRequestComponent', () => {
  let component: EmployeesItemRequestComponent;
  let fixture: ComponentFixture<EmployeesItemRequestComponent>;

  beforeEach(() => {
    fixture = TestBed.createComponent(EmployeesItemRequestComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
