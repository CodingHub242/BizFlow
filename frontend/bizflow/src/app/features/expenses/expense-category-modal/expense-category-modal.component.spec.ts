import { ComponentFixture, TestBed } from '@angular/core/testing';

import { ExpenseCategoryModalComponent } from './expense-category-modal.component';

describe('ExpenseCategoryModalComponent', () => {
  let component: ExpenseCategoryModalComponent;
  let fixture: ComponentFixture<ExpenseCategoryModalComponent>;

  beforeEach(() => {
    fixture = TestBed.createComponent(ExpenseCategoryModalComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
