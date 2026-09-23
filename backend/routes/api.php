<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CatalogItemController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\DashboardController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    ///INVOICES
    Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);

    //CUSTOMERS
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::put('/customers/{customer}', [CustomerController::class, 'update']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);
    Route::post('/customers/{customer}/restore', [CustomerController::class, 'restore'])->withTrashed();

    //PRODUCTS/SERVICES
    Route::post('/catalog-items', [CatalogItemController::class, 'store']);
    Route::get('/catalog-items', [CatalogItemController::class, 'index']);
    Route::get('/catalog-items/{catalogItem}', [CatalogItemController::class, 'show']);
    Route::put('/catalog-items/{catalogItem}', [CatalogItemController::class, 'update']);
    Route::delete('/catalog-items/{catalogItem}', [CatalogItemController::class, 'destroy']);
    Route::post('/catalog-items/{catalogItem}/restore', [CatalogItemController::class, 'restore'])
    ->withTrashed();

    //INVENTORY
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/movements', [InventoryController::class, 'movements']);
    Route::get('/inventory/{inventory}', [InventoryController::class, 'show']);
    Route::post('/inventory/receive', [InventoryController::class, 'receive']);
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust']);
    Route::post('/inventory/transfer', [InventoryController::class, 'transfer']);

    //EXPENSES
    Route::post('/expenses', [ExpenseController::class, 'store']);
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::get('/expenses/{expense}', [ExpenseController::class, 'show']);
    Route::put('/expenses/{expense}', [ExpenseController::class, 'update']);
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);

    //DASHBOARD
    Route::get('/dashboard', [DashboardController::class, 'index']);
});
