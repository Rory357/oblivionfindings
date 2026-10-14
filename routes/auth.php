<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\MicrosoftController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\IdentityDisconnectController;
use App\Http\Controllers\Auth\LocaleController;
use App\Http\Controllers\Auth\SignedOutController;

// English / Te reo Māori switch on the sign-in pages (cookie for signed-out visitors).
Route::post('/locale', [LocaleController::class, 'update'])
    ->middleware('throttle:30,1')
    ->name('locale.update');

// "You've signed out" (staff, or ?for=family). See LogoutResponse.
Route::get('/signed-out', SignedOutController::class)
    ->middleware('guest')
    ->name('signed-out');

/**
 * OAuth Authentication Routes
 *
 * Handles third-party authentication via Google and Microsoft.
 * Rate limited to prevent abuse.
 */

Route::middleware(['throttle:auth'])->group(function () {
    // Google OAuth
    Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])
        ->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleController::class, 'callback'])
        ->name('auth.google.callback');

    // Microsoft OAuth
    Route::get('/auth/microsoft/redirect', [MicrosoftController::class, 'redirect'])
        ->name('auth.microsoft.redirect');
    Route::get('/auth/microsoft/callback', [MicrosoftController::class, 'callback'])
        ->name('auth.microsoft.callback');

    // Disconnect a linked provider
    Route::post('/auth/{provider}/disconnect', [IdentityDisconnectController::class, 'destroy'])
        ->middleware('auth')
        ->name('auth.disconnect');
});
