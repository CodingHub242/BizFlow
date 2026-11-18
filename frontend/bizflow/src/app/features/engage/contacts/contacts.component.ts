import { Component, OnInit, OnDestroy,CUSTOM_ELEMENTS_SCHEMA,ChangeDetectorRef,inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject, debounceTime, distinctUntilChanged, takeUntil } from 'rxjs';
import { IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  arrowDownOutline,
  arrowUpOutline,
  cartOutline,
  documentTextOutline,
  peopleOutline,
  cardOutline,
  receiptOutline,
  walletOutline,
  alertCircleOutline,
  arrowForwardOutline,
  ellipsisHorizontalOutline,
  closeOutline,
} from 'ionicons/icons';
import {
  EngageContact,
  EngageContactFilters,
  EngageService
} from '../../../core/services/engage.service';

@Component({
  selector: 'app-contacts',
  standalone: true,
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
  imports: [CommonModule, FormsModule],
  templateUrl: './contacts.component.html',
  styleUrls: ['./contacts.component.scss'],
})
export class ContactsComponent implements OnInit, OnDestroy {
  private readonly cdr = inject(ChangeDetectorRef);
  contacts: EngageContact[] = [];

  loading = false;
  errorMessage = '';

  search = '';
  status = '';
  lifecycleStatus = '';

  currentPage = 1;
  lastPage = 1;
  perPage = 15;
  total = 0;

  selectedContact: EngageContact | null = null;
  detailsLoading = false;
  detailsError = '';
  drawerOpen = false;

  private searchSubject = new Subject<string>();
  private destroy$ = new Subject<void>();

  isEditing = false;
  savingContact = false;
  saveError = '';

  editForm = {
    name: '',
    phone: '',
    email: '',
    company_name: '',
    lifecycle_status: 'prospect' as 'prospect' | 'lead' | 'customer',
    status: 'active' as 'active' | 'inactive' | 'archived',
    notes: ''
  };

  constructor(private engageService: EngageService) {
    addIcons({
      arrowDownOutline,
      arrowUpOutline,
      cartOutline,
      cardOutline,
      closeOutline,
      receiptOutline,
      documentTextOutline,
      peopleOutline,
      walletOutline,
      alertCircleOutline,
      arrowForwardOutline,
      ellipsisHorizontalOutline,
    });
  }

  ngOnInit(): void {
    this.searchSubject.pipe(
      debounceTime(350),
      distinctUntilChanged(),
      takeUntil(this.destroy$)
    ).subscribe(() => {
      this.currentPage = 1;
      this.loadContacts();
    });

    this.loadContacts();
  }

  loadContacts(): void {
    this.loading = true;
    this.errorMessage = '';

    const filters: EngageContactFilters = {
      page: this.currentPage,
      per_page: this.perPage,
      search: this.search.trim() || undefined,
      status: this.status
        ? this.status as EngageContactFilters['status']
        : undefined,
      lifecycle_status: this.lifecycleStatus
        ? this.lifecycleStatus as EngageContactFilters['lifecycle_status']
        : undefined,
    };

    this.engageService.getContacts(filters)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.contacts = response.data;
          this.currentPage = response.meta.current_page;
          this.lastPage = response.meta.last_page;
          this.total = response.meta.total;
          this.loading = false;

          this.cdr.detectChanges();
        },
        error: (error) => {
          this.loading = false;
          this.errorMessage =
            error?.error?.message || 'Unable to load contacts. Please try again.';

            this.cdr.detectChanges();
        }
      });
  }

  onSearchChange(value: string): void {
    this.search = value;
    this.searchSubject.next(value);
  }

  onFilterChange(): void {
    this.currentPage = 1;
    this.loadContacts();
  }

  goToPage(page: number): void {
    if (page < 1 || page > this.lastPage || page === this.currentPage) {
      return;
    }

    this.currentPage = page;
    this.loadContacts();
  }

  get showingFrom(): number {
    return this.total === 0 ? 0 : (this.currentPage - 1) * this.perPage + 1;
  }

  get showingTo(): number {
    return Math.min(this.currentPage * this.perPage, this.total);
  }

  openContact(contact: EngageContact): void {
    this.drawerOpen = true;
    this.detailsLoading = true;
    this.detailsError = '';
    this.selectedContact = contact;

    this.engageService.getContact(contact.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.selectedContact = response.data;
          this.detailsLoading = false;

          this.cdr.detectChanges();
        },
        error: (error) => {
          this.detailsLoading = false;
          this.detailsError =
            error?.error?.message || 'Unable to load contact details.';

            this.cdr.detectChanges();
        }
      });
  }

  startEditing(): void {
  if (!this.selectedContact) return;

  const contact = this.selectedContact;

  this.editForm = {
    name: contact.name || '',
    phone: contact.phone || '',
    email: contact.email || '',
    company_name: contact.company_name || '',
    lifecycle_status: contact.lifecycle_status,
    status: contact.status,
    notes: contact.notes || ''
  };

  this.saveError = '';
  this.isEditing = true;
}

cancelEditing(): void {
  this.isEditing = false;
  this.saveError = '';
}

saveContact(): void {
  if (!this.selectedContact || this.savingContact) return;

  this.savingContact = true;
  this.saveError = '';

  this.engageService.updateContact(
    this.selectedContact.id,
    this.editForm
  )
  .pipe(takeUntil(this.destroy$))
  .subscribe({
    next: (response) => {
      this.selectedContact = response.data;
      this.isEditing = false;
      this.savingContact = false;

      // Keep the listing in sync with the updated contact.
      this.contacts = this.contacts.map(contact =>
        contact.id === response.data.id ? response.data : contact
      );

      this.cdr.detectChanges();
    },
    error: (error) => {
      this.savingContact = false;
      this.saveError =
        error?.error?.errors || 'Unable to save contact changes.';

        this.cdr.detectChanges();
    }
  });
}

  closeContactDrawer(): void {
    this.drawerOpen = false;
    this.selectedContact = null;
    this.detailsError = '';
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
    this.searchSubject.complete();
  }
}