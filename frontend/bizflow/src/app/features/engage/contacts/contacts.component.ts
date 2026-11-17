import { Component, OnInit, OnDestroy,CUSTOM_ELEMENTS_SCHEMA } from '@angular/core';
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

  private searchSubject = new Subject<string>();
  private destroy$ = new Subject<void>();

  constructor(private engageService: EngageService) {
    addIcons({
      arrowDownOutline,
      arrowUpOutline,
      cartOutline,
      cardOutline,
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
        },
        error: (error) => {
          this.loading = false;
          this.errorMessage =
            error?.error?.message || 'Unable to load contacts. Please try again.';
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

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
    this.searchSubject.complete();
  }
}