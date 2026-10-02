import { Component, OnInit,OnDestroy, ChangeDetectorRef, inject } from '@angular/core';
import { CatalogApi, CatalogItem, CatalogPagination, CatalogItemType,CatalogListParams } from '../../core/services/catalog-api';
import { FormsModule } from '@angular/forms'; 
import { Router } from '@angular/router'; 
import { Subject, Subscription } from 'rxjs';
import { debounceTime, distinctUntilChanged, } from 'rxjs/operators';

@Component({
  selector: 'app-catalog',
  templateUrl: './catalog.component.html',
  styleUrls: ['./catalog.component.scss'],
  imports: [FormsModule],
})
export class CatalogComponent  {
private readonly catalogApi = inject(CatalogApi); 
private readonly searchSubject = new Subject<string>(); 
private readonly searchSubscription: Subscription; 
private readonly router = inject(Router); 
private readonly changeDetector = inject(ChangeDetectorRef);

readonly Math = Math; 
catalogItems: CatalogItem[] = [];
pagination: CatalogPagination = { current_page: 1, last_page: 1, per_page: 15, total: 0, };
search = ''; 
type: CatalogItemType | '' = '';
isActive: boolean | '' = '';
viewMode: 'active' | 'archived' = 'active';

  constructor() { 
    this.searchSubscription = this.searchSubject .pipe( debounceTime(300), distinctUntilChanged(), ) .subscribe(search => { this.loadCatalogItems(1, search); });
  }

  ngOnInit(): void {
    this.loadCatalogItems();
  }

  private loadCatalogItems(page = 1,search = this.search): void {
      const params: CatalogListParams = {
        page,
        search,
        type: this.type || undefined,
        perPage: 15,
      };

      const request$ =
        this.viewMode === 'archived'
          ? this.catalogApi.archived(params)
          : this.catalogApi.list({
              ...params,
              isActive: this.isActive === '' ? undefined : this.isActive,
            });

      request$.subscribe({
        next: response => {
          this.catalogItems = response.data;
          this.pagination = response.meta;
          this.changeDetector.detectChanges();
        },
        error: error => {
          console.error('Unable to load catalog items:', error);
        },
      });
  }

  loadPage(page: number): void 
  { 
    this.loadCatalogItems(page, this.search); 
  }

  searchCatalogItems(): void 
  { 
    this.searchSubject.next(this.search); 
  }

  filterCatalog(): void 
  { 
    this.loadCatalogItems(1, this.search); 
  }

  addCatalogItem(): void 
  { 
    this.router.navigate(['/catalog/add']); 
  }

  setViewMode(mode: 'active' | 'archived'): void {
    this.viewMode = mode;

    this.search = '';
    this.type = '';

    if (mode === 'active') {
      this.isActive = '';
    }
    this.changeDetector.detectChanges();
    this.loadCatalogItems(1, '');

    
  }

  viewCatalogItem(id: number): void 
  { 
    this.router.navigate(['/catalog', id]); 
  }

  ngOnDestroy(): void 
  { 
    this.searchSubscription.unsubscribe(); 
  }

}
