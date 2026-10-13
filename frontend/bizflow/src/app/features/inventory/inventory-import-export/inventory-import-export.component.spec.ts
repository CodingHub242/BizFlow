import { ComponentFixture, TestBed } from '@angular/core/testing';

import { InventoryImportExportComponent } from './inventory-import-export.component';

describe('InventoryImportExportComponent', () => {
  let component: InventoryImportExportComponent;
  let fixture: ComponentFixture<InventoryImportExportComponent>;

  beforeEach(() => {
    fixture = TestBed.createComponent(InventoryImportExportComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
