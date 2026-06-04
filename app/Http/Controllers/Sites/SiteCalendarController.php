<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Sites\Concerns\ResolvesAllowedSiteTypes;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\User;
use App\Services\Sites\Calendar\CalendarSources;
use App\Services\Sites\Calendar\IcsFeedBuilder;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use App\Services\Sites\SiteCalendarService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SiteCalendarController extends Controller
{
    use ResolvesAllowedSiteTypes;

    public function __construct(
        private SiteCalendarService $calendarService,
        private SiteCalendarAggregator $aggregator,
        private IcsFeedBuilder $icsFeedBuilder,
    ) {}

    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        return inertia('sites/calendar/index', [
            'context' => 'page',
            'scope' => 'site',
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
            ],
            'eventTypes' => $this->eventTypeOptions(),
            'sources' => CalendarSources::all(),
            'canCreate' => ($request->user()?->canDo('calendar.create') ?? false)
                && ($request->user()?->can('update', $site) ?? false),
            'canManage' => ($request->user()?->canDo('calendar.manage') ?? false)
                && ($request->user()?->can('update', $site) ?? false),
            'canApprove' => $request->user()?->canDo('calendar.approve') ?? false,
            'feedUrl' => $this->feedUrlFor($request->user()),
        ]);
    }

    /**
     * Unified JSON feed for a single site (manual events + obligations).
     */
    public function events(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        [$start, $end] = $this->range($request);

        return response()->json([
            'events' => $this->aggregator->arrayForRange([$site->id], $start, $end, $this->filters($request)),
        ]);
    }

    /**
     * Unified JSON feed across every site the user can see (with filters).
     */
    public function globalEvents(Request $request)
    {
        $user = $request->user();
        abort_unless($user?->canDo('calendar.view'), 403);

        [$start, $end] = $this->range($request);
        $siteIds = $this->resolveGlobalSites($request)->pluck('id')->all();

        return response()->json([
            'events' => $this->aggregator->arrayForRange($siteIds, $start, $end, $this->filters($request)),
        ]);
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

        // The datetime-local inputs are business-timezone wall-clock; persist UTC.
        $validated['start_at'] = $this->toUtcFromWorker($validated['start_at']);
        $validated['end_at'] = $this->toUtcFromWorker($validated['end_at'] ?? null);

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

        // Partial updates supported so drag-to-move / resize can send only times.
        $validated = $request->validate([
            'event_type' => 'sometimes|required|string|max:50',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'start_at' => 'sometimes|required|date',
            'end_at' => 'sometimes|nullable|date|after:start_at',
            'owner_user_id' => 'sometimes|nullable|exists:users,id',
            'recurrence_rule' => 'sometimes|nullable|string',
            'reminder_minutes' => 'sometimes|nullable|array',
            'status' => 'sometimes|required|in:draft,pending,approved,completed,cancelled',
        ]);

        // Times arrive as business-timezone wall-clock; store UTC. Only convert the
        // keys actually present — drag/resize updates send just start/end.
        if (array_key_exists('start_at', $validated)) {
            $validated['start_at'] = $this->toUtcFromWorker($validated['start_at']);
        }
        if (array_key_exists('end_at', $validated)) {
            $validated['end_at'] = $this->toUtcFromWorker($validated['end_at']);
        }

        $event->update($validated);

        return redirect()->back()->with('success', 'Event updated.');
    }

    public function approve(Request $request, Site $site, SiteCalendarEvent $event)
    {
        $this->authorize('update', $site);
        abort_unless($event->site_id === $site->id, 404);

        $event->update([
            'approval_status' => 'approved',
            'status' => 'approved',
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Event approved.');
    }

    public function reject(Request $request, Site $site, SiteCalendarEvent $event)
    {
        $this->authorize('update', $site);
        abort_unless($event->site_id === $site->id, 404);

        $validated = $request->validate([
            'approval_notes' => 'nullable|string|max:1000',
        ]);

        $event->update([
            'approval_status' => 'rejected',
            'status' => 'cancelled',
            'approval_notes' => $validated['approval_notes'] ?? null,
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Event rejected.');
    }

    /**
     * Public per-user subscribe feed (token-authenticated, no session). Returns a
     * VCALENDAR of the user's accessible sites' events + obligations for a window.
     */
    public function feed(Request $request, string $token)
    {
        $user = User::query()->where('calendar_feed_token', $token)->first();
        abort_unless($user, 404);

        $start = now()->subMonth();
        $end = now()->addMonths(3);

        $siteIds = $this->siteAccess()->accessibleSiteIds($user);
        if ($siteIds === []) {
            $siteIds = Site::active()->pluck('id')->all();
        }

        $items = $this->aggregator->itemsForRange($siteIds, $start, $end);

        return response($this->icsFeedBuilder->build($items, 'Oblivion Findings — Site Calendar'), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="site-calendar.ics"',
        ]);
    }

    public function resetFeed(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $user->calendar_feed_token = Str::random(48);
        $user->save();

        return redirect()->back()->with('success', 'Calendar subscribe link reset.');
    }

    private function feedUrlFor(?User $user): ?string
    {
        $token = $user?->calendar_feed_token;

        return $token ? url("/calendar/feed/{$token}.ics") : null;
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
        $user = $request->user();
        abort_unless($user?->canDo('calendar.view'), 403);

        $sites = $this->resolveGlobalSites($request);

        $siteIds = $request->input('site_ids');
        if (! is_array($siteIds) && $siteIds !== null) {
            $siteIds = [$siteIds];
        }

        return inertia('calendar/global', [
            'context' => 'page',
            'scope' => 'global',
            'sites' => $sites,
            'eventTypes' => $this->eventTypeOptions(),
            'sources' => CalendarSources::all(),
            'canCreate' => $user->canDo('calendar.create'),
            'canManage' => $user->canDo('calendar.manage'),
            'canApprove' => $user->canDo('calendar.approve'),
            'feedUrl' => $this->feedUrlFor($user),
            'filters' => [
                'site_ids' => is_array($siteIds) ? $siteIds : [],
                'site_type' => $request->input('site_type'),
                'event_types' => (array) ($request->input('event_types') ?? []),
                'sources' => (array) ($request->input('sources') ?? []),
                'status' => $request->input('status'),
                'my_events_only' => $request->boolean('my_events_only'),
            ],
        ]);
    }

    /**
     * Resolve the sites visible on the global calendar, honouring the user's
     * site-access scope, allowed site types and request filters.
     */
    private function resolveGlobalSites(Request $request): Collection
    {
        $user = $request->user();

        $siteIds = $request->input('site_ids');
        if (! is_array($siteIds) && $siteIds !== null) {
            $siteIds = [$siteIds];
        }

        $siteType = $request->input('site_type');
        $allowedSiteTypes = $this->allowedSiteTypes($request);

        if ($siteType && ! in_array($siteType, $allowedSiteTypes, true)) {
            abort(403);
        }

        $accessibleSiteIds = $this->siteAccess()->accessibleSiteIds($user);

        $query = Site::active()
            ->select(['id', 'name', 'type'])
            ->whereIn('type', $allowedSiteTypes);

        if ($accessibleSiteIds !== []) {
            $query->whereIn('id', $accessibleSiteIds);
        }

        if ($siteType) {
            $query->where('type', $siteType);
        }

        if (is_array($siteIds) && count($siteIds) > 0) {
            $query->whereIn('id', $siteIds);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        return [
            Carbon::parse($request->input('start', now()->startOfMonth())),
            Carbon::parse($request->input('end', now()->endOfMonth())),
        ];
    }

    /**
     * Aggregator filters from the request (source layers, manual event types,
     * personal "my events only").
     *
     * @return array{sources: ?array, event_types: ?array, user_id: ?int}
     */
    private function filters(Request $request): array
    {
        $sources = $request->input('sources');
        $eventTypes = $request->input('event_types');

        return [
            'sources' => is_array($sources) ? $sources : ($sources !== null ? [$sources] : null),
            'event_types' => is_array($eventTypes) ? $eventTypes : ($eventTypes !== null ? [$eventTypes] : null),
            'user_id' => $request->boolean('my_events_only') ? $request->user()?->id : null,
        ];
    }

    /**
     * Event-type options (tile-picker + manual-event colours), from settings
     * with a sensible default.
     */
    private function eventTypeOptions(): array
    {
        $configured = function_exists('settings') ? settings('sites.default_event_types', []) : [];

        $options = collect($configured)->map(fn ($type) => [
            'key' => $type['key'] ?? null,
            'label' => $type['label'] ?? ($type['key'] ?? 'Other'),
            'color' => $type['color'] ?? '#64748b',
            'icon' => $type['icon'] ?? null,
            'requires_approval' => (bool) ($type['requires_approval'] ?? false),
            'site_types' => $type['site_types'] ?? null,
        ])->filter(fn ($type) => ! empty($type['key']))->values();

        if ($options->isEmpty()) {
            $options = collect([
                ['key' => 'general', 'label' => 'General Event', 'color' => '#6366f1', 'icon' => 'calendar', 'requires_approval' => false, 'site_types' => null],
                ['key' => 'maintenance', 'label' => 'Maintenance Schedule', 'color' => '#f59e0b', 'icon' => 'wrench', 'requires_approval' => true, 'site_types' => null],
                ['key' => 'site_visit', 'label' => 'Site Visit', 'color' => '#10b981', 'icon' => 'map-pin', 'requires_approval' => false, 'site_types' => null],
                ['key' => 'inspection', 'label' => 'Inspection', 'color' => '#8b5cf6', 'icon' => 'clipboard-check', 'requires_approval' => true, 'site_types' => null],
            ]);
        }

        return $options->all();
    }

    private function requiresApproval(string $eventType): bool
    {
        $eventTypes = function_exists('settings')
            ? settings('sites.default_event_types', [])
            : [];
        $type = collect($eventTypes)->firstWhere('key', $eventType);

        return $type['requires_approval'] ?? false;
    }

    private function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }

    /**
     * Business timezone calendar times are authored and displayed in (NZ).
     * Storage stays UTC — we convert at this read/write boundary.
     */
    private function workerTimezone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }

    /**
     * Interpret a `datetime-local` wall-clock string (no offset) as business-
     * timezone local time and return the equivalent UTC instant. Null/empty
     * passes through so nullable end times stay null.
     */
    private function toUtcFromWorker(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, $this->workerTimezone())->utc();
    }
}
