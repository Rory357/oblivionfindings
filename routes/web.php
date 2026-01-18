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
use App\Http\Controllers\Auth\MicrosoftController;
use App\Http\Controllers\Auth\GoogleController;

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

    // ✅ ALL authenticated users (policy decides data)
    // (now permission-based so it’s consistent)
    Route::middleware('permission:clients.viewAny')->group(function () {
        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
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
