<?php

use App\Http\Controllers\Staff\AuthController;
use App\Http\Controllers\Staff\BranchController;
use App\Http\Controllers\Staff\DiaryController;
use App\Http\Controllers\Staff\TherapistController;
use App\Http\Controllers\Staff\TreatmentController;
use Illuminate\Support\Facades\Route;

/*
 | Public. One page, no account needed - a client books and is told what
 | happens next.
 */
Route::view('/', 'book')->name('book');

/*
 | Reception sign-in. One shared token exchanged for a session, so staff can
 | click between screens instead of pasting a header into every request. The
 | throttle is here because a single shared secret is worth guessing.
 */
Route::get('/staff/login', [AuthController::class, 'show'])->name('staff.login');
Route::post('/staff/login', [AuthController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('staff.login.store');
Route::post('/staff/logout', [AuthController::class, 'destroy'])->name('staff.logout');

// The phase-one URL, kept working so older links and the README still land.
Route::get('/diary', fn () => redirect()->route('staff.diary'));

/*
 | Everything behind the desk. Every route is named, and every screen is
 | reachable from the navigation bar - nobody should have to type a URL.
 */
Route::middleware('staff')->prefix('staff')->name('staff.')->group(function () {
    Route::get('/diary', [DiaryController::class, 'index'])->name('diary');
    Route::post('/diary/bookings', [DiaryController::class, 'store'])->name('diary.store');
    Route::post('/diary/bookings/{booking}/cancel', [DiaryController::class, 'cancel'])->name('diary.cancel');
    Route::get('/diary/slots', [DiaryController::class, 'slots'])->name('diary.slots');

    /*
     | Catalogue management. No destroy route anywhere on purpose: branches,
     | treatments and therapists are all referenced by bookings, consent records
     | and payments, so they deactivate instead of disappearing.
     */
    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::get('/branches/new', [BranchController::class, 'create'])->name('branches.create');
    Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
    Route::get('/branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
    Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
    Route::patch('/branches/{branch}/toggle', [BranchController::class, 'toggle'])->name('branches.toggle');

    Route::get('/treatments', [TreatmentController::class, 'index'])->name('treatments.index');
    Route::get('/treatments/new', [TreatmentController::class, 'create'])->name('treatments.create');
    Route::post('/treatments', [TreatmentController::class, 'store'])->name('treatments.store');
    Route::get('/treatments/{treatment}/edit', [TreatmentController::class, 'edit'])->name('treatments.edit');
    Route::put('/treatments/{treatment}', [TreatmentController::class, 'update'])->name('treatments.update');
    Route::patch('/treatments/{treatment}/toggle', [TreatmentController::class, 'toggle'])->name('treatments.toggle');

    Route::get('/therapists', [TherapistController::class, 'index'])->name('therapists.index');
    Route::get('/therapists/new', [TherapistController::class, 'create'])->name('therapists.create');
    Route::post('/therapists', [TherapistController::class, 'store'])->name('therapists.store');
    Route::get('/therapists/{therapist}/edit', [TherapistController::class, 'edit'])->name('therapists.edit');
    Route::put('/therapists/{therapist}', [TherapistController::class, 'update'])->name('therapists.update');
    Route::patch('/therapists/{therapist}/toggle', [TherapistController::class, 'toggle'])->name('therapists.toggle');
});
