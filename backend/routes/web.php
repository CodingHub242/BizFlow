<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlatformAuthController;
use App\Http\Controllers\PlatformDashboardController;
use App\Http\Controllers\PlatformAuditLogController;
use App\Http\Controllers\PlatformBusinessApprovalController;

Route::get('/', function () {
    return view('welcome');
});


Route::prefix('platform')->group(function () {
    Route::get('/login', [PlatformAuthController::class, 'showLogin'])
        ->name('platform.login');

    Route::post('/login', [PlatformAuthController::class, 'login'])
    ->middleware('throttle:platform-login')
    ->name('platform.login.submit');

    Route::middleware('platform.auth')->group(function () {
        Route::get('/dashboard', [PlatformDashboardController::class, 'index'])
        ->name('platform.dashboard');

        Route::get('/audit-logs', [PlatformAuditLogController::class, 'index'])
        ->name('platform.audit-logs.index');

        Route::get('/businesses/pending', [
            PlatformBusinessApprovalController::class,
            'pending',
        ])->name('platform.businesses.pending');

        Route::get('/businesses/{tenant}', [PlatformBusinessApprovalController::class, 'show'])
        ->name('platform.businesses.show');

        Route::post('/businesses/{tenant}/approve', [
            PlatformBusinessApprovalController::class,
            'approve',
        ])->name('platform.businesses.approve');

        Route::post('/businesses/{tenant}/reject', [
            PlatformBusinessApprovalController::class,
            'reject',
        ])->name('platform.businesses.reject');

        Route::post('/businesses/{tenant}/suspend', [
            PlatformBusinessApprovalController::class,
            'suspend',
        ])->name('platform.businesses.suspend');

        Route::post('/logout', [PlatformAuthController::class, 'logout'])
            ->name('platform.logout');
    });
});