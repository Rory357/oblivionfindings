<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ClientAssignmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffAssignmentController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\Auth\MicrosoftController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\ClientNoteController;
use App\Http\Controllers\SummaryController;
use App\Http\Controllers\UnifiController;
use App\Http\Controllers\ClientMedicalController;
use App\Http\Controllers\ClientDocumentController;
use App\Http\Controllers\ClientPortalUserController;
use App\Http\Controllers\ClientRagController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PortalClientController;
use App\Http\Controllers\RagController;

Route::get('/', function () {
    return Inertia::render('home', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('/contact', function () {
    return Inertia::render('Contact');
})->name('contact');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
});

Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');

Route::get('/auth/microsoft/redirect', [MicrosoftController::class, 'redirect'])->name('auth.microsoft.redirect');
Route::get('/auth/microsoft/callback', [MicrosoftController::class, 'callback'])->name('auth.microsoft.callback');

Route::middleware(['auth'])->group(function () {

    // Global RAG endpoints for the header query bar
    Route::get('/rag/clients', [RagController::class, 'clients'])->name('rag.clients');
    Route::post('/rag/ask', [RagController::class, 'ask'])->name('rag.ask');

    // Client/Next-of-kin Portal
    Route::get('/portal', [PortalController::class, 'index'])->name('portal.index');
    Route::get('/portal/clients/{client}', [PortalClientController::class, 'show'])->name('portal.clients.show');
    Route::post('/portal/clients/{client}/rag/ask', [ClientRagController::class, 'ask'])->name('portal.clients.rag.ask');
    Route::get('/portal/clients/{client}/documents/{document}/download', [ClientDocumentController::class, 'download'])->name('portal.clients.documents.download');
    Route::post('/portal/summaries/generate', [SummaryController::class, 'generate'])->name('portal.summaries.generate');

    // Timelines
    Route::get('/timeline', [TimelineController::class, 'my'])->name('timeline.my');
    Route::get('/staff/{user}/timeline', [TimelineController::class, 'staff'])->name('timeline.staff');
    Route::get('/clients/{client}/timeline', [TimelineController::class, 'client'])->name('timeline.client');

    Route::post('/clients/{client}/notes', [ClientNoteController::class, 'store'])
        ->middleware('permission:timeline.create')
        ->name('clients.notes.store');

    // Summaries
    Route::get('/summaries', fn() => redirect('/summaries/me'))->name('summaries.home');
    Route::get('/summaries/me', [SummaryController::class, 'my'])->name('summaries.me');
    Route::get('/summaries/staff/{user}', [SummaryController::class, 'staff'])->name('summaries.staff');
    Route::get('/summaries/clients/{client}', [SummaryController::class, 'client'])->name('summaries.client');
    Route::post('/summaries/generate', [SummaryController::class, 'generate'])
        ->middleware('permission:summaries.generate')
        ->name('summaries.generate');

    // Integrations: UniFi
    Route::get('/integrations/unifi', [UnifiController::class, 'index'])
        ->middleware('permission:unifi.manage')
        ->name('integrations.unifi.index');
    Route::post('/integrations/unifi/{site}', [UnifiController::class, 'upsert'])
        ->middleware('permission:unifi.manage')
        ->name('integrations.unifi.upsert');
    Route::post('/integrations/unifi/{site}/sync', [UnifiController::class, 'sync'])
        ->middleware('permission:unifi.manage')
        ->name('integrations.unifi.sync');

    // Sites
    Route::middleware('permission:sites.viewAny')->group(function () {
        Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
    });
    Route::middleware('permission:sites.create')->group(function () {
        Route::get('/sites/create', [SiteController::class, 'create'])->name('sites.create');
        Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');
    });
    Route::middleware('permission:sites.update')->group(function () {
        Route::get('/sites/{site}/edit', [SiteController::class, 'edit'])->name('sites.edit');
        Route::put('/sites/{site}', [SiteController::class, 'update'])->name('sites.update');
    });

    // ✅ ALL authenticated users (policy decides data)
    // (now permission-based so it’s consistent)
    Route::middleware('permission:clients.viewAny')->group(function () {
        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
        Route::get('/clients/{client}/documents', [ClientDocumentController::class, 'index'])->name('clients.documents.index');
        Route::get('/clients/{client}/documents/{document}/download', [ClientDocumentController::class, 'download'])->name('clients.documents.download');
    });

    // ✅ Manager/Admin modules (permission-based)
    Route::middleware('permission:workers.viewAny')->group(function () {
        Route::get('/workers', fn() => inertia('workers/index'))->name('workers.index');
    });

    Route::middleware('permission:reports.viewAny')->group(function () {
        Route::get('/reports', fn() => inertia('reports/index'))->name('reports.index');
    });

    // Staff
    Route::get('/staff', [StaffController::class, 'index'])
        ->middleware('permission:staff.viewAny')
        ->name('staff.index');
    // Staff can always view their own profile; managers/admins can view any (enforced in controller)
    Route::get('/staff/{user}', [StaffController::class, 'show'])->name('staff.show');
    Route::get('/staff/{user}/edit', [StaffController::class, 'edit'])
        ->middleware('permission:staff.update')
        ->name('staff.edit');
    Route::put('/staff/{user}', [StaffController::class, 'update'])
        ->middleware('permission:staff.update')
        ->name('staff.update');

    Route::get('/staff/{user}/assignments', [StaffAssignmentController::class, 'edit'])
        ->middleware('permission:staff.assignments.update')
        ->name('staff.assignments.edit');
    Route::put('/staff/{user}/assignments', [StaffAssignmentController::class, 'update'])
        ->middleware('permission:staff.assignments.update')
        ->name('staff.assignments.update');

    Route::middleware('permission:rostering.viewAny')->group(function () {
        Route::get('/rostering', fn() => inertia('rostering/index'))->name('rostering.index');
    });

    Route::middleware('permission:fleet.viewAny')->group(function () {
        Route::get('/fleet-management', fn() => inertia('fleet-management/index'))->name('fleet.index');
    });

    Route::middleware('permission:calendar.viewAny')->group(function () {
        Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
        Route::get('/calendar/events', [CalendarController::class, 'events'])->name('calendar.events');

        // Calendar interactions (create/edit shifts inline)
        Route::post('/calendar/shifts', [CalendarController::class, 'storeShift'])
            ->middleware('permission:shifts.create')
            ->name('calendar.shifts.store');
        Route::patch('/calendar/shifts/{shift}', [CalendarController::class, 'updateShift'])
            ->middleware('permission:shifts.update')
            ->name('calendar.shifts.update');
    });

    // ✅ Client create/update (manager/admin permissions)
    Route::middleware('permission:clients.create')->group(function () {
        Route::get('/clients/create', [ClientController::class, 'create'])->name('clients.create');
        Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    });

    Route::middleware('permission:clients.update')->group(function () {
        Route::get('/clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
        Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');

        // Client medical + portal users management
        Route::post('/clients/{client}/documents', [ClientDocumentController::class, 'store'])->name('clients.documents.store');
        Route::delete('/clients/{client}/documents/{document}', [ClientDocumentController::class, 'destroy'])->name('clients.documents.destroy');

        Route::get('/clients/{client}/medical', [ClientMedicalController::class, 'show'])->name('clients.medical.show');
        Route::put('/clients/{client}/medical/profile', [ClientMedicalController::class, 'updateProfile'])->name('clients.medical.profile.update');
        Route::post('/clients/{client}/medical/medications', [ClientMedicalController::class, 'storeMedication'])->name('clients.medical.medications.store');
        Route::put('/clients/{client}/medical/medications/{medication}', [ClientMedicalController::class, 'updateMedication'])->name('clients.medical.medications.update');
        Route::delete('/clients/{client}/medical/medications/{medication}', [ClientMedicalController::class, 'destroyMedication'])->name('clients.medical.medications.destroy');

        Route::post('/clients/{client}/medical/conditions', [ClientMedicalController::class, 'storeCondition'])->name('clients.medical.conditions.store');
        Route::put('/clients/{client}/medical/conditions/{condition}', [ClientMedicalController::class, 'updateCondition'])->name('clients.medical.conditions.update');
        Route::delete('/clients/{client}/medical/conditions/{condition}', [ClientMedicalController::class, 'destroyCondition'])->name('clients.medical.conditions.destroy');

        Route::post('/clients/{client}/medical/emergency-contacts', [ClientMedicalController::class, 'storeEmergencyContact'])->name('clients.medical.emergency_contacts.store');
        Route::put('/clients/{client}/medical/emergency-contacts/{contact}', [ClientMedicalController::class, 'updateEmergencyContact'])->name('clients.medical.emergency_contacts.update');
        Route::delete('/clients/{client}/medical/emergency-contacts/{contact}', [ClientMedicalController::class, 'destroyEmergencyContact'])->name('clients.medical.emergency_contacts.destroy');

        Route::get('/clients/{client}/portal-users', [ClientPortalUserController::class, 'edit'])->name('clients.portal_users.edit');
        Route::post('/clients/{client}/portal-users', [ClientPortalUserController::class, 'store'])->name('clients.portal_users.store');
        Route::delete('/clients/{client}/portal-users/{user}', [ClientPortalUserController::class, 'destroy'])->name('clients.portal_users.destroy');

        Route::post('/clients/{client}/rag/ask', [ClientRagController::class, 'ask'])->name('clients.rag.ask');
    });

    // ✅ Assign support workers to a client
    Route::middleware('permission:clients.assignments.update')->group(function () {
        Route::get('/clients/{client}/assignments', [ClientAssignmentController::class, 'edit'])
            ->name('clients.assignments.edit');

        Route::put('/clients/{client}/assignments', [ClientAssignmentController::class, 'update'])
            ->name('clients.assignments.update');
    });

    // Shifts
    Route::get('/shifts', [ShiftController::class, 'index'])
        ->middleware('permission:shifts.viewAny')
        ->name('shifts.index');
    Route::get('/shifts/create', [ShiftController::class, 'create'])
        ->middleware('permission:shifts.create')
        ->name('shifts.create');
    Route::post('/shifts', [ShiftController::class, 'store'])
        ->middleware('permission:shifts.create')
        ->name('shifts.store');
    Route::get('/shifts/{shift}/edit', [ShiftController::class, 'edit'])
        ->middleware('permission:shifts.update')
        ->name('shifts.edit');
    Route::put('/shifts/{shift}', [ShiftController::class, 'update'])
        ->middleware('permission:shifts.update')
        ->name('shifts.update');

    // Timesheets
    Route::get('/timesheets', [TimesheetController::class, 'index'])
        ->middleware('permission:timesheets.viewAny')
        ->name('timesheets.index');
    Route::get('/timesheets/create', [TimesheetController::class, 'create'])
        ->middleware('permission:timesheets.create')
        ->name('timesheets.create');
    Route::post('/timesheets', [TimesheetController::class, 'store'])
        ->middleware('permission:timesheets.create')
        ->name('timesheets.store');
    Route::get('/timesheets/{timesheet}/edit', [TimesheetController::class, 'edit'])
        ->middleware('permission:timesheets.viewAny')
        ->name('timesheets.edit');
    Route::put('/timesheets/{timesheet}', [TimesheetController::class, 'update'])
        ->middleware('permission:timesheets.update')
        ->name('timesheets.update');
});

require __DIR__ . '/settings.php';
