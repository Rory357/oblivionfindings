<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientAssignmentController;

Route::get('/', function () {
    return Inertia::render('home', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('/contact', function () {
    return Inertia::render('Contact');
})->name('contact');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

Route::middleware(['auth'])->group(function () {

    // ✅ ALL authenticated users (policy decides data)
    // (now permission-based so it’s consistent)
    Route::middleware('permission:clients.viewAny')->group(function () {
        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    });

    // ✅ Manager/Admin modules (permission-based)
    Route::middleware('permission:workers.viewAny')->group(function () {
        Route::get('/workers', fn() => inertia('workers/index'))->name('workers.index');
    });

    Route::middleware('permission:reports.viewAny')->group(function () {
        Route::get('/reports', fn() => inertia('reports/index'))->name('reports.index');
    });

    Route::middleware('permission:staff.viewAny')->group(function () {
        Route::get('/staff', fn() => inertia('staff/index'))->name('staff.index');
    });

    Route::middleware('permission:rostering.viewAny')->group(function () {
        Route::get('/rostering', fn() => inertia('rostering/index'))->name('rostering.index');
    });

    Route::middleware('permission:fleet.viewAny')->group(function () {
        Route::get('/fleet-management', fn() => inertia('fleet-management/index'))->name('fleet.index');
    });

    Route::middleware('permission:calendar.viewAny')->group(function () {
        Route::get('/calendar', fn() => inertia('calendar/index'))->name('calendar.index');
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

    // ✅ Support worker module (permission-based now)
    Route::middleware('permission:shifts.viewAny')->group(function () {
        Route::get('/shifts', fn() => inertia('shifts/index'))->name('shifts.index');
    });
});

require __DIR__ . '/settings.php';
