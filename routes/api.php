<?php

use App\Http\Controllers\ServiceRecordController;
use Illuminate\Support\Facades\Route;

Route::get('service-records', [ServiceRecordController::class, 'index']);
Route::get('advisors/workload', [ServiceRecordController::class, 'advisorWorkload']);
Route::get('appointments/{appointment}/full-history', [ServiceRecordController::class, 'fullHistory']);
