import {
  Component,
  ElementRef,
  HostListener,
  Input,
  forwardRef,
  inject,
} from '@angular/core';
import {
  ControlValueAccessor,
  NG_VALUE_ACCESSOR,
} from '@angular/forms';

export interface SearchableSelectOption {
  value: number;
  label: string;
  description?: string;
}

@Component({
  selector: 'app-searchable-select',
  standalone: true,
  templateUrl: './searchable-select.component.html',
  styleUrl: './searchable-select.component.scss',
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => SearchableSelectComponent),
      multi: true,
    },
  ],
})
export class SearchableSelectComponent implements ControlValueAccessor {
  private static nextId = 0;
  private readonly elementRef = inject(ElementRef<HTMLElement>);

  @Input() inputId =
    `searchable-select-${SearchableSelectComponent.nextId++}`;
  @Input() options: SearchableSelectOption[] = [];
  @Input() placeholder = 'Select an option';
  @Input() searchPlaceholder = 'Type to search...';
  @Input() noResultsText = 'No matching options found.';
  @Input() disabled = false;
  @Input() required = false;
  @Input() clearable = true;

  isOpen = false;
  search = '';
  activeIndex = 0;

  private value: number | null = null;
  private onChange: (value: number | null) => void = () => {};
  private onTouched: () => void = () => {};

 

  get selectedOption(): SearchableSelectOption | undefined {
    return this.options.find((option) => option.value === this.value);
  }

  get canClearSelection(): boolean {
    return Boolean(
      this.clearable && this.selectedOption && !this.isDisabled,
    );
  }

  get displayValue(): string {
    return this.isOpen ? this.search : this.selectedOption?.label ?? '';
  }

  get listboxId(): string {
    return `${this.inputId}-listbox`;
  }

  get filteredOptions(): SearchableSelectOption[] {
    const term = this.search.trim().toLowerCase();

    if (!term) {
      return this.options;
    }

    return this.options.filter((option) =>
      `${option.label} ${option.description ?? ''}`
        .toLowerCase()
        .includes(term),
    );
  }

  writeValue(value: number | string | null | undefined): void {
    if (value === null || value === undefined || value === '') {
      this.value = null;
      return;
    }

    const numericValue = Number(value);
    this.value = Number.isFinite(numericValue) ? numericValue : null;
  }

  registerOnChange(fn: (value: number | null) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

 get isDisabled(): boolean {
  return this.disabled;
}

setDisabledState(disabled: boolean): void {
  this.disabled = disabled;

  if (disabled) {
    this.closeDropdown();
  }
}

  openDropdown(): void {
    if (this.isDisabled || this.isOpen) return;

    this.search = '';
    this.activeIndex = 0;
    this.isOpen = true;
  }

  toggleDropdown(): void {
    if (this.isDisabled) return;

    if (this.isOpen) {
      this.closeDropdown();
    } else {
      this.openDropdown();
    }
  }

  onInput(event: Event): void {
    if (this.isDisabled) return;

    this.search = (event.target as HTMLInputElement).value;
    this.activeIndex = 0;
    this.isOpen = true;
  }

  selectOption(option: SearchableSelectOption): void {
    if (this.isDisabled) return;

    this.value = option.value;
    this.onChange(this.value);
    this.onTouched();
    this.closeDropdown();
  }

  clearSelection(event: MouseEvent): void {
    event.stopPropagation();

    if (!this.canClearSelection) return;

    this.value = null;
    this.search = '';
    this.onChange(null);
    this.onTouched();
    this.closeDropdown();
  }

  onKeydown(event: KeyboardEvent): void {
    if (this.isDisabled) return;

    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();

        if (!this.isOpen) {
          this.openDropdown();
        } else if (this.filteredOptions.length) {
          this.activeIndex =
            (this.activeIndex + 1) % this.filteredOptions.length;
        }
        break;

      case 'ArrowUp':
        event.preventDefault();

        if (!this.isOpen) {
          this.openDropdown();
        } else if (this.filteredOptions.length) {
          this.activeIndex =
            (this.activeIndex - 1 + this.filteredOptions.length) %
            this.filteredOptions.length;
        }
        break;

      case 'Enter':
        if (this.isOpen && this.filteredOptions.length) {
          event.preventDefault();

          const option = this.filteredOptions[this.activeIndex];
          if (option) this.selectOption(option);
        } else if (!this.isOpen) {
          event.preventDefault();
          this.openDropdown();
        }
        break;

      case 'Escape':
        if (this.isOpen) {
          event.preventDefault();
          this.closeDropdown();
        }
        break;

      case 'Tab':
        this.closeDropdown();
        break;
    }
  }

  closeDropdown(): void {
    this.isOpen = false;
    this.search = '';
    this.activeIndex = 0;
  }

  onOptionMouseDown(event: MouseEvent): void {
    event.preventDefault();
  }

  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    if (
      this.isOpen &&
      !this.elementRef.nativeElement.contains(event.target as Node)
    ) {
      this.closeDropdown();
      this.onTouched();
    }
  }

  optionId(index: number): string {
    return `${this.listboxId}-option-${index}`;
  }
}