<?php

use App\Http\Controllers\It\ItProvisioningWorkspaceController;
use Illuminate\Support\Facades\Route;

// Registered inside the canonical authenticated IT route group.
Route::prefix('it/provisioning')->middleware(\App\Http\Middleware\ProtectProvisioningResponses::class)->group(function (): void {
    Route::get('tasks/{task}', [ItProvisioningWorkspaceController::class, 'showTask'])->whereNumber('task')->name('it.provisioning.tasks.show');
    Route::get('workflows/{workflow}', [ItProvisioningWorkspaceController::class, 'showWorkflow'])->whereNumber('workflow')->name('it.provisioning.workflows.show');
    Route::middleware('permission:it.manage')->group(function (): void {
        Route::get('templates/{template}', [ItProvisioningWorkspaceController::class, 'showTemplate'])->whereNumber('template')->name('it.provisioning.templates.show');
        Route::get('options', [ItProvisioningWorkspaceController::class, 'options'])->name('it.provisioning.options');
        Route::get('commands/{kind}/{target}/{operation}', [ItProvisioningWorkspaceController::class, 'recover'])->whereNumber('target')->name('it.provisioning.commands.show');
        Route::post('commands/{kind}/{target}/{operation}', [ItProvisioningWorkspaceController::class, 'command'])->whereNumber('target')->name('it.provisioning.commands.store');
        Route::post('commands/{kind}/{target}/{operation}/cancel', [ItProvisioningWorkspaceController::class, 'cancelCommand'])->whereNumber('target')->name('it.provisioning.commands.cancel');
    });
});
