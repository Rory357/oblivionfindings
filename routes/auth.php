<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\MicrosoftController;
use App\Http\Controllers\Auth\GoogleController;

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
});
