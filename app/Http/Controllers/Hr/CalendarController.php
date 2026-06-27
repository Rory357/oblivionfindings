<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CalendarController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly HrCalendarAggregator $aggregator,
    ) {}

    /**
     * The unified, layered organisation calendar. Events themselves are
     * range-fetched client-side from feed(); index() bootstraps the page chrome:
     * filter options, permissions, the hero's headline stats, and the "Up next"
     * rail. (The Renewals tab consumes the compliance layer of the same feed.)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $sites = Site::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']);

        $departments = HrDepartment::query()
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $teams = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('team')
            ->distinct()
            ->orderBy('team')
            ->pluck('team')
            ->values();

        $icalToken = \App\Domain\Hr\Models\HrICalToken::query()
            ->where('user_id', $user->id)
            ->value('token');

        return Inertia::render('hr/calendar/index', [
            'sites' => $sites,
            'departments' => $departments,
            'teams' => $teams,
            'stats' => $this->heroStats($tenantId, $user),
            'upNext' => $this->upNext($tenantId, $user),
            'ical' => [
                'url' => $icalToken ? url('/hr/ical/'.$icalToken) : null,
            ],
            'can' => [
                'manage' => $this->canManage($user),
                'manageRecurring' => (bool) $user->canDo('calendar.manage_recurring'),
                'seeSensitive' => (bool) $user->canDo('hr.leave.manage'),
            ],
        ]);
    }

    /** Headline stats for the hero band (each click-filters / deep-links). */
    private function heroStats(int $tenantId, $user): array
    {
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();
        $today = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        $eventsThisWeek = HrCalendarEvent::forTenant($tenantId)
            ->inRange($weekStart->toDateString(), $weekEnd->toDateString())
            ->count();

        $onLeaveToday = HrLeaveRequest::where('tenant_id', $tenantId)
            ->where('status', 'approved')
            ->where('starts_at', '<=', $todayEnd)
            ->where('ends_at', '>=', $today)
            ->distinct('user_id')
            ->count('user_id');

        $coverageGapsToday = 0;
        if ($user->canDo('rostering.viewAny')) {
            $coverageGapsToday = collect(
                app(\App\Services\ShiftCoverageService::class)->buildRangeCoverage($today, $todayEnd, null)
            )->filter(fn (array $w) => ! empty($w['has_actionable_gap']))->count();
        }

        $renewalSoon = \App\Domain\Hr\Models\HrStaffComplianceStatus::query()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$today, now()->copy()->addDays(30)])
            ->count();

        return [
            'eventsThisWeek' => $eventsThisWeek,
            'onLeaveToday' => $onLeaveToday,
            'coverageGapsToday' => $coverageGapsToday,
            'renewalsSoon' => $renewalSoon,
        ];
    }

    /** Next ~5 upcoming entries across the default layers, for the hero rail. */
    private function upNext(int $tenantId, $user): array
    {
        $from = now()->toDateString();
        $to = now()->copy()->addDays(30)->toDateString();

        $feed = $this->aggregator->feed(
            $tenantId,
            $from,
            $to,
            ['event', 'leave', 'shift', 'holiday'],
            [],
            $user,
        );

        return collect($feed)
            ->filter(fn ($e) => ! empty($e['start']) && empty($e['extendedProps']['gap']))
            ->sortBy('start')
            ->take(5)
            ->map(fn ($e) => [
                'id' => $e['id'],
                'layer' => $e['layer'],
                'title' => $e['title'],
                'start' => $e['start'],
                'allDay' => $e['allDay'] ?? false,
                'deepLink' => $e['deepLink'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Unified layered feed for the rebuilt /hr/calendar page. Returns one flat
     * list of CalendarLayerFeed rows (see resources/js/lib/calendar/layer-feed.ts)
     * across every requested layer, range-fetched on FullCalendar's datesSet.
     */
    public function feed(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'layers' => ['nullable', 'string'],
            'site' => ['nullable', 'integer'],
            'team' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'integer'],
        ]);

        $allLayers = ['event', 'leave', 'shift', 'holiday', 'compliance', 'milestone'];
        $layers = array_values(array_intersect(
            $allLayers,
            array_filter(explode(',', (string) ($data['layers'] ?? ''))),
        ));
        if ($layers === []) {
            $layers = ['event', 'leave', 'shift', 'holiday'];
        }

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $events = $this->aggregator->feed(
            $tenantId,
            $data['from'],
            $data['to'],
            $layers,
            [
                'site_id' => $data['site'] ?? null,
                'team' => $data['team'] ?? null,
                'department_id' => $data['department'] ?? null,
            ],
            $user,
        );

        return response()->json(['events' => $events]);
    }

    /**
     * Store a new calendar event.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_type' => ['required', 'string', 'in:company,team,training,social,holiday'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'is_all_day' => ['sometimes', 'boolean'],
            'location' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:hr_departments,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        HrCalendarEvent::create([
            'tenant_id' => $tenantId,
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Calendar event created.');
    }

    /**
     * Update an existing calendar event.
     */
    public function update(Request $request, HrCalendarEvent $event)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $event->tenant_id);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_type' => ['sometimes', 'string', 'in:company,team,training,social,holiday'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after_or_equal:starts_at'],
            'is_all_day' => ['sometimes', 'boolean'],
            'location' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:hr_departments,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        $event->update($data);

        return redirect()->back()->with('success', 'Calendar event updated.');
    }

    /**
     * Delete a calendar event.
     */
    public function destroy(Request $request, HrCalendarEvent $event)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $event->tenant_id);

        $event->delete();

        return redirect()->back()->with('success', 'Calendar event deleted.');
    }

    private function canView($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.calendar.view')
            || $user->canDo('hr.calendar.manage')
            || $user->canDo('calendar.view')
            || $user->canDo('calendar.viewAny')
            || $user->canDo('calendar.manage_recurring')
            // Leave viewers land here too (the retired Time-Off page folded into
            // the Leave layer); they see leave/holiday layers, not event editing.
            || $user->canDo('hr.leave.viewAny')
            || $user->canDo('hr.leave.viewOwn')
            || $user->canDo('hr.leave.manage')
        );
    }

    private function canManage($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.calendar.manage')
            || $user->canDo('calendar.create')
            || $user->canDo('calendar.manage_recurring')
        );
    }
}
