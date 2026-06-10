<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionService;
use App\Domain\Rostering\RosteringFeatureFlags;
use App\Domain\Rostering\RosterPeriodService;
use App\Domain\Rostering\RosterPublishingService;
use App\Http\Requests\Operations\Rostering\AutoScheduleRosterRequest;
use App\Http\Requests\Operations\Rostering\RosteringConflictsRequest;
use App\Http\Requests\Operations\Rostering\RosteringIndexRequest;
use App\Models\Client;
use App\Models\RosterPeriod;
use App\Models\RosterSuggestionRun;
use App\Models\RosterTemplate;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftEligibilityOverride;
use App\Models\ShiftSeries;
use App\Models\Site;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Services\Operations\ShiftSeriesPresenter;
use App\Services\ShiftCoverageService;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class RosteringController extends Controller
{
    public function __construct(
        protected ShiftCoverageService $shiftCoverageService,
        protected RosterPeriodService $rosterPeriods,
        protected RosterPublishingService $publishing,
        protected RosterSuggestionService $suggestions,
        protected RosteringFeatureFlags $featureFlags,
    ) {}

    public function index(RosteringIndexRequest $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.viewAny'), 403);

        $canManageAny = $auth->canDo('shifts.manageAny');
        $canApproveLeave = $auth->canDo('hr.leave.approve') || $auth->canDo('hr.leave.manage');
        $organizationId = $auth->organization_id;

        // Roster Templates live as a tab in this workspace. View access piggybacks
        // on the rostering.viewAny gate this method already enforces; create/update
        // and delete keep their own permissions (with a rostering.* fallback so a
        // scheduler who manages shifts can also manage the patterns).
        $canManageTemplates = $auth->canDo('roster_templates.create')
            || $auth->canDo('roster_templates.update')
            || $auth->canDo('rostering.create')
            || $auth->canDo('rostering.edit');
        $canDeleteTemplates = $auth->canDo('roster_templates.delete')
            || $auth->canDo('rostering.delete');

        // /availability redirects here with ?tab=availability. On that landing the
        // availability pane is the only tab body rendered, so heavy manager-only
        // blocks that pane never consumes can be skipped. We only skip work whose
        // output no always-visible chrome reads: the open-shift eligibility map and
        // the 4-week historical trend. eligibilityAlerts is intentionally NOT skipped
        // — its counts feed the always-visible "Open shifts" header donut, the hero
        // "blocked candidates" badge and the signal rail.
        $isAvailabilityTab = $request->query('tab') === 'availability';

        $data = $request->validated();

        $week = ! empty($data['week'])
            ? Carbon::parse($data['week'])
            : now();

        // NZ: week starts on Monday.
        $weekStart = (clone $week)->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weekEnd = (clone $weekStart)->addDays(7);

        $staff = [];
        $clients = [];
        $sites = [];
        $serviceContexts = [];

        // The template wizard (Templates tab) needs the client/staff/service-context
        // pickers too, and it is gated on the broader $canManageTemplates. Load the
        // datasets for either gate so a roster_templates.create user who is not a
        // shifts.manageAny manager doesn't open the wizard to empty dropdowns.
        if ($canManageAny || $canManageTemplates) {
            // Org-scope the filter dropdowns so managers only see their own
            // organization's staff/clients. Sites are not organization-scoped
            // in the schema (the table carries tenant_id, not organization_id),
            // so the site list is left unscoped here.
            $staff = User::staff()
                ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
                ->orderBy('name')
                ->get(['id', 'name', 'email']);
            $clients = Client::query()
                ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
                ->with('site:id,name')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'service_context_id', 'site_id']);
            $sites = Site::query()->orderBy('name')->get(['id', 'name', 'type']);
            $serviceContexts = ServiceContext::query()
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'is_active']);
        }

        $query = Shift::query()
            ->with([
                'client:id,first_name,last_name',
                'site:id,name,type',
                'staff:id,name,email',
                'serviceContext:id,name,type,is_active',
                'series:id,client_id,service_context_id,user_id,start_date,end_date,by_weekday,starts_time,ends_time,location,status,shift_type,is_sleepover,is_on_call',
                'series.client:id,first_name,last_name',
                'series.staff:id,name',
                'series.serviceContext:id,name,type',
                'replacementRequests' => fn ($q) => $q->active()
                    ->with([
                        'requester:id,name',
                        'currentStaff:id,name',
                        'replacementStaff:id,name',
                        'openPosition:id,replacement_request_id,status,claimed_by,approved_by,expires_at',
                        'openPosition.claimer:id,name',
                    ]),
                'timesheets' => fn ($q) => $q->orderByDesc('id')->limit(1),
            ])
            ->withCount([
                'incidents as incidents_count',
                'tasks as tasks_total',
                'tasks as tasks_completed' => fn ($q) => $q->where('is_completed', true),
            ])
            // overlap window
            ->where('starts_at', '<', $weekEnd)
            ->where('ends_at', '>', $weekStart)
            ->orderBy('starts_at');

        // Normalise the site_id filter once — may be null, a single int, or an int[].
        $siteFilter = $request->siteFilter();

        if (! $canManageAny) {
            $query->where('user_id', $auth->id);
        } else {
            if (! empty($data['staff_id'])) {
                $query->where('user_id', $data['staff_id']);
            }
            if (! empty($data['client_id'])) {
                $query->where('client_id', $data['client_id']);
            }
            if ($siteFilter !== null) {
                if (is_array($siteFilter)) {
                    $query->whereIn('site_id', $siteFilter);
                } else {
                    $query->where('site_id', $siteFilter);
                }
            }
        }

        $shifts = $query->get();

        // Time-off / one-off unavailability blocks
        $timeOffQuery = StaffTimeOff::query()
            ->with(['user:id,name'])
            ->where('starts_at', '<', $weekEnd)
            ->where('ends_at', '>', $weekStart)
            ->orderBy('starts_at');

        if (! $canManageAny) {
            $timeOffQuery->where('user_id', $auth->id);
        } else {
            if (! empty($data['staff_id'])) {
                $timeOffQuery->where('user_id', $data['staff_id']);
            }
        }

        $timeOffs = $timeOffQuery->get();

        // Conflict detection (UI-only warnings): actionable overlaps only.
        // Completed shifts are immutable (locked) and cancelled shifts are non-actionable.
        $actionableShifts = $shifts->filter(fn ($s) => ! in_array($s->status, ['completed', 'cancelled'], true))->values();

        // Overlaps per staff and per client.
        $staffOverlapCount = 0;
        $clientOverlapCount = 0;

        if ($canManageAny) {
            $staffGroups = $actionableShifts
                ->filter(fn ($s) => ! empty($s->user_id))
                ->groupBy('user_id');

            foreach ($staffGroups as $group) {
                $sorted = $group->sortBy('starts_at')->values();
                for ($i = 1; $i < $sorted->count(); $i++) {
                    $prev = $sorted[$i - 1];
                    $cur = $sorted[$i];
                    if ($prev->ends_at && $cur->starts_at && $prev->ends_at->gt($cur->starts_at)) {
                        $staffOverlapCount++;
                    }
                }
            }

            $clientGroups = $actionableShifts->groupBy('client_id');
            foreach ($clientGroups as $group) {
                $sorted = $group->sortBy('starts_at')->values();
                for ($i = 1; $i < $sorted->count(); $i++) {
                    $prev = $sorted[$i - 1];
                    $cur = $sorted[$i];
                    if ($prev->ends_at && $cur->starts_at && $prev->ends_at->gt($cur->starts_at)) {
                        $clientOverlapCount++;
                    }
                }
            }
        }

        // Time-off conflicts: where a shift overlaps a staff time-off block
        $timeOffConflicts = 0;
        if ($canManageAny) {
            $byUser = $timeOffs->groupBy('user_id');
            foreach ($actionableShifts->filter(fn ($s) => ! empty($s->user_id)) as $s) {
                $blocks = $byUser->get($s->user_id);
                if (! $blocks) {
                    continue;
                }
                foreach ($blocks as $b) {
                    if ($b->starts_at < $s->ends_at && $b->ends_at > $s->starts_at) {
                        $timeOffConflicts++;
                        break;
                    }
                }
            }
        }

        $stats = [
            'total' => $shifts->count(),
            'open' => $shifts->whereNull('user_id')->count(),
            'draft' => $shifts->where('status', 'draft')->count(),
            'scheduled' => $shifts->where('status', 'scheduled')->count(),
            'in_progress' => $shifts->where('status', 'in_progress')->count(),
            'completed' => $shifts->where('status', 'completed')->count(),
            'cancelled' => $shifts->where('status', 'cancelled')->count(),
            'incidents' => (int) $shifts->sum('incidents_count'),
            'staff_overlaps' => $staffOverlapCount,
            'client_overlaps' => $clientOverlapCount,
            'timesheets_pending' => (int) $shifts->filter(function ($s) {
                $ts = $s->timesheets->first();
                if (! $ts) {
                    return false;
                }

                return in_array($ts->status, ['draft', 'submitted', 'returned'], true);
            })->count(),
            'time_off_conflicts' => $timeOffConflicts,
        ];

        // Capacity (hours per staff for the week)
        $capacity = [];
        if ($canManageAny) {
            $staffForCapacity = $staff;
            if (! empty($data['staff_id'])) {
                $staffForCapacity = $staffForCapacity->where('id', (int) $data['staff_id']);
            }

            $grouped = $shifts->filter(fn ($s) => ! empty($s->user_id) && $s->status !== 'cancelled')
                ->groupBy('user_id');

            foreach ($staffForCapacity as $u) {
                $hrs = 0.0;
                foreach (($grouped->get($u->id) ?? collect()) as $s) {
                    $start = $s->starts_at->copy()->max($weekStart);
                    $end = $s->ends_at->copy()->min($weekEnd);
                    $mins = max(0, $end->diffInMinutes($start));
                    $hrs += $mins / 60.0;
                }
                $capacity[] = [
                    'user_id' => $u->id,
                    'name' => $u->name,
                    'hours' => round($hrs, 2),
                    'warn' => $hrs >= 50 ? 'high' : ($hrs >= 40 ? 'medium' : null),
                ];
            }
        }

        // --- Analytics Data ---

        // Daily shift coverage (scheduled vs filled per day)
        $dailyCoverage = [];
        for ($d = 0; $d < 7; $d++) {
            $day = (clone $weekStart)->addDays($d);
            $dayEnd = (clone $day)->addDay();
            $dayShifts = $shifts->filter(fn ($s) => $s->starts_at && $s->starts_at->gte($day) && $s->starts_at->lt($dayEnd));
            $dailyCoverage[] = [
                'day' => $day->format('D'),
                'date' => $day->toDateString(),
                'scheduled' => $dayShifts->count(),
                'filled' => $dayShifts->whereNotNull('user_id')->count(),
                'open' => $dayShifts->whereNull('user_id')->count(),
            ];
        }

        // Shift type distribution
        $shiftTypeDistribution = $shifts
            ->where('status', '!=', 'cancelled')
            ->groupBy(fn ($s) => $s->shift_type ?? 'standard')
            ->map(fn ($group, $type) => [
                'type' => ucfirst(str_replace('_', ' ', $type)),
                'value' => $group->count(),
            ])
            ->values()
            ->all();

        // Staff on leave this week
        $onLeaveCount = $canManageAny ? $this->scopeHrRecordOrganization(HrLeaveRequest::query(), $organizationId)
            ->where('status', 'approved')
            ->where('starts_at', '<', $weekEnd)
            ->where('ends_at', '>', $weekStart)
            ->distinct('user_id')
            ->count('user_id') : 0;

        // Compliance overview
        $complianceExpiring = 0;
        $complianceExpired = 0;
        if ($canManageAny && $organizationId) {
            $complianceExpiring = $this->scopeHrRecordOrganization(HrStaffComplianceStatus::query(), $organizationId)
                ->where('status', 'expiring_soon')
                ->count();
            $complianceExpired = $this->scopeHrRecordOrganization(HrStaffComplianceStatus::query(), $organizationId)
                ->where('status', 'expired')
                ->whereHas('requirement', fn ($q) => $q->where('hard_stop', true))
                ->count();
        }

        // 4-week historical trend (shifts completed vs cancelled per week).
        // Collapsed into a single GROUP BY week-bucket query and organization
        // scoped to match the sibling analytics above (Shift carries an
        // organization_id column). The bucket index is the number of whole
        // 7-day windows from the trend start (weekStart - 3 weeks); because all
        // window boundaries land on startOfDay, DATEDIFF (date-only) reproduces
        // the original half-open [wStart, wEnd) buckets exactly.
        // Skipped on the availability-tab landing: only the analytics pane reads
        // analytics.historicalTrend, and that tab body is not rendered there.
        $historicalTrend = [];
        if ($canManageAny && ! $isAvailabilityTab) {
            $trendStart = (clone $weekStart)->subWeeks(3);
            $trendEnd = (clone $weekStart)->addDays(7);

            $bucketRows = Shift::query()
                ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
                ->where('starts_at', '>=', $trendStart)
                ->where('starts_at', '<', $trendEnd)
                ->selectRaw('FLOOR(DATEDIFF(starts_at, ?) / 7) as week_bucket', [$trendStart->toDateString()])
                ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
                ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
                ->selectRaw('COUNT(*) as total')
                ->groupBy('week_bucket')
                ->get()
                ->keyBy(fn ($row) => (int) $row->week_bucket);

            for ($w = 3; $w >= 0; $w--) {
                $wStart = (clone $weekStart)->subWeeks($w);
                $bucketIndex = 3 - $w;
                $row = $bucketRows->get($bucketIndex);
                $historicalTrend[] = [
                    'week' => $wStart->format('d M'),
                    'completed' => (int) ($row?->completed ?? 0),
                    'cancelled' => (int) ($row?->cancelled ?? 0),
                    'total' => (int) ($row?->total ?? 0),
                ];
            }
        }

        // Coverage rate
        $totalShiftsThisWeek = $shifts->where('status', '!=', 'cancelled')->count();
        $filledShiftsThisWeek = $shifts->where('status', '!=', 'cancelled')->whereNotNull('user_id')->count();
        $coverageRate = $totalShiftsThisWeek > 0 ? round(($filledShiftsThisWeek / $totalShiftsThisWeek) * 100, 0) : 100;

        // Unique staff rostered
        $staffRostered = $shifts->where('status', '!=', 'cancelled')->whereNotNull('user_id')->pluck('user_id')->unique()->count();

        $replacementQueue = $shifts
            ->map(function (Shift $shift) {
                $replacement = $shift->replacementRequests
                    ->sortByDesc('requested_at')
                    ->first();

                if (! $replacement) {
                    return null;
                }

                return [
                    'id' => $replacement->id,
                    'shift_id' => $shift->id,
                    'status' => $replacement->status,
                    'reason' => $replacement->reason,
                    'requested_at' => optional($replacement->requested_at)->toIso8601String(),
                    'starts_at' => optional($shift->starts_at)->toIso8601String(),
                    'ends_at' => optional($shift->ends_at)->toIso8601String(),
                    'client' => $shift->client ? trim($shift->client->first_name.' '.$shift->client->last_name) : null,
                    'location' => $shift->location,
                    'current_staff' => $replacement->currentStaff?->name,
                    'requested_by' => $replacement->requester?->name,
                    'replacement_staff' => $replacement->replacementStaff?->name,
                    'open_position_id' => $replacement->openPosition?->id,
                    'open_position_status' => $replacement->openPosition?->status,
                    'open_position_claimed_by' => $replacement->openPosition?->claimer?->name,
                    'expires_at' => optional($replacement->openPosition?->expires_at)->toIso8601String(),
                ];
            })
            ->filter()
            ->sortBy('starts_at')
            ->values();

        // Roster period, auto-schedule, and coverage breakdowns are per-site.
        // Only honour them when exactly one site is selected.
        $selectedSiteId = null;
        if (is_int($siteFilter)) {
            $selectedSiteId = $siteFilter;
        } elseif ($siteFilter === null && ! empty($data['client_id'])) {
            $selectedSiteId = Client::query()->whereKey($data['client_id'])->value('site_id');
        }

        $coverageSites = $canManageAny
            ? $this->shiftCoverageService->buildSiteSummaries($weekStart, $weekEnd, $selectedSiteId)
            : [];

        $coverageAlerts = collect($coverageSites)
            ->flatMap(fn (array $site) => collect($site['alerts'] ?? [])->map(function (array $alert) use ($site) {
                return [
                    ...$alert,
                    'site_id' => $site['site_id'],
                    'site_name' => $site['site_name'],
                ];
            }))
            ->sortByDesc(fn (array $alert) => (
                (($alert['unfilled_after_open_shifts'] ?? 0) * 100)
                + ((count($alert['planned_role_shortages'] ?? []) > 0 ? 1 : 0) * 75)
                + ((count($alert['role_shortages'] ?? []) > 0 ? 1 : 0) * 50)
                + ($alert['missing_staff'] ?? 0)
            ))
            ->values()
            ->all();

        $stats['coverage_gaps'] = count($coverageAlerts);
        $recurringCoverageAlignment = $canManageAny
            ? $this->shiftCoverageService->buildRecurringAlignment($weekStart, $weekEnd, $selectedSiteId)
            : ['rule_drift' => [], 'orphan_series' => []];

        $recurringPatterns = $shifts
            ->filter(fn (Shift $shift) => ! empty($shift->shift_series_id) && $shift->series)
            ->groupBy('shift_series_id')
            ->map(function ($group) {
                /** @var Shift $sample */
                $sample = $group->first();
                $series = $sample->series;
                $nextShift = $group
                    ->filter(fn (Shift $shift) => ! in_array($shift->status, ['completed', 'cancelled'], true))
                    ->sortBy('starts_at')
                    ->first() ?? $group->sortBy('starts_at')->first();

                return [
                    'id' => $series->id,
                    'client' => $series->client ? trim($series->client->first_name.' '.$series->client->last_name) : ($sample->client ? trim($sample->client->first_name.' '.$sample->client->last_name) : null),
                    'staff' => $series->staff?->name ?? $sample->staff?->name,
                    'service_context' => $series->serviceContext?->name ?? $sample->serviceContext?->name,
                    'location' => $series->location ?? $sample->location,
                    'status' => $series->status ?? $sample->status,
                    'shift_type' => $series->shift_type ?? $sample->shift_type ?? 'standard',
                    'is_sleepover' => (bool) ($series->is_sleepover ?? $sample->is_sleepover),
                    'is_on_call' => (bool) ($series->is_on_call ?? $sample->is_on_call),
                    'weekdays' => $series->by_weekday ?? [],
                    'starts_time' => $series->starts_time,
                    'ends_time' => $series->ends_time,
                    'occurrences_this_week' => $group->count(),
                    'open_occurrences' => $group->whereNull('user_id')->count(),
                    'active_replacement_count' => $group->filter(fn (Shift $shift) => $shift->replacementRequests->isNotEmpty())->count(),
                    'next_shift_id' => $nextShift?->id,
                    'next_starts_at' => optional($nextShift?->starts_at)->toIso8601String(),
                ];
            })
            ->sortBy('next_starts_at')
            ->values();

        // ── Eligibility dashboard: 14-day lookahead ──────────────────
        $eligibilityAlerts = ['counts' => ['eligible' => 0, 'warnings' => 0, 'blocked' => 0, 'overrides' => 0], 'blocked' => [], 'warnings' => []];
        $openShiftEligibility = [];
        if ($canManageAny) {
            $eligibilityAlerts = $this->buildEligibilityAlerts();

            // Per-candidate open-shift eligibility is only read by the open-shifts
            // pane; skip it on the availability-tab landing.
            if (! $isAvailabilityTab) {
                $openShiftEligibility = $this->buildOpenShiftEligibility(
                    $shifts->whereNull('user_id'),
                    $staff,
                );
            }
        }

        $publishEnabled = $this->featureFlags->publishEnabled($auth->organization_id);
        $autoScheduleEnabled = $this->featureFlags->autoScheduleEnabled($auth->organization_id);
        $selectedRosterPeriod = null;
        $selectedRosterPeriodDiffSummary = null;

        if ($publishEnabled && $canManageAny && $selectedSiteId) {
            $selectedRosterPeriod = $this->rosterPeriods->activeFor($auth->organization_id, (int) $selectedSiteId, $weekStart)
                ?? $this->rosterPeriods->findOrCreate($auth->organization_id, (int) $selectedSiteId, $weekStart);

            if ($selectedRosterPeriod->snapshot) {
                $selectedRosterPeriodDiffSummary = $this->publishing->diff($selectedRosterPeriod)['summary'];
            }
        }

        $leaveLookaheadEnd = $weekStart->copy()->addDays(14);
        $approvedLeave = collect();
        $pendingLeave = collect();

        if ($canManageAny) {
            $approvedLeave = $this->scopeHrRecordOrganization(HrLeaveRequest::query(), $organizationId)
                ->where('status', 'approved')
                ->where('starts_at', '<', $leaveLookaheadEnd)
                ->where('ends_at', '>', $weekStart)
                ->when(! empty($data['staff_id']), fn ($query) => $query->where('user_id', $data['staff_id']))
                ->with('user:id,name')
                ->orderBy('starts_at')
                ->get();
        }

        if ($canApproveLeave) {
            $pendingLeave = $this->scopeHrRecordOrganization(HrLeaveRequest::query(), $organizationId)
                ->where('status', 'pending')
                ->where('starts_at', '<', $leaveLookaheadEnd)
                ->where('ends_at', '>', $weekStart)
                ->when(! empty($data['staff_id']), fn ($query) => $query->where('user_id', $data['staff_id']))
                ->with('user:id,name')
                ->orderBy('starts_at')
                ->get();
        }

        return inertia('operations/rostering/index', [
            'canManageAny' => $canManageAny,
            'canApproveLeave' => $canApproveLeave,
            'canPublishRoster' => $auth->canDo('rostering.publish'),
            'canAutoScheduleRoster' => $auth->canDo('rostering.autoSchedule'),
            'rosteringFeatures' => [
                'publish' => $publishEnabled,
                'auto_schedule' => $autoScheduleEnabled,
            ],
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'filters' => [
                'week' => $weekStart->toDateString(),
                // Cast to int: the 'integer' rule validates but does not cast,
                // so query-string values arrive as strings — and the hero
                // EntityFilter pills match items with a strict ===, so a
                // string id renders as "All staff/clients" while the data is
                // actually filtered.
                'staff_id' => isset($data['staff_id']) ? (int) $data['staff_id'] : null,
                'client_id' => isset($data['client_id']) ? (int) $data['client_id'] : null,
                // Single int when one site is selected (back-compat for publish/auto-schedule),
                // null when none.
                'site_id' => $selectedSiteId,
                // Always an int[] reflecting every selected site — frontend uses this
                // for the multi-select UI and the shift-query filter.
                'site_ids' => is_array($siteFilter)
                    ? $siteFilter
                    : ($siteFilter !== null ? [$siteFilter] : []),
            ],
            'staff' => $staff,
            'clients' => $clients,
            'sites' => $sites,
            'serviceContexts' => $serviceContexts,
            'defaultServiceContextId' => ServiceContext::defaultId(),
            'canManageTemplates' => $canManageTemplates,
            'canDeleteTemplates' => $canDeleteTemplates,
            // Roster templates (tab). Loaded lazily like staffAvailabilitySummary:
            // eager only on the ?tab=templates landing, otherwise resolved on a
            // partial reload when the user opens the tab.
            'rosterTemplates' => $request->query('tab') === 'templates'
                ? $this->buildRosterTemplates($organizationId)
                : Inertia::optional(fn () => $this->buildRosterTemplates($organizationId)),
            'canManageSeries' => $auth->canDo('shifts.manageAny') || $auth->canDo('rostering.viewAny'),
            // Recurring series (tab). Same lazy pattern as rosterTemplates: eager on
            // the ?tab=recurring landing, otherwise resolved on a partial reload.
            'rosterSeries' => $request->query('tab') === 'recurring'
                ? app(ShiftSeriesPresenter::class)->list()
                : Inertia::optional(fn () => app(ShiftSeriesPresenter::class)->list()),
            // Detail for the series pop-up. Eager when deep-linked (?series=ID),
            // otherwise resolved on demand when a card is opened (partial reload).
            'seriesDetail' => $request->query('series')
                ? $this->buildSeriesDetail($request->query('series'))
                : Inertia::optional(fn () => $this->buildSeriesDetail($request->query('series'))),
            'rosterPeriod' => $selectedRosterPeriod ? [
                'id' => $selectedRosterPeriod->id,
                'site_id' => $selectedRosterPeriod->site_id,
                'week_start' => $selectedRosterPeriod->week_start->toDateString(),
                'week_end' => optional($selectedRosterPeriod->week_end)->toDateString(),
                'version' => $selectedRosterPeriod->version,
                'status' => $selectedRosterPeriod->status,
                'shift_count' => $selectedRosterPeriod->shift_count,
                'published_at' => optional($selectedRosterPeriod->published_at)->toIso8601String(),
                'last_validated_at' => optional($selectedRosterPeriod->last_validated_at)->toIso8601String(),
                'validation_summary' => $selectedRosterPeriod->validation_summary,
                'diff_summary' => $selectedRosterPeriodDiffSummary,
            ] : null,
            'stats' => $stats,
            'shifts' => $shifts->map(function (Shift $shift) {
                $clientName = $shift->client ? ($shift->client->first_name.' '.$shift->client->last_name) : null;
                $staffName = $shift->staff ? $shift->staff->name : null;
                $ts = $shift->timesheets->first();
                $activeReplacement = $shift->replacementRequests
                    ->sortByDesc('requested_at')
                    ->first();

                return [
                    'id' => $shift->id,
                    'client_id' => $shift->client_id,
                    'user_id' => $shift->user_id,
                    'shift_series_id' => $shift->shift_series_id,
                    'starts_at' => optional($shift->starts_at)->toIso8601String(),
                    'ends_at' => optional($shift->ends_at)->toIso8601String(),
                    'location' => $shift->location,
                    'site_id' => $shift->site_id,
                    'site' => $shift->site?->name,
                    'status' => $shift->status,
                    'roster_period_id' => $shift->roster_period_id,
                    'published_at' => optional($shift->published_at)->toIso8601String(),
                    'publish_dirty_at' => optional($shift->publish_dirty_at)->toIso8601String(),
                    'shift_type' => $shift->shift_type ?? 'standard',
                    'service_context' => $shift->serviceContext ? $shift->serviceContext->name : null,
                    'client' => $clientName,
                    'staff' => $staffName,
                    'tasks_total' => (int) ($shift->tasks_total ?? 0),
                    'tasks_completed' => (int) ($shift->tasks_completed ?? 0),
                    'incidents_count' => (int) ($shift->incidents_count ?? 0),
                    'timesheet_id' => $ts?->id,
                    'timesheet_status' => $ts ? $ts->status : null,
                    'has_active_replacement' => (bool) $activeReplacement,
                    'replacement_status' => $activeReplacement?->status,
                    'replacement_reason' => $activeReplacement?->reason,
                    'replacement_requested_by' => $activeReplacement?->requester?->name,
                    'replacement_current_staff' => $activeReplacement?->currentStaff?->name,
                    'open_position_status' => $activeReplacement?->openPosition?->status,
                ];
            })->values(),
            'replacementQueue' => $replacementQueue,
            'recurringPatterns' => $recurringPatterns,
            'coverageSites' => $coverageSites,
            'coverageAlerts' => $coverageAlerts,
            'recurringCoverageAlignment' => $recurringCoverageAlignment,
            'timeOffs' => $timeOffs->map(fn ($b) => [
                'id' => $b->id,
                'user_id' => $b->user_id,
                'user' => $b->user ? $b->user->name : null,
                'starts_at' => optional($b->starts_at)->toIso8601String(),
                'ends_at' => optional($b->ends_at)->toIso8601String(),
                'type' => $b->type,
                'label' => $b->label,
                'notes' => $b->notes,
            ])->values(),
            'staffAvailabilitySummary' => $canManageAny
                ? ($request->query('tab') === 'availability'
                    ? $this->buildAvailabilitySummary($auth)
                    : Inertia::optional(fn () => $this->buildAvailabilitySummary($auth)))
                : ['staff' => [], 'upcomingLeave' => []],
            'capacity' => $capacity,

            // HR leave overlay: formal leave requests in the visible 14-day time-off window.
            'approvedLeave' => $approvedLeave
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'user_id' => $l->user_id,
                    'user' => $l->user?->name,
                    'leave_type' => $l->leave_type,
                    'reason' => $l->reason,
                    'status' => $l->status,
                    'starts_at' => $l->starts_at?->toIso8601String(),
                    'ends_at' => $l->ends_at?->toIso8601String(),
                ])->values(),
            'pendingLeave' => $pendingLeave
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'user_id' => $l->user_id,
                    'user' => $l->user?->name,
                    'leave_type' => $l->leave_type,
                    'reason' => $l->reason,
                    'status' => $l->status,
                    'starts_at' => $l->starts_at?->toIso8601String(),
                    'ends_at' => $l->ends_at?->toIso8601String(),
                ])->values(),

            // HR compliance badges per staff member
            'complianceBadges' => $canManageAny ? $this->getComplianceBadges($organizationId) : [],

            // Eligibility dashboard (14-day lookahead)
            'eligibilityAlerts' => $eligibilityAlerts,

            // Per-candidate eligibility for the visible open shifts.
            // Shape: { [shift_id]: { [user_id]: { status: 'warning'|'blocked', reasons: string[] } } }
            // Eligible candidates are omitted; the UI treats absence as eligible.
            'openShiftEligibility' => $openShiftEligibility,

            // Analytics data
            'analytics' => [
                'dailyCoverage' => $dailyCoverage,
                'shiftTypeDistribution' => $shiftTypeDistribution,
                'historicalTrend' => $historicalTrend,
                'coverageRate' => $coverageRate,
                'staffRostered' => $staffRostered,
                'onLeaveCount' => $onLeaveCount,
                'complianceExpiring' => $complianceExpiring,
                'complianceExpired' => $complianceExpired,
            ],
        ]);
    }

    /**
     * Flat payload for the Roster Templates tab — every template with its shift
     * rows and the relations the pop-ups need (client/staff/service context).
     * Templates are few and small, so loading the rows up front lets the
     * view/apply/edit modals run entirely client-side with no extra round-trips.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildRosterTemplates(?int $organizationId): array
    {
        return RosterTemplate::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->with([
                'creator:id,name',
                'templateShifts.client:id,first_name,last_name',
                'templateShifts.user:id,name',
                'templateShifts.serviceContext:id,name,type',
            ])
            ->withCount('templateShifts')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (RosterTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'template_type' => $template->template_type,
                'is_active' => (bool) $template->is_active,
                'template_shifts_count' => (int) $template->template_shifts_count,
                'creator' => $template->creator ? [
                    'id' => $template->creator->id,
                    'name' => $template->creator->name,
                ] : null,
                'updated_at' => optional($template->updated_at)->toIso8601String(),
                'template_shifts' => $template->templateShifts
                    ->sortBy([['day_of_week', 'asc'], ['start_time', 'asc']])
                    ->values()
                    ->map(fn ($shift) => [
                        'id' => $shift->id,
                        'client_id' => $shift->client_id,
                        'user_id' => $shift->user_id,
                        'service_context_id' => $shift->service_context_id,
                        'day_of_week' => (int) $shift->day_of_week,
                        'start_time' => substr((string) $shift->start_time, 0, 5),
                        'end_time' => substr((string) $shift->end_time, 0, 5),
                        'shift_type' => $shift->shift_type ?? 'standard',
                        'is_sleepover' => (bool) $shift->is_sleepover,
                        'is_on_call' => (bool) $shift->is_on_call,
                        'expected_break_minutes' => $shift->expected_break_minutes,
                        'required_skills' => $shift->required_skills ?? [],
                        'location' => $shift->location,
                        'notes' => $shift->notes,
                        'client' => $shift->client ? [
                            'id' => $shift->client->id,
                            'first_name' => $shift->client->first_name,
                            'last_name' => $shift->client->last_name,
                        ] : null,
                        'user' => $shift->user ? [
                            'id' => $shift->user->id,
                            'name' => $shift->user->name,
                        ] : null,
                        'service_context' => $shift->serviceContext ? [
                            'id' => $shift->serviceContext->id,
                            'name' => $shift->serviceContext->name,
                        ] : null,
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Build the detail payload for one recurring series (the tab pop-up).
     *
     * @return array<string, mixed>|null
     */
    protected function buildSeriesDetail($seriesId): ?array
    {
        if (! $seriesId) {
            return null;
        }

        $series = ShiftSeries::find($seriesId);

        if (! $series) {
            return null;
        }

        return app(ShiftSeriesPresenter::class)->detail($series);
    }

    protected function buildAvailabilitySummary(User $auth): array
    {
        $staff = User::query()
            ->when($auth->organization_id, fn ($query) => $query->where('organization_id', $auth->organization_id))
            ->staff()
            ->with([
                'staffAvailability' => fn ($query) => $query
                    ->orderBy('day_of_week')
                    ->orderBy('starts_at'),
                'staffTimeOff' => fn ($query) => $query
                    ->where('ends_at', '>=', now())
                    ->orderBy('starts_at'),
            ])
            ->orderBy('name')
            ->get();

        $upcomingLeave = collect();

        if ($staff->isNotEmpty() && Schema::hasTable('hr_leave_requests')) {
            $upcomingLeave = HrLeaveRequest::query()
                ->whereIn('user_id', $staff->pluck('id'))
                ->where('status', 'approved')
                ->where('ends_at', '>=', now())
                ->orderBy('starts_at')
                ->get()
                ->groupBy('user_id')
                ->map(fn ($items) => $items->map(fn ($leave) => [
                    'id' => $leave->id,
                    'leave_type' => $leave->leave_type,
                    'starts_at' => $leave->starts_at?->toIso8601String(),
                    'ends_at' => $leave->ends_at?->toIso8601String(),
                    'status' => $leave->status,
                ])->values());
        }

        return [
            'staff' => $staff->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->role,
                // Each row in staff_availabilities means "this worker is free
                // on day_of_week between starts_at and ends_at". Absence of a
                // row for a given day = not declared. The pane treats slot
                // existence as availability — see availability-pane.tsx.
                'staff_availability' => $member->staffAvailability->map(fn ($slot) => [
                    'id' => $slot->id,
                    'day_of_week' => $slot->day_of_week,
                    'start_time' => substr((string) $slot->starts_at, 0, 5),
                    'end_time' => substr((string) $slot->ends_at, 0, 5),
                ])->values(),
                'staff_time_off' => $member->staffTimeOff->map(fn ($off) => [
                    'id' => $off->id,
                    'reason' => $off->label ?? $off->type,
                    'starts_at' => $off->starts_at?->toIso8601String(),
                    'ends_at' => $off->ends_at?->toIso8601String(),
                ])->values(),
            ])->values(),
            'upcomingLeave' => $upcomingLeave->all(),
        ];
    }

    public function conflicts(RosteringConflictsRequest $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.viewAny'), 403);

        $data = $request->validated();

        $week = ! empty($data['week']) ? Carbon::parse($data['week']) : now();
        $weekStart = (clone $week)->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weekEnd = (clone $weekStart)->addDays(7);

        $shifts = Shift::query()
            ->with([
                'client:id,first_name,last_name',
                'site:id,name,type',
                'staff:id,name,email',
                'serviceContext:id,name,type,is_active',
                'replacementRequests' => fn ($query) => $query->active()->with([
                    'requester:id,name',
                    'currentStaff:id,name',
                    'replacementStaff:id,name',
                    'openPosition:id,replacement_request_id,status,claimed_by',
                    'openPosition.claimer:id,name',
                ]),
            ])
            ->where('starts_at', '<', $weekEnd)
            ->where('ends_at', '>', $weekStart)
            ->orderBy('starts_at')
            ->get();

        $actionableShifts = $shifts
            ->filter(fn (Shift $shift) => ! in_array($shift->status, ['completed', 'cancelled'], true))
            ->values();

        $staffOverlaps = [];
        foreach ($actionableShifts->filter(fn (Shift $shift) => ! empty($shift->user_id))->groupBy('user_id') as $userId => $group) {
            $sorted = $group->sortBy('starts_at')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                $previous = $sorted[$i - 1];
                $current = $sorted[$i];
                if ($previous && $current && $previous->ends_at && $current->starts_at && $previous->ends_at->gt($current->starts_at)) {
                    $staffOverlaps[] = [
                        'staff_id' => (int) $userId,
                        'staff_name' => $current->staff?->name ?? $previous->staff?->name ?? 'Staff member',
                        'first' => $this->serializeConflictShift($previous),
                        'second' => $this->serializeConflictShift($current),
                    ];
                }
            }
        }

        $clientOverlaps = [];
        foreach ($actionableShifts->groupBy('client_id') as $clientId => $group) {
            $sorted = $group->sortBy('starts_at')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                $previous = $sorted[$i - 1];
                $current = $sorted[$i];
                if ($previous && $current && $previous->ends_at && $current->starts_at && $previous->ends_at->gt($current->starts_at)) {
                    $clientOverlaps[] = [
                        'client_id' => (int) $clientId,
                        'client_name' => $current->client ? trim($current->client->first_name.' '.$current->client->last_name) : 'Client',
                        'first' => $this->serializeConflictShift($previous),
                        'second' => $this->serializeConflictShift($current),
                    ];
                }
            }
        }

        $timeOffs = StaffTimeOff::query()
            ->with('user:id,name')
            ->where('starts_at', '<', $weekEnd)
            ->where('ends_at', '>', $weekStart)
            ->orderBy('starts_at')
            ->get();

        $timeOffConflicts = [];
        foreach ($actionableShifts->filter(fn (Shift $shift) => ! empty($shift->user_id)) as $shift) {
            foreach ($timeOffs->where('user_id', $shift->user_id) as $timeOff) {
                if ($timeOff->starts_at < $shift->ends_at && $timeOff->ends_at > $shift->starts_at) {
                    $timeOffConflicts[] = [
                        'shift' => $this->serializeConflictShift($shift),
                        'time_off' => [
                            'id' => $timeOff->id,
                            'user_name' => $timeOff->user?->name ?? 'Staff member',
                            'type' => $timeOff->type,
                            'label' => $timeOff->label,
                            'starts_at' => optional($timeOff->starts_at)->toIso8601String(),
                            'ends_at' => optional($timeOff->ends_at)->toIso8601String(),
                        ],
                    ];
                    break;
                }
            }
        }

        $tightTurnarounds = [];
        foreach ($actionableShifts->filter(fn (Shift $shift) => ! empty($shift->user_id))->groupBy('user_id') as $userId => $group) {
            $sorted = $group->sortBy('starts_at')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                $previous = $sorted[$i - 1];
                $current = $sorted[$i];

                if (! $previous?->ends_at || ! $current?->starts_at) {
                    continue;
                }

                if ($previous->ends_at->gt($current->starts_at)) {
                    continue;
                }

                $gapMinutes = $previous->ends_at->diffInMinutes($current->starts_at);
                if ($gapMinutes > 30) {
                    continue;
                }

                $tightTurnarounds[] = [
                    'staff_id' => (int) $userId,
                    'staff_name' => $current->staff?->name ?? $previous->staff?->name ?? 'Staff member',
                    'gap_minutes' => $gapMinutes,
                    'first' => $this->serializeConflictShift($previous),
                    'second' => $this->serializeConflictShift($current),
                ];
            }
        }

        $openShifts = $actionableShifts
            ->whereNull('user_id')
            ->map(fn (Shift $shift) => $this->serializeConflictShift($shift))
            ->values();

        $activeReplacements = $actionableShifts
            ->map(function (Shift $shift) {
                $replacement = $shift->replacementRequests->sortByDesc('requested_at')->first();
                if (! $replacement) {
                    return null;
                }

                return [
                    'id' => $replacement->id,
                    'shift' => $this->serializeConflictShift($shift),
                    'status' => $replacement->status,
                    'reason' => $replacement->reason,
                    'requested_by' => $replacement->requester?->name,
                    'current_staff' => $replacement->currentStaff?->name,
                    'replacement_staff' => $replacement->replacementStaff?->name,
                    'claimed_by' => $replacement->openPosition?->claimer?->name,
                    'open_position_id' => $replacement->openPosition?->id,
                ];
            })
            ->filter()
            ->values();

        $coverageGaps = collect($this->shiftCoverageService->buildSiteSummaries($weekStart, $weekEnd))
            ->flatMap(fn (array $site) => collect($site['alerts'] ?? [])->map(function (array $alert) use ($site) {
                return [
                    ...$alert,
                    'site_id' => $site['site_id'],
                    'site_name' => $site['site_name'],
                ];
            }))
            ->sortByDesc(fn (array $alert) => (
                (($alert['unfilled_after_open_shifts'] ?? 0) * 100)
                + ((count($alert['planned_role_shortages'] ?? []) > 0 ? 1 : 0) * 75)
                + ((count($alert['role_shortages'] ?? []) > 0 ? 1 : 0) * 50)
                + ($alert['missing_staff'] ?? 0)
            ))
            ->values()
            ->all();
        $recurringCoverageAlignment = $this->shiftCoverageService->buildRecurringAlignment($weekStart, $weekEnd);

        return inertia('operations/rostering/conflicts', [
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'staffOverlaps' => array_values($staffOverlaps),
            'clientOverlaps' => array_values($clientOverlaps),
            'timeOffConflicts' => array_values($timeOffConflicts),
            'tightTurnarounds' => array_values($tightTurnarounds),
            'openShifts' => $openShifts,
            'activeReplacements' => $activeReplacements,
            'coverageGaps' => $coverageGaps,
            'recurringCoverageAlignment' => $recurringCoverageAlignment,
        ]);
    }

    public function autoSchedule(AutoScheduleRosterRequest $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.autoSchedule'), 403);

        $data = $request->validated();

        if ($this->featureFlags->autoScheduleEnabled($auth->organization_id)) {
            $siteId = ! empty($data['site_id'])
                ? (int) $data['site_id']
                : (! empty($data['client_id'])
                    ? (int) Client::query()->whereKey($data['client_id'])->value('site_id')
                    : null);

            if (! $siteId) {
                return redirect()
                    ->route('operations.rostering.index', array_filter([
                        'week' => $data['week'] ?? null,
                        'client_id' => $data['client_id'] ?? null,
                    ]))
                    ->with('warning', __('rostering.suggestions.choose_site'));
            }

            $run = $this->suggestions->generateOrQueue($auth, $data['week'] ?? null, $siteId);

            return redirect()
                ->route('operations.rostering.suggestions.show', $run)
                ->with(
                    $run->status === RosterSuggestionRun::STATUS_PENDING ? 'warning' : 'success',
                    $run->status === RosterSuggestionRun::STATUS_PENDING
                        ? __('rostering.suggestions.queued')
                        : __('rostering.suggestions.generated'),
                );
        }

        return redirect()
            ->route('operations.rostering.index', array_filter([
                'week' => $data['week'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'site_id' => $data['site_id'] ?? null,
            ]))
            ->with('warning', __('rostering.suggestions.auto_schedule_disabled'));
    }

    public function reviewForPublish(Request $request, RosterPeriod $period)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.publish'), 403);
        abort_unless($this->featureFlags->publishEnabled($auth->organization_id), 404);
        abort_unless((int) $period->organization_id === (int) $auth->organization_id, 403);

        $summary = $this->publishing->review($period, $auth);

        return redirect()
            ->route('operations.rostering.periods.review.show', $period)
            ->with(
                $summary['can_publish'] ? 'success' : 'warning',
                $summary['can_publish']
                    ? __('rostering.publish.review_ready')
                    : __('rostering.publish.review_blocked'),
            );
    }

    public function viewPublishReview(Request $request, RosterPeriod $period)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.publish'), 403);
        abort_unless($this->featureFlags->publishEnabled($auth->organization_id), 404);
        abort_unless((int) $period->organization_id === (int) $auth->organization_id, 403);

        $period->load(['site:id,name', 'publisher:id,name']);
        $summary = $period->validation_summary ?? [
            'can_publish' => false,
            'blocks' => [],
            'warnings' => [],
            'shift_count' => $period->shift_count,
        ];
        $shifts = $this->rosterPeriods->shiftsQuery($period)
            ->with(['client:id,first_name,last_name', 'staff:id,name,email', 'site:id,name', 'serviceContext:id,name'])
            ->orderBy('starts_at')
            ->get();

        return inertia('operations/rostering/publish/Review', [
            'period' => [
                'id' => $period->id,
                'site_id' => $period->site_id,
                'site_name' => $period->site?->name,
                'week_start' => $period->week_start->toDateString(),
                'week_end' => optional($period->week_end)->toDateString(),
                'version' => $period->version,
                'status' => $period->status,
                'published_at' => optional($period->published_at)->toIso8601String(),
                'published_by' => $period->publisher?->name,
                'last_validated_at' => optional($period->last_validated_at)->toIso8601String(),
            ],
            'summary' => $summary,
            'shifts' => $shifts->map(fn (Shift $shift) => [
                'id' => $shift->id,
                'starts_at' => optional($shift->starts_at)->toIso8601String(),
                'ends_at' => optional($shift->ends_at)->toIso8601String(),
                'status' => $shift->status,
                'client' => $shift->client ? trim($shift->client->first_name.' '.$shift->client->last_name) : null,
                'site' => $shift->site?->name,
                'staff' => $shift->staff?->name,
                'service_context' => $shift->serviceContext?->name,
                'published_at' => optional($shift->published_at)->toIso8601String(),
                'publish_dirty_at' => optional($shift->publish_dirty_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    public function confirmPublish(Request $request, RosterPeriod $period)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.publish'), 403);
        abort_unless($this->featureFlags->publishEnabled($auth->organization_id), 404);
        abort_unless((int) $period->organization_id === (int) $auth->organization_id, 403);

        $published = $this->publishing->publish($period, $auth);
        $reportLink = $this->shiftOperationsReportLink($published);

        return redirect()
            ->route('operations.rostering.index', [
                'week' => $published->week_start->toDateString(),
                'site_id' => $published->site_id,
            ])
            ->with('success', __('rostering.publish.published_message'))
            ->with('rostering_report_link', $reportLink);
    }

    public function viewDiff(Request $request, RosterPeriod $period)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.publish'), 403);
        abort_unless($this->featureFlags->publishEnabled($auth->organization_id), 404);
        abort_unless((int) $period->organization_id === (int) $auth->organization_id, 403);

        $period->load(['site:id,name', 'publisher:id,name']);
        $diff = $this->publishing->diff($period);

        return inertia('operations/rostering/publish/Diff', [
            'period' => [
                'id' => $period->id,
                'site_id' => $period->site_id,
                'site_name' => $period->site?->name,
                'week_start' => $period->week_start->toDateString(),
                'week_end' => optional($period->week_end)->toDateString(),
                'version' => $period->version,
                'status' => $period->status,
                'published_at' => optional($period->published_at)->toIso8601String(),
                'published_by' => $period->publisher?->name,
            ],
            'summary' => $diff['summary'],
            'changes' => $diff['changes'],
        ]);
    }

    public function republish(Request $request, RosterPeriod $period)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.publish'), 403);
        abort_unless($this->featureFlags->publishEnabled($auth->organization_id), 404);
        abort_unless((int) $period->organization_id === (int) $auth->organization_id, 403);

        $published = $this->publishing->republish($period, $auth);
        $reportLink = $this->shiftOperationsReportLink($published);

        return redirect()
            ->route('operations.rostering.index', [
                'week' => $published->week_start->toDateString(),
                'site_id' => $published->site_id,
            ])
            ->with('success', __('rostering.publish.republished_message', ['version' => $published->version]))
            ->with('rostering_report_link', $reportLink);
    }

    public function unpublish(Request $request, RosterPeriod $period)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.publish'), 403);
        abort_unless($this->featureFlags->publishEnabled($auth->organization_id), 404);
        abort_unless((int) $period->organization_id === (int) $auth->organization_id, 403);

        $draft = $this->publishing->unpublish($period, $auth);

        return redirect()
            ->route('operations.rostering.index', [
                'week' => $draft->week_start->toDateString(),
                'site_id' => $draft->site_id,
            ])
            ->with('warning', __('rostering.publish.unpublished_message'));
    }

    private function shiftOperationsReportLink(RosterPeriod $period): string
    {
        $reportDateTo = ($period->week_end
            ? $period->week_end->copy()->subDay()
            : $period->week_start->copy()->addDays(6)
        )->toDateString();

        return '/operations/reports/shifts?'.http_build_query([
            'date_from' => $period->week_start->toDateString(),
            'date_to' => $reportDateTo,
            'site_id' => $period->site_id,
        ]);
    }

    /**
     * Get compliance status badges for all active staff (for rostering overlays).
     */
    protected function getComplianceBadges(?int $organizationId): array
    {
        if (! $organizationId) {
            return [];
        }

        return $this->scopeHrRecordOrganization(HrStaffComplianceStatus::query(), $organizationId)
            ->whereIn('status', ['expired', 'expiring_soon'])
            ->whereHas('requirement', fn ($q) => $q->where('is_active', true))
            ->with('requirement:id,code,name,hard_stop')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($statuses, $userId) => [
                'user_id' => $userId,
                'has_hard_stop' => $statuses->contains(fn ($s) => $s->requirement?->hard_stop && $s->status === 'expired'),
                'expired_count' => $statuses->where('status', 'expired')->count(),
                'expiring_count' => $statuses->where('status', 'expiring_soon')->count(),
            ])
            ->values()
            ->toArray();
    }

    protected function scopeHrRecordOrganization($query, ?int $organizationId)
    {
        if (! $organizationId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'user',
            fn ($userQuery) => $userQuery->where('organization_id', $organizationId),
        );
    }

    protected function serializeConflictShift(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'client_name' => $shift->client ? trim($shift->client->first_name.' '.$shift->client->last_name) : 'Client',
            'staff_name' => $shift->staff?->name,
            'service_context' => $shift->serviceContext?->name,
            'status' => $shift->status,
            'shift_type' => $shift->shift_type ?? 'standard',
            'location' => $shift->location,
            'starts_at' => optional($shift->starts_at)->toIso8601String(),
            'ends_at' => optional($shift->ends_at)->toIso8601String(),
            'shift_series_id' => $shift->shift_series_id,
        ];
    }

    /**
     * Build eligibility alert data for the rostering dashboard.
     *
     * Evaluates future assigned shifts in the next 14 days and returns
     * counts + up to 10 blocked / 10 warning shifts for the summary tables.
     */
    protected function buildEligibilityAlerts(): array
    {
        $eligibility = app(ShiftStaffEligibilityService::class);

        $futureShifts = Shift::query()
            ->whereIn('status', ['scheduled'])
            ->whereNotNull('user_id')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<', now()->addDays(14))
            // client/serviceContext are read inside evaluate() (overfill coverage +
            // coverage-role checks); eager-load them here so the per-shift evaluate()
            // calls below don't lazy-load and re-introduce an N+1 (parity with
            // ShiftStaffEligibilityService::evaluateMany()'s shift-side preload).
            ->with(['staff:id,name', 'site:id,name', 'client:id,site_id', 'serviceContext:id,name,type'])
            ->orderBy('starts_at')
            ->get();

        // Each future shift only needs evaluating against its OWN assignee — the
        // dashboard reads the diagonal [$shift->id][$shift->staff->id] only.
        // Evaluating the full cartesian product (every shift × every assignee)
        // was wasted work, so build per-shift assignee pairs instead.
        //
        // Eager-load the rule stack's relations onto the distinct assignees and
        // batch-load their active shifts grouped by user_id (the same relations
        // and preloaded-shift set evaluateMany() would have prepared), so the
        // per-pair evaluate() calls stay eager-loaded and reuse one query per
        // user for the conflict/turnaround checks.
        $assignees = $futureShifts
            ->pluck('staff')
            ->filter()
            ->unique('id')
            ->values();

        $eligibilityResults = [];

        if ($assignees->isNotEmpty()) {
            (new Collection($assignees->all()))
                ->loadMissing([
                    'staffAvailability',
                    'staffTimeOff',
                    'hrLeaveRequests' => fn ($query) => $query->where('status', 'approved'),
                    'hrEmployeeProfile',
                    'hrComplianceStatuses.requirement:id,code,name,hard_stop,is_active',
                    'hrDriverEligibility',
                ]);

            // Reuse the eager-loaded User instances for every shift sharing the
            // same assignee (pluck() yields distinct instances per shift row).
            $usersById = $assignees->keyBy('id');

            $shiftsByUser = Shift::query()
                ->whereIn('user_id', $assignees->pluck('id')->all())
                ->whereNotIn('status', ['cancelled', 'completed'])
                ->with(['client:id,first_name,last_name'])
                ->orderBy('starts_at')
                ->get()
                ->groupBy('user_id');

            foreach ($futureShifts as $shift) {
                if (! $shift->staff) {
                    continue;
                }

                $assignee = $usersById->get($shift->staff->id, $shift->staff);

                try {
                    $eligibilityResults[$shift->id][$assignee->id] = $eligibility->evaluate(
                        $shift,
                        $assignee,
                        $shiftsByUser->get($assignee->id, collect()),
                    );
                } catch (\Throwable $e) {
                    Log::warning('Eligibility alert evaluate failed', [
                        'shift_id' => $shift->id,
                        'user_id' => $assignee->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $blocked = [];
        $warnings = [];
        $eligibleCount = 0;

        foreach ($futureShifts as $shift) {
            if (! $shift->staff) {
                $eligibleCount++;

                continue;
            }

            $result = $eligibilityResults[$shift->id][$shift->staff->id] ?? null;
            if (! $result) {
                $eligibleCount++;

                continue;
            }

            if ($result->hasBlocks()) {
                $blocked[] = [
                    'id' => $shift->id,
                    'user_id' => $shift->staff->id,
                    'starts_at' => $shift->starts_at?->toIso8601String(),
                    'staff' => $shift->staff->name,
                    'site' => $shift->site?->name ?? 'Unknown',
                    'reason' => $result->blocking_reasons[0] ?? 'Eligibility check failed',
                ];
            } elseif ($result->hasWarnings()) {
                $warnings[] = [
                    'id' => $shift->id,
                    'user_id' => $shift->staff->id,
                    'starts_at' => $shift->starts_at?->toIso8601String(),
                    'staff' => $shift->staff->name,
                    'site' => $shift->site?->name ?? 'Unknown',
                    'reason' => $result->warnings[0] ?? 'Eligibility warning',
                ];
            } else {
                $eligibleCount++;
            }
        }

        $overrideCount = ShiftEligibilityOverride::where('created_at', '>=', now()->subDays(7))->count();

        return [
            'counts' => [
                'eligible' => $eligibleCount,
                'warnings' => count($warnings),
                'blocked' => count($blocked),
                'overrides' => $overrideCount,
            ],
            'blocked' => array_slice($blocked, 0, 10),
            'warnings' => array_slice($warnings, 0, 10),
        ];
    }

    /**
     * Build per-candidate eligibility entries for the visible open shifts.
     *
     * The map is keyed by `(shift_id, user_id)` and only contains entries
     * with status `warning` or `blocked`. Eligible candidates are omitted
     * — the UI treats absence as eligible to keep the payload small.
     *
     * We iterate the same staff pool the page exposes as `props.staff`
     * (loaded from `User::staff()`) so the IDs line up with what the JS
     * chip renderer iterates. Full User models are reloaded by ID because
     * the controller's $staff collection only selects [id, name, email]
     * and the rule classes lazy-load extra relations.
     *
     * @param  iterable<Shift>  $openShifts
     * @param  iterable<User>  $staffPool
     * @return array<int, array<int, array{status: string, reasons: array<int, string>}>>
     */
    protected function buildOpenShiftEligibility(iterable $openShifts, iterable $staffPool): array
    {
        $openShiftModels = collect($openShifts)->values();
        $staffIds = collect($staffPool)->pluck('id')->filter()->values();
        if ($openShiftModels->isEmpty() || $staffIds->isEmpty()) {
            return [];
        }

        $candidates = User::query()->whereIn('id', $staffIds->all())->get();
        if ($candidates->isEmpty()) {
            return [];
        }

        $eligibility = app(ShiftStaffEligibilityService::class);
        $eligibilityResults = $eligibility->evaluateMany($openShiftModels, $candidates);
        $result = [];

        foreach ($openShiftModels as $shift) {
            if (! $shift->starts_at || ! $shift->ends_at) {
                continue;
            }

            $perShift = [];
            foreach ($candidates as $candidate) {
                $check = $eligibilityResults[$shift->id][$candidate->id] ?? null;
                if (! $check) {
                    continue;
                }

                if ($check->hasBlocks()) {
                    $perShift[$candidate->id] = [
                        'status' => 'blocked',
                        'reasons' => array_values(array_slice($check->blocking_reasons, 0, 3)),
                    ];
                } elseif ($check->hasWarnings()) {
                    $perShift[$candidate->id] = [
                        'status' => 'warning',
                        'reasons' => array_values(array_slice($check->warnings, 0, 3)),
                    ];
                }
            }

            if (! empty($perShift)) {
                $result[$shift->id] = $perShift;
            }
        }

        return $result;
    }
}
