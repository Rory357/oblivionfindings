<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ConsentRequest;
use App\Models\FamilyPortalSetting;
use App\Models\FamilyVisitRequest;
use App\Models\ProgressNote;
use App\Models\RespiteBooking;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Services\ShiftTimelineService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FamilyDashboardController extends Controller
{
    public function show(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);

        // Ensure the user has portal access to this client
        abort_unless($user->canAccessClientPortal($client), 403);

        $today = now()->startOfDay();
        $tomorrow = (clone $today)->addDay();
        $weekEnd = (clone $today)->addDays(7);
        $monthEnd = (clone $today)->addDays(30);
        $portalSettings = FamilyPortalSetting::query()->where('client_id', $client->id)->first();
        $showShiftSchedule = $portalSettings?->show_shift_schedule ?? true;
        $showRespite = $portalSettings?->show_respite ?? true;

        // Load client with key relationships
        $client->load(['keyWorker:id,name,email,profile_photo_path,last_seen_at,presence_status', 'supportWorkers:id,name,email,profile_photo_path,last_seen_at,presence_status', 'site:id,name,address_line_1,city', 'medicalProfile']);

        // Helper to derive presence
        $derivePresence = function ($user) {
            if (!$user || !$user->last_seen_at) return 'offline';
            if ($user->presence_status === 'online' && $user->last_seen_at->gt(now()->subMinutes(5))) return 'online';
            if ($user->last_seen_at->gt(now()->subMinutes(15))) return 'away';
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
        $recentEvents = TimelineEvent::where('client_id', $client->id)
            ->where('visibility', 'portal')
            ->when(! $showShiftSchedule, fn ($query) => $query->whereNotIn('type', ShiftTimelineService::shiftEventTypes()))
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->with(['actor:id,name', 'reactions'])
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
        $recentIncidents = ClientIncident::where('client_id', $client->id)
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
            ]);

        // Critical alerts (high/critical only, last 14 days, max 3)
        $criticalAlerts = ClientIncident::where('client_id', $client->id)
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
            ]);

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
        $lastEvent = TimelineEvent::where('client_id', $client->id)
            ->where('visibility', 'portal')
            ->when(! $showShiftSchedule, fn ($query) => $query->whereNotIn('type', ShiftTimelineService::shiftEventTypes()))
            ->orderByDesc('occurred_at')
            ->first();

        // Care plan summary
        $carePlan = \App\Models\CarePlan::where('client_id', $client->id)
            ->where('status', 'active')
            ->withCount(['goals', 'goals as goals_completed' => fn ($q) => $q->where('status', 'completed')])
            ->first();

        // Pending consent requests addressed to this portal user
        $pendingConsentRequests = ConsentRequest::query()
            ->forClient($client->id)
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
            'incidentsLast30Days' => ClientIncident::where('client_id', $client->id)
                ->where('portal_visible', true)
                ->whereNotNull('reviewed_at')
                ->where('occurred_at', '>=', now()->subDays(30))
                ->count(),
        ];

        // Next of kin relationship info
        $nokRelation = $user->portalClients()
            ->where('clients.id', $client->id)
            ->first()?->pivot?->relation;

        // Emotion summaries for family portal
        $getTopEmotions = function ($since) use ($client) {
            $notes = ProgressNote::where('client_id', $client->id)
                ->where('created_at', '>=', $since)
                ->whereNotNull('emotions')
                ->where('visibility', '!=', 'private')
                ->get(['emotions']);
            $counts = [];
            foreach ($notes as $n) {
                foreach ($n->emotions ?? [] as $e) {
                    $counts[$e] = ($counts[$e] ?? 0) + 1;
                }
            }
            arsort($counts);
            return $counts;
        };

        $emotionSummary = [
            'today' => $getTopEmotions($today),
            'week' => $getTopEmotions(now()->startOfWeek()),
            'month' => $getTopEmotions(now()->startOfMonth()),
        ];

        return inertia('portal/family-dashboard', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'preferred_name' => $client->preferred_name,
                'date_of_birth' => $client->date_of_birth?->toDateString(),
                'status' => $client->status,
                'avatar' => $client->avatar,
                'profile_photo_url' => $client->profile_photo_url,
                'phone' => $client->phone,
                'address_line_1' => $client->address_line_1,
                'city' => $client->city,
                'interests_hobbies' => $client->interests_hobbies,
                'dietary_requirements' => $client->dietary_requirements,
                'mobility_needs' => $client->mobility_needs,
            ],
            'site' => $client->site ? [
                'id' => $client->site->id,
                'name' => $client->site->name,
                'address' => $client->site->address_line_1,
                'city' => $client->site->city,
            ] : null,
            'keyWorker' => $client->keyWorker ? [
                'id' => $client->keyWorker->id,
                'name' => $client->keyWorker->name,
                'email' => $client->keyWorker->email,
                'avatar' => $client->keyWorker->avatar,
                'presence' => $derivePresence($client->keyWorker),
            ] : null,
            'supportWorkers' => $client->supportWorkers->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'avatar' => $w->avatar,
                'presence' => $derivePresence($w),
            ])->values(),
            'currentShiftWorker' => (function () use ($client, $derivePresence, $showShiftSchedule) {
                if (! $showShiftSchedule) return null;
                $current = Shift::where('client_id', $client->id)
                    ->where('status', 'in_progress')
                    ->with(['staff:id,name,profile_photo_path,last_seen_at,presence_status', 'serviceContext:id,name'])
                    ->first();
                if (!$current?->staff) return null;
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
                if (! $showShiftSchedule) return null;
                $next = Shift::where('client_id', $client->id)
                    ->where('status', 'scheduled')
                    ->where('starts_at', '>', now())
                    ->orderBy('starts_at')
                    ->with(['staff:id,name,profile_photo_path,last_seen_at,presence_status', 'serviceContext:id,name'])
                    ->first();
                if (!$next?->staff) return null;
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
            'medicalSummary' => $client->medicalProfile ? [
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
            'familyNotesSummary' => [
                'open' => \App\Models\FamilyNote::where('client_id', $client->id)->open()->count(),
                'overdue' => \App\Models\FamilyNote::where('client_id', $client->id)->overdue()->count(),
                'recent' => \App\Models\FamilyNote::where('client_id', $client->id)
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
            ],
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
        $dateLabel = \Carbon\Carbon::parse($validated['requested_date'])->format('j M');
        app(\App\Services\Timeline\TimelineEmitter::class)->record([
            'source_type' => FamilyVisitRequest::class,
            'source_id' => $visit->id,
            'occurred_at' => now(),
            'type' => 'visit_requested',
            'actor_user_id' => $user->id,
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'subject' => 'Visit request: ' . ucfirst($visitTypeLabel) . ' on ' . $dateLabel,
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
        abort_unless($visit->user_id === $user->id, 403);
        abort_unless($visit->status === 'pending', 422);

        $visit->update(['status' => 'cancelled']);

        app(\App\Services\Timeline\TimelineEmitter::class)->record([
            'source_type' => FamilyVisitRequest::class,
            'source_id' => $visit->id,
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

        return redirect()->back()->with('success', 'Visit request cancelled.');
    }
}
