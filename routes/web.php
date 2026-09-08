<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReportController;
use App\Http\Middleware\EnsureServiceAdvisor;
use App\Http\Middleware\LogReportRequest;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::get('healthz', HealthController::class)->name('healthz');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware([LogReportRequest::class, 'auth', 'verified', EnsureServiceAdvisor::class])
    ->prefix('reports')
    ->name('reports.')
    ->group(function () {
        Route::get('services', [ReportController::class, 'index'])
            ->name('services.index');
    });

require __DIR__.'/settings.php';

Route::middleware(['auth', 'verified', EnsureServiceAdvisor::class])->group(function () {
    Route::inertia('appointments', 'appointments/Index')->name('appointments.index');
});
