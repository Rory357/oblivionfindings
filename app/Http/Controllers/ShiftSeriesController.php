<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftSeries;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Services\NotificationService;
use App\Services\CoverageReservationService;
use App\Models\ShiftTask;
use App\Services\ShiftConflictService;
use App\Services\ShiftCoverageService;
use App\Services\ShiftReplacementService;
use App\Services\ShiftStateGuardService;
use App\Services\ShiftTimelineService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ShiftSeriesController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canViewSeries($auth), 403);

        $series = ShiftSeries::query()
            ->with([
                'client:id,first_name,last_name',
                'site:id,name,type',
                'staff:id,name',
                'serviceContext:id,name,type',
            ])
            ->withCount([
                'shifts as occurrences_total',
                'shifts as active_occurrences_count' => fn ($query) => $query->whereNotIn('status', ['completed', 'cancelled']),
                'shifts as open_occurrences_count' => fn ($query) => $query
                    ->whereNull('user_id')
                    ->whereNotIn('status', ['completed', 'cancelled']),
                'shifts as replacement_occurrences_count' => fn ($query) => $query
                    ->whereHas('replacementRequests', fn ($replacementQuery) => $replacementQuery->active()),
            ])
            ->withMin([
                'shifts as next_starts_at' => fn ($query) => $query
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->where('ends_at', '>=', now()->startOfDay()),
            ], 'starts_at')
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/shifts/series/Index', [
            'series' => $series->through(fn (ShiftSeries $row) => [
                'id' => $row->id,
                'status' => $row->status,
                'shift_type' => $row->shift_type ?? 'standard',
                'client' => $row->client ? [
                    'id' => $row->client->id,
                    'name' => trim($row->client->first_name.' '.$row->client->last_name),
                ] : null,
                'site' => $row->site ? [
                    'id' => $row->site->id,
                    'name' => $row->site->name,
                    'type' => $row->site->type,
                ] : null,
                'staff' => $row->staff ? ['id' => $row->staff->id, 'name' => $row->staff->name] : null,
                'service_context' => $row->serviceContext ? [
                    'id' => $row->serviceContext->id,
                    'name' => $row->serviceContext->name,
                    'type' => $row->serviceContext->type,
                ] : null,
                'location' => $row->location,
                'weekdays' => $row->by_weekday ?? [],
                'starts_time' => $row->starts_time,
                'ends_time' => $row->ends_time,
                'is_sleepover' => (bool) $row->is_sleepover,
                'is_on_call' => (bool) $row->is_on_call,
                'start_date' => optional($row->start_date)->toDateString(),
                'end_date' => optional($row->end_date)->toDateString(),
                'occurrences_total' => (int) ($row->occurrences_total ?? 0),
                'active_occurrences_count' => (int) ($row->active_occurrences_count ?? 0),
                'open_occurrences_count' => (int) ($row->open_occurrences_count ?? 0),
                'replacement_occurrences_count' => (int) ($row->replacement_occurrences_count ?? 0),
                'next_starts_at' => $row->next_starts_at ? Carbon::parse($row->next_starts_at)->toIso8601String() : null,
            ]),
            'canManageAny' => $this->canManageSeries($auth),
        ]);
    }

    public function show(Request $request, ShiftSeries $series)
    {
        $auth = $request->user();
        abort_unless($this->canViewSeries($auth), 403);

        $series->load([
            'client:id,first_name,last_name',
            'site:id,name,type',
            'staff:id,name,email',
            'serviceContext:id,name,type',
        ]);

        $today = now()->startOfDay();

        $upcomingOccurrences = Shift::query()
            ->with([
                'staff:id,name,email',
                'serviceContext:id,name,type',
                'replacementRequests' => fn ($query) => $query->active()
                    ->with([
                        'requester:id,name',
                        'currentStaff:id,name',
                        'replacementStaff:id,name',
                        'openPosition:id,replacement_request_id,status,claimed_by,expires_at',
                        'openPosition.claimer:id,name',
                    ]),
            ])
            ->withCount([
                'incidents as incidents_count',
                'tasks as tasks_total',
                'tasks as tasks_completed' => fn ($query) => $query->where('is_completed', true),
            ])
            ->where('shift_series_id', $series->id)
            ->where('ends_at', '>=', $today)
            ->orderBy('starts_at')
            ->limit(18)
            ->get();

        $recentOccurrences = Shift::query()
            ->with(['staff:id,name,email', 'serviceContext:id,name,type'])
            ->withCount([
                'incidents as incidents_count',
                'tasks as tasks_total',
                'tasks as tasks_completed' => fn ($query) => $query->where('is_completed', true),
            ])
            ->where('shift_series_id', $series->id)
            ->where('ends_at', '<', $today)
            ->orderByDesc('starts_at')
            ->limit(8)
            ->get();

        $stats = [
            'occurrences_total' => Shift::query()->where('shift_series_id', $series->id)->count(),
            'remaining_occurrences' => Shift::query()
                ->where('shift_series_id', $series->id)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->where('ends_at', '>=', $today)
                ->count(),
            'open_occurrences' => Shift::query()
                ->where('shift_series_id', $series->id)
                ->whereNull('user_id')
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->where('ends_at', '>=', $today)
                ->count(),
            'completed_occurrences' => Shift::query()
                ->where('shift_series_id', $series->id)
                ->where('status', 'completed')
                ->count(),
            'cancelled_occurrences' => Shift::query()
                ->where('shift_series_id', $series->id)
                ->where('status', 'cancelled')
                ->count(),
            'active_replacements' => Shift::query()
                ->where('shift_series_id', $series->id)
                ->whereHas('replacementRequests', fn ($query) => $query->active())
                ->count(),
        ];

        $nextOccurrence = $upcomingOccurrences->first();
        $coverageAlignment = [
            'linked_rule_issues' => [],
            'orphan_series' => null,
        ];

        if ($series->site_id) {
            $alignmentWindowEnd = now()->addDays(28)->endOfDay();
            $alignment = app(ShiftCoverageService::class)->buildRecurringAlignment(
                now()->startOfDay(),
                $alignmentWindowEnd,
                $series->site_id,
            );

            $coverageAlignment['linked_rule_issues'] = collect($alignment['rule_drift'] ?? [])
                ->filter(fn (array $issue) => collect($issue['matching_series'] ?? [])
                    ->contains(fn (array $row) => (int) ($row['id'] ?? 0) === (int) $series->id))
                ->values()
                ->all();
            $coverageAlignment['orphan_series'] = collect($alignment['orphan_series'] ?? [])
                ->first(fn (array $issue) => (int) ($issue['series_id'] ?? 0) === (int) $series->id);
        }

        return inertia('operations/shifts/series/Show', [
            'series' => [
                'id' => $series->id,
                'status' => $series->status,
                'shift_type' => $series->shift_type ?? 'standard',
                'client' => $series->client ? [
                    'id' => $series->client->id,
                    'name' => trim($series->client->first_name.' '.$series->client->last_name),
                ] : null,
                'site' => $series->site ? [
                    'id' => $series->site->id,
                    'name' => $series->site->name,
                    'type' => $series->site->type,
                ] : null,
                'staff' => $series->staff ? [
                    'id' => $series->staff->id,
                    'name' => $series->staff->name,
                    'email' => $series->staff->email,
                ] : null,
                'service_context' => $series->serviceContext ? [
                    'id' => $series->serviceContext->id,
                    'name' => $series->serviceContext->name,
                    'type' => $series->serviceContext->type,
                ] : null,
                'location' => $series->location,
                'notes' => $series->notes,
                'weekdays' => $series->by_weekday ?? [],
                'starts_time' => $series->starts_time,
                'ends_time' => $series->ends_time,
                'start_date' => optional($series->start_date)->toDateString(),
                'end_date' => optional($series->end_date)->toDateString(),
                'is_sleepover' => (bool) $series->is_sleepover,
                'is_on_call' => (bool) $series->is_on_call,
                'expected_break_minutes' => $series->expected_break_minutes,
            ],
            'stats' => $stats,
            'nextOccurrence' => $nextOccurrence ? $this->mapOccurrence($nextOccurrence) : null,
            'upcomingOccurrences' => $upcomingOccurrences->map(fn (Shift $shift) => $this->mapOccurrence($shift))->values(),
            'recentOccurrences' => $recentOccurrences->map(fn (Shift $shift) => $this->mapOccurrence($shift))->values(),
            'coverageAlignment' => $coverageAlignment,
            'canManageAny' => $this->canManageSeries($auth),
        ]);
    }

    public function cancelFuture(Request $request, ShiftSeries $series)
    {
        $auth = $request->user();
        abort_unless($this->canManageSeries($auth), 403);

        $futureShifts = Shift::query()
            ->where('shift_series_id', $series->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->where('starts_at', '>=', now()->startOfDay())
            ->orderBy('starts_at')
            ->get();

        if ($futureShifts->isEmpty()) {
            return back()->with('error', 'There are no future active occurrences left to cancel.');
        }

        DB::transaction(function () use ($series, $futureShifts, $auth) {
            foreach ($futureShifts as $shift) {
                app(ShiftReplacementService::class)->cancelActiveForShift($shift, $auth);
                app(CoverageReservationService::class)->releaseForShift($shift);
                \App\Models\ShiftOpenPosition::query()
                    ->where('shift_id', $shift->id)
                    ->whereIn('status', ['open', 'claimed'])
                    ->update(['status' => 'cancelled']);
                $shift->update(['status' => 'cancelled']);
                app(ShiftTimelineService::class)->recordCancelled($shift->fresh(), $auth);
            }

            $series->update(['status' => 'cancelled']);
        });

        return redirect()->route('operations.shifts.series.show', $series)->with(
            'success',
            'Future occurrences in this recurring series were cancelled.',
        );
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'by_weekday' => ['required', 'array', 'min:1'],
            'by_weekday.*' => ['in:mon,tue,wed,thu,fri,sat,sun'],
            'starts_time' => ['required', 'date_format:H:i'],
            'ends_time' => ['required', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,scheduled'],
            'shift_type' => ['nullable', 'in:standard,sleepover,on_call,split,travel'],
            'is_sleepover' => ['nullable', 'boolean'],
            'is_on_call' => ['nullable', 'boolean'],
            'expected_break_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'coverage_rule_id' => ['nullable', 'integer', 'exists:site_coverage_requirements,id'],
            'coverage_roles' => ['nullable', 'array'],
            'coverage_roles.*' => ['string', 'in:caregiver,driver,med_competent'],
            'coverage_reservation_token' => ['nullable', 'string', 'max:120'],
            'return_to' => ['nullable', 'string', 'max:2048'],
            'tasks' => ['sometimes', 'array'],
            'tasks.*.label' => ['required_with:tasks', 'string', 'max:255'],
        ]);

        $data = $this->normalizeSeriesData($data);
        $data['site_id'] = Client::query()->whereKey($data['client_id'])->value('site_id');
        $this->assertCoverageClientMatchesContext(
            (int) $data['client_id'],
            $data['site_id'],
            ! empty($data['coverage_rule_id']) ? (int) $data['coverage_rule_id'] : null,
        );
        $data['status'] = app(ShiftStateGuardService::class)->normalizePlanningStatus(
            $data['status'] ?? null,
            ! empty($data['user_id']),
        );

        // If not explicitly provided, inherit the client's service context
        // so that each generated shift is correctly classified for audit.
        if (empty($data['service_context_id'])) {
            $data['service_context_id'] = Client::query()
                ->whereKey($data['client_id'])
                ->value('service_context_id');
        }

        // If still not set, apply organisation default service context (if configured).
        if (empty($data['service_context_id'])) {
            $data['service_context_id'] = ServiceContext::defaultId();
        }

        // Overnight recurring patterns are valid; combineDateAndTimes will roll the
        // end time into the next day when needed.
        $tz = $data['timezone'] ?? 'Pacific/Auckland';

        $conflicts = [];
        $occurrences = $this->expandWeeklyOccurrences(
            CarbonImmutable::parse($data['start_date'], $tz),
            CarbonImmutable::parse($data['end_date'], $tz),
            $data['by_weekday']
        );
        $occurrenceWindows = collect($occurrences)
            ->map(function (CarbonImmutable $date) use ($data, $tz) {
                [$startsAt, $endsAt] = $this->combineDateAndTimes($date, $data['starts_time'], $data['ends_time'], $tz);

                return [
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ];
            })
            ->values()
            ->all();

        if ($this->hasSelfOverlappingWindows($occurrenceWindows)) {
            return back()->withErrors([
                'repeat' => 'This recurring pattern overlaps itself. Adjust the weekdays or times before saving.',
            ]);
        }

        foreach ($occurrences as $date) {
            [$startsAt, $endsAt] = $this->combineDateAndTimes($date, $data['starts_time'], $data['ends_time'], $tz);
            $existing = $this->findConflicts(
                userId: isset($data['user_id']) ? (int) $data['user_id'] : null,
                clientId: (int) $data['client_id'],
                startsAt: $startsAt,
                endsAt: $endsAt,
                ignoreShiftId: null
            );

            if ($existing->isNotEmpty()) {
                $conflicts[] = [
                    'date' => $date->toDateString(),
                    'starts_at' => $startsAt->toDateTimeString(),
                    'ends_at' => $endsAt->toDateTimeString(),
                    'conflicting_shift_ids' => $existing->pluck('id')->values(),
                ];
            }
        }

        if (!empty($conflicts)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Conflicting shifts detected for one or more occurrences.',
                    'conflicts' => $conflicts,
                ], 422);
            }

            return back()->withErrors([
                'repeat' => 'Conflicting shifts detected for one or more occurrences. Please adjust times/staff/client.',
            ])->with('conflicts', $conflicts);
        }

        $reservation = app(CoverageReservationService::class)->validateToken(
            $data['coverage_reservation_token'] ?? null,
            $auth,
            [
                'site_id' => $data['site_id'] ?? null,
                'coverage_requirement_id' => $data['coverage_rule_id'] ?? null,
            ],
        );

        if (! $reservation) {
            [$firstStart, $firstEnd] = $this->combineDateAndTimes($occurrences[0], $data['starts_time'], $data['ends_time'], $tz);
            $reservation = app(CoverageReservationService::class)->reserveForCoveragePayload($auth, [
                'site_id' => $data['site_id'] ?? null,
                'starts_at' => $firstStart->toIso8601String(),
                'ends_at' => $firstEnd->toIso8601String(),
                'coverage_rule_id' => $data['coverage_rule_id'] ?? null,
                'coverage_roles' => $data['coverage_roles'] ?? [],
            ], 'series_store');
        }

        try {
            $result = DB::transaction(function () use ($auth, $data, $tz, $occurrences, $reservation) {
                $series = ShiftSeries::create([
                    ...Arr::except($data, ['tasks', 'coverage_reservation_token', 'coverage_rule_id']),
                    'timezone' => $tz,
                    'status' => $data['status'],
                    'created_by' => $auth->id,
                ]);

                $tasks = collect($data['tasks'] ?? [])
                    ->map(fn ($t, $i) => ['label' => (string) ($t['label'] ?? ''), 'sort_order' => $i])
                    ->filter(fn ($t) => trim($t['label']) !== '')
                    ->values();

                foreach ($occurrences as $date) {
                    [$startsAt, $endsAt] = $this->combineDateAndTimes($date, $data['starts_time'], $data['ends_time'], $tz);

                    $shift = Shift::create([
                        'shift_series_id' => $series->id,
                        'client_id' => $data['client_id'],
                        'site_id' => $data['site_id'] ?? null,
                        'service_context_id' => $data['service_context_id'] ?? null,
                        'user_id' => $data['user_id'],
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'location' => $data['location'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'status' => $data['status'],
                        'shift_type' => $data['shift_type'] ?? 'standard',
                        'is_sleepover' => (bool) ($data['is_sleepover'] ?? false),
                        'is_on_call' => (bool) ($data['is_on_call'] ?? false),
                        'expected_break_minutes' => $data['expected_break_minutes'] ?? null,
                        'coverage_roles' => $data['coverage_roles'] ?? null,
                        'created_by' => $auth->id,
                    ]);

                    foreach ($tasks as $t) {
                        ShiftTask::create([
                            'shift_id' => $shift->id,
                            'label' => $t['label'],
                            'sort_order' => $t['sort_order'],
                        ]);
                    }
                }

                app(CoverageReservationService::class)->fulfill($reservation);

                return [
                    'series_id' => $series->id,
                    'count' => count($occurrences),
                ];
            });
        } catch (\Throwable $e) {
            app(CoverageReservationService::class)->release($reservation);
            throw $e;
        }


        $seriesModel = \App\Models\ShiftSeries::query()->find($result['series_id'] ?? null);
        $client = \App\Models\Client::query()->find($data['client_id'] ?? null);
        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'shift series', $seriesModel, $client, [
            'title' => 'Recurring shifts created',
            'body' => 'Created ' . ($result['count'] ?? 0) . ' shifts.',
            'url' => url('/shifts'),
            'target_user_ids' => array_values(array_filter([$data['user_id'] ?? null])),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                ...$result,
            ], 201);
        }

        return redirect($data['return_to'] ?? route('shifts.index'))
            ->with('success', 'Recurring shifts created (' . $result['count'] . ').');
    }

    private function mapOccurrence(Shift $shift): array
    {
        $replacement = $shift->replacementRequests
            ->sortByDesc('requested_at')
            ->first();

        return [
            'id' => $shift->id,
            'starts_at' => optional($shift->starts_at)->toIso8601String(),
            'ends_at' => optional($shift->ends_at)->toIso8601String(),
            'status' => $shift->status,
            'user_id' => $shift->user_id,
            'staff' => $shift->staff ? [
                'id' => $shift->staff->id,
                'name' => $shift->staff->name,
                'email' => $shift->staff->email,
            ] : null,
            'service_context' => $shift->serviceContext ? [
                'id' => $shift->serviceContext->id,
                'name' => $shift->serviceContext->name,
                'type' => $shift->serviceContext->type,
            ] : null,
            'location' => $shift->location,
            'tasks_total' => (int) ($shift->tasks_total ?? 0),
            'tasks_completed' => (int) ($shift->tasks_completed ?? 0),
            'incidents_count' => (int) ($shift->incidents_count ?? 0),
            'replacement' => $replacement ? [
                'id' => $replacement->id,
                'status' => $replacement->status,
                'reason' => $replacement->reason,
                'requested_by' => $replacement->requester?->name,
                'current_staff' => $replacement->currentStaff?->name,
                'replacement_staff' => $replacement->replacementStaff?->name,
                'open_position_status' => $replacement->openPosition?->status,
                'open_position_claimed_by' => $replacement->openPosition?->claimer?->name,
                'expires_at' => optional($replacement->openPosition?->expires_at)->toIso8601String(),
            ] : null,
        ];
    }

    protected function assertCoverageClientMatchesContext(int $clientId, ?int $siteId, ?int $coverageRuleId = null): void
    {
        $clientSiteId = Client::query()->whereKey($clientId)->value('site_id');

        if ($siteId && (int) $clientSiteId !== (int) $siteId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'client_id' => 'The selected planning client does not belong to the site coverage window you are filling.',
            ]);
        }

        if ($coverageRuleId) {
            $ruleSiteId = \App\Models\SiteCoverageRequirement::query()
                ->whereKey($coverageRuleId)
                ->value('site_id');

            if ($ruleSiteId && (int) $clientSiteId !== (int) $ruleSiteId) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'client_id' => 'The selected planning client no longer matches the linked site coverage rule.',
                ]);
            }
        }
    }

    private function canViewSeries($auth): bool
    {
        return (bool) $auth && (
            $auth->canDo('rostering.viewAny')
            || $auth->canDo('shifts.manageAny')
            || $auth->canDo('shifts.viewAny')
        );
    }

    private function canManageSeries($auth): bool
    {
        return (bool) $auth && (
            $auth->canDo('shifts.manageAny')
            || $auth->canDo('rostering.viewAny')
        );
    }

    private function expandWeeklyOccurrences(CarbonImmutable $start, CarbonImmutable $end, array $byWeekday): array
    {
        $map = [
            'mon' => 1,
            'tue' => 2,
            'wed' => 3,
            'thu' => 4,
            'fri' => 5,
            'sat' => 6,
            'sun' => 7,
        ];
        $wanted = collect($byWeekday)->map(fn ($d) => $map[$d] ?? null)->filter()->unique()->values()->all();
        $out = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            if (in_array((int) $d->dayOfWeekIso, $wanted, true)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    private function combineDateAndTimes(CarbonImmutable $date, string $startsTime, string $endsTime, string $tz): array
    {
        $start = CarbonImmutable::parse($date->toDateString() . ' ' . $startsTime, $tz);
        $end = CarbonImmutable::parse($date->toDateString() . ' ' . $endsTime, $tz);

        if (! $end->greaterThan($start)) {
            $end = $end->addDay();
        }

        return [$start, $end];
    }

    private function findConflicts(?int $userId, int $clientId, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?int $ignoreShiftId)
    {
        return app(ShiftConflictService::class)
            ->findBlockingStaffConflicts($userId, $startsAt, $endsAt, $ignoreShiftId)
            ->map(fn (Shift $shift) => $shift->only(['id', 'user_id', 'client_id', 'starts_at', 'ends_at']));
    }

    /**
     * @param array<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}> $windows
     */
    private function hasSelfOverlappingWindows(array $windows): bool
    {
        $sorted = collect($windows)
            ->sortBy(fn (array $window) => $window['starts_at']->getTimestamp())
            ->values();

        for ($index = 1; $index < $sorted->count(); $index++) {
            $previous = $sorted[$index - 1];
            $current = $sorted[$index];

            if ($previous['ends_at']->gt($current['starts_at'])) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSeriesData(array $data): array
    {
        $data['shift_type'] = $data['shift_type'] ?? 'standard';
        $data['is_sleepover'] = (bool) ($data['is_sleepover'] ?? false);
        $data['is_on_call'] = (bool) ($data['is_on_call'] ?? false);

        if ($data['shift_type'] === 'sleepover') {
            $data['is_sleepover'] = true;
        }

        if ($data['shift_type'] === 'on_call') {
            $data['is_on_call'] = true;
        }

        $data['expected_break_minutes'] = array_key_exists('expected_break_minutes', $data)
            && $data['expected_break_minutes'] !== null
            && $data['expected_break_minutes'] !== ''
            ? (int) $data['expected_break_minutes']
            : null;

        return $data;
    }
}
