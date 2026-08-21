<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CarePlan;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientNote;
use App\Models\ConsentRequest;
use App\Models\FamilyNote;
use App\Models\FamilyVisitRequest;
use App\Models\RespiteBooking;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Services\Portal\PortalClientSectionAccess;
use App\Services\Timeline\TimelineEmitter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FamilyDashboardController extends Controller
{
    public function show(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);

        // Ensure the user has portal access to this client
        abort_unless($user->canAccessClientPortal($client), 403);

        $sectionAccessService = app(PortalClientSectionAccess::class);
        $sectionAccess = $sectionAccessService->for($user, $client);

        $today = now()->startOfDay();
        $tomorrow = (clone $today)->addDay();
        $weekEnd = (clone $today)->addDays(7);
        $monthEnd = (clone $today)->addDays(30);
        $showShiftSchedule = $sectionAccess['show_shift_schedule'];
        $showRespite = $sectionAccess['show_respite'];
        $showCareNotes = $sectionAccess['show_care_notes'];
        $canViewMedical = $sectionAccess['can_view_medical'];
        $canViewFamilyInformation = $sectionAccess['has_family_information_consent'];
        $canViewIncidents = $sectionAccess['can_view_incidents']
            && $user->canDo('incidents.view.portal');

        // Load client with key relationships
        $clientRelations = ['site:id,name,address_line_1,city'];
        if ($canViewFamilyInformation) {
            $clientRelations[] = 'keyWorker:id,name,email,profile_photo_path,last_seen_at,presence_status';
            $clientRelations[] = 'supportWorkers:id,name,email,profile_photo_path,last_seen_at,presence_status';
        }
        if ($canViewMedical) {
            $clientRelations[] = 'medicalProfile';
        }
        $client->load($clientRelations);

        // Helper to derive presence
        $derivePresence = function ($user) {
            if (! $user || ! $user->last_seen_at) {
                return 'offline';
            }
            if ($user->presence_status === 'online' && $user->last_seen_at->gt(now()->subMinutes(5))) {
                return 'online';
            }
            if ($user->last_seen_at->gt(now()->subMinutes(15))) {
                return 'away';
            }

            return 'offline';
        };

        // Today's shifts
        $todayShifts = $showShiftSchedule
            ? Shift::where('client_id', $client->id)
                ->where('starts_at', '<', $tomorrow)
                ->where('ends_at', '>', $today)
                ->orderBy('starts_at')
                ->with(['staff:id,name,email,profile_photo_path', 'serviceContext:id,name'])
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'starts_at' => $s->starts_at?->toISOString(),
                    'ends_at' => $s->ends_at?->toISOString(),
                    'status' => $s->status,
                    'type' => $s->shift_type ?? 'standard',
                    'shift_type' => $s->shift_type ?? 'standard',
                    'service_context' => $s->serviceContext?->name,
                    'location' => $s->location,
                    'is_sleepover' => (bool) $s->is_sleepover,
                    'is_on_call' => (bool) $s->is_on_call,
                    'expected_break_minutes' => $s->expected_break_minutes,
                    'staff' => $s->staff ? ['id' => $s->staff->id, 'name' => $s->staff->name, 'avatar' => $s->staff->avatar] : null,
                ])
            : collect();

        // This week's shifts
        $weekShifts = $showShiftSchedule
            ? Shift::where('client_id', $client->id)
                ->where('starts_at', '<', $weekEnd)
                ->where('ends_at', '>', $today)
                ->orderBy('starts_at')
                ->with(['staff:id,name,profile_photo_path', 'serviceContext:id,name'])
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'starts_at' => $s->starts_at?->toISOString(),
                    'ends_at' => $s->ends_at?->toISOString(),
                    'status' => $s->status,
                    'type' => $s->shift_type ?? 'standard',
                    'shift_type' => $s->shift_type ?? 'standard',
                    'service_context' => $s->serviceContext?->name,
                    'location' => $s->location,
                    'is_sleepover' => (bool) $s->is_sleepover,
                    'is_on_call' => (bool) $s->is_on_call,
                    'expected_break_minutes' => $s->expected_break_minutes,
                    'staff' => $s->staff ? ['id' => $s->staff->id, 'name' => $s->staff->name, 'avatar' => $s->staff->avatar] : null,
                ])
            : collect();

        // Monthly calendar events (shifts for the next 30 days)
        $monthShifts = $showShiftSchedule
            ? Shift::where('client_id', $client->id)
                ->where('starts_at', '<', $monthEnd)
                ->where('ends_at', '>', $today)
                ->orderBy('starts_at')
                ->with(['staff:id,name', 'serviceContext:id,name'])
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'date' => $s->starts_at?->toDateString(),
                    'starts_at' => $s->starts_at?->toISOString(),
                    'ends_at' => $s->ends_at?->toISOString(),
                    'status' => $s->status,
                    'type' => $s->shift_type ?? 'standard',
                    'shift_type' => $s->shift_type ?? 'standard',
                    'service_context' => $s->serviceContext?->name,
                    'location' => $s->location,
                    'is_sleepover' => (bool) $s->is_sleepover,
                    'is_on_call' => (bool) $s->is_on_call,
                    'staff_name' => $s->staff?->name,
                ])
            : collect();

        // Recent timeline events (portal-visible)
        $recentEventsQuery = TimelineEvent::where('client_id', $client->id)
            ->where('visibility', 'portal')
            ->with(['actor:id,name', 'reactions']);
        $sectionAccessService->constrainTimeline($recentEventsQuery, $sectionAccess);
        $recentEvents = $recentEventsQuery->orderByDesc('occurred_at')
            ->limit(10)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'subject' => $e->subject,
                'body' => $e->body,
                'occurred_at' => $e->occurred_at?->toISOString(),
                'actor_name' => $e->actor?->name,
                'meta' => $e->meta ?? [],
                'reactions' => $e->reactions
                    ->groupBy('emoji')
                    ->map(fn ($group, $emoji) => [
                        'emoji' => $emoji,
                        'count' => $group->count(),
                        'user_ids' => $group->pluck('user_id')->all(),
                    ])
                    ->values()
                    ->all(),
            ]);

        $upcomingRespite = $showRespite
            ? RespiteBooking::query()
                ->where('client_id', $client->id)
                ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
                ->where('start_at', '<', $monthEnd)
                ->where('end_at', '>', $today)
                ->with(['stays' => fn ($query) => $query->latest('actual_start')])
                ->orderBy('start_at')
                ->limit(5)
                ->get()
                ->map(fn (RespiteBooking $booking) => [
                    'id' => $booking->id,
                    'starts_at' => $booking->start_at?->toISOString(),
                    'ends_at' => $booking->end_at?->toISOString(),
                    'status' => $booking->status,
                    'stay_status' => $booking->stays->first()?->status,
                    'date' => $booking->start_at?->toDateString(),
                ])
            : collect();

        // Recent incidents (portal-visible, reviewed only)
        $recentIncidents = $canViewIncidents
            ? ClientIncident::where('client_id', $client->id)
                ->where('portal_visible', true)
                ->whereNotNull('reviewed_at')
                ->orderByDesc('occurred_at')
                ->limit(5)
                ->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'type' => $i->type,
                    'severity' => $i->severity,
                    'occurred_at' => $i->occurred_at?->toISOString(),
                    'description' => $i->description,
                ])
            : collect();

        // Critical alerts (high/critical only, last 14 days, max 3)
        $criticalAlerts = $canViewIncidents
            ? ClientIncident::where('client_id', $client->id)
                ->where('portal_visible', true)
                ->whereNotNull('reviewed_at')
                ->whereIn('severity', ['high', 'critical'])
                ->where('occurred_at', '>=', now()->subDays(14))
                ->orderByDesc('occurred_at')
                ->limit(3)
                ->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'type' => $i->type,
                    'severity' => $i->severity,
                    'occurred_at' => $i->occurred_at?->toISOString(),
                    'description' => $i->description,
                ])
            : collect();

        // Daily summary (deterministic)
        $completedToday = $showShiftSchedule
            ? Shift::where('client_id', $client->id)
                ->where('starts_at', '<', $tomorrow)
                ->where('ends_at', '>', $today)
                ->where('status', 'completed')
                ->count()
            : 0;
        $scheduledToday = $showShiftSchedule
            ? Shift::where('client_id', $client->id)
                ->where('starts_at', '<', $tomorrow)
                ->where('ends_at', '>', $today)
                ->whereIn('status', ['scheduled', 'in_progress'])
                ->count()
            : 0;
        $lastEventQuery = TimelineEvent::where('client_id', $client->id)
            ->where('visibility', 'portal');
        $sectionAccessService->constrainTimeline($lastEventQuery, $sectionAccess);
        $lastEvent = $lastEventQuery->orderByDesc('occurred_at')->first();

        // Care plan summary
        $carePlan = $sectionAccess['show_care_plans']
            ? CarePlan::where('client_id', $client->id)
                ->where('status', 'active')
                ->withCount(['goals', 'goals as goals_completed' => fn ($q) => $q->where('status', 'completed')])
                ->first()
            : null;

        // Pending consent requests addressed to this portal user
        $pendingConsentRequests = ConsentRequest::query()
            ->forClient($client->id)
            ->where('site_id', $client->site_id)
            ->forRecipient($user->id)
            ->active()
            ->with(['consentType:id,name,category', 'requestedBy:id,name'])
            ->orderBy('expires_at')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'consent_type' => $r->consentType?->only(['id', 'name', 'category']),
                'requested_by' => $r->requestedBy?->only(['id', 'name']),
                'purpose' => $r->purpose,
                'sent_at' => $r->sent_at?->toIso8601String(),
                'expires_at' => $r->expires_at?->toIso8601String(),
                'action_url' => route('portal.clients.consent-requests.show', [$client->id, $r->id]),
            ]);

        // Visit requests for this family member
        $visitRequests = FamilyVisitRequest::where('user_id', $user->id)
            ->where('client_id', $client->id)
            ->upcoming()
            ->orderBy('requested_date')
            ->limit(10)
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'requested_date' => $v->requested_date?->toDateString(),
                'preferred_time_start' => $v->preferred_time_start,
                'preferred_time_end' => $v->preferred_time_end,
                'visit_type' => $v->visit_type,
                'notes' => $v->notes,
                'status' => $v->status,
                'review_notes' => $v->review_notes,
            ]);

        // Stats
        $stats = [
            'shiftsToday' => $todayShifts->count(),
            'shiftsThisWeek' => $weekShifts->count(),
            'shiftsThisMonth' => $monthShifts->count(),
            'pendingVisitRequests' => FamilyVisitRequest::where('user_id', $user->id)
                ->where('client_id', $client->id)
                ->where('status', 'pending')
                ->count(),
            'pendingConsentRequests' => $pendingConsentRequests->count(),
            'incidentsLast30Days' => $canViewIncidents
                ? ClientIncident::where('client_id', $client->id)
                    ->where('portal_visible', true)
                    ->whereNotNull('reviewed_at')
                    ->where('occurred_at', '>=', now()->subDays(30))
                    ->count()
                : 0,
        ];

        // Next of kin relationship info
        $nokRelation = $user->portalClients()
            ->where('clients.id', $client->id)
            ->first()?->pivot?->relation;

        // Emotion summaries for family portal
        $getTopEmotions = function ($since) use ($client) {
            $notes = ClientNote::query()
                ->where('client_id', $client->id)
                ->where('occurred_at', '>=', $since)
                ->where('visibility', 'portal')
                ->where('is_private', false)
                ->where('is_draft', false)
                ->whereNotNull('behaviour_tags')
                ->get(['behaviour_tags']);
            $counts = [];
            foreach ($notes as $n) {
                foreach ($n->behaviour_tags ?? [] as $e) {
                    $counts[$e] = ($counts[$e] ?? 0) + 1;
                }
            }
            arsort($counts);

            return $counts;
        };

        $emotionSummary = $showCareNotes
            ? [
                'today' => $getTopEmotions($today),
                'week' => $getTopEmotions(now()->startOfWeek()),
                'month' => $getTopEmotions(now()->startOfMonth()),
            ]
            : ['today' => [], 'week' => [], 'month' => []];

        $familyNotesSummary = $showCareNotes
            ? [
                'open' => FamilyNote::where('client_id', $client->id)->open()->count(),
                'overdue' => FamilyNote::where('client_id', $client->id)->overdue()->count(),
                'recent' => FamilyNote::where('client_id', $client->id)
                    ->open()
                    ->orderByDesc('created_at')
                    ->limit(3)
                    ->with(['shift:id,starts_at,shift_type'])
                    ->get(['id', 'title', 'note_type', 'priority', 'due_date', 'status', 'assigned_to_shift_id'])
                    ->map(fn ($n) => [
                        'id' => $n->id,
                        'title' => $n->title,
                        'note_type' => $n->note_type,
                        'priority' => $n->priority,
                        'due_date' => $n->due_date?->toDateString(),
                        'is_overdue' => $n->due_date && $n->due_date->isPast(),
                        'assigned_shift' => $n->shift ? [
                            'starts_at' => $n->shift->starts_at?->toISOString(),
                            'shift_type' => $n->shift->shift_type ?? 'standard',
                        ] : null,
                    ]),
            ]
            : ['open' => 0, 'overdue' => 0, 'recent' => collect()];

        return inertia('portal/family-dashboard', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'preferred_name' => $client->preferred_name,
                'date_of_birth' => $canViewFamilyInformation
                    ? $client->date_of_birth?->toDateString()
                    : null,
                'status' => $client->status,
                'avatar' => $client->avatar,
                'profile_photo_url' => $client->profile_photo_url,
                'phone' => $canViewFamilyInformation ? $client->phone : null,
                'address_line_1' => $canViewFamilyInformation ? $client->address_line_1 : null,
                'city' => $canViewFamilyInformation ? $client->city : null,
                'interests_hobbies' => $canViewFamilyInformation ? $client->interests_hobbies : null,
                'dietary_requirements' => $canViewMedical ? $client->dietary_requirements : null,
                'mobility_needs' => $canViewMedical ? $client->mobility_needs : null,
            ],
            'site' => $client->site ? [
                'id' => $client->site->id,
                'name' => $client->site->name,
                'address' => $client->site->address_line_1,
                'city' => $client->site->city,
            ] : null,
            'keyWorker' => $canViewFamilyInformation && $client->keyWorker ? [
                'id' => $client->keyWorker->id,
                'name' => $client->keyWorker->name,
                'email' => $client->keyWorker->email,
                'avatar' => $client->keyWorker->avatar,
                'presence' => $derivePresence($client->keyWorker),
            ] : null,
            'supportWorkers' => $canViewFamilyInformation
                ? $client->supportWorkers->map(fn ($w) => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'avatar' => $w->avatar,
                    'presence' => $derivePresence($w),
                ])->values()
                : [],
            'currentShiftWorker' => (function () use ($client, $derivePresence, $showShiftSchedule) {
                if (! $showShiftSchedule) {
                    return null;
                }
                $current = Shift::where('client_id', $client->id)
                    ->where('status', 'in_progress')
                    ->with(['staff:id,name,profile_photo_path,last_seen_at,presence_status', 'serviceContext:id,name'])
                    ->first();
                if (! $current?->staff) {
                    return null;
                }

                return [
                    'id' => $current->staff->id,
                    'name' => $current->staff->name,
                    'avatar' => $current->staff->avatar,
                    'presence' => $derivePresence($current->staff),
                    'shift_ends_at' => $current->ends_at?->toISOString(),
                    'shift_type' => $current->shift_type ?? 'standard',
                    'service_context' => $current->serviceContext?->name,
                    'location' => $current->location,
                ];
            })(),
            'nextShiftWorker' => (function () use ($client, $derivePresence, $showShiftSchedule) {
                if (! $showShiftSchedule) {
                    return null;
                }
                $next = Shift::where('client_id', $client->id)
                    ->where('status', 'scheduled')
                    ->where('starts_at', '>', now())
                    ->orderBy('starts_at')
                    ->with(['staff:id,name,profile_photo_path,last_seen_at,presence_status', 'serviceContext:id,name'])
                    ->first();
                if (! $next?->staff) {
                    return null;
                }

                return [
                    'id' => $next->staff->id,
                    'name' => $next->staff->name,
                    'avatar' => $next->staff->avatar,
                    'presence' => $derivePresence($next->staff),
                    'shift_starts_at' => $next->starts_at?->toISOString(),
                    'shift_type' => $next->shift_type ?? 'standard',
                    'service_context' => $next->serviceContext?->name,
                    'location' => $next->location,
                ];
            })(),
            'todayShifts' => $todayShifts->values(),
            'weekShifts' => $weekShifts->values(),
            'monthShifts' => $monthShifts->values(),
            'upcomingRespite' => $upcomingRespite->values(),
            'recentEvents' => $recentEvents->values(),
            'recentIncidents' => $recentIncidents->values(),
            'visitRequests' => $visitRequests->values(),
            'pendingConsentRequests' => $pendingConsentRequests->values(),
            'stats' => $stats,
            'relation' => $nokRelation,
            'medicalSummary' => $canViewMedical && $client->medicalProfile ? [
                'allergies' => $client->medicalProfile->allergies,
                'disabilities' => $client->medicalProfile->disabilities,
                'notes' => $client->medicalProfile->notes,
            ] : null,
            'criticalAlerts' => $criticalAlerts->values(),
            'dailySummary' => [
                'completedToday' => $completedToday,
                'scheduledToday' => $scheduledToday,
                'lastEvent' => $lastEvent ? [
                    'subject' => $lastEvent->subject,
                    'occurred_at' => $lastEvent->occurred_at?->toISOString(),
                ] : null,
            ],
            'carePlan' => $carePlan ? [
                'title' => $carePlan->title,
                'goals_count' => (int) $carePlan->goals_count,
                'goals_completed' => (int) $carePlan->goals_completed,
                'important_to_me' => $carePlan->content['about_me']['important_to_me'] ?? null,
                'ideal_day' => $carePlan->content['about_me']['ideal_day'] ?? null,
                'how_to_support' => $carePlan->content['about_me']['how_to_support'] ?? null,
                'likes' => $carePlan->content['about_me']['likes'] ?? null,
                'dislikes' => $carePlan->content['about_me']['dislikes'] ?? null,
            ] : null,
            'emotionSummary' => $emotionSummary,
            'familyNotesSummary' => $familyNotesSummary,
        ]);
    }

    public function storeVisitRequest(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $validated = $request->validate([
            'requested_date' => 'required|date|after_or_equal:today',
            'preferred_time_start' => 'nullable|date_format:H:i',
            'preferred_time_end' => 'nullable|date_format:H:i|after:preferred_time_start',
            'visit_type' => 'required|in:in_person,video_call,outing',
            'notes' => 'nullable|string|max:1000',
        ]);

        $visit = FamilyVisitRequest::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            ...$validated,
        ]);

        $visitTypeLabel = str_replace('_', ' ', $validated['visit_type']);
        $dateLabel = Carbon::parse($validated['requested_date'])->format('j M');
        app(TimelineEmitter::class)->record([
            'source_type' => FamilyVisitRequest::class,
            'source_id' => $visit->id,
            'occurred_at' => now(),
            'type' => 'visit_requested',
            'actor_user_id' => $user->id,
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'subject' => 'Visit request: '.ucfirst($visitTypeLabel).' on '.$dateLabel,
            'body' => $validated['notes'] ?? null,
            'meta' => array_filter([
                'visit_type' => $validated['visit_type'],
                'requested_date' => $validated['requested_date'],
                'preferred_time_start' => $validated['preferred_time_start'] ?? null,
                'preferred_time_end' => $validated['preferred_time_end'] ?? null,
            ]),
            'visibility' => 'portal',
            'is_pinned' => false,
            'created_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Visit request submitted successfully.');
    }

    public function cancelVisitRequest(Request $request, Client $client, FamilyVisitRequest $visit)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        abort_unless((int) $visit->client_id === (int) $client->id, 404);

        DB::transaction(function () use ($client, $user, $visit): void {
            $lockedVisit = FamilyVisitRequest::query()
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->findOrFail($visit->id);

            abort_unless((int) $lockedVisit->user_id === (int) $user->id, 403);
            abort_unless($lockedVisit->status === 'pending', 422);

            $lockedVisit->update(['status' => 'cancelled']);

            app(TimelineEmitter::class)->record([
                'source_type' => FamilyVisitRequest::class,
                'source_id' => $lockedVisit->id,
                'occurred_at' => now(),
                'type' => 'visit_cancelled',
                'actor_user_id' => $user->id,
                'client_id' => $client->id,
                'site_id' => $client->site_id,
                'subject' => 'Visit request cancelled',
                'body' => null,
                'visibility' => 'portal',
                'is_pinned' => false,
                'created_by' => $user->id,
            ]);
        });

        return redirect()->back()->with('success', 'Visit request cancelled.');
    }
}
