<?php

use App\Http\Controllers\System\AccessControlController;
use App\Http\Controllers\System\UsersController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| System Module Routes
|--------------------------------------------------------------------------
|
| These routes handle system-level administration including:
| - Access Control (roles, permissions, user assignments)
| - User Management (all system users)
| - Audit Logs
|
*/

Route::middleware(['auth', 'verified'])->prefix('system')->group(function () {
    
    // Access Control Dashboard
    Route::get('/access', [AccessControlController::class, 'dashboard'])
        ->middleware('permission:settings.access.manage')
        ->name('system.access.dashboard');
    
    // Roles Management
    Route::get('/access/roles', [AccessControlController::class, 'roles'])
        ->middleware('permission:settings.access.manage')
        ->name('system.access.roles');
    Route::post('/access/roles', [AccessControlController::class, 'storeRole'])
        ->middleware('permission:settings.access.manage')
        ->name('system.access.roles.store');
    Route::put('/access/roles/{role}', [AccessControlController::class, 'updateRole'])
        ->middleware('permission:settings.access.manage')
        ->name('system.access.roles.update');
    Route::post('/access/roles/{role}/clone', [AccessControlController::class, 'cloneRole'])
        ->middleware('permission:settings.access.manage')
        ->name('system.access.roles.clone');
    Route::delete('/access/roles/{role}', [AccessControlController::class, 'destroyRole'])
        ->middleware('permission:settings.access.manage')
        ->name('system.access.roles.destroy');
    
    // Permissions Matrix
    Route::get('/access/matrix', [AccessControlController::class, 'matrix'])
        ->middleware('permission:settings.access.manage')
        ->name('system.access.matrix');
    
    // User Assignments
    Route::get('/access/assignments', [AccessControlController::class, 'assignments'])
        ->middleware('permission:settings.access.manage')
        ->name('system.access.assignments');
    Route::put('/access/assignments/{target}', [AccessControlController::class, 'updateAssignments'])
        ->middleware('permission:settings.access.manage')
        ->name('system.access.assignments.update');
    
    // Impersonation (must be before /users/{target} routes)
    Route::post('/users/stop-impersonating', [UsersController::class, 'stopImpersonating'])
        ->name('system.users.stop-impersonating');
    Route::post('/users/{target}/impersonate', [UsersController::class, 'impersonate'])
        ->middleware('permission:settings.access.impersonate')
        ->name('system.users.impersonate');

    // Users Management
    Route::get('/users', [UsersController::class, 'index'])
        ->middleware('permission:settings.access.manage')
        ->name('system.users.index');
    Route::get('/users/create', [UsersController::class, 'create'])
        ->middleware('permission:settings.access.manage')
        ->name('system.users.create');
    Route::post('/users', [UsersController::class, 'store'])
        ->middleware('permission:settings.access.manage')
        ->name('system.users.store');
    Route::get('/users/{target}', [UsersController::class, 'show'])
        ->middleware('permission:settings.access.manage')
        ->name('system.users.show');
    Route::put('/users/{target}', [UsersController::class, 'update'])
        ->middleware('permission:settings.access.manage')
        ->name('system.users.update');
    Route::delete('/users/{target}', [UsersController::class, 'destroy'])
        ->middleware('permission:settings.access.manage')
        ->name('system.users.destroy');
    Route::post('/users/{target}/approve', [UsersController::class, 'approve'])
        ->middleware('permission:settings.access.manage')
        ->name('system.users.approve');
    Route::post('/users/{target}/suspend', [UsersController::class, 'suspend'])
        ->middleware('permission:settings.access.manage')
        ->name('system.users.suspend');
});
