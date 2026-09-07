<?php

use App\Http\Controllers\AppointmentBoardController;
use App\Http\Controllers\BoardBranchController;
use App\Http\Controllers\ServiceRecordController;
use App\Http\Middleware\EnsureServiceAdvisor;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

Route::get('service-records', [ServiceRecordController::class, 'index']);
Route::get('advisors/workload', [ServiceRecordController::class, 'advisorWorkload']);
Route::get('appointments/{appointment}/full-history', [ServiceRecordController::class, 'fullHistory']);

// These read-only API endpoints reuse the existing Fortify session, without Inertia middleware.
Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, 'auth:web', 'verified', EnsureServiceAdvisor::class])->group(function () {
    Route::get('branches', BoardBranchController::class)->name('branches.index');
    Route::get('appointments', AppointmentBoardController::class)->name('appointments.data');
});
