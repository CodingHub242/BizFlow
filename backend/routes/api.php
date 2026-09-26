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
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\RoleController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
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

    Route::get('/catalog-items/{catalogItem}', [CatalogItemController::class, 'show'])
        ->middleware('permission:products.view');

    Route::put('/catalog-items/{catalogItem}', [CatalogItemController::class, 'update'])
        ->middleware('permission:products.update');

    Route::delete('/catalog-items/{catalogItem}', [CatalogItemController::class, 'destroy'])
        ->middleware('permission:products.delete');

    Route::post('/catalog-items/{catalogItem}/restore', [CatalogItemController::class, 'restore'])
        ->middleware('permission:products.update')
        ->withTrashed();

    //INVENTORY
    Route::get('/inventory', [InventoryController::class, 'index'])
        ->middleware('permission:inventory.view');

    Route::get('/inventory/movements', [InventoryController::class, 'movements'])
        ->middleware('permission:inventory.view');

    Route::get('/inventory/{inventory}', [InventoryController::class, 'show'])
        ->middleware('permission:inventory.view');

    Route::post('/inventory/receive', [InventoryController::class, 'receive'])
        ->middleware('permission:inventory.receive');

    Route::post('/inventory/adjust', [InventoryController::class, 'adjust'])
        ->middleware('permission:inventory.adjust');

    Route::post('/inventory/transfer', [InventoryController::class, 'transfer'])
        ->middleware('permission:inventory.transfer');

    //EXPENSES
    Route::post('/expenses', [ExpenseController::class, 'store'])
        ->middleware('permission:expenses.create');

    Route::get('/expenses', [ExpenseController::class, 'index'])
        ->middleware('permission:expenses.view');

    Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])
        ->middleware('permission:expenses.view');

    Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])
        ->middleware('permission:expenses.update');

    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])
        ->middleware('permission:expenses.delete');

    //DASHBOARD
    Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('permission:reports.view');

    // EMPLOYEES
    Route::post('/employees', [EmployeeController::class, 'store'])
    ->middleware('permission:employees.create');

    // MIGRATION CENTER
    Route::post('/migration-sessions', [MigrationSessionController::class, 'store'])
        ->middleware('permission:migration.create');

    Route::post('/migration-sessions/{session}/upload', [MigrationSessionController::class, 'upload'])
        ->middleware('permission:migration.create');

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

    // ROLES
    Route::post('/roles', [RoleController::class, 'store']);
});
