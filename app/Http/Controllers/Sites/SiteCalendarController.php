<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Sites\Concerns\ResolvesAllowedSiteTypes;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Services\Sites\SiteCalendarService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SiteCalendarController extends Controller
{
    use ResolvesAllowedSiteTypes;

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
            'canCreate' => ($request->user()?->canDo('calendar.create') ?? false)
                && ($request->user()?->can('update', $site) ?? false),
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
            'tenant_id' => $site->tenant_id,
            'created_by_user_id' => $request->user()->id,
            'status' => 'draft',
            'approval_status' => $this->requiresApproval($validated['event_type']) ? 'pending' : 'not_required',
        ]);

        return redirect()->back()->with('success', 'Event created.');
    }

    public function update(Request $request, Site $site, SiteCalendarEvent $event)
    {
        $this->authorize('update', $site);
        abort_unless($event->site_id === $site->id, 404);

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
        abort_unless($event->site_id === $site->id, 404);

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
        abort_unless($request->user()?->canDo('calendar.view'), 403);

        $start = Carbon::parse($request->input('start', now()->startOfMonth()));
        $end = Carbon::parse($request->input('end', now()->endOfMonth()));

        $siteIds = $request->input('site_ids');
        $eventTypes = $request->input('event_types');
        if (!is_array($siteIds) && $siteIds !== null) {
            $siteIds = [$siteIds];
        }
        if (!is_array($eventTypes) && $eventTypes !== null) {
            $eventTypes = [$eventTypes];
        }
        $siteType = $request->input('site_type');
        $status = $request->input('status');
        $allowedSiteTypes = $this->allowedSiteTypes($request);

        if ($siteType && !in_array($siteType, $allowedSiteTypes, true)) {
            abort(403);
        }

        $siteQuery = Site::active()
            ->select(['id', 'name', 'type'])
            ->whereIn('type', $allowedSiteTypes);

        if ($siteType) {
            $siteQuery->where('type', $siteType);
        }

        if (is_array($siteIds) && count($siteIds) > 0) {
            $siteQuery->whereIn('id', $siteIds);
        }

        $sites = $siteQuery->orderBy('name')->get();

        $effectiveSiteIds = $sites->pluck('id')->all();

        $events = $this->calendarService->getEventsForRange(
            $effectiveSiteIds,
            $eventTypes,
            $start,
            $end,
            $request->boolean('my_events_only') ? $request->user()->id : null
        );

        $eventTypesConfig = function_exists('settings')
            ? settings('sites.default_event_types', [])
            : [];

        $eventTypeOptions = collect($eventTypesConfig)->map(fn ($type) => [
            'key' => $type['key'] ?? null,
            'label' => $type['label'] ?? ($type['key'] ?? 'Other'),
            'color' => $type['color'] ?? '#64748b',
        ])->filter(fn ($type) => !empty($type['key']))->values();

        if ($eventTypeOptions->isEmpty()) {
            $eventTypeOptions = collect([
                ['key' => 'event', 'label' => 'Events', 'color' => '#6366f1'],
                ['key' => 'maintenance', 'label' => 'Maintenance Schedule', 'color' => '#f59e0b'],
                ['key' => 'site_visit', 'label' => 'Site Visit', 'color' => '#10b981'],
                ['key' => 'inspection', 'label' => 'Inspection', 'color' => '#ef4444'],
            ]);
        }

        $formattedEvents = collect($events)
            ->map(function (array $event) {
                $site = $event['site'] ?? [];
                $owner = $event['owner'] ?? [];

                return [
                    'id' => $event['id'],
                    'site_id' => $site['id'] ?? null,
                    'site_name' => $site['name'] ?? 'Unknown Site',
                    'site_type' => $site['type'] ?? null,
                    'event_type' => $event['event_type'] ?? 'event',
                    'title' => $event['title'] ?? 'Untitled',
                    'start_at' => $event['start_at'] ?? null,
                    'end_at' => $event['end_at'] ?? null,
                    'status' => $event['status'] ?? 'draft',
                    'approval_status' => $event['approval_status'] ?? 'not_required',
                    'owner_name' => $owner['name'] ?? null,
                ];
            })
            ->when($status, fn ($collection) => $collection->where('status', $status))
            ->values()
            ->all();

        return inertia('calendar/global', [
            'events' => $formattedEvents,
            'sites' => $sites,
            'eventTypes' => $eventTypeOptions,
            'filters' => [
                'site_ids' => is_array($siteIds) ? $siteIds : [],
                'site_type' => $siteType,
                'event_types' => $eventTypes ?? [],
                'status' => $status,
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
