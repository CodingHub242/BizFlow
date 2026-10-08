import {ChangeDetectorRef,Component,OnDestroy,OnInit,inject} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import {Subject,debounceTime,distinctUntilChanged,takeUntil} from 'rxjs';
import { Branch, BranchApi } from '../../core/services/branch-api';

@Component({
  selector: 'app-branches',
  templateUrl: './branches.component.html',
  styleUrls: ['./branches.component.scss'],
  imports: [FormsModule],
})
export class BranchesComponent implements OnInit, OnDestroy {
  private readonly router = inject(Router);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly branchApi = inject(BranchApi);

  private readonly destroy$ = new Subject<void>();
  private readonly searchSubject = new Subject<string>();

  branches: Branch[] = [];

  loading = false;
  error = '';

  search = '';

  currentPage = 1;
  lastPage = 1;
  totalItems = 0;
  perPage = 15;

  showCreateBranch = false;

  newBranch = {
    name: '',
    code: '',
    address: '',
    phone: '',
    email: '',
  };

  creatingBranch = false;
  branchCreateError = '';

  ngOnInit(): void {
    this.searchSubject
      .pipe(
        debounceTime(300),
        distinctUntilChanged(),
        takeUntil(this.destroy$),
      )
      .subscribe((search) => {
        this.search = search.trim();
        this.loadBranches(1);
      });

    this.loadBranches(1);
  }

  loadBranches(page = 1): void {
    this.loading = true;
    this.error = '';

    this.branchApi
      .list({
        page,
        search: this.search || undefined,
        isActive: true,
        perPage: this.perPage,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.branches = response.data;

          this.currentPage = response.meta.current_page;
          this.lastPage = response.meta.last_page;
          this.totalItems = response.meta.total;

          this.loading = false;

          this.cdr.markForCheck();
        },

        error: () => {
          this.branches = [];
          this.loading = false;
          this.error =
            'Unable to load branches. Please try again.';

          this.cdr.markForCheck();
        },
      });
  }

  searchBranch(value: string): void {
    this.searchSubject.next(value);
  }

  loadPage(page: number): void {
    if (
      page < 1 ||
      page > this.lastPage ||
      page === this.currentPage
    ) {
      return;
    }

    this.loadBranches(page);
  }

  viewBranch(id: number): void {
    this.router.navigate(['/branches', id]);
  }

  openCreateBranch(): void {
    this.branchCreateError = '';

    this.newBranch = {
      name: '',
      code: '',
      address: '',
      phone: '',
      email: '',
    };

    this.showCreateBranch = true;
  }

  closeCreateBranch(): void {
    if (this.creatingBranch) {
      return;
    }

    this.showCreateBranch = false;
  }

  createBranch(): void {
    this.branchCreateError = '';

    const name = this.newBranch.name.trim();
    const code = this.newBranch.code.trim();

    if (!name) {
      this.branchCreateError = 'Branch name is required.';
      return;
    }

    if (!code) {
      this.branchCreateError = 'Branch code is required.';
      return;
    }

    this.creatingBranch = true;

    this.branchApi
      .create({
        name,
        code,
        address: this.newBranch.address.trim() || undefined,
        phone: this.newBranch.phone.trim() || undefined,
        email: this.newBranch.email.trim() || undefined,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          const branch = response.data;

          this.creatingBranch = false;
          this.showCreateBranch = false;

          /*
           * Reload the current list from the API rather than
           * manually inserting the branch into a paginated list.
           */
          this.loadBranches(this.currentPage);
        },

        error: (error) => {
          this.creatingBranch = false;

          this.branchCreateError =
            error?.error?.message ||
            'Unable to create branch. Please try again.';

          this.cdr.markForCheck();
        },
      });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}