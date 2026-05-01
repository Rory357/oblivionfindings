<?php

use App\Http\Controllers\StaffAssignmentController;
use App\Http\Controllers\StaffAvailabilityController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffCredentialController;
use Illuminate\Support\Facades\Route;

/**
 * Staff Management Routes
 *
 * Handles staff profiles, assignments, credentials, and availability.
 */
Route::middleware(['auth'])->group(function () {
    // Staff listing
    Route::get('/staff', [StaffController::class, 'index'])
        ->middleware('permission:staff.viewAny')
        ->name('staff.index');

    // Staff profile (own profile always accessible, managers can view any)
    Route::get('/staff/{user}', [StaffController::class, 'show'])
        ->name('staff.show');

    // Staff updates
    Route::get('/staff/{user}/edit', [StaffController::class, 'edit'])
        ->middleware('permission:staff.update')
        ->name('staff.edit');
    Route::put('/staff/{user}', [StaffController::class, 'update'])
        ->middleware('permission:staff.update')
        ->name('staff.update');

    // Staff assignments
    Route::get('/staff/{user}/assignments', [StaffAssignmentController::class, 'edit'])
        ->middleware('permission:staff.assignments.update')
        ->name('staff.assignments.edit');
    Route::put('/staff/{user}/assignments', [StaffAssignmentController::class, 'update'])
        ->middleware('permission:staff.assignments.update')
        ->name('staff.assignments.update');

    // Staff credentials (compliance) - read
    Route::middleware('permission:staff.credentials.viewAny|staff.viewAny|staff.credentials.updateSelf')->group(function () {
        Route::get('/staff/{user}/credentials', [StaffCredentialController::class, 'index'])
            ->name('staff.credentials.index');
    });

    // Staff credentials (compliance) - write
    Route::middleware('permission:staff.credentials.updateAny|staff.update|staff.credentials.updateSelf')->group(function () {
        Route::post('/staff/{user}/credentials', [StaffCredentialController::class, 'store'])
            ->name('staff.credentials.store');
        Route::put('/staff/{user}/credentials/{credential}', [StaffCredentialController::class, 'update'])
            ->name('staff.credentials.update');
        Route::delete('/staff/{user}/credentials/{credential}', [StaffCredentialController::class, 'destroy'])
            ->name('staff.credentials.destroy');
    });

    // Staff availability - read
    Route::middleware('permission:staff.viewAny|staff.availability.updateAny|staff.availability.updateSelf')->group(function () {
        Route::get('/staff/{user}/availability', [StaffAvailabilityController::class, 'index'])
            ->name('staff.availability.index');
    });

    // Staff availability - write
    Route::middleware('permission:staff.availability.updateAny|staff.availability.updateSelf')->group(function () {
        Route::post('/staff/{user}/availability', [StaffAvailabilityController::class, 'store'])
            ->name('staff.availability.store');
        Route::delete('/staff/{user}/availability/{availability}', [StaffAvailabilityController::class, 'destroy'])
            ->name('staff.availability.destroy');
    });

});
