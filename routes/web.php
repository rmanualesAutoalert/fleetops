<?php

use App\Http\Controllers\ReportController;
use App\Http\Middleware\EnsureServiceAdvisor;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['auth', 'verified', EnsureServiceAdvisor::class])
    ->prefix('reports')
    ->name('reports.')
    ->group(function () {
        Route::get('services', [ReportController::class, 'index'])
            ->name('services.index');
    });

require __DIR__.'/settings.php';
