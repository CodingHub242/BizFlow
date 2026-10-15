// import { ComponentFixture, TestBed } from '@angular/core/testing';
// import { ActivatedRoute, convertToParamMap } from '@angular/router';
// import { of } from 'rxjs';
// import { vi } from 'vitest';

// import { QuickBooksApi } from '../../../core/services/quickbooks-api';
// import { QuickBooksMigrationComponent } from './quickbooks-migration.component';

// describe('QuickBooksMigrationComponent', () => {
//   let component: QuickBooksMigrationComponent;
//   let fixture: ComponentFixture<QuickBooksMigrationComponent>;

//   let quickBooksApi: {
//     connect: ReturnType<typeof vi.fn>;
//     status: ReturnType<typeof vi.fn>;
//     disconnect: ReturnType<typeof vi.fn>;
//     companyInfo: ReturnType<typeof vi.fn>;
//     migrationCatalog: ReturnType<typeof vi.fn>;
//     previewEntity: ReturnType<typeof vi.fn>;
//     importEntity: ReturnType<typeof vi.fn>;
//   };

//   beforeEach(async () => {
//     quickBooksApi = {
//       connect: vi.fn().mockReturnValue(
//         of({
//           success: true,
//           data: { authorization_url: 'https://example.test/authorize' },
//         }),
//       ),
//       status: vi.fn().mockReturnValue(
//         of({ success: true, data: [] }),
//       ),
//       disconnect: vi.fn().mockReturnValue(
//         of({
//           success: true,
//           message: 'QuickBooks disconnected.',
//         }),
//       ),
//       companyInfo: vi.fn().mockReturnValue(
//         of({ success: true, data: null }),
//       ),
//       migrationCatalog: vi.fn().mockReturnValue(
//         of({ success: true, data: null }),
//       ),
//       previewEntity: vi.fn().mockReturnValue(
//         of({ success: true, data: null }),
//       ),
//       importEntity: vi.fn().mockReturnValue(
//         of({ success: true, data: null }),
//       ),
//     };

//     await TestBed.configureTestingModule({
//       imports: [QuickBooksMigrationComponent],
//       providers: [
//         {
//           provide: QuickBooksApi,
//           useValue: quickBooksApi,
//         },
//         {
//           provide: ActivatedRoute,
//           useValue: {
//             queryParamMap: of(convertToParamMap({})),
//           },
//         },
//       ],
//     }).compileComponents();
//   });

//   function createComponent(): void {
//     fixture = TestBed.createComponent(QuickBooksMigrationComponent);
//     component = fixture.componentInstance;
//     fixture.detectChanges();
//   }

//   it('should create the component', () => {
//     createComponent();

//     expect(component).toBeTruthy();
//   });

//   it('should load QuickBooks connections on initialization', () => {
//     createComponent();

//     expect(quickBooksApi.status).toHaveBeenCalledOnce();
//   });

//   it('should display an empty state when no company is connected', () => {
//     createComponent();

//     const element: HTMLElement = fixture.nativeElement;

//     expect(element.textContent).toContain(
//       'No QuickBooks company connected',
//     );
//   });

//   it('should identify entities that support importing', () => {
//     createComponent();

//     expect(component.isImportable('customers')).toBe(true);
//     expect(component.isImportable('invoices')).toBe(true);
//     expect(component.isImportable('expenses')).toBe(false);
//   });

//   it('should require a branch for invoices, bills, and estimates', () => {
//     createComponent();

//     expect(component.requiresBranch('invoices')).toBe(true);
//     expect(component.requiresBranch('bills')).toBe(true);
//     expect(component.requiresBranch('estimates')).toBe(true);
//     expect(component.requiresBranch('customers')).toBe(false);
//     expect(component.requiresBranch('payments')).toBe(false);
//   });

//   it('should clear a pending import when cancelled', () => {
//     createComponent();

//     component.pendingImportEntity = 'invoices';
//     component.branchId = 5;

//     component.cancelPendingImport();

//     expect(component.pendingImportEntity).toBeNull();
//     expect(component.branchId).toBeNull();
//   });
// });