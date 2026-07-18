<?php

use App\Http\Controllers\Careers\CareerPortalController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\It\ItCatalogController;
use App\Http\Controllers\It\ItChangeController;
use App\Http\Controllers\It\ItKbController;
use App\Http\Controllers\It\ItMajorIncidentController;
use App\Http\Controllers\It\ItProvisioningController;
use App\Http\Controllers\It\ItProblemController;
use App\Http\Controllers\It\ItReportsController;
use App\Http\Controllers\It\ItServiceManagementSetupController;
use App\Http\Controllers\It\ItTicketController;
use App\Http\Controllers\It\ItWorkTaskController;
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
| - consents.php: Consent management, Privacy Act 2020 and HIPC consent workflows
| - training.php: Staff vetting, training, competency assessments
| - privacy.php: Privacy Act 2020 compliance, privacy requests, breach management
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
// Career Portal — public surface lives on the requisition-backed Careers
// controller. The legacy posting-backed job-detail page has been retired; only
// the candidate application-status tracker (`applicationStatus`) remains on the
// original controller, now sourced from requisitions.
Route::get('/careers', [CareerPortalController::class, 'index'])->name('careers.index');
// Public capability-token lookup — throttle to blunt token enumeration.
Route::get('/careers/application/{token}', [App\Http\Controllers\CareerPortalController::class, 'applicationStatus'])->middleware('throttle:30,1')->name('careers.application.status');
Route::get('/careers/offers/{token}', [CareerPortalController::class, 'showOffer'])->middleware('throttle:30,1')->name('careers.offer.show');
Route::post('/careers/offers/{token}', [CareerPortalController::class, 'respondToOffer'])->middleware('throttle:10,1')->name('careers.offer.respond');
Route::get('/careers/jobs/{job:slug}/apply', [CareerPortalController::class, 'showApply'])->name('careers.apply');
Route::post('/careers/jobs/{job:slug}/apply', [CareerPortalController::class, 'submitApplication'])->name('careers.apply.store');
// Public token-guarded reference questionnaire — must precede the /careers/{slug} catch-all.
Route::get('/careers/references/{token}', [App\Http\Controllers\Careers\ReferenceController::class, 'show'])->middleware('throttle:30,1')->name('careers.reference.show');
Route::post('/careers/references/{token}', [App\Http\Controllers\Careers\ReferenceController::class, 'submit'])->middleware('throttle:10,1')->name('careers.reference.submit');
Route::get('/careers/{slug}', fn () => redirect()->route('careers.index'))->where('slug', '^(?!application|offers|jobs|references).*$')->name('careers.show');

// Authenticated routes
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/today', TodayDashboardController::class)->name('today');
    Route::get('/quality/checklist', QualityChecklistController::class)->name('quality.checklist');

    // Internal design-system showcase. Admin-only outside local/testing env so
    // we keep a single canonical reference for the shared PageHero/PageTabs/etc.
    // platform-wide hero standardisation initiative.
    Route::get('/internal/_design/page-hero', function () {
        $user = auth()->user();
        $isAdmin = $user && method_exists($user, 'canDo')
            && $user->canDo('settings.access.impersonate');
        if (! $isAdmin && ! app()->environment(['local', 'testing'])) {
            abort(403);
        }
        return Inertia::render('internal/_design/page-hero');
    })->name('internal.design.page-hero');
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

// IT & Support — self-service helpdesk (everyone on staff raises and
// tracks their own tickets) + the agent provisioning/ticket queues. Built
// from docs/IT_PROVISIONING_WIREFRAME.md; ticketing per
// docs/IT_TICKETING_GAP_ANALYSIS.md.
Route::middleware(['auth', 'permission:it.request|it.view'])->group(function () {
    Route::get('/it', [ItProvisioningController::class, 'index'])->name('it.index');
    Route::get('/it/catalog', [ItCatalogController::class, 'index'])->name('it.catalog.index');
    Route::post('/it/catalog/{catalogItem}/submissions', [ItCatalogController::class, 'store'])->name('it.catalog.submissions.store');
    Route::get('/it/changes', [ItChangeController::class, 'index'])->middleware('permission:it.view')->name('it.changes.index');
    Route::get('/it/changes/{change}', [ItChangeController::class, 'show'])->middleware('permission:it.view')->name('it.changes.show');
    Route::get('/it/major-incidents', [ItMajorIncidentController::class, 'index'])->middleware('permission:it.view')->name('it.major-incidents.index');
    Route::get('/it/major-incidents/{majorIncident}', [ItMajorIncidentController::class, 'show'])->middleware('permission:it.view')->name('it.major-incidents.show');
    Route::get('/it/major-incidents/{majorIncident}/status', [ItMajorIncidentController::class, 'status'])->name('it.major-incidents.status');
    Route::get('/it/problems', [ItProblemController::class, 'index'])->middleware('permission:it.view')->name('it.problems.index');
    Route::get('/it/problems/{problem}', [ItProblemController::class, 'show'])->middleware('permission:it.view')->name('it.problems.show');
    // Self-service: raising a ticket needs it.request (or it.manage for
    // agents logging on behalf of others) — enforced via ItTicketPolicy.
    Route::post('/it/tickets', [ItProvisioningController::class, 'storeTicket'])->name('it.tickets.store');
    // The workspace: agents see every ticket, requesters their own
    // (ItTicketPolicy; internal notes stripped server-side).
    Route::get('/it/tickets/{ticket}', [ItTicketController::class, 'show'])->name('it.tickets.show');
    Route::post('/it/tickets/{ticket}/comments', [ItTicketController::class, 'storeComment'])->name('it.tickets.comments.store');
    Route::get('/it/attachments/{attachment}', [ItTicketController::class, 'downloadAttachment'])->name('it.attachments.download');
    // Reopen: agents anytime, the requester within 7 days of resolution
    // (ItTicketPolicy::reopen owns the window).
    Route::post('/it/tickets/{ticket}/reopen', [ItTicketController::class, 'reopen'])->name('it.tickets.reopen');
    // CSAT: the requester rates their own resolved ticket (ItTicketPolicy::csat
    // owns the who/when — agents 403, editable until the ticket closes).
    Route::post('/it/tickets/{ticket}/csat', [ItTicketController::class, 'csat'])->name('it.tickets.csat');

    // Provisioning-queue CSV export — a read any agent (it.view) can run over
    // what the queue shows them; requesters (it.request only) are refused.
    Route::get('/it/provisioning/export', [ItProvisioningController::class, 'exportProvisioning'])
        ->middleware('permission:it.view')
        ->name('it.provisioning.export');

    // Reports (§L) — server-computed analytics as JSON; any agent (it.view)
    // reads, requesters (it.request only) are refused.
    Route::get('/it/reports/data', [ItReportsController::class, 'data'])
        ->middleware('permission:it.view')
        ->name('it.reports.data');
    // Per-card CSV export of the same aggregates (injection-guarded stream).
    Route::get('/it/reports/export', [ItReportsController::class, 'export'])
        ->middleware('permission:it.view')
        ->name('it.reports.export');

    // Knowledge base browse — anyone who can reach /it (it.request or it.view)
    // reads published articles and votes; the controller guards published + tenant.
    Route::post('/it/kb/{article}/view', [ItKbController::class, 'view'])->name('it.kb.view');
    Route::post('/it/kb/{article}/helpful', [ItKbController::class, 'helpful'])->name('it.kb.helpful');

    Route::middleware('permission:it.manage')->group(function () {
        Route::get('/it/setup', [ItServiceManagementSetupController::class, 'index'])->name('it.setup.index');
        Route::post('/it/setup/teams', [ItServiceManagementSetupController::class, 'storeTeam'])->name('it.setup.teams.store');
        Route::patch('/it/setup/teams/{team}', [ItServiceManagementSetupController::class, 'updateTeam'])->name('it.setup.teams.update');
        Route::post('/it/setup/queues', [ItServiceManagementSetupController::class, 'storeQueue'])->name('it.setup.queues.store');
        Route::patch('/it/setup/queues/{queue}', [ItServiceManagementSetupController::class, 'updateQueue'])->name('it.setup.queues.update');
        Route::post('/it/setup/services', [ItServiceManagementSetupController::class, 'storeService'])->name('it.setup.services.store');
        Route::patch('/it/setup/services/{service}', [ItServiceManagementSetupController::class, 'updateService'])->name('it.setup.services.update');
        Route::post('/it/provisioning', [ItProvisioningController::class, 'storeProvisioning'])->name('it.provisioning.store');
        Route::post('/it/changes', [ItChangeController::class, 'store'])->name('it.changes.store');
        Route::patch('/it/changes/{change}', [ItChangeController::class, 'update'])->name('it.changes.update');
        Route::post('/it/changes/{change}/transitions', [ItChangeController::class, 'transition'])->name('it.changes.transitions.store');
        Route::post('/it/major-incidents', [ItMajorIncidentController::class, 'store'])->name('it.major-incidents.store');
        Route::patch('/it/major-incidents/{majorIncident}', [ItMajorIncidentController::class, 'update'])->name('it.major-incidents.update');
        Route::post('/it/major-incidents/{majorIncident}/updates', [ItMajorIncidentController::class, 'storeUpdate'])->name('it.major-incidents.updates.store');
        Route::post('/it/major-incidents/{majorIncident}/transitions', [ItMajorIncidentController::class, 'transition'])->name('it.major-incidents.transitions.store');
        Route::post('/it/problems', [ItProblemController::class, 'store'])->name('it.problems.store');
        Route::patch('/it/problems/{problem}', [ItProblemController::class, 'update'])->name('it.problems.update');
        Route::post('/it/problems/{problem}/transitions', [ItProblemController::class, 'transition'])->name('it.problems.transitions.store');
        // Bulk assign/fulfil across a selection (§H) — literal `bulk` sits
        // above the {provisioning} routes so it is never bound as an id.
        Route::post('/it/provisioning/bulk', [ItProvisioningController::class, 'bulkProvisioning'])->name('it.provisioning.bulk');
        Route::post('/it/provisioning/{provisioning}/assign', [ItProvisioningController::class, 'assign'])->name('it.provisioning.assign');
        Route::post('/it/provisioning/{provisioning}/fulfil', [ItProvisioningController::class, 'fulfil'])->name('it.provisioning.fulfil');
        Route::post('/it/provisioning/{provisioning}/cancel', [ItProvisioningController::class, 'cancel'])->name('it.provisioning.cancel');
        Route::post('/it/tickets/bulk', [ItTicketController::class, 'bulk'])->name('it.tickets.bulk');
        Route::patch('/it/tickets/{ticket}', [ItProvisioningController::class, 'updateTicket'])->name('it.tickets.update');
        Route::post('/it/tickets/{ticket}/resolve', [ItProvisioningController::class, 'resolveTicket'])->name('it.tickets.resolve');
        Route::post('/it/tickets/{ticket}/close', [ItTicketController::class, 'close'])->name('it.tickets.close');
        Route::post('/it/tickets/{ticket}/transitions', [ItTicketController::class, 'transition'])->name('it.tickets.transitions.store');
        Route::post('/it/tickets/{ticket}/tasks', [ItWorkTaskController::class, 'store'])->name('it.tickets.tasks.store');
        Route::patch('/it/tickets/{ticket}/tasks/{task}', [ItWorkTaskController::class, 'update'])->name('it.tickets.tasks.update');
        Route::post('/it/tickets/{ticket}/tasks/{task}/complete', [ItWorkTaskController::class, 'complete'])->name('it.tickets.tasks.complete');
        Route::post('/it/tickets/{ticket}/tasks/{task}/reopen', [ItWorkTaskController::class, 'reopen'])->name('it.tickets.tasks.reopen');
        Route::post('/it/tickets/{ticket}/merge', [ItTicketController::class, 'merge'])->name('it.tickets.merge');
        Route::post('/it/tickets/{ticket}/approvals', [ItTicketController::class, 'requestApproval'])->name('it.tickets.approvals.request');
        Route::post('/it/approvals/{approval}/decide', [ItTicketController::class, 'decideApproval'])->name('it.approvals.decide');
        Route::post('/it/tickets/{ticket}/watch', [ItTicketController::class, 'watch'])->name('it.tickets.watch');
        Route::post('/it/tickets/{ticket}/unwatch', [ItTicketController::class, 'unwatch'])->name('it.tickets.unwatch');
        // SLA target grid — admin-only on top of it.manage (FormRequest authorize).
        Route::put('/it/sla-policies', [ItProvisioningController::class, 'updateSlaPolicies'])->name('it.sla.update');
        // Knowledge base authoring (§I) — agents create/edit/publish/delete.
        Route::post('/it/kb', [ItKbController::class, 'store'])->name('it.kb.store');
        Route::patch('/it/kb/{article}', [ItKbController::class, 'update'])->name('it.kb.update');
        Route::delete('/it/kb/{article}', [ItKbController::class, 'destroy'])->name('it.kb.destroy');
    });
});

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
    Route::post('/my-tasks/timesheet/ensure-today', [\App\Http\Controllers\MyDayActionsController::class, 'ensureTodayTimesheet'])->name('my-day.timesheet.ensure-today');
    Route::post('/my-tasks/timesheet/{timesheet}/submit', [\App\Http\Controllers\MyDayActionsController::class, 'submitTimesheet'])->name('my-day.timesheet.submit');

    // PR 17 — frontline alert quick actions. Scoped to the alert's assignee so
    // a frontline worker can acknowledge or snooze an alert from /my-day
    // without reaching into the Control Room operator surface.
    Route::post('/my-day/alerts/{alert}/ack', [\App\Http\Controllers\MyDayActionsController::class, 'acknowledgeAlert'])->name('my-day.alert.ack');
    Route::post('/my-day/alerts/{alert}/snooze', [\App\Http\Controllers\MyDayActionsController::class, 'snoozeAlert'])->name('my-day.alert.snooze');

    // Site-first redesign — frontline medication quick actions. The same
    // ClientMedicationAdministration writes flow through here as via the full
    // eMAR, but UX-light: one click marks a dose given/refused; snooze hides
    // the row from this worker's /my-day for 15m without touching the record.
    Route::post('/my-day/medications/{medication}/administer', [\App\Http\Controllers\MyDayMedicationsController::class, 'administer'])->name('my-day.medications.administer');
    Route::post('/my-day/medications/{medication}/refuse', [\App\Http\Controllers\MyDayMedicationsController::class, 'refuse'])->name('my-day.medications.refuse');
    Route::post('/my-day/medications/{medication}/snooze', [\App\Http\Controllers\MyDayMedicationsController::class, 'snooze'])->name('my-day.medications.snooze');
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
require __DIR__.'/tasks.php';
require __DIR__.'/assets.php';
require __DIR__.'/sites.php';
require __DIR__.'/catering.php';
require __DIR__.'/fleet.php';
require __DIR__.'/fleet-assets.php';
require __DIR__.'/control-room.php';
require __DIR__.'/security-devices.php';
require __DIR__.'/shifts.php';
require __DIR__.'/medications.php';
require __DIR__.'/compliance.php';
require __DIR__.'/reports.php';
require __DIR__.'/integrations.php';
require __DIR__.'/settings.php';
require __DIR__.'/respite.php';

// Compliance module routes
require __DIR__.'/safeguarding.php';
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
    Route::get('/shifts/{any}', \App\Http\Controllers\LegacyRouteRedirectController::class)
        ->where('any', '.*')
        ->defaults('destination_prefix', '/operations/shifts')
        ->defaults('status', 301);
    Route::get('/timesheets/{any}', \App\Http\Controllers\LegacyRouteRedirectController::class)
        ->where('any', '.*')
        ->defaults('destination_prefix', '/operations/timesheets')
        ->defaults('status', 301);
    Route::get('/rostering', \App\Http\Controllers\LegacyRouteRedirectController::class)
        ->defaults('destination', '/operations/rostering')
        ->defaults('status', 301);
    Route::get('/rostering/{any}', \App\Http\Controllers\LegacyRouteRedirectController::class)
        ->where('any', '.*')
        ->defaults('destination_prefix', '/operations/rostering')
        ->defaults('status', 301);
    Route::redirect('/medications/{any}', '/emar/{any}')->where('any', '.*');
    Route::redirect('/emergency-access', '/emar/emergency-access');
});
