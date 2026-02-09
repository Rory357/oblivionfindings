<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Services\Sites\SiteCalendarService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SiteCalendarController extends Controller
{
    public function __construct(
        private SiteCalendarService $calendarService
    ) {}

    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        return inertia('sites/calendar/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
            ],
        ]);
    }

    public function events(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $start = Carbon::parse($request->input('start', now()->startOfMonth()));
        $end = Carbon::parse($request->input('end', now()->endOfMonth()));

        $events = $this->calendarService->getEventsForRange(
            [$site->id],
            $request->input('event_types'),
            $start,
            $end,
            $request->boolean('my_events_only') ? $request->user()->id : null
        );

        return response()->json(['events' => $events]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'event_type' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_at' => 'required|date',
            'end_at' => 'nullable|date|after:start_at',
            'recurrence_rule' => 'nullable|string',
            'owner_user_id' => 'nullable|exists:users,id',
            'attendee_user_ids' => 'nullable|array',
            'attendee_user_ids.*' => 'exists:users,id',
            'reminder_minutes' => 'nullable|array',
        ]);

        $event = SiteCalendarEvent::create([
            ...$validated,
            'site_id' => $site->id,
            'created_by_user_id' => $request->user()->id,
            'status' => 'draft',
            'approval_status' => $this->requiresApproval($validated['event_type']) ? 'pending' : 'not_required',
        ]);

        return redirect()->back()->with('success', 'Event created.');
    }

    public function update(Request $request, Site $site, SiteCalendarEvent $event)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'event_type' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_at' => 'required|date',
            'end_at' => 'nullable|date|after:start_at',
            'owner_user_id' => 'nullable|exists:users,id',
            'status' => 'required|in:draft,pending,approved,completed,cancelled',
        ]);

        $event->update($validated);

        return redirect()->back()->with('success', 'Event updated.');
    }

    public function destroy(Request $request, Site $site, SiteCalendarEvent $event)
    {
        $this->authorize('update', $site);

        $event->delete();

        return redirect()->back()->with('success', 'Event deleted.');
    }

    public function createException(Request $request, SiteCalendarEvent $event)
    {
        $this->authorize('update', $event->site);

        $validated = $request->validate([
            'exception_date' => 'required|date',
            'is_cancelled' => 'required|boolean',
            'overridden_fields' => 'nullable|array',
        ]);

        $this->calendarService->createException(
            $event->id,
            $validated['exception_date'],
            $validated['overridden_fields'] ?? null,
            $validated['is_cancelled']
        );

        return redirect()->back()->with('success', 'Exception created.');
    }

    public function global(Request $request)
    {
        $this->authorize('calendar.view');

        $start = Carbon::parse($request->input('start', now()->startOfMonth()));
        $end = Carbon::parse($request->input('end', now()->endOfMonth()));

        $siteIds = $request->input('site_ids');
        $eventTypes = $request->input('event_types');

        $events = $this->calendarService->getEventsForRange(
            $siteIds,
            $eventTypes,
            $start,
            $end,
            $request->boolean('my_events_only') ? $request->user()->id : null
        );

        $sites = Site::active()
            ->select(['id', 'name', 'type'])
            ->orderBy('name')
            ->get();

        return inertia('calendar/global', [
            'events' => $events,
            'sites' => $sites,
            'filters' => [
                'site_ids' => $siteIds ?? [],
                'event_types' => $eventTypes ?? [],
                'my_events_only' => $request->boolean('my_events_only'),
            ],
        ]);
    }

    private function requiresApproval(string $eventType): bool
    {
        $eventTypes = function_exists('settings') 
            ? settings('sites.default_event_types', [])
            : [];
        $type = collect($eventTypes)->firstWhere('key', $eventType);
        return $type['requires_approval'] ?? false;
    }
}
