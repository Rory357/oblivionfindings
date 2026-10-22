<?php

use App\Http\Controllers\Chat\ChatLiveController;
use App\Http\Middleware\EnsureSharedChatEnabled;
use Illuminate\Support\Facades\Route;

// Oblivion Live's presence reports: signed by the gateway, so no session, cookie or CSRF token.
Route::post('/chat/api/live/presence', [ChatLiveController::class, 'presence'])
    ->middleware(EnsureSharedChatEnabled::class)->name('chat.live.presence');
