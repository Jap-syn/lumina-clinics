<?php

use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\StaffDiaryController;
use Illuminate\Support\Facades\Route;

/*
 | Public booking API.
 */
Route::get('/branches', [CatalogueController::class, 'branches']);
Route::get('/treatments', [CatalogueController::class, 'treatments']);
Route::get('/therapists', [CatalogueController::class, 'therapists']);
Route::get('/availability', [AvailabilityController::class, 'index']);

Route::post('/bookings', [BookingController::class, 'store']);
Route::get('/bookings/{reference}', [BookingController::class, 'show']);
Route::post('/bookings/{reference}/deposit', [BookingController::class, 'pay']);
Route::post('/bookings/{reference}/cancel', [BookingController::class, 'cancel']);

/*
 | Reception. Shared bearer token for phase one - see StaffToken middleware.
 */
Route::middleware('staff')->prefix('staff')->group(function () {
    Route::get('/diary', [StaffDiaryController::class, 'index']);
    Route::post('/bookings', [StaffDiaryController::class, 'store']);
    Route::post('/bookings/{reference}/cancel', [StaffDiaryController::class, 'cancel']);
});
