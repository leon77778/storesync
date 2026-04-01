<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

// Redirect the root URL straight to the dashboard
Route::get('/', fn () => redirect()->route('dashboard.index'));

// CSV upload form
Route::get('/import', [ImportController::class, 'create'])->name('import.create');
Route::post('/import', [ImportController::class, 'store'])->name('import.store');

// Dashboard
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');

// Polling endpoint — called by JavaScript every few seconds to refresh statuses
Route::get('/api/batches/{batch}/status', [DashboardController::class, 'batchStatus'])
    ->name('api.batch.status');
