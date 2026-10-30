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
});
