<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TodayDashboardController;
use App\Http\Controllers\QualityChecklistController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application.
| Routes are organized into domain-specific files for maintainability:
|
| - auth.php: OAuth authentication (Google, Microsoft)
| - clients.php: Client management, medical records, documents
| - staff.php: Staff profiles, credentials, availability
| - incidents.php: Incident reporting and management
| - assets.php: Asset management, sites, QR codes
| - shifts.php: Shift scheduling and timesheets
| - medications.php: Medication management and compliance
| - reports.php: Reports, analytics, and audit logs
| - portal.php: Client/family portal, timelines, summaries
| - integrations.php: Third-party integrations (UniFi, etc.)
| - settings.php: Application settings and configuration
|
*/

// Public routes
Route::get('/', function () {
    return Inertia::render('home', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('/contact', function () {
    return Inertia::render('Contact');
})->name('contact');

// Authenticated routes
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/today', TodayDashboardController::class)->name('today');
    Route::get('/quality/checklist', QualityChecklistController::class)->name('quality.checklist');
});

// Domain-specific routes
require __DIR__ . '/auth.php';
require __DIR__ . '/portal.php';
require __DIR__ . '/clients.php';
require __DIR__ . '/staff.php';
require __DIR__ . '/incidents.php';
require __DIR__ . '/assets.php';
require __DIR__ . '/shifts.php';
require __DIR__ . '/medications.php';
require __DIR__ . '/reports.php';
require __DIR__ . '/integrations.php';
require __DIR__ . '/settings.php';
