import { ComponentFixture, TestBed } from '@angular/core/testing';
import { FormsModule } from '@angular/forms';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { SearchableSelectComponent } from './searchable-select.component';

describe('SearchableSelectComponent', () => {
  let component: SearchableSelectComponent;
  let fixture: ComponentFixture<SearchableSelectComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [FormsModule, SearchableSelectComponent],
    }).compileComponents();

    fixture = TestBed.createComponent(SearchableSelectComponent);
    component = fixture.componentInstance;

    component.inputId = 'test-select';
    component.options = [
      { value: 1, label: 'Accra North', description: 'ACC-N01' },
      { value: 2, label: 'Kumasi Branch', description: 'KMS-01' },
    ];

    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should open when the input receives focus', () => {
    const input = fixture.nativeElement.querySelector(
      'input#test-select',
    ) as HTMLInputElement;

    input.dispatchEvent(new Event('focus'));
    fixture.detectChanges();

    expect(component.isOpen).toBe(true);
    expect(fixture.nativeElement.querySelector('.select-dropdown')).toBeTruthy();
  });

  it('should filter options by label', () => {
    component.search = 'accra';

    expect(component.filteredOptions).toHaveLength(1);
    expect(component.filteredOptions[0].label).toBe('Accra North');
  });

  it('should filter options by code', () => {
    component.search = 'KMS-01';

    expect(component.filteredOptions).toHaveLength(1);
    expect(component.filteredOptions[0].value).toBe(2);
  });

  it('should notify the form when an option is selected', () => {
    const onChange = vi.fn();
    component.registerOnChange(onChange);

    component.selectOption(component.options[0]);

    expect(onChange).toHaveBeenCalledWith(1);
    expect(component.selectedOption?.label).toBe('Accra North');
    expect(component.isOpen).toBe(false);
  });

  it('should clear the selected value', () => {
    const onChange = vi.fn();
    component.registerOnChange(onChange);
    component.writeValue(1);
    fixture.detectChanges();

    expect(component.selectedOption?.value).toBe(1);
    expect(component.canClearSelection).toBe(true);

    component.clearSelection(new MouseEvent('click'));
    fixture.detectChanges();

    expect(onChange).toHaveBeenCalledWith(null);
    expect(component.selectedOption).toBeUndefined();
    expect(component.canClearSelection).toBe(false);
  });

  it('should close the dropdown with Escape', () => {
    component.isOpen = true;
    fixture.detectChanges();

    const input = fixture.nativeElement.querySelector(
      'input#test-select',
    ) as HTMLInputElement;

    input.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    );
    fixture.detectChanges();

    expect(component.isOpen).toBe(false);
  });

 it('should disable the selector through its Angular input', () => {
  fixture.componentRef.setInput('disabled', true);
  fixture.detectChanges();

  const input = fixture.nativeElement.querySelector(
    'input#test-select',
  ) as HTMLInputElement;

  const toggleButton = fixture.nativeElement.querySelector(
    'button.toggle-button',
  ) as HTMLButtonElement;

  expect(component.isDisabled).toBe(true);
  expect(input.disabled).toBe(true);
  expect(toggleButton.disabled).toBe(true);

  component.openDropdown();

  expect(component.isOpen).toBe(false);
});

it('should close the dropdown when the forms API disables it', () => {
  component.isOpen = true;

  component.setDisabledState(true);
  fixture.detectChanges();

  expect(component.isDisabled).toBe(true);
  expect(component.isOpen).toBe(false);

  component.openDropdown();

  expect(component.isOpen).toBe(false);
});
});