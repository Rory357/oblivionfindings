<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PortalClientController;
use App\Http\Controllers\PortalIncidentAttachmentController;
use App\Http\Controllers\ClientRagController;
use App\Http\Controllers\ClientDocumentController;
use App\Http\Controllers\SummaryController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\RagController;
use App\Http\Controllers\NotificationInboxController;
use App\Http\Controllers\AnnouncementInboxController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\Portal\FamilyDashboardController;
use App\Http\Controllers\Portal\PortalTimelineController;
use App\Http\Controllers\Portal\PortalHealthController;
use App\Http\Controllers\Portal\PortalScheduleController;
use App\Http\Controllers\Portal\PortalDocumentController;
use App\Http\Controllers\Portal\PortalPhotoController;
use App\Http\Controllers\Portal\PortalMessageController;
use App\Http\Controllers\Portal\PortalNotificationController;
use App\Http\Controllers\Portal\PortalPreferenceController;

/**
 * Portal & Shared Features Routes
 *
 * Handles client/family portal, timelines, summaries, notifications,
 * calendar, and RAG/AI queries.
 */

// Portal SSO routes (outside auth middleware - these are for login)
Route::get('portal/login', fn () => \Inertia\Inertia::render('portal/login'))->name('portal.login')->middleware('guest');
Route::get('portal/auth/microsoft/redirect', [\App\Http\Controllers\Auth\PortalOAuthController::class, 'redirectMicrosoft'])->name('portal.auth.microsoft');
Route::get('portal/auth/microsoft/callback', [\App\Http\Controllers\Auth\PortalOAuthController::class, 'callbackMicrosoft']);
Route::get('portal/auth/google/redirect', [\App\Http\Controllers\Auth\PortalOAuthController::class, 'redirectGoogle'])->name('portal.auth.google');
Route::get('portal/auth/google/callback', [\App\Http\Controllers\Auth\PortalOAuthController::class, 'callbackGoogle']);

Route::middleware(['auth'])->group(function () {
    // Client/Next-of-kin Portal
    Route::get('/portal', [PortalController::class, 'index'])->name('portal.index');
    Route::get('/portal/clients/{client}', [PortalClientController::class, 'show'])
        ->name('portal.clients.show');
    Route::get('/portal/clients/{client}/incidents/{incident}/attachments/{attachment}/download', [PortalIncidentAttachmentController::class, 'download'])
        ->name('portal.clients.incidents.attachments.download');
    Route::post('/portal/clients/{client}/rag/ask', [ClientRagController::class, 'ask'])
        ->middleware('throttle:ai-queries')
        ->name('portal.clients.rag.ask');
    Route::get('/portal/clients/{client}/documents/{document}/download', [ClientDocumentController::class, 'download'])
        ->name('portal.clients.documents.download');

    // Family Dashboard
    Route::get('/portal/clients/{client}/dashboard', [FamilyDashboardController::class, 'show'])
        ->name('portal.clients.dashboard');
    Route::post('/portal/clients/{client}/visit-requests', [FamilyDashboardController::class, 'storeVisitRequest'])
        ->name('portal.clients.visit-requests.store');
    Route::post('/portal/clients/{client}/visit-requests/{visit}/cancel', [FamilyDashboardController::class, 'cancelVisitRequest'])
        ->name('portal.clients.visit-requests.cancel');

    // Portal Tab Pages
    Route::get('/portal/clients/{client}/timeline', [PortalTimelineController::class, 'index'])
        ->name('portal.clients.timeline');
    Route::get('/portal/clients/{client}/health', [PortalHealthController::class, 'index'])
        ->name('portal.clients.health');
    Route::get('/portal/clients/{client}/schedule', [PortalScheduleController::class, 'index'])
        ->name('portal.clients.schedule');
    Route::get('/portal/clients/{client}/documents', [PortalDocumentController::class, 'index'])
        ->name('portal.clients.documents');
    Route::post('/portal/clients/{client}/documents', [PortalDocumentController::class, 'store'])
        ->name('portal.clients.documents.store');

    // Photo Gallery
    Route::get('/portal/clients/{client}/photos', [PortalPhotoController::class, 'index'])
        ->name('portal.clients.photos');
    Route::post('/portal/clients/{client}/photos', [PortalPhotoController::class, 'store'])
        ->name('portal.clients.photos.store');

    // Messaging
    Route::get('/portal/clients/{client}/messages', [PortalMessageController::class, 'index'])
        ->name('portal.clients.messages');
    Route::post('/portal/clients/{client}/messages/start', [PortalMessageController::class, 'startConversation'])
        ->name('portal.clients.messages.start');
    Route::get('/portal/clients/{client}/messages/{conversation}', [PortalMessageController::class, 'show'])
        ->name('portal.clients.messages.show');
    Route::post('/portal/clients/{client}/messages/{conversation}', [PortalMessageController::class, 'storeMessage'])
        ->name('portal.clients.messages.send');

    // Portal Notifications & Preferences
    Route::get('/portal/notifications', [PortalNotificationController::class, 'index'])
        ->name('portal.notifications');
    Route::post('/portal/notifications/{notification}/read', [PortalNotificationController::class, 'markRead'])
        ->name('portal.notifications.read');
    Route::post('/portal/notifications/read-all', [PortalNotificationController::class, 'markAllRead'])
        ->name('portal.notifications.readAll');
    Route::get('/portal/preferences', [PortalPreferenceController::class, 'index'])
        ->name('portal.preferences');
    Route::post('/portal/preferences', [PortalPreferenceController::class, 'update'])
        ->name('portal.preferences.update');

    Route::post('/portal/summaries/generate', [SummaryController::class, 'generate'])
        ->name('portal.summaries.generate');

    // Header inbox (notifications + announcements)
    Route::post('/inbox/notifications/{notification}/read', [NotificationInboxController::class, 'markRead'])
        ->name('inbox.notifications.read');
    Route::post('/inbox/notifications/{notification}/acknowledge', [NotificationInboxController::class, 'acknowledge'])
        ->name('inbox.notifications.ack');
    Route::post('/inbox/notifications/read-all', [NotificationInboxController::class, 'markAllRead'])
        ->name('inbox.notifications.readAll');

    Route::post('/inbox/announcements/{announcement}/read', [AnnouncementInboxController::class, 'markRead'])
        ->name('inbox.announcements.read');
    Route::post('/inbox/announcements/read-all', [AnnouncementInboxController::class, 'markAllRead'])
        ->name('inbox.announcements.readAll');

    // Notification Centre (full page)
    Route::get('/notifications', function (\Illuminate\Http\Request $request) {
        $user = $request->user();
        $query = $user->notifications();

        $filter = $request->query('filter', 'all');
        if ($filter === 'unread') $query->whereNull('read_at');
        if ($filter === 'read') $query->whereNotNull('read_at');

        $type = $request->query('type', 'all');
        if ($type !== 'all') {
            $query->where('data->module', $type);
        }

        return inertia('notifications/index', [
            'notifications' => $query->orderByDesc('created_at')->paginate(50)->through(fn ($n) => [
                'id' => $n->id,
                'type' => class_basename($n->type),
                'data' => $n->data,
                'read_at' => $n->read_at,
                'acknowledged_at' => $n->acknowledged_at,
                'created_at' => $n->created_at,
            ]),
            'unread_count' => $user->unreadNotifications()->count(),
            'filters' => ['filter' => $filter, 'type' => $type],
            'announcements' => \App\Models\Announcement::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(),
        ]);
    })->name('notifications.index');

    // Global RAG endpoints (header query bar)
    Route::middleware(['throttle:ai-queries'])->group(function () {
        Route::get('/rag/clients', [RagController::class, 'clients'])->name('rag.clients');
        Route::post('/rag/ask', [RagController::class, 'ask'])->name('rag.ask');
    });

    // Timelines
    Route::get('/timeline', [TimelineController::class, 'my'])->name('timeline.my');
    Route::get('/staff/{user}/timeline', [TimelineController::class, 'staff'])->name('timeline.staff');
    Route::get('/clients/{client}/timeline', [TimelineController::class, 'client'])->name('timeline.client');

    // Summaries
    Route::get('/summaries', fn() => redirect('/summaries/me'))->name('summaries.home');
    Route::get('/summaries/me', [SummaryController::class, 'my'])->name('summaries.me');
    Route::get('/summaries/staff/{user}', [SummaryController::class, 'staff'])->name('summaries.staff');
    Route::get('/summaries/clients/{client}', [SummaryController::class, 'client'])->name('summaries.client');
    Route::post('/summaries/generate', [SummaryController::class, 'generate'])
        ->middleware(['permission:summaries.generate', 'throttle:ai-queries'])
        ->name('summaries.generate');

    // Calendar
    Route::middleware('permission:calendar.viewAny')->group(function () {
        Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
        Route::get('/calendar/events', [CalendarController::class, 'events'])->name('calendar.events');

        // Calendar interactions (create/edit shifts inline)
        Route::post('/calendar/shifts', [CalendarController::class, 'storeShift'])
            ->middleware('permission:shifts.create')
            ->name('calendar.shifts.store');
        Route::patch('/calendar/shifts/{shift}', [CalendarController::class, 'updateShift'])
            ->middleware('permission:shifts.update')
            ->name('calendar.shifts.update');
    });
});
