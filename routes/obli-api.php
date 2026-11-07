<?php

use App\Http\Controllers\Obli\ObliApiController;
use App\Http\Controllers\Obli\ObliChatController;
use App\Http\Controllers\Obli\ObliComputerContextController;
use App\Http\Controllers\Obli\ObliKnowledgeController;
use Illuminate\Support\Facades\Route;

Route::prefix('obli')->name('api.obli.')->middleware('throttle:obli-api')->group(function (): void {
    Route::post('/ticket-computer-context', [ObliComputerContextController::class, 'mint'])->middleware('throttle:obli-ticket')->name('computer-context.mint');
    Route::get('/ticket-computer-context/{requestUuid}', [ObliComputerContextController::class, 'show'])->whereUuid('requestUuid')->name('computer-context.show');
    Route::get('/tickets/requests/{requestUuid}', [ObliComputerContextController::class, 'recoverTicket'])->whereUuid('requestUuid')->name('tickets.recover');
    Route::get('/knowledge', [ObliKnowledgeController::class, 'index'])->name('knowledge.index');
    Route::get('/knowledge/{article}', [ObliKnowledgeController::class, 'show'])->whereNumber('article')->name('knowledge.show');
    Route::get('/chat/options', [ObliChatController::class, 'options'])->name('chat.options');
    Route::post('/chat', [ObliChatController::class, 'store'])->middleware('throttle:obli-chat')->name('chat.store');
    Route::get('/chat/{uuid}', [ObliChatController::class, 'show'])->whereUuid('uuid')->name('chat.show');
    Route::post('/token', [ObliApiController::class, 'token'])->middleware('throttle:obli-token')->name('token');
    Route::get('/me', [ObliApiController::class, 'me'])->name('me');
    Route::post('/logout', [ObliApiController::class, 'logout'])->name('logout');
    Route::get('/tickets/{ticket}', [ObliApiController::class, 'showTicket'])->whereNumber('ticket')->name('tickets.show');
    Route::post('/tickets/{ticket}/replies', [ObliApiController::class, 'replyTicket'])->whereNumber('ticket')->middleware('throttle:obli-ticket')->name('tickets.replies.store');
    Route::get('/tickets/{ticket}/replies/{requestUuid}', [ObliApiController::class, 'recoverReply'])->whereNumber('ticket')->whereUuid('requestUuid')->name('tickets.replies.recover');
    Route::get('/tickets', [ObliApiController::class, 'tickets'])->name('tickets.index');
    Route::post('/tickets', [ObliComputerContextController::class, 'storeTicket'])->middleware('throttle:obli-ticket')->name('tickets.store');
    // S&D Phase I: Obli fixes things (sd-obli-fix-protocol.md). The engine checks session, computer and limits.
    Route::post('/fixes', [\App\Http\Controllers\Obli\ObliFixController::class, 'store'])->middleware('throttle:obli-ticket')->name('fixes.store');
    Route::get('/fixes/{uuid}', [\App\Http\Controllers\Obli\ObliFixController::class, 'show'])->whereUuid('uuid')->name('fixes.show');
    Route::post('/fixes/{uuid}/approve', [\App\Http\Controllers\Obli\ObliFixController::class, 'approve'])->whereUuid('uuid')->name('fixes.approve');
    Route::post('/fixes/{uuid}/turn', [\App\Http\Controllers\Obli\ObliFixController::class, 'turn'])->whereUuid('uuid')->name('fixes.turn');
    Route::post('/fixes/{uuid}/stop', [\App\Http\Controllers\Obli\ObliFixController::class, 'stop'])->whereUuid('uuid')->name('fixes.stop');
    Route::post('/fixes/{uuid}/resume', [\App\Http\Controllers\Obli\ObliFixController::class, 'resume'])->whereUuid('uuid')->name('fixes.resume');
    Route::post('/fixes/{uuid}/ticket', [\App\Http\Controllers\Obli\ObliFixController::class, 'ticket'])->whereUuid('uuid')->middleware('throttle:obli-ticket')->name('fixes.ticket');
    Route::post('/fixes/{uuid}/cancel-request', [\App\Http\Controllers\Obli\ObliFixController::class, 'cancelRequest'])->whereUuid('uuid')->name('fixes.cancel-request');
    Route::post('/offline-reports', [\App\Http\Controllers\Obli\ObliFixController::class, 'offlineReport'])->middleware('throttle:obli-ticket')->name('offline-reports.store');
});
