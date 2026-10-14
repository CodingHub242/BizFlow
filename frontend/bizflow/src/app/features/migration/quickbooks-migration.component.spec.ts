import { ComponentFixture, TestBed } from '@angular/core/testing';

import { QuickbooksMigrationComponent } from './quickbooks-migration.component';

describe('QuickbooksMigrationComponent', () => {
  let component: QuickbooksMigrationComponent;
  let fixture: ComponentFixture<QuickbooksMigrationComponent>;

  beforeEach(() => {
    fixture = TestBed.createComponent(QuickbooksMigrationComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
