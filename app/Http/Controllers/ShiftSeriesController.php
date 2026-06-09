<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftSeries;
use App\Models\ShiftTask;
use App\Models\SiteCoverageRequirement;
use App\Models\User;
use App\Services\CoverageReservationService;
use App\Services\NotificationService;
use App\Services\Operations\ShiftSeriesPresenter;
use App\Services\ShiftConflictService;
use App\Services\ShiftReplacementService;
use App\Services\ShiftStateGuardService;
use App\Services\ShiftTimelineService;
use App\Services\Sites\SiteChecklistScheduler;
use App\Support\ShiftTaskSupport;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShiftSeriesController extends Controller
{
    public function __construct(private ShiftSeriesPresenter $presenter) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canViewSeries($auth), 403);

        // Recurring series now live as a tab in the Rostering workspace. Send
        // users who can open that workspace there; everyone else who may view
        // series (e.g. the read-only Auditor role, which lacks rostering.viewAny)
        // keeps the standalone list as a fallback.
        if ($auth->canDo('rostering.viewAny')) {
            return redirect()->route('operations.rostering.index', ['tab' => 'recurring']);
        }

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

        // Rostering users open the series in the Recurring tab pop-up; other
        // viewers (Auditor) keep the standalone read-only detail page.
        if ($auth->canDo('rostering.viewAny')) {
            return redirect()->route('operations.rostering.index', [
                'tab' => 'recurring',
                'series' => $series->id,
            ]);
        }

        return inertia('operations/shifts/series/Show', [
            ...$this->presenter->detail($series),
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
                ShiftOpenPosition::query()
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
            'tasks.*.scheduled_time' => ['nullable', 'date_format:H:i'],
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

        // Sampled eligibility check across representative occurrences when staff is assigned.
        // Checks first, mid, last, and one-per-week to catch fatigue accumulation.
        if (! empty($data['user_id']) && ! empty($occurrenceWindows)) {
            try {
                $assignee = User::findOrFail($data['user_id']);
                $shiftTemplate = [
                    'user_id' => $data['user_id'],
                    'site_id' => $data['site_id'] ?? null,
                    'shift_type' => $data['shift_type'] ?? 'standard',
                    'is_sleepover' => $data['is_sleepover'] ?? false,
                    'is_on_call' => $data['is_on_call'] ?? false,
                    'coverage_roles' => $data['coverage_roles'] ?? [],
                    'service_context_id' => $data['service_context_id'] ?? null,
                ];

                $samplerResult = app(ShiftSeriesEligibilitySampler::class)
                    ->evaluate($occurrenceWindows, $assignee, $shiftTemplate);

                if (! $samplerResult['passed']) {
                    $blockedDate = $samplerResult['blocked_at']['date'] ?? 'a future occurrence';
                    $blockedReason = $samplerResult['blocked_at']['reasons'][0]
                        ?? 'This staff member cannot be assigned to this series.';

                    return back()->withErrors([
                        'user_id' => "This recurring series cannot be created because the occurrence on {$blockedDate} is blocked: {$blockedReason}",
                    ])->withInput();
                }

                if (! empty($samplerResult['warnings'])) {
                    $allWarnings = collect($samplerResult['warnings'])
                        ->flatMap(fn (array $w) => $w['messages'])
                        ->unique()
                        ->values()
                        ->all();
                    session()->flash('assignment_warnings', $allWarnings);
                }
            } catch (\Throwable $e) {
                Log::warning('Eligibility check failed during series creation', [
                    'error' => $e->getMessage(),
                ]);
            }
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

        if (! empty($conflicts)) {
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

                $tasks = ShiftTaskSupport::normalizeInputs($data['tasks'] ?? []);

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

                    app(SiteChecklistScheduler::class)->ensureRunsForShiftLocalDay($shift);

                    foreach ($tasks as $t) {
                        ShiftTask::create([
                            'shift_id' => $shift->id,
                            'label' => $t['label'],
                            'scheduled_time' => $t['scheduled_time'],
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

        $seriesModel = ShiftSeries::query()->find($result['series_id'] ?? null);
        $client = Client::query()->find($data['client_id'] ?? null);
        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'shift series', $seriesModel, $client, [
            'title' => 'Recurring shifts created',
            'body' => 'Created '.($result['count'] ?? 0).' shifts.',
            'url' => url('/operations/shifts'),
            'target_user_ids' => array_values(array_filter([$data['user_id'] ?? null])),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                ...$result,
            ], 201);
        }

        return redirect($data['return_to'] ?? route('operations.shifts.index'))
            ->with('success', 'Recurring shifts created ('.$result['count'].').');
    }

    protected function assertCoverageClientMatchesContext(int $clientId, ?int $siteId, ?int $coverageRuleId = null): void
    {
        $clientSiteId = Client::query()->whereKey($clientId)->value('site_id');

        if ($siteId && (int) $clientSiteId !== (int) $siteId) {
            throw ValidationException::withMessages([
                'client_id' => 'The selected planning client does not belong to the site coverage window you are filling.',
            ]);
        }

        if ($coverageRuleId) {
            $ruleSiteId = SiteCoverageRequirement::query()
                ->whereKey($coverageRuleId)
                ->value('site_id');

            if ($ruleSiteId && (int) $clientSiteId !== (int) $ruleSiteId) {
                throw ValidationException::withMessages([
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
        $start = CarbonImmutable::parse($date->toDateString().' '.$startsTime, $tz);
        $end = CarbonImmutable::parse($date->toDateString().' '.$endsTime, $tz);

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
     * @param  array<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $windows
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
