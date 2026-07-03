<?php

use App\Http\Controllers\AllTasksController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| All Tasks — company-wide work-item dashboard
|--------------------------------------------------------------------------
| No route-level permission: the TaskAggregator gates every module feed on
| that module's own view permission, so users only ever see sources they
| could already open directly.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/tasks', [AllTasksController::class, 'index'])->name('tasks.index');
    Route::get('/tasks/detail', [AllTasksController::class, 'detail'])->name('tasks.detail');
    Route::get('/tasks/lookup', [AllTasksController::class, 'lookup'])->name('tasks.lookup');
    Route::get('/tasks/reports', [AllTasksController::class, 'reports'])->name('tasks.reports');
    Route::post('/tasks/default-view', [AllTasksController::class, 'saveDefaultView'])->name('tasks.default-view');
    Route::post('/tasks/{source}/{id}/assign', [AllTasksController::class, 'assign'])
        ->whereNumber('id')
        ->name('tasks.assign');
});
