<?php

use App\Http\Controllers\CompanyHub\HubRecognitionSocialController;
use Illuminate\Support\Facades\Route;

// Included outside the main Company Hub group; every route retains its own guard.
Route::middleware(['auth', 'verified', 'permission:company_hub.view'])->prefix('company-hub')->name('company-hub.')->group(function (): void {
    Route::get('/recognition/mention-options', [HubRecognitionSocialController::class, 'mentionOptions'])->middleware('throttle:60,1')->name('recognition.mentions.options');
    Route::get('/recognition/{kudos}/conversation', [HubRecognitionSocialController::class, 'show'])->whereNumber('kudos')->name('recognition.conversation');
    Route::get('/recognition/{kudos}/mention-options', [HubRecognitionSocialController::class, 'mentionOptions'])->whereNumber('kudos')->middleware('throttle:60,1')->name('recognition.mentions');
    Route::post('/recognition/{kudos}/replies', [HubRecognitionSocialController::class, 'store'])->whereNumber('kudos')->middleware(['permission:hr.recognition.give', 'throttle:30,1'])->name('recognition.replies.store');
    Route::patch('/recognition/{kudos}/replies/{reply}', [HubRecognitionSocialController::class, 'update'])->whereNumber(['kudos', 'reply'])->middleware(['permission:hr.recognition.give', 'throttle:60,1'])->name('recognition.replies.update');
    Route::delete('/recognition/{kudos}/replies/{reply}', [HubRecognitionSocialController::class, 'destroy'])->whereNumber(['kudos', 'reply'])->middleware(['permission:hr.recognition.give', 'throttle:60,1'])->name('recognition.replies.destroy');
    Route::patch('/recognition/{kudos}/replies/{reply}/moderation', [HubRecognitionSocialController::class, 'moderate'])->whereNumber(['kudos', 'reply'])->middleware('throttle:30,1')->name('recognition.replies.moderate');
    Route::get('/recognition-images/{image}', [HubRecognitionSocialController::class, 'image'])->whereNumber('image')->name('recognition.images.show');
});
