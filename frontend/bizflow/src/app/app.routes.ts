import { Routes } from '@angular/router';
import { authGuard } from './core/guards/auth.guard';
import { tenantApprovedGuard } from './core/guards/tenant-approved.guard';

export const routes: Routes = [
  {
    path: 'login',
    loadComponent: () =>
      import('./features/auth/login/login.component')
        .then(m => m.LoginComponent),
  },
  {
    path: '',
     canActivate: [authGuard, tenantApprovedGuard],
     loadComponent: () =>
      import('./layout/app-shell/app-shell.component').then(
        (m) => m.AppShellComponent,
      ),

      children: [
      {
        path: '',
        redirectTo: 'dashboard',
        pathMatch: 'full',
      },
      {
        path: 'dashboard',
       // canActivate: [authGuard],
        loadComponent: () =>
          import('./features/dashboard/dashboard.component').then((m) => m.DashboardComponent),
      },
      {
        path: 'customers',
       // canActivate: [authGuard],
        loadComponent: () =>
          import('./features/customers/customers.component')
            .then(m => m.CustomersComponent),
      },
      {
        path: 'customers/add',
       // canActivate: [authGuard],
        loadComponent: () =>
          import('./features/customers/add-customer/add-customer.component')
            .then(m => m.AddCustomerComponent),
      },
      {
        path: 'customers/:id',
      //  canActivate: [authGuard],
        loadComponent: () =>
          import('./features/customers/customer-details/customer-details.component')
            .then(m => m.CustomerDetailsComponent),
      },

      {
      path: 'catalog',
     // canActivate: [authGuard],
      loadComponent: () =>
        import('./features/catalog/catalog.component')
          .then(m => m.CatalogComponent),
    },

    {
      path: 'catalog/add',
     // canActivate: [authGuard],
      loadComponent: () =>
        import('./features/catalog/add-catalog-item/add-catalog-item.component')
          .then(m => m.AddCatalogItemComponent),
    },

    {
      path: 'catalog/:id',
    //  canActivate: [authGuard],
      loadComponent: () =>
        import('./features/catalog/catalog-details/catalog-details.component')
          .then(m => m.CatalogDetailsComponent),
    },

    {
      path: 'inventory',
    //  canActivate: [authGuard],
      loadComponent: () =>
        import('./features/inventory/inventory.component')
          .then(m => m.InventoryComponent),
    },
    {
      path: 'inventory/receive-stock',
    //  canActivate: [authGuard],
      loadComponent: () =>
        import('./features/inventory/receive-stock/receive-stock.component')
          .then(m => m.ReceiveStockComponent),
    },
    {
      path: 'inventory/adjust-stock',
      loadComponent: () =>
        import('./features/inventory/adjust-stock/adjust-stock.component')
          .then(m => m.AdjustStockComponent),
    },
    {
      path: 'inventory/transfer-stock',
      loadComponent: () =>
        import('./features/inventory/transfer-stock/transfer-stock.component')
          .then(m => m.TransferStockComponent),
    },
    {
      path: 'inventory/import-export',
      loadComponent: () =>
        import('./features/inventory/inventory-import-export/inventory-import-export.component')
          .then(m => m.InventoryImportExportComponent),
    },
    {
      path: 'inventory/:id',
      loadComponent: () =>
        import('./features/inventory/inventory-details/inventory-details.component')
          .then(m => m.InventoryDetailsComponent),
    },
  
    {
      path: 'branches',
    //  canActivate: [authGuard],
      loadComponent: () =>
        import('./features/branches/branches.component')
          .then(m => m.BranchesComponent),
    },
    {
      path: 'branches/:id',
      loadComponent: () =>
        import('./features/branches/branch-details/branch-details.component')
          .then(m => m.BranchDetailsComponent),
    },
    {
      path: 'sales',
      loadComponent: () =>
        import('./features/sales/sales.component')
          .then(m => m.SalesComponent),
    },
    {
      path: 'invoices',
      loadComponent: () =>
        import('./features/invoices/invoices.component')
          .then(m => m.InvoicesComponent),
    },
    {
      path: 'invoices/create',
      loadComponent: () =>
        import('./features/invoices/create-invoice/create-invoice.component')
          .then(m => m.CreateInvoiceComponent),
    },
    {
      path: 'invoices/:id',
      loadComponent: () =>
        import('./features/invoices/invoice-details/invoice-details.component')
          .then(m => m.InvoiceDetailsComponent),
    },

    {
      path: 'activity',
      loadComponent: () =>
        import('./features/activity/activity.component')
          .then(m => m.ActivityComponent),
    },
    {
      path: 'integrations/quickbooks-migration',
      loadComponent: () =>
        import(
          './features/integrations/quickbooks-migration/quickbooks-migration.component'
        ).then((m) => m.QuickBooksMigrationComponent),
    },
    {
      path: 'expenses',
      loadComponent: () =>
        import('./features/expenses/expenses.component')
          .then(m => m.ExpensesComponent),
    },
    {
      path: 'reports',
      loadComponent: () =>
        import('./features/reports/reports.component')
          .then(m => m.ReportsComponent),
    },
    {
      path: 'generalreports',
      loadComponent: () =>
        import('./features/general-reports/general-reports.component')
          .then(m => m.GeneralReportsComponent),
    },
      
    ],
  },
  {
    path: 'register',
    loadComponent: () =>
      import('./features/auth/register/register.component')
        .then(m => m.RegisterComponent),
  },
];
