<?php

use App\Http\Controllers\It\ItTicketDraftController;
use Illuminate\Support\Facades\Route;

Route::prefix('it/drafts')->name('it.drafts.')->group(function (): void {
    Route::post('/validate-local-candidate', [ItTicketDraftController::class, 'validateLocalCandidate'])->name('validate-local-candidate');
    Route::post('/context', [ItTicketDraftController::class, 'context'])->name('context');
    Route::get('/{draftUuid}', [ItTicketDraftController::class, 'show'])->whereUuid('draftUuid')->name('show');
    Route::post('/{draftUuid}/resume', [ItTicketDraftController::class, 'resume'])->whereUuid('draftUuid')->name('resume');
    Route::post('/{draftUuid}/validate-candidate', [ItTicketDraftController::class, 'validateCandidate'])->whereUuid('draftUuid')->name('validate-candidate');
    Route::patch('/{draftUuid}', [ItTicketDraftController::class, 'update'])->whereUuid('draftUuid')->name('update');
    Route::delete('/{draftUuid}', [ItTicketDraftController::class, 'destroy'])->whereUuid('draftUuid')->name('destroy');
    Route::post('/{draftUuid}/start-new', [ItTicketDraftController::class, 'startNew'])->whereUuid('draftUuid')->name('start-new');
    Route::post('/{draftUuid}/attachments', [ItTicketDraftController::class, 'upload'])->whereUuid('draftUuid')->name('attachments.store');
    Route::delete('/{draftUuid}/attachments/{attachmentId}', [ItTicketDraftController::class, 'removeAttachment'])
        ->whereUuid('draftUuid')->whereNumber('attachmentId')->name('attachments.destroy');
});
