<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FamilyVisitRequest;
use App\Models\Shift;
use App\Models\TimelineEvent;
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

        // Load client with key relationships
        $client->load(['keyWorker:id,name,email,profile_photo_path', 'supportWorkers:id,name,email,profile_photo_path', 'site:id,name,address_line_1,city', 'medicalProfile']);

        // Today's shifts
        $todayShifts = Shift::where('client_id', $client->id)
            ->whereBetween('starts_at', [$today, $tomorrow])
            ->orderBy('starts_at')
            ->with('staff:id,name,email,profile_photo_path')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'starts_at' => $s->starts_at?->toISOString(),
                'ends_at' => $s->ends_at?->toISOString(),
                'status' => $s->status,
                'type' => $s->type ?? 'support',
                'staff' => $s->staff ? ['id' => $s->staff->id, 'name' => $s->staff->name, 'avatar' => $s->staff->avatar] : null,
            ]);

        // This week's shifts
        $weekShifts = Shift::where('client_id', $client->id)
            ->whereBetween('starts_at', [$today, $weekEnd])
            ->orderBy('starts_at')
            ->with('staff:id,name,profile_photo_path')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'starts_at' => $s->starts_at?->toISOString(),
                'ends_at' => $s->ends_at?->toISOString(),
                'status' => $s->status,
                'type' => $s->type ?? 'support',
                'staff' => $s->staff ? ['id' => $s->staff->id, 'name' => $s->staff->name, 'avatar' => $s->staff->avatar] : null,
            ]);

        // Monthly calendar events (shifts for the next 30 days)
        $monthShifts = Shift::where('client_id', $client->id)
            ->whereBetween('starts_at', [$today, $monthEnd])
            ->orderBy('starts_at')
            ->with('staff:id,name')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'date' => $s->starts_at?->toDateString(),
                'starts_at' => $s->starts_at?->toISOString(),
                'ends_at' => $s->ends_at?->toISOString(),
                'status' => $s->status,
                'type' => $s->type ?? 'support',
                'staff_name' => $s->staff?->name,
            ]);

        // Recent timeline events (portal-visible)
        $recentEvents = TimelineEvent::where('client_id', $client->id)
            ->where('visibility', 'portal')
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->with(['actor:id,name'])
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'subject' => $e->subject,
                'body' => $e->body,
                'occurred_at' => $e->occurred_at?->toISOString(),
                'actor_name' => $e->actor?->name,
            ]);

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
        $completedToday = Shift::where('client_id', $client->id)
            ->whereBetween('starts_at', [$today, $tomorrow])
            ->where('status', 'completed')
            ->count();
        $scheduledToday = Shift::where('client_id', $client->id)
            ->whereBetween('starts_at', [$today, $tomorrow])
            ->where('status', 'scheduled')
            ->count();
        $lastEvent = TimelineEvent::where('client_id', $client->id)
            ->where('visibility', 'portal')
            ->orderByDesc('occurred_at')
            ->first();

        // Care plan summary
        $carePlan = \App\Models\CarePlan::where('client_id', $client->id)
            ->where('status', 'active')
            ->withCount(['goals', 'goals as goals_completed' => fn ($q) => $q->where('status', 'completed')])
            ->first();

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
            ] : null,
            'supportWorkers' => $client->supportWorkers->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'avatar' => $w->avatar,
            ])->values(),
            'todayShifts' => $todayShifts->values(),
            'weekShifts' => $weekShifts->values(),
            'monthShifts' => $monthShifts->values(),
            'recentEvents' => $recentEvents->values(),
            'recentIncidents' => $recentIncidents->values(),
            'visitRequests' => $visitRequests->values(),
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

        return redirect()->back()->with('success', 'Visit request submitted successfully.');
    }

    public function cancelVisitRequest(Request $request, Client $client, FamilyVisitRequest $visit)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($visit->user_id === $user->id, 403);
        abort_unless($visit->status === 'pending', 422);

        $visit->update(['status' => 'cancelled']);

        return redirect()->back()->with('success', 'Visit request cancelled.');
    }
}
