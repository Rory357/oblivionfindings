<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\SiteCoverageRequirement;
use App\Models\User;
use App\Services\CoverageReservationService;
use App\Services\NotificationService;
use App\Services\ShiftConflictService;
use App\Services\ShiftCoverageService;
use App\Services\ShiftStaffEligibilityService;
use App\Services\ShiftStateGuardService;
use App\Services\ShiftTimelineService;
use App\Services\Sites\SiteChecklistScheduler;
use App\Support\ShiftTaskSupport;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CalendarController extends Controller
{
    public function __construct(
        protected ShiftConflictService $shiftConflictService,
        protected ShiftCoverageService $shiftCoverageService,
    ) {}

    /**
     * JSON feed for FullCalendar — the "Calendar" tab inside the Rostering
     * workspace (/operations/rostering?tab=calendar). The standalone
     * /scheduling page was retired; the Rostering page now supplies the page
     * chrome and the canManageAny/staff/clients/serviceContexts props.
     */
    public function events(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.viewAny'), 403);

        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        $canManageAny = $auth->canDo('shifts.manageAny');

        // FullCalendar supplies an inclusive start and an exclusive end.
        // Use an overlap query so shifts that start before the range but
        // overlap it are still included.
        $query = Shift::query()
            ->with([
                'client:id,first_name,last_name',
                'site:id,name,type',
                'staff:id,name',
                'serviceContext:id,name,type,is_active',
                'series:id,by_weekday,status',
                'replacementRequests' => fn ($query) => $query->active(),
                'tasks:id,shift_id,label,scheduled_time,is_completed,sort_order',
            ])
            ->withCount([
                'incidents as incidents_count',
                'tasks as tasks_total',
                'tasks as tasks_completed' => fn ($query) => $query->where('is_completed', true),
            ])
            ->where('starts_at', '<', $data['end'])
            ->where('ends_at', '>', $data['start']);

        if (! $canManageAny) {
            $query
                ->where('user_id', $auth->id)
                ->visibleToFrontline();
        } else {
            if (! empty($data['staff_id'])) {
                $query->where('user_id', $data['staff_id']);
            }
            if (! empty($data['client_id'])) {
                $query->where('client_id', $data['client_id']);
            }
        }

        $shifts = $query->get();

        $siteFilterId = null;
        if (! empty($data['client_id'])) {
            $siteFilterId = Client::query()->whereKey($data['client_id'])->value('site_id');
        }

        $coverageWindows = $canManageAny
            ? collect($this->shiftCoverageService->buildRangeCoverage(
                Carbon::parse($data['start']),
                Carbon::parse($data['end']),
                $siteFilterId,
            ))
            : collect();

        $shiftCoverageById = $shifts->mapWithKeys(function (Shift $shift) use ($coverageWindows) {
            $siteId = $shift->site_id ?: $shift->client?->site_id;
            $match = $coverageWindows
                ->filter(fn (array $window) => (int) $window['site_id'] === (int) $siteId)
                ->first(fn (array $window) => $shift->starts_at && $shift->ends_at
                    && $shift->starts_at->lt(Carbon::parse($window['ends_at']))
                    && $shift->ends_at->gt(Carbon::parse($window['starts_at']))
                );

            return [$shift->id => $match];
        });

        $shiftEvents = $shifts->map(function (Shift $shift) use ($canManageAny, $shiftCoverageById) {
            $clientName = $shift->client ? ($shift->client->first_name.' '.$shift->client->last_name) : 'Client';
            $staffName = $shift->staff ? $shift->staff->name : 'Staff';
            $coverage = $shiftCoverageById->get($shift->id);
            $isRespite = (bool) $shift->respite_booking_id;

            $title = $canManageAny ? ($clientName.' · '.$staffName) : $clientName;

            return [
                'id' => $shift->id,
                'title' => $title,
                // Send ISO-8601 strings so FullCalendar parses reliably.
                'start' => optional($shift->starts_at)->toIso8601String(),
                'end' => optional($shift->ends_at)->toIso8601String(),
                'backgroundColor' => $isRespite ? '#7c3aed' : null,
                'borderColor' => $isRespite ? 'transparent' : null,
                'extendedProps' => [
                    'client_id' => $shift->client_id,
                    'service_context_id' => $shift->service_context_id,
                    'service_context' => $shift->serviceContext ? $shift->serviceContext->name : null,
                    'is_respite' => $isRespite,
                    'respite_booking_id' => $shift->respite_booking_id,
                    'user_id' => $shift->user_id,
                    'location' => $shift->location,
                    'notes' => $shift->notes,
                    'status' => $shift->status,
                    'shift_type' => $shift->shift_type ?? 'standard',
                    'shift_series_id' => $shift->shift_series_id,
                    'is_recurring' => (bool) $shift->shift_series_id,
                    'recurring_weekdays' => $shift->series?->by_weekday ?? [],
                    'has_active_replacement' => $shift->replacementRequests->isNotEmpty(),
                    'replacement_status' => $shift->replacementRequests->sortByDesc('requested_at')->first()?->status,
                    'is_sleepover' => (bool) $shift->is_sleepover,
                    'is_on_call' => (bool) $shift->is_on_call,
                    'expected_break_minutes' => $shift->expected_break_minutes,
                    'coverage_roles' => $shift->coverage_roles ?? [],
                    'required_licence_class' => $shift->required_licence_class,
                    'required_licence_endorsements' => $shift->required_licence_endorsements ?? [],
                    'tasks_total' => (int) ($shift->tasks_total ?? 0),
                    'tasks_completed' => (int) ($shift->tasks_completed ?? 0),
                    'tasks' => ShiftTaskSupport::payloadsForShift($shift),
                    'timed_tasks' => ShiftTaskSupport::timedPayloadForShift($shift),
                    'incidents_count' => (int) ($shift->incidents_count ?? 0),
                    'is_open_shift' => $shift->user_id === null,
                    'client' => $clientName,
                    'staff' => $staffName,
                    'site_id' => $shift->site_id ?: $shift->client?->site_id,
                    'site_name' => $shift->site?->name ?? $shift->client?->site?->name ?? null,
                    'coverage_state' => $coverage['coverage_state'] ?? null,
                    'coverage_gap_kind' => $coverage['gap_kind'] ?? null,
                    'coverage_recommended_fill_action' => $coverage['recommended_fill_action'] ?? null,
                    'coverage_missing_staff' => $coverage['missing_staff'] ?? 0,
                    'coverage_required_staff' => $coverage['required_staff'] ?? null,
                    'coverage_assigned_staff' => $coverage['assigned_staff'] ?? null,
                    'coverage_window_label' => $coverage['window_label'] ?? null,
                    'coverage_rule_id' => $coverage['rule_id'] ?? null,
                    'coverage_preferred_client_id' => $coverage['preferred_client_id'] ?? null,
                    'coverage_role_shortages' => $coverage['role_shortages'] ?? [],
                    'coverage_planned_role_shortages' => $coverage['planned_role_shortages'] ?? [],
                    'coverage_contradictions' => $coverage['contradictions'] ?? [],
                ],
            ];
        })->values();

        $coverageGapEvents = $coverageWindows
            ->filter(fn (array $window) => ! empty($window['has_actionable_gap']))
            ->map(fn (array $window) => [
                'id' => 'coverage-gap-'.$window['rule_id'].'-'.md5($window['starts_at'].'-'.$window['ends_at']),
                'title' => in_array($window['gap_kind'] ?? null, ['role_unplanned', 'role_open', 'mixed_open', 'mixed_unplanned'], true)
                    ? 'Role coverage gap'
                    : 'Coverage gap',
                'start' => $window['starts_at'],
                'end' => $window['ends_at'],
                'display' => 'background',
                'backgroundColor' => in_array($window['gap_kind'] ?? null, ['role_unplanned', 'role_open', 'mixed_open', 'mixed_unplanned'], true)
                    ? 'rgba(245, 158, 11, 0.12)'
                    : 'rgba(239, 68, 68, 0.12)',
                'borderColor' => in_array($window['gap_kind'] ?? null, ['role_unplanned', 'role_open', 'mixed_open', 'mixed_unplanned'], true)
                    ? 'rgba(245, 158, 11, 0.45)'
                    : 'rgba(239, 68, 68, 0.45)',
                'extendedProps' => [
                    'event_type' => 'coverage_gap',
                    'site_id' => $window['site_id'],
                    'site_name' => $window['site_name'],
                    'coverage_rule_id' => $window['rule_id'] ?? null,
                    'rule_name' => $window['rule_name'],
                    'coverage_state' => $window['coverage_state'],
                    'coverage_gap_kind' => $window['gap_kind'] ?? null,
                    'coverage_recommended_fill_action' => $window['recommended_fill_action'] ?? null,
                    'coverage_missing_staff' => $window['missing_staff'],
                    'coverage_required_staff' => $window['required_staff'],
                    'coverage_assigned_staff' => $window['assigned_staff'],
                    'coverage_window_label' => $window['window_label'],
                    'coverage_preferred_client_id' => $window['preferred_client_id'] ?? null,
                    'coverage_role_shortages' => $window['role_shortages'] ?? [],
                    'coverage_planned_role_shortages' => $window['planned_role_shortages'] ?? [],
                    'coverage_contradictions' => $window['contradictions'] ?? [],
                ],
            ])
            ->values();

        return response()->json($shiftEvents->concat($coverageGapEvents)->values());
    }

    public function storeShift(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
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
            'required_licence_class' => ['nullable', 'string', Rule::in(HrDriverEligibility::LICENCE_CLASSES)],
            'required_licence_endorsements' => ['nullable', 'array'],
            'required_licence_endorsements.*' => ['string', Rule::in(HrDriverEligibility::LICENCE_ENDORSEMENTS)],
            'coverage_reservation_token' => ['nullable', 'string', 'max:120'],
            'tasks' => ['sometimes', 'array'],
            'tasks.*.label' => ['required_with:tasks', 'string', 'max:255'],
            'tasks.*.scheduled_time' => ['nullable', 'date_format:H:i'],
        ]);

        $data = $this->normalizeShiftData($data);
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

        // If not explicitly provided, inherit the client's service context.
        // This keeps service setting consistent for audit trails.
        if (empty($data['service_context_id'])) {
            $data['service_context_id'] = Client::query()
                ->whereKey($data['client_id'])
                ->value('service_context_id');
        }

        // If still not set, apply organisation default service context (if configured).
        if (empty($data['service_context_id'])) {
            $data['service_context_id'] = ServiceContext::defaultId();
        }

        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = Carbon::parse($data['ends_at']);
        $blockingConflicts = $this->shiftConflictService->findBlockingStaffConflicts(
            ! empty($data['user_id']) ? (int) $data['user_id'] : null,
            $startsAt,
            $endsAt,
        );

        abort_unless(
            $blockingConflicts->isEmpty(),
            422,
            $this->shiftConflictService->blockingMessage($blockingConflicts),
        );

        if (! empty($data['user_id'])) {
            $assignee = User::findOrFail((int) $data['user_id']);
            $tempShift = new Shift(Arr::except($data, ['tasks', 'coverage_reservation_token', 'coverage_rule_id']));
            $tempShift->organization_id = $auth->organization_id ?: 1;
            $eligibility = app(ShiftStaffEligibilityService::class)->evaluate($tempShift, $assignee);

            abort_if($eligibility->hasBlocks(), 422, $eligibility->blocking_reasons[0]);
        }

        $reservation = app(CoverageReservationService::class)->validateToken(
            $data['coverage_reservation_token'] ?? null,
            $auth,
            [
                'site_id' => $data['site_id'] ?? null,
                'coverage_requirement_id' => $data['coverage_rule_id'] ?? null,
                'window_starts_at' => $data['starts_at'] ?? null,
                'window_ends_at' => $data['ends_at'] ?? null,
            ],
        );

        if (! $reservation) {
            $reservation = app(CoverageReservationService::class)->reserveForCoveragePayload($auth, $data, 'calendar_store');
        }

        try {
            $shift = DB::transaction(function () use ($auth, $data, $reservation) {
                $shift = Shift::create([
                    ...Arr::except($data, ['tasks', 'coverage_reservation_token', 'coverage_rule_id']),
                    'status' => $data['status'],
                    'created_by' => $auth->id,
                ]);

                app(SiteChecklistScheduler::class)->ensureRunsForShiftLocalDay($shift);

                ShiftTaskSupport::createForShift($shift, $data['tasks'] ?? []);

                app(CoverageReservationService::class)->fulfill($reservation, $shift);

                return $shift;
            });
        } catch (\Throwable $e) {
            app(CoverageReservationService::class)->release($reservation);
            throw $e;
        }

        $shift->load(['client:id,first_name,last_name', 'staff:id,name']);
        app(ShiftTimelineService::class)->syncSnapshot($shift->fresh());

        if ($shift->user_id) {
            app(NotificationService::class)->notifyCrud($auth, 'created', 'shift', $shift, $shift->client, [
                'title' => 'Shift created',
                'body' => $shift->client ? ('Client: '.$shift->client->first_name.' '.$shift->client->last_name) : null,
                'url' => url("/shifts/{$shift->id}"),
                'target_user_ids' => [$shift->user_id],
            ]);
        }

        return response()->json([
            'ok' => true,
            'shift' => $shift,
        ], 201);
    }

    public function updateShift(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.update'), 403);

        // Staff can edit only own shifts unless manageAny
        if (! $auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }

        if (in_array($shift->status, ['completed', 'cancelled'], true)) {
            abort(422, 'This shift is locked and can no longer be edited from the calendar.');
        }

        // Support partial updates (drag/drop resize sends only times)
        $data = $request->validate([
            'client_id' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
            'service_context_id' => ['sometimes', 'nullable', 'integer', 'exists:service_contexts,id'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['sometimes', 'required', 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'nullable', 'in:draft,scheduled'],
            'shift_type' => ['sometimes', 'nullable', 'in:standard,sleepover,on_call,split,travel'],
            'is_sleepover' => ['sometimes', 'nullable', 'boolean'],
            'is_on_call' => ['sometimes', 'nullable', 'boolean'],
            'expected_break_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:720'],
            'coverage_rule_id' => ['sometimes', 'nullable', 'integer', 'exists:site_coverage_requirements,id'],
            'coverage_roles' => ['sometimes', 'nullable', 'array'],
            'coverage_roles.*' => ['string', 'in:caregiver,driver,med_competent'],
            'required_licence_class' => ['sometimes', 'nullable', 'string', Rule::in(HrDriverEligibility::LICENCE_CLASSES)],
            'required_licence_endorsements' => ['sometimes', 'nullable', 'array'],
            'required_licence_endorsements.*' => ['string', Rule::in(HrDriverEligibility::LICENCE_ENDORSEMENTS)],
            'coverage_reservation_token' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tasks' => ['sometimes', 'array'],
            'tasks.*.id' => ['sometimes', 'integer', 'exists:shift_tasks,id'],
            'tasks.*.label' => ['required_with:tasks', 'string', 'max:255'],
            'tasks.*.scheduled_time' => ['nullable', 'date_format:H:i'],
        ]);

        $data = $this->normalizeShiftData($data);
        if (array_key_exists('client_id', $data) || empty($shift->site_id)) {
            $data['site_id'] = Client::query()->whereKey($data['client_id'] ?? $shift->client_id)->value('site_id');
        }
        $this->assertCoverageClientMatchesContext(
            (int) ($data['client_id'] ?? $shift->client_id),
            $data['site_id'] ?? $shift->site_id,
            ! empty($data['coverage_rule_id']) ? (int) $data['coverage_rule_id'] : null,
        );
        app(ShiftStateGuardService::class)->assertEditableFromPlanning($shift, $data['status'] ?? null);

        if ($shift->status === 'in_progress' && array_key_exists('user_id', $data) && empty($data['user_id'])) {
            abort(422, 'In-progress shifts cannot be unassigned from the calendar. Use the replacement workflow instead.');
        }

        // If the client is changed but service_context_id isn't explicitly set,
        // inherit the new client's service context.
        if (array_key_exists('client_id', $data) && ! array_key_exists('service_context_id', $data)) {
            $data['service_context_id'] = Client::query()
                ->whereKey($data['client_id'])
                ->value('service_context_id');
        }

        // If one of starts/ends provided, require both and ensure ends > starts
        $hasStart = array_key_exists('starts_at', $data);
        $hasEnd = array_key_exists('ends_at', $data);

        if ($hasStart || $hasEnd) {
            abort_unless($hasStart && $hasEnd, 422, 'Both starts_at and ends_at are required when updating time.');
            $start = Carbon::parse($data['starts_at']);
            $end = Carbon::parse($data['ends_at']);
            abort_unless($end->greaterThan($start), 422, 'ends_at must be after starts_at.');
        }

        // Conflict check when we have enough data
        $resolvedClientId = $data['client_id'] ?? $shift->client_id;
        $resolvedUserId = $data['user_id'] ?? $shift->user_id;
        $resolvedStart = Carbon::parse($data['starts_at'] ?? $shift->starts_at);
        $resolvedEnd = Carbon::parse($data['ends_at'] ?? $shift->ends_at);

        $blockingConflicts = $this->shiftConflictService->findBlockingStaffConflicts(
            ! empty($resolvedUserId) ? (int) $resolvedUserId : null,
            $resolvedStart,
            $resolvedEnd,
            $shift->id,
        );

        abort_unless(
            $blockingConflicts->isEmpty(),
            422,
            $this->shiftConflictService->blockingMessage($blockingConflicts),
        );

        $eligibilityRelevant = $resolvedUserId && collect([
            'user_id',
            'starts_at',
            'ends_at',
            'required_licence_class',
            'required_licence_endorsements',
        ])->contains(fn (string $key) => array_key_exists($key, $data));

        if ($eligibilityRelevant) {
            $assignee = User::findOrFail((int) $resolvedUserId);
            $tempShift = clone $shift;
            $tempShift->fill(Arr::except($data, ['tasks', 'coverage_reservation_token', 'coverage_rule_id']));
            $eligibility = app(ShiftStaffEligibilityService::class)->evaluate($tempShift, $assignee);

            abort_if($eligibility->hasBlocks(), 422, $eligibility->blocking_reasons[0]);
        }

        // If the client changes and service context is not explicitly set,
        // inherit from the client to keep classification consistent.
        if (array_key_exists('client_id', $data) && ! array_key_exists('service_context_id', $data)) {
            $data['service_context_id'] = Client::query()
                ->whereKey($resolvedClientId)
                ->value('service_context_id');
        }

        if (array_key_exists('status', $data)) {
            $data['status'] = app(ShiftStateGuardService::class)->normalizePlanningStatus(
                $data['status'],
                ! empty($resolvedUserId),
            );
        } elseif (array_key_exists('user_id', $data) && empty($resolvedUserId) && $shift->status === 'scheduled') {
            $data['status'] = 'draft';
        } elseif (array_key_exists('user_id', $data) && ! empty($resolvedUserId) && $shift->status === 'draft') {
            $data['status'] = 'scheduled';
        }

        $reservationContext = [
            ...$data,
            'site_id' => $data['site_id'] ?? $shift->site_id,
            'starts_at' => $data['starts_at'] ?? $shift->starts_at?->toIso8601String(),
            'ends_at' => $data['ends_at'] ?? $shift->ends_at?->toIso8601String(),
            'coverage_roles' => array_key_exists('coverage_roles', $data)
                ? array_values($data['coverage_roles'] ?? [])
                : array_values($shift->coverage_roles ?? []),
        ];
        $reservation = app(CoverageReservationService::class)->validateToken(
            $data['coverage_reservation_token'] ?? null,
            $auth,
            [
                'site_id' => $reservationContext['site_id'] ?? null,
                'coverage_requirement_id' => $data['coverage_rule_id'] ?? null,
                'window_starts_at' => $reservationContext['starts_at'] ?? null,
                'window_ends_at' => $reservationContext['ends_at'] ?? null,
            ],
        );

        if (! $reservation && (
            array_key_exists('user_id', $data)
            || array_key_exists('starts_at', $data)
            || array_key_exists('ends_at', $data)
            || array_key_exists('client_id', $data)
            || array_key_exists('coverage_roles', $data)
        )) {
            $reservation = app(CoverageReservationService::class)->reserveForCoveragePayload($auth, $reservationContext, 'calendar_update');
        }

        $beforeSnapshot = [
            'user_id' => $shift->user_id,
            'starts_at' => $shift->starts_at?->toISOString(),
            'ends_at' => $shift->ends_at?->toISOString(),
            'location' => $shift->location,
            'service_context_id' => $shift->service_context_id,
            'status' => $shift->status,
        ];

        try {
            DB::transaction(function () use ($shift, $data, $reservation) {
                $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);
                $previousStartsAt = $shift->starts_at?->copy();
                $shift->update(Arr::except($data, ['tasks', 'coverage_reservation_token', 'coverage_rule_id']));
                ShiftTaskSupport::clearRemindersForShiftStartChange($shift, $previousStartsAt);

                if (array_key_exists('tasks', $data)) {
                    ShiftTaskSupport::syncForShift($shift, $data['tasks'] ?? []);
                }

                app(CoverageReservationService::class)->fulfill($reservation, $shift);
            });
        } catch (\Throwable $e) {
            app(CoverageReservationService::class)->release($reservation);
            throw $e;
        }

        $shift->load(['client:id,first_name,last_name', 'staff:id,name', 'tasks']);
        app(ShiftTimelineService::class)->syncSnapshot($shift->fresh());

        $afterSnapshot = [
            'user_id' => $shift->user_id,
            'starts_at' => $shift->starts_at?->toISOString(),
            'ends_at' => $shift->ends_at?->toISOString(),
            'location' => $shift->location,
            'service_context_id' => $shift->service_context_id,
            'status' => $shift->status,
        ];

        if ($afterSnapshot !== $beforeSnapshot && $shift->user_id) {
            app(NotificationService::class)->notifyCrud($auth, 'updated', 'shift', $shift, $shift->client, [
                'title' => 'Shift updated',
                'body' => $shift->client ? ('Client: '.$shift->client->first_name.' '.$shift->client->last_name) : null,
                'url' => url("/shifts/{$shift->id}"),
                'target_user_ids' => [$shift->user_id],
            ]);
        }

        return response()->json([
            'ok' => true,
            'shift' => $shift,
        ]);
    }

    protected function normalizeShiftData(array $data): array
    {
        if (array_key_exists('shift_type', $data)) {
            $data['shift_type'] = $data['shift_type'] ?: 'standard';
        }

        if (array_key_exists('is_sleepover', $data)) {
            $data['is_sleepover'] = (bool) $data['is_sleepover'];
        }

        if (array_key_exists('is_on_call', $data)) {
            $data['is_on_call'] = (bool) $data['is_on_call'];
        }

        if (($data['shift_type'] ?? null) === 'sleepover') {
            $data['is_sleepover'] = true;
        }

        if (($data['shift_type'] ?? null) === 'on_call') {
            $data['is_on_call'] = true;
        }

        if (array_key_exists('expected_break_minutes', $data)) {
            $data['expected_break_minutes'] = $data['expected_break_minutes'] !== null && $data['expected_break_minutes'] !== ''
                ? (int) $data['expected_break_minutes']
                : null;
        }

        return $data;
    }

    protected function assertCoverageClientMatchesContext(int $clientId, ?int $siteId, ?int $coverageRuleId = null): void
    {
        $clientSiteId = Client::query()->whereKey($clientId)->value('site_id');

        if ($siteId && (int) $clientSiteId !== (int) $siteId) {
            abort(422, 'The selected planning client does not belong to the site coverage window you are filling.');
        }

        if ($coverageRuleId) {
            $ruleSiteId = SiteCoverageRequirement::query()
                ->whereKey($coverageRuleId)
                ->value('site_id');

            if ($ruleSiteId && (int) $clientSiteId !== (int) $ruleSiteId) {
                abort(422, 'The selected planning client no longer matches the linked site coverage rule.');
            }
        }
    }
}
