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
});
