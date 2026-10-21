<?php

use App\Http\Controllers\Chat\ChatMediaAuthorizationController;
use App\Http\Controllers\Chat\ChatMediaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/chat/calling/authorize', ChatMediaAuthorizationController::class)->middleware('throttle:600,1')->name('chat.calling.authorize');
Route::post('/chat/calling/events', ChatMediaWebhookController::class)->middleware('throttle:600,1')->name('chat.calling.events');
