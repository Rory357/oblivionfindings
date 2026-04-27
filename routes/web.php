<?php

use App\Http\Controllers\Careers\CareerPortalController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\QualityChecklistController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\TodayDashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
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
| Compliance Modules:
| - safeguarding.php: Safeguarding concerns, investigations, alerts
| - consents.php: Consent management, GDPR Article 7 compliance
| - training.php: Staff vetting, training, competency assessments
| - privacy.php: GDPR compliance, data subject requests, breach management
|
*/

use Laravel\Fortify\Features;

Route::get('/robots.txt', function () {
    $disallowAll = ! config('app.indexing_enabled');
    $body = $disallowAll
        ? "User-agent: *\nDisallow: /\n"
        : "User-agent: *\nDisallow:\n";

    return response($body, 200)->header('Content-Type', 'text/plain');
})->name('robots');

// Public routes
Route::get('/', function () {
    return Inertia::render('home', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('/features', function () {
    return Inertia::render('features');
})->name('features');

Route::get('/pricing', function () {
    return Inertia::render('pricing');
})->name('pricing');

Route::get('/about', function () {
    return Inertia::render('about');
})->name('about');

Route::get('/privacy', function () {
    $user = auth()->user();

    if ($user && method_exists($user, 'canDo') && $user->canDo('privacy.viewRequests')) {
        return redirect()->route('privacy.dashboard');
    }

    return Inertia::render('privacy');
})->name('privacy');

Route::get('/privacy-policy', function () {
    return Inertia::render('privacy');
})->name('privacy.policy');

Route::get('/terms', function () {
    return Inertia::render('terms');
})->name('terms');

Route::get('/smart-monitoring', function () {
    return Inertia::render('smart-monitoring');
})->name('smart-monitoring');

Route::get('/contact', [ContactController::class, 'index'])->name('contact');
Route::post('/contact', [ContactController::class, 'store']);
// Career Portal — Job Postings based
Route::get('/careers', [App\Http\Controllers\CareerPortalController::class, 'index'])->name('careers.index');
Route::get('/careers/application/{token}', [App\Http\Controllers\CareerPortalController::class, 'applicationStatus'])->name('careers.application.status');
Route::get('/careers/offers/{token}', [CareerPortalController::class, 'showOffer'])->name('careers.offer.show');
Route::post('/careers/offers/{token}', [CareerPortalController::class, 'respondToOffer'])->name('careers.offer.respond');
Route::get('/careers/{slug}', [App\Http\Controllers\CareerPortalController::class, 'show'])->name('careers.show');
Route::get('/careers/{slug}/apply', [App\Http\Controllers\CareerPortalController::class, 'apply'])->name('careers.apply');
Route::post('/careers/{slug}/apply', [App\Http\Controllers\CareerPortalController::class, 'storeApplication'])->name('careers.apply.store');

// Authenticated routes
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/today', TodayDashboardController::class)->name('today');
    Route::get('/quality/checklist', QualityChecklistController::class)->name('quality.checklist');
});

// Canonical frontline home.
// `/my-day` is the single staff/frontline entry point. Legacy `/my-tasks`
// links (including existing POST action endpoints) keep working via the
// redirect below and the unchanged action routes.
Route::get('/my-day', \App\Http\Controllers\MyTasksController::class)
    ->middleware(['auth'])
    ->name('my-day');

Route::get('/my-roster', [RosterController::class, 'index'])
    ->middleware(['auth'])
    ->name('my-roster');

Route::get('/my-roster/data', [RosterController::class, 'data'])
    ->middleware(['auth'])
    ->name('my-roster.data');

// Legacy alias — any inbound `/my-tasks` link (email, bookmarks) lands on
// the canonical home. Kept as a simple redirect to avoid a second home surface.
Route::redirect('/my-tasks', '/my-day')
    ->middleware(['auth'])
    ->name('my-tasks');

// My Day quick actions — URL paths intentionally unchanged to avoid churning
// every POST call-site; names are aliased under `my-day.*` for future use.
//
// PR 4.5 removed the `/my-tasks/clock-in/{shift}` and `/my-tasks/clock-out/{shift}`
// shortcuts so the frontline clock flow funnels exclusively through
// `POST /attendance/clock-in` + `POST /attendance/clock-out` (AttendanceController),
// which goes through AttendanceService and writes a real HrAttendanceSession and
// draft Timesheet. Do not re-add quick-clock endpoints here.
Route::middleware(['auth'])->group(function () {
    Route::post('/my-tasks/shift-task/{task}/complete', [\App\Http\Controllers\MyDayActionsController::class, 'completeShiftTask'])->name('my-day.shift-task.complete');
    Route::post('/my-tasks/timesheet/{timesheet}/submit', [\App\Http\Controllers\MyDayActionsController::class, 'submitTimesheet'])->name('my-day.timesheet.submit');

    // PR 17 — frontline alert quick actions. Scoped to the alert's assignee so
    // a frontline worker can acknowledge or snooze an alert from /my-day
    // without reaching into the Control Room operator surface.
    Route::post('/my-day/alerts/{alert}/ack', [\App\Http\Controllers\MyDayActionsController::class, 'acknowledgeAlert'])->name('my-day.alert.ack');
    Route::post('/my-day/alerts/{alert}/snooze', [\App\Http\Controllers\MyDayActionsController::class, 'snoozeAlert'])->name('my-day.alert.snooze');
});

Route::get('/my-calendar', [\App\Http\Controllers\MyCalendarController::class, 'index'])->middleware('auth')->name('my-calendar');
Route::get('/my-calendar/events', [\App\Http\Controllers\MyCalendarController::class, 'events'])->middleware('auth')->name('my-calendar.events');

// ── Operations module ────────────────────────────────────────────────
require __DIR__.'/operations.php';

// ── eMAR module ──────────────────────────────────────────────────────
require __DIR__.'/emar.php';

// Domain-specific routes
require __DIR__.'/auth.php';
require __DIR__.'/portal.php';
require __DIR__.'/clients.php';
require __DIR__.'/staff.php';
require __DIR__.'/incidents.php';
require __DIR__.'/assets.php';
require __DIR__.'/sites.php';
require __DIR__.'/fleet.php';
require __DIR__.'/fleet-assets.php';
require __DIR__.'/control-room.php';
require __DIR__.'/security-devices.php';
require __DIR__.'/shifts.php';
require __DIR__.'/medications.php';
require __DIR__.'/reports.php';
require __DIR__.'/integrations.php';
require __DIR__.'/settings.php';
require __DIR__.'/respite.php';

// Compliance module routes
require __DIR__.'/safeguarding.php';
// require __DIR__.'/consents.php'; // TODO: Controllers not yet implemented
require __DIR__.'/training.php';
require __DIR__.'/privacy.php';

// Board & Governance module
require __DIR__.'/governance.php';

// Roadmap module
require __DIR__.'/roadmap.php';

// HR module
require __DIR__.'/hr.php';

// System module (Access Control, Users)
require __DIR__.'/system.php';

// Finance module
require __DIR__.'/finance.php';

// Health & Clinical module
require __DIR__.'/health-clinical.php';

// Health & Safety module
require __DIR__.'/health-safety.php';

// API routes
require __DIR__.'/api_medications.php';

// ── Backward-compatible redirects (old → new Operations URLs) ────────
Route::middleware(['auth'])->group(function () {
    Route::redirect('/clients/{any}', '/operations/clients/{any}')->where('any', '.*');
    Route::redirect('/shifts/{any}', '/operations/shifts/{any}')->where('any', '.*');
    Route::redirect('/timesheets/{any}', '/operations/timesheets/{any}')->where('any', '.*');
    Route::redirect('/rostering/{any}', '/operations/rostering/{any}')->where('any', '.*');
    Route::redirect('/medications/{any}', '/emar/{any}')->where('any', '.*');
    Route::redirect('/emergency-access', '/emar/emergency-access');
    Route::redirect('/consents', '/operations/clients');
});
