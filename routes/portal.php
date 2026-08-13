<?php

use App\Http\Controllers\AnnouncementInboxController;
use App\Http\Controllers\Auth\PortalOAuthController;
use App\Http\Controllers\ClientCalendarController;
use App\Http\Controllers\ClientDocumentController;
use App\Http\Controllers\ClientPhotoMediaController;
use App\Http\Controllers\ClientRagController;
use App\Http\Controllers\ClientVisitRequestController;
use App\Http\Controllers\FamilyNoteController;
use App\Http\Controllers\NotificationInboxController;
use App\Http\Controllers\Portal\CarePlanAttestationController;
use App\Http\Controllers\Portal\ConsentRequestPortalController;
use App\Http\Controllers\Portal\FamilyDashboardController;
use App\Http\Controllers\Portal\PortalCalendarController;
use App\Http\Controllers\Portal\PortalDocumentController;
use App\Http\Controllers\Portal\PortalFamilyNoteController;
use App\Http\Controllers\Portal\PortalHealthController;
use App\Http\Controllers\Portal\PortalLocationController;
use App\Http\Controllers\Portal\PortalMessageController;
use App\Http\Controllers\Portal\PortalNotificationController;
use App\Http\Controllers\Portal\PortalPhotoController;
use App\Http\Controllers\Portal\PortalPreferenceController;
use App\Http\Controllers\Portal\PortalScheduleController;
use App\Http\Controllers\Portal\PortalTimelineController;
use App\Http\Controllers\Portal\PortalTimelineInteractionController;
use App\Http\Controllers\PortalClientController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PortalIncidentAttachmentController;
use App\Http\Controllers\RagController;
use App\Http\Controllers\SummaryController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\TimelineInteractionController;
use App\Models\Announcement;
use App\Models\Client;
use App\Models\FamilyVisitRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/**
 * Portal & Shared Features Routes
 *
 * Handles client/family portal, timelines, summaries, notifications,
 * calendar, and RAG/AI queries.
 */

// Portal SSO routes (outside auth middleware - these are for login)
Route::get('portal/login', fn () => Inertia::render('portal/login'))->name('portal.login')->middleware('guest');
Route::get('portal/auth/microsoft/redirect', [PortalOAuthController::class, 'redirectMicrosoft'])->name('portal.auth.microsoft');
Route::get('portal/auth/microsoft/callback', [PortalOAuthController::class, 'callbackMicrosoft']);
Route::get('portal/auth/google/redirect', [PortalOAuthController::class, 'redirectGoogle'])->name('portal.auth.google');
Route::get('portal/auth/google/callback', [PortalOAuthController::class, 'callbackGoogle']);

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

    // Consent Requests (portal-side respond flow)
    Route::get('/portal/clients/{client}/consent-requests/{consentRequest}', [ConsentRequestPortalController::class, 'show'])
        ->name('portal.clients.consent-requests.show');
    Route::post('/portal/clients/{client}/consent-requests/{consentRequest}/approve', [ConsentRequestPortalController::class, 'approve'])
        ->name('portal.clients.consent-requests.approve');
    Route::post('/portal/clients/{client}/consent-requests/{consentRequest}/decline', [ConsentRequestPortalController::class, 'decline'])
        ->name('portal.clients.consent-requests.decline');

    Route::post('/portal/clients/{client}/care-plans/{carePlan}/attestations', [CarePlanAttestationController::class, 'store'])
        ->name('portal.clients.care-plans.attestations.store');

    // Portal Calendar
    Route::get('/portal/clients/{client}/calendar', [PortalCalendarController::class, 'index'])
        ->name('portal.clients.calendar');
    Route::get('/portal/clients/{client}/calendar/events', [PortalCalendarController::class, 'events'])
        ->name('portal.clients.calendar.events');

    // Family Notes (Portal)
    Route::get('/portal/clients/{client}/family-notes', [PortalFamilyNoteController::class, 'index'])
        ->name('portal.clients.family-notes');
    Route::post('/portal/clients/{client}/family-notes', [PortalFamilyNoteController::class, 'store'])
        ->name('portal.clients.family-notes.store');
    Route::put('/portal/clients/{client}/family-notes/{familyNote}', [PortalFamilyNoteController::class, 'update'])
        ->name('portal.clients.family-notes.update');
    Route::delete('/portal/clients/{client}/family-notes/{familyNote}', [PortalFamilyNoteController::class, 'destroy'])
        ->name('portal.clients.family-notes.destroy');

    // Portal Tab Pages
    Route::get('/portal/clients/{client}/timeline', [PortalTimelineController::class, 'index'])
        ->name('portal.clients.timeline');

    // Timeline Interactions (comments & reactions)
    Route::post('/portal/clients/{client}/timeline/{timelineEvent}/comments', [PortalTimelineInteractionController::class, 'storeComment'])
        ->name('portal.clients.timeline.comments.store');
    Route::delete('/portal/clients/{client}/timeline/comments/{timelineEventComment}', [PortalTimelineInteractionController::class, 'destroyComment'])
        ->name('portal.clients.timeline.comments.destroy');
    Route::post('/portal/clients/{client}/timeline/comments/{timelineEventComment}/like', [PortalTimelineInteractionController::class, 'toggleCommentLike'])
        ->name('portal.clients.timeline.comments.like');
    Route::post('/portal/clients/{client}/timeline/{timelineEvent}/react', [PortalTimelineInteractionController::class, 'toggleReaction'])
        ->name('portal.clients.timeline.react');
    Route::get('/portal/clients/{client}/health', [PortalHealthController::class, 'index'])
        ->name('portal.clients.health');
    Route::get('/portal/clients/{client}/schedule', [PortalScheduleController::class, 'index'])
        ->name('portal.clients.schedule');
    Route::get('/portal/clients/{client}/documents', [PortalDocumentController::class, 'index'])
        ->name('portal.clients.documents');
    Route::post('/portal/clients/{client}/documents', [PortalDocumentController::class, 'store'])
        ->name('portal.clients.documents.store');

    // Location Tracking
    Route::get('/portal/clients/{client}/location', [PortalLocationController::class, 'index'])
        ->name('portal.clients.location');
    Route::get('/portal/clients/{client}/location/history', [PortalLocationController::class, 'history'])
        ->name('portal.clients.location.history');
    Route::get('/portal/clients/{client}/location/privacy-status', [PortalLocationController::class, 'privacyStatus'])
        ->name('portal.clients.location.privacy-status');

    // Photo Gallery
    Route::get('/portal/clients/{client}/photos', [PortalPhotoController::class, 'index'])
        ->name('portal.clients.photos');
    Route::post('/portal/clients/{client}/photos', [PortalPhotoController::class, 'store'])
        ->name('portal.clients.photos.store');
    Route::get('/portal/clients/{client}/photos/{photo}/media', [ClientPhotoMediaController::class, 'portalMedia'])
        ->whereNumber('client')
        ->whereNumber('photo')
        ->name('portal.clients.photos.media');
    Route::get('/portal/clients/{client}/photos/{photo}/thumbnail', [ClientPhotoMediaController::class, 'portalThumbnail'])
        ->whereNumber('client')
        ->whereNumber('photo')
        ->name('portal.clients.photos.thumbnail');

    // Messaging
    Route::get('/portal/clients/{client}/messages', [PortalMessageController::class, 'index'])
        ->name('portal.clients.messages');
    Route::post('/portal/clients/{client}/messages/start', [PortalMessageController::class, 'startConversation'])
        ->name('portal.clients.messages.start');
    Route::get('/portal/clients/{client}/messages/{conversation}', [PortalMessageController::class, 'show'])
        ->name('portal.clients.messages.show');
    Route::post('/portal/clients/{client}/messages/{conversation}', [PortalMessageController::class, 'storeMessage'])
        ->name('portal.clients.messages.send');
    Route::post('/portal/clients/{client}/messages/react/{message}', [PortalMessageController::class, 'toggleReaction'])
        ->name('portal.clients.messages.react');
    Route::post('/portal/clients/{client}/messages/pin/{message}', [PortalMessageController::class, 'togglePin'])
        ->name('portal.clients.messages.pin');
    Route::get('/portal/clients/{client}/messages-search', [PortalMessageController::class, 'searchMessages'])
        ->name('portal.clients.messages.search');
    Route::delete('/portal/clients/{client}/messages/archive/{message}', [PortalMessageController::class, 'archiveMessage'])
        ->name('portal.clients.messages.archive');

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
        ->middleware('throttle:ai-queries')
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
    Route::get('/notifications', function (Request $request) {
        $user = $request->user();
        $query = $user->notifications();

        $filter = $request->query('filter', 'all');
        if ($filter === 'unread') {
            $query->whereNull('read_at');
        }
        if ($filter === 'read') {
            $query->whereNotNull('read_at');
        }

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
            'announcements' => Announcement::query()
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

    // Client Calendar
    Route::get('/operations/clients/{client}/calendar', function (Request $request, Client $client) {
        app(ClientCalendarController::class)->authorize('view', $client);

        return inertia('operations/clients/calendar', [
            'client' => ['id' => $client->id, 'first_name' => $client->first_name, 'last_name' => $client->last_name],
            'pending_visit_count' => FamilyVisitRequest::where('client_id', $client->id)->where('status', 'pending')->count(),
        ]);
    })->name('client.calendar');
    Route::get('/clients/{client}/calendar/events', [ClientCalendarController::class, 'events'])->name('client.calendar.events');
    Route::post('/clients/{client}/calendar/appointments', [ClientCalendarController::class, 'storeAppointment'])
        ->middleware('permission:calendar.create')
        ->name('client.calendar.appointments.store');
    Route::put('/clients/{client}/calendar/appointments/{appointment}', [ClientCalendarController::class, 'updateAppointment'])
        ->middleware('permission:calendar.manage')
        ->name('client.calendar.appointments.update');
    Route::delete('/clients/{client}/calendar/appointments/{appointment}', [ClientCalendarController::class, 'destroyAppointment'])
        ->middleware('permission:calendar.manage')
        ->name('client.calendar.appointments.destroy');

    // Family Notes (Staff)
    Route::post('/clients/{client}/family-notes/{familyNote}/respond', [FamilyNoteController::class, 'respond'])
        ->name('client.family-notes.respond');
    Route::post('/clients/{client}/family-notes/{familyNote}/status', [FamilyNoteController::class, 'updateStatus'])
        ->name('client.family-notes.status');
    Route::post('/clients/{client}/family-notes/{familyNote}/assign-shift', [FamilyNoteController::class, 'assignToShift'])
        ->name('client.family-notes.assign-shift');

    // Visit Request Approval
    Route::get('/operations/clients/{client}/visit-requests', [ClientVisitRequestController::class, 'index'])->name('client.visit-requests.index');
    Route::post('/operations/clients/{client}/visit-requests/{visit}/approve', [ClientVisitRequestController::class, 'approve'])->name('client.visit-requests.approve');
    Route::post('/operations/clients/{client}/visit-requests/{visit}/decline', [ClientVisitRequestController::class, 'decline'])->name('client.visit-requests.decline');

    // Staff Timeline Interactions (comments & reactions)
    Route::post('/clients/{client}/timeline/{timelineEvent}/comments', [TimelineInteractionController::class, 'storeComment'])
        ->name('timeline.comments.store');
    Route::delete('/clients/{client}/timeline/comments/{timelineEventComment}', [TimelineInteractionController::class, 'destroyComment'])
        ->name('timeline.comments.destroy');
    Route::post('/clients/{client}/timeline/comments/{timelineEventComment}/like', [TimelineInteractionController::class, 'toggleCommentLike'])
        ->name('timeline.comments.like');
    Route::post('/clients/{client}/timeline/{timelineEvent}/react', [TimelineInteractionController::class, 'toggleReaction'])
        ->name('timeline.react');

    // Summaries
    Route::get('/summaries', fn () => redirect('/summaries/me'))->name('summaries.home');
    Route::get('/summaries/me', [SummaryController::class, 'my'])->name('summaries.me');
    Route::get('/summaries/staff/{user}', [SummaryController::class, 'staff'])->name('summaries.staff');
    Route::get('/summaries/clients/{client}', [SummaryController::class, 'client'])->name('summaries.client');
    Route::post('/summaries/generate', [SummaryController::class, 'generate'])
        ->middleware(['permission:summaries.generate', 'throttle:ai-queries'])
        ->name('summaries.generate');

    // Scheduling has been consolidated into the Rostering workspace: the
    // FullCalendar is now the "Calendar" tab at /operations/rostering, and its
    // data/write endpoints live under operations.rostering.calendar.* (see
    // routes/operations.php). Keep a redirect so old bookmarks / links land on
    // the calendar tab.
    Route::redirect('/scheduling', '/operations/rostering?tab=calendar', 301);
});
