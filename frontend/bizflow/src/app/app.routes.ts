import { Routes } from '@angular/router';
import { authGuard } from './core/guards/auth.guard';

export const routes: Routes = [
  {
    path: 'login',
    loadComponent: () =>
      import('./features/auth/login/login.component')
        .then(m => m.LoginComponent),
  },
  {
    path: '',
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
        canActivate: [authGuard],
        loadComponent: () =>
          import('./features/dashboard/dashboard.component').then((m) => m.DashboardComponent),
      },
      {
        path: 'customers',
        canActivate: [authGuard],
        loadComponent: () =>
          import('./features/customers/customers.component')
            .then(m => m.CustomersComponent),
      },
      {
        path: 'customers/add',
        canActivate: [authGuard],
        loadComponent: () =>
          import('./features/customers/add-customer/add-customer.component')
            .then(m => m.AddCustomerComponent),
      },
      {
        path: 'customers/:id',
        canActivate: [authGuard],
        loadComponent: () =>
          import('./features/customers/customer-details/customer-details.component')
            .then(m => m.CustomerDetailsComponent),
      },

      {
      path: 'catalog',
      canActivate: [authGuard],
      loadComponent: () =>
        import('./features/catalog/catalog.component')
          .then(m => m.CatalogComponent),
    },

    {
      path: 'catalog/add',
      canActivate: [authGuard],
      loadComponent: () =>
        import('./features/catalog/add-catalog-item/add-catalog-item.component')
          .then(m => m.AddCatalogItemComponent),
    },

    {
      path: 'catalog/:id',
      canActivate: [authGuard],
      loadComponent: () =>
        import('./features/catalog/catalog-details/catalog-details.component')
          .then(m => m.CatalogDetailsComponent),
    },

    {
      path: 'inventory',
      canActivate: [authGuard],
      loadComponent: () =>
        import('./features/inventory/inventory.component')
          .then(m => m.InventoryComponent),
    },
      
    ],
  },
];
