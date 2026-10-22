<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CatalogItemController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MigrationSessionController;
use App\Http\Controllers\ExpenseReportController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\QuickBooksCreditMemosImportController;
use App\Http\Controllers\InventoryExportController;
use App\Http\Controllers\QuickBooksCreditMemoApplicationsReconcileController;
use App\Http\Controllers\QuickBooksCustomerImportController;
use App\Http\Controllers\QuickBooksMigrationCatalogController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\QuickBooksExpensesImportController;
use App\Http\Controllers\QuickBooksCatalogItemsImportController;
use App\Http\Controllers\QuickBooksChartOfAccountsImportController;
use App\Http\Controllers\QuickBooksInvoicesImportController;
use App\Http\Controllers\QuickBooksSupplierImportController;
use App\Http\Controllers\QuickBooksPaymentsImportController;
use App\Http\Controllers\QuickBooksCompanyInfoController;
use App\Http\Controllers\QuickBooksBillsImportController;
use App\Http\Controllers\QuickBooksEstimatesImportController;
use App\Http\Controllers\QuickBooksOAuthController;
use App\Http\Controllers\InventoryImportController;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\BusinessOnboardingController;
use App\Http\Controllers\GeneralReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;


//Login
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum');

Route::get('/user', function (Request $request) {
     $user = $request->user();

    $roles = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->pluck('roles.name');

    foreach($roles as $role){}

    return response()->json([
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'role' => $role,
        'tenant_id' => $user->tenant_id,
        'tenant' => [
            'id' => $user->tenant->id,
            'name' => $user->tenant->name,
            'status' => $user->tenant->status->value,
        ],
    ]);
})->middleware('auth:sanctum');

// The callback is intentionally public and protected by the single-use OAuth state value.
Route::prefix('integrations/quickbooks')->name('quickbooks.')->group(function () {
    Route::get('/callback', [QuickBooksOAuthController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('callback');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/connect', [QuickBooksOAuthController::class, 'connect'])
            ->middleware('throttle:10,1')
            ->name('connect');
        Route::get('/status', [QuickBooksOAuthController::class, 'status'])
            ->name('status');

        Route::get('/connections/{connection}/company-info', [QuickBooksCompanyInfoController::class, 'show'])
            ->whereNumber('connection')
            ->middleware('throttle:30,1')
            ->name('company-info');

        Route::get('/connections/{connection}/migration-catalog', [QuickBooksMigrationCatalogController::class, 'index'])
        ->whereNumber('connection')
        ->middleware('throttle:10,1')
        ->name('migration-catalog.index');

        Route::get('/connections/{connection}/migration-catalog/{entityType}', [QuickBooksMigrationCatalogController::class, 'show'])
            ->whereNumber('connection')
            ->where('entityType', 'customers|suppliers|catalog_items|invoices|bills|estimates|chart_of_accounts|expenses|payments|credit_memos')
            ->middleware('throttle:30,1')
            ->name('migration-catalog.preview');

        Route::post(
        '/connections/{connection}/migration-catalog/chart_of_accounts/import',
        [QuickBooksChartOfAccountsImportController::class, 'store']
    )
        ->whereNumber('connection')
        ->middleware('throttle:5,1')
        ->name('migration-catalog.chart-of-accounts.import');

        Route::post(
            '/connections/{connection}/migration-catalog/customers/import',
            [QuickBooksCustomerImportController::class, 'store']
        )
            ->whereNumber('connection')
            ->middleware('throttle:5,1')
            ->name('migration-catalog.customers.import');

        Route::post(
        '/connections/{connection}/migration-catalog/suppliers/import',
        [QuickBooksSupplierImportController::class, 'store']
        )
        ->whereNumber('connection')
        ->middleware('throttle:5,1')
        ->name('migration-catalog.suppliers.import');

        Route::post(
            '/connections/{connection}/migration-catalog/catalog_items/import',
            [QuickBooksCatalogItemsImportController::class, 'store']
        )
            ->whereNumber('connection')
            ->middleware('throttle:5,1')
            ->name('migration-catalog.catalog-items.import');

        Route::post(
            '/connections/{connection}/migration-catalog/bills/import',
            [QuickBooksBillsImportController::class, 'store']
        )
            ->whereNumber('connection')
            ->middleware('throttle:5,1')
            ->name('migration-catalog.bills.import');

        Route::post(
            '/connections/{connection}/migration-catalog/estimates/import',
            [QuickBooksEstimatesImportController::class, 'store']
        )
            ->whereNumber('connection')
            ->middleware('throttle:5,1')
            ->name('migration-catalog.estimates.import');

        Route::post(
            '/connections/{connection}/migration-catalog/invoices/import',
            [QuickBooksInvoicesImportController::class, 'store']
        )
            ->whereNumber('connection')
            ->middleware('throttle:8,1')
            ->name('migration-catalog.invoices.import');

        Route::post(
            '/connections/{connection}/migration-catalog/payments/import',
            [QuickBooksPaymentsImportController::class, 'store']
        )
            ->whereNumber('connection')
            ->middleware('throttle:5,1')
            ->name('migration-catalog.payments.import');

        Route::post(
            '/connections/{connection}/migration-catalog/expenses/import',
            [QuickBooksExpensesImportController::class, 'store']
        )
            ->whereNumber('connection')
            ->middleware('throttle:5,1')
            ->name('migration-catalog.expenses.import');

        Route::post(
            '/connections/{connection}/migration-catalog/credit_memos/import',
            [QuickBooksCreditMemosImportController::class, 'store']
        )
            ->whereNumber('connection')
            ->middleware('throttle:5,1')
            ->name('migration-catalog.credit-memos.import');

        Route::post(
            '/connections/{connection}/migration-catalog/credit_memos/reconcile-applications',
            [QuickBooksCreditMemoApplicationsReconcileController::class, 'store']
        )
            ->whereNumber('connection')
            ->middleware('throttle:5,1')
            ->name('migration-catalog.credit-memos.reconcile-applications');

        Route::delete('/connections/{connection}', [QuickBooksOAuthController::class, 'disconnect'])
            ->whereNumber('connection')
            ->middleware('throttle:10,1')
            ->name('disconnect');
    });
});

//ONBOARDING BUSINESS/COMPANY
Route::post('/onboarding/register',[BusinessOnboardingController::class, 'register'])->middleware('throttle:5,1');

// Route::middleware('auth:sanctum','tenant.approved')
//     ->get('/core-access-test', function (Request $request) {
//         return response()->json([
//             'message' => 'Core access granted.',
//         ]);
//     });



Route::middleware('auth:sanctum','tenant.approved')->group(function () {
    ///INVOICES
    Route::post('/invoices', [InvoiceController::class, 'store'])
        ->middleware('permission:invoices.create');

    Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])
        ->middleware('permission:invoices.record_payment');

    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
        ->middleware('permission:invoices.view');

    Route::get('/invoices', [InvoiceController::class, 'index'])
        ->middleware('permission:invoices.view');

    Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])
        ->middleware('permission:invoices.update');

    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])
        ->middleware('permission:invoices.cancel');

    // CUSTOMERS
    Route::post('/customers', [CustomerController::class, 'store'])->middleware('permission:customers.create');
    Route::get('/customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.update');
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.delete');
    Route::post('/customers/{customer}/restore', [CustomerController::class, 'restore'])->middleware('permission:customers.update')->withTrashed();

    //PRODUCTS/SERVICES
    Route::post('/catalog-items', [CatalogItemController::class, 'store'])
        ->middleware('permission:products.create');

    Route::get('/catalog-items', [CatalogItemController::class, 'index'])
        ->middleware('permission:products.view');

    Route::get('/catalog-items/archived', [CatalogItemController::class, 'archived'])
    ->middleware('permission:products.view');

    Route::get('/catalog-items/{catalogItem}', [CatalogItemController::class, 'show'])
        ->middleware('permission:products.view')
        ->withTrashed();

    Route::put('/catalog-items/{catalogItem}', [CatalogItemController::class, 'update'])
        ->middleware('permission:products.update');

    Route::delete('/catalog-items/{catalogItem}', [CatalogItemController::class, 'destroy'])
        ->middleware('permission:products.delete');

    Route::post('/catalog-items/{catalogItem}/restore', [CatalogItemController::class, 'restore'])
        ->middleware('permission:products.update')
        ->withTrashed();

    Route::post('/catalog-items/{catalogItem}/image',[CatalogItemController::class, 'uploadImage']
    )->middleware('permission:products.update');

    Route::delete('/catalog-items/{catalogItem}/image',[CatalogItemController::class, 'deleteImage']
    )->middleware('permission:products.update');

    //INVENTORY
    Route::get('/inventory', [InventoryController::class, 'index'])
        ->middleware('permission:inventory.view');

    Route::get('/inventory/availability', [InventoryController::class, 'availability'])
    ->middleware('permission:inventory.view');

    Route::get('/inventory/movements', [InventoryController::class, 'movements'])
        ->middleware('permission:inventory.view');

    Route::get('/inventory/export',[InventoryExportController::class, 'export']
    )->middleware('permission:inventory.view');

     Route::post('/inventory/receive', [InventoryController::class, 'receive'])
        ->middleware('permission:inventory.receive');

    Route::post('/inventory/adjust', [InventoryController::class, 'adjust'])
        ->middleware('permission:inventory.adjust');

    Route::post('/inventory/transfer', [InventoryController::class, 'transfer'])
        ->middleware('permission:inventory.transfer');

    
    Route::post('/inventory/opening-stock', [InventoryController::class,'openingStock',
    ])->middleware('permission:inventory.receive');

    Route::get('/inventory/opening-stock-candidates',[InventoryController::class, 'openingStockCandidates']
    )->middleware('permission:inventory.receive');

    Route::get('/inventory/{inventory}', [InventoryController::class, 'show'])
        ->middleware('permission:inventory.view');

   


    Route::post('/inventory/imports/preview',[InventoryImportController::class, 'preview']
    )->middleware('permission:inventory.import');

    Route::get('/inventory/imports/{uuid}',[InventoryImportController::class, 'show'])->middleware('permission:inventory.import');

    Route::post('/inventory/imports/{uuid}/confirm',[InventoryImportController::class, 'confirm']
    )->middleware('permission:inventory.import');

    //SALES/ORDERS
    Route::get('/sales', [OrderController::class, 'index'])
    ->middleware('permission:sales.view');

    Route::get('/sales/{order}', [OrderController::class, 'show'])
        ->middleware('permission:sales.view');

    Route::post('/sales', [OrderController::class, 'store'])
        ->middleware('permission:sales.create');

    Route::post('/sales/{order}/payments', [OrderController::class,'storePayment',
    ])->middleware('permission:sales.update');

    Route::post(
        '/sales/{order}/fulfillment-requests',
        [OrderController::class, 'prepareFulfillment']
    )->middleware('permission:sales.update');

    Route::post('/sales/{order}/fulfill', [OrderController::class,'fulfill',
    ])->middleware('permission:sales.update');

    Route::put('/sales/{order}', [OrderController::class, 'update'])
    ->middleware('permission:sales.update');

    Route::delete('/sales/{order}', [OrderController::class, 'destroy'])
        ->middleware('permission:sales.delete');

    
    //EXPENSES
    Route::post('/expenses', [ExpenseController::class, 'store'])
        ->middleware('permission:expenses.create');

    Route::get('/expenses', [ExpenseController::class, 'index'])
        ->middleware('permission:expenses.view');

    Route::prefix('expenses/reports')
        ->middleware('permission:expenses.view')
        ->controller(ExpenseReportController::class)
        ->group(function () {
            Route::get('/summary', 'summary');
            Route::get('/categories', 'categories');
            Route::get('/branches', 'branches');
            Route::get('/payment-methods', 'paymentMethods');
        });

    Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])
        ->middleware('permission:expenses.view');

    Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])
        ->middleware('permission:expenses.update');

    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])
        ->middleware('permission:expenses.delete');

    //DASHBOARD
    Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('permission:reports.view');

    Route::get('/dashboard/activity',[DashboardController::class, 'activity']);

    // EMPLOYEES
    Route::post('/employees', [EmployeeController::class, 'store'])
    ->middleware('permission:employees.create');

    // QUICK BOOKS MIGRATION CENTER
    Route::post('/migration-sessions', [MigrationSessionController::class, 'store'])
        ->middleware('permission:migration.create');

    Route::post('/migration-sessions/{session}/upload', [MigrationSessionController::class, 'upload'])
        ->middleware([
        'permission:migration.create',
        'throttle:migration-upload',
    ]);

    Route::post('/migration-sessions/{session}/analyze', [MigrationSessionController::class, 'analyze'])
        ->middleware('permission:migration.create');

    Route::post('/migration-sessions/{session}/mapping', [MigrationSessionController::class, 'storeMapping'])
        ->middleware('permission:migration.create');

    Route::post('/migration-sessions/{session}/validate', [MigrationSessionController::class, 'validateMigration'])
        ->middleware('permission:migration.create');

    Route::get('/migration-sessions/{session}/review', [MigrationSessionController::class, 'review'])
        ->middleware('permission:migration.view');

    Route::post('/migration-sessions/{session}/batches', [MigrationSessionController::class, 'createBatch'])
        ->middleware('permission:migration.import');

    Route::post('/migration-sessions/{session}/batches/{batch}/import', [MigrationSessionController::class, 'import'])
        ->middleware('permission:migration.import');

    Route::post('/migration-sessions/{session}/batches/{batch}/retry', [MigrationSessionController::class, 'retry'])
        ->middleware('permission:migration.import');

    Route::get('/migration-sessions/{session}/report', [MigrationSessionController::class, 'report'])
        ->middleware('permission:migration.view');

    // BRANCHES
    Route::get('/branches', [BranchController::class, 'index'])
        ->middleware('permission:branches.view');

    Route::post('/branches', [BranchController::class, 'store'])
        ->middleware('permission:branches.create');

    Route::get('/branches/{branch}', [BranchController::class, 'show'])
        ->middleware('permission:branches.view');

    Route::put('/branches/{branch}', [BranchController::class, 'update'])
        ->middleware('permission:branches.update');

    Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])
        ->middleware('permission:branches.delete');

    Route::post('/branches/{branch}/restore', [BranchController::class, 'restore'])
        ->middleware('permission:branches.update')
        ->withTrashed();

    // CATEGORIES
    Route::get('/categories', [CategoryController::class, 'index'])
        ->middleware('permission:categories.view');

    Route::post('/categories', [CategoryController::class, 'store'])
        ->middleware('permission:categories.create');

    Route::get('/categories/{category}', [CategoryController::class, 'show'])
        ->middleware('permission:categories.view');

    Route::put('/categories/{category}', [CategoryController::class, 'update'])
        ->middleware('permission:categories.update');

    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
        ->middleware('permission:categories.delete');

    // GENERAL TENANT REPORTS
    Route::get('/reports/overview', [GeneralReportController::class,'overview',
    ])->middleware('permission:reports.view');

    // ROLES
    Route::post('/roles', [RoleController::class, 'store']);
});
