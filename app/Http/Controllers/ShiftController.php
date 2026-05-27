<?php

namespace App\Http\Controllers;

use App\Domain\Shifts\Lifecycle\Data\CompleteShiftData;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleSource;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\CustomForm;
use App\Models\CustomFormSubmission;
use App\Models\FleetResidentTransport;
use App\Models\IncidentTemplate;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftReplacementRequest;
use App\Models\ShiftSeries;
use App\Models\ShiftTask;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\CoverageReservationService;
use App\Services\EnhancedMarService;
use App\Services\NotificationService;
use App\Services\ServiceContextResolver;
use App\Services\ShiftAssignmentRecommendationService;
use App\Services\ShiftConflictService;
use App\Services\ShiftCoverageService;
use App\Services\ShiftReplacementService;
use App\Services\ShiftStaffEligibilityService;
use App\Services\ShiftStateGuardService;
use App\Services\ShiftTimelineService;
use App\Services\UserSiteAccessService;
use App\Support\ClientSafetyPayload;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('shifts.viewAny') || $auth->canDo('shifts.viewAssigned')), 403);

        $from = $request->query('from');
        $to = $request->query('to');
        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');

        // Default to current week (Mon–Sun) so the hero week-nav has a sensible anchor.
        if ($from || $to) {
            $filterFrom = $from
                ? Carbon::parse((string) $from, $timezone)->toDateString()
                : Carbon::parse((string) $to, $timezone)->toDateString();
            $filterTo = $to
                ? Carbon::parse((string) $to, $timezone)->toDateString()
                : Carbon::parse((string) $from, $timezone)->toDateString();
        } else {
            $weekStart = now($timezone)->startOfWeek(Carbon::MONDAY);
            $filterFrom = $weekStart->toDateString();
            $filterTo = $weekStart->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();
        }

        $start = Carbon::parse($filterFrom, $timezone)->startOfDay()->utc();
        $end = Carbon::parse($filterTo, $timezone)->endOfDay()->utc();

        $query = Shift::query()
            ->with([
                'client:id,first_name,last_name,site_id',
                'staff:id,name,email',
                'site:id,name,type',
                'tasks:id,shift_id,label,sort_order,is_completed',
            ])
            ->whereBetween('starts_at', [$start, $end])
            ->orderBy('starts_at');

        $this->siteAccess()->applyShiftScope($query, $auth, $this->shiftBypassPermissions());

        // Status: accept either single `status` or `status_ids[]`/`statuses[]` array.
        $statusFilter = (array) ($request->query('statuses', $request->query('status_ids', [])));
        if (! empty($statusFilter)) {
            $query->whereIn('status', array_values(array_filter($statusFilter, fn ($s) => $s !== null && $s !== '')));
        } elseif ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        // Clients: accept either single `client_id` or `client_ids[]` array.
        $clientFilter = (array) $request->query('client_ids', []);
        if (! empty($clientFilter)) {
            $query->whereIn('client_id', array_values(array_filter($clientFilter, fn ($v) => $v !== null && $v !== '')));
        } elseif ($request->filled('client_id')) {
            $query->where('client_id', $request->query('client_id'));
        }

        // Staff: accept either single `user_id` or `user_ids[]` array.
        $staffFilter = (array) $request->query('user_ids', []);
        if (! empty($staffFilter)) {
            $query->whereIn('user_id', array_values(array_filter($staffFilter, fn ($v) => $v !== null && $v !== '')));
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        // Sites: accept either single `site_id` or `site_ids[]` array.
        $siteFilter = (array) $request->query('site_ids', []);
        if (! empty($siteFilter)) {
            $query->whereIn('site_id', array_values(array_filter($siteFilter, fn ($v) => $v !== null && $v !== '')));
        } elseif ($request->filled('site_id')) {
            $query->where('site_id', $request->query('site_id'));
        }

        if ($request->query('assigned') === 'assigned') {
            $query->whereNotNull('user_id');
        } elseif ($request->query('assigned') === 'unassigned') {
            $query->whereNull('user_id');
        }

        if ($request->filled('q')) {
            $q = $request->query('q');
            $searchTerm = '%'.$q.'%';
            $query->where(function ($builder) use ($searchTerm) {
                $builder->where('location', 'like', $searchTerm)
                    ->orWhereHas('client', function ($cq) use ($searchTerm) {
                        $cq->where('first_name', 'like', $searchTerm)
                            ->orWhere('last_name', 'like', $searchTerm);
                    })
                    ->orWhereHas('staff', function ($sq) use ($searchTerm) {
                        $sq->where('name', 'like', $searchTerm)
                            ->orWhere('email', 'like', $searchTerm);
                    });
            });
        }

        if (! $auth->canDo('shifts.manageAny')) {
            // Assigned-only access: only their own shifts
            $query
                ->where('user_id', $auth->id)
                ->visibleToFrontline($auth->organization_id);
        }

        // Week-bounded — show all shifts in range rather than paginating, but cap defensively.
        $rows = $query->limit(500)->get();
        $shifts = ['data' => $rows];

        $clients = $this->siteAccess()->applyClientScope(
            Client::query(),
            $auth,
            $this->shiftBypassPermissions(),
        )
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'service_context_id', 'site_id']);

        $staff = $this->siteAccess()->applyStaffScope(
            User::staff(),
            $auth,
            $this->shiftStaffBypassPermissions(),
        )
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $sitesQuery = $this->siteAccess()->applySiteScope(
            \App\Models\Site::query(),
            $auth,
            $this->shiftBypassPermissions(),
        );
        $sites = $sitesQuery->orderBy('name')->get(['id', 'name', 'type']);

        $serviceContexts = ServiceContext::query()
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'is_active']);

        // Stats (server-side, over the whole filtered week — independent of any client tab filter).
        $todayKey = now($timezone)->toDateString();
        $statsTotal = $rows->count();
        $statsInProgress = $rows->where('status', 'in_progress')->count();
        $statsScheduled = $rows->where('status', 'scheduled')->count();
        $statsCompleted = $rows->where('status', 'completed')->count();
        $statsDraft = $rows->where('status', 'draft')->count();
        $statsCancelled = $rows->where('status', 'cancelled')->count();
        $statsUnassigned = $rows->whereNull('user_id')->count();
        // "Open" = scheduled with no staff assigned (the prototype's notion of needing cover).
        $statsOpen = $rows->where('status', 'scheduled')->whereNull('user_id')->count();
        $statsToday = $rows->filter(
            fn ($s) => $s->starts_at?->copy()->timezone($timezone)->toDateString() === $todayKey
        )->count();
        $minutes = $rows->sum(function ($s) {
            if (! $s->starts_at || ! $s->ends_at) {
                return 0;
            }
            return $s->starts_at->diffInMinutes($s->ends_at);
        });
        $statsSites = $rows->pluck('site_id')->filter()->unique()->count();
        $statsStaff = $rows->pluck('user_id')->filter()->unique()->count();

        // Normalise array filters back to int arrays for the front end.
        $intArray = fn ($v) => collect((array) $v)
            ->map(fn ($x) => (int) $x)
            ->filter(fn ($x) => $x > 0)
            ->values()
            ->all();
        $stringArray = fn ($v) => collect((array) $v)
            ->map(fn ($x) => (string) $x)
            ->filter(fn ($x) => $x !== '')
            ->values()
            ->all();

        return inertia('operations/shifts/index', [
            'shifts' => $shifts,
            'filters' => [
                'from' => $filterFrom,
                'to' => $filterTo,
                'status' => $request->query('status'),
                'statuses' => $stringArray($request->query('statuses', $request->query('status_ids', []))),
                'client_id' => $request->query('client_id'),
                'client_ids' => $intArray($request->query('client_ids', [])),
                'user_id' => $request->query('user_id'),
                'user_ids' => $intArray($request->query('user_ids', [])),
                'site_id' => $request->query('site_id'),
                'site_ids' => $intArray($request->query('site_ids', [])),
                'assigned' => $request->query('assigned'),
                'q' => $request->query('q'),
            ],
            'clients' => $clients,
            'staff' => $staff,
            'sites' => $sites,
            'serviceContexts' => $serviceContexts,
            'defaultServiceContextId' => ServiceContext::defaultId(),
            'statuses' => ['draft', 'scheduled', 'in_progress', 'completed', 'cancelled'],
            'stats' => [
                'total' => $statsTotal,
                'open' => $statsOpen,
                'today' => $statsToday,
                'in_progress' => $statsInProgress,
                'scheduled' => $statsScheduled,
                'completed' => $statsCompleted,
                'draft' => $statsDraft,
                'cancelled' => $statsCancelled,
                'unassigned' => $statsUnassigned,
                'hours' => (int) round($minutes / 60),
                'sites' => $statsSites,
                'staff' => $statsStaff,
            ],
            'canCreate' => $auth->canDo('shifts.create'),
        ]);
    }

    public function show(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('shifts.viewAny') || $auth->canDo('shifts.viewAssigned')), 403);

        if (! $auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }

        if (! $auth->canDo('shifts.manageAny')) {
            abort_unless(
                Shift::query()
                    ->whereKey($shift->id)
                    ->visibleToFrontline($auth->organization_id)
                    ->exists(),
                404,
            );
        }

        $this->assertCanAccessShift($auth, $shift);

        $shift->load([
            'client:id,first_name,last_name,site_id,risk_level,safeguarding_flag',
            'client.medicalProfile',
            'client.risks',
            'staff:id,name,email',
            'site:id,name,type',
            'tasks',
            'serviceContext:id,name,type,is_active',
            'respiteBooking:id,start_at,end_at,status,cancellation_reason',
        ]);

        $canViewMedications = $auth->canDo('medications.view')
            || $auth->canDo('medications.administer.record')
            || $auth->canDo('clients.update');
        $canRecordMedications = $auth->canDo('medications.administer.record')
            || $auth->canDo('clients.update');
        $canViewForms = $auth->canDo('custom_forms.viewAny')
            || $auth->canDo('custom_forms.submit');
        $canSubmitForms = $auth->canDo('custom_forms.submit');
        $latestReplacement = $shift->replacementRequests()
            ->with([
                'requester:id,name',
                'currentStaff:id,name',
                'replacementStaff:id,name',
                'approver:id,name',
                'canceller:id,name',
                'openPosition.claimer:id,name',
                'openPosition.approver:id,name',
            ])
            ->latest('requested_at')
            ->first();

        $transports = FleetResidentTransport::query()
            ->where('shift_id', $shift->id)
            ->with(['asset:id,name,asset_tag', 'driver:id,name'])
            ->orderByDesc('departed_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $handover = ShiftHandover::query()
            ->where(function ($query) use ($shift) {
                $query->where('outgoing_shift_id', $shift->id)
                    ->orWhere('incoming_shift_id', $shift->id)
                    ->orWhere('client_id', $shift->client_id);
            })
            ->orderByDesc('created_at')
            ->with(['outgoingStaff:id,name', 'incomingStaff:id,name'])
            ->limit(5)
            ->get();

        // Notes linked to this shift
        $notes = \App\Models\TimelineEvent::query()
            ->where('shift_id', $shift->id)
            ->orderByDesc('occurred_at')
            ->with(['actor:id,name'])
            ->limit(100)
            ->get();

        $incidents = ClientIncident::query()
            ->where('shift_id', $shift->id)
            ->with(['reporter:id,name', 'attachments'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $incidentTemplates = IncidentTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $availableForms = collect();
        $formSubmissions = collect();

        if ($canViewForms) {
            $availableForms = CustomForm::query()
                ->when($auth->organization_id, fn ($query) => $query->where('organization_id', $auth->organization_id))
                ->active()
                ->whereIn('form_type', ['general', 'shift', 'care_delivery', 'handover'])
                ->orderBy('name')
                ->get(['id', 'name', 'description', 'form_type', 'schema']);

            $formSubmissions = CustomFormSubmission::query()
                ->where('shift_id', $shift->id)
                ->with(['form:id,name,form_type', 'submitter:id,name'])
                ->orderByDesc('created_at')
                ->limit(25)
                ->get();
        }

        $medicationSummary = null;
        $medicationWitnesses = collect();

        if ($canViewMedications && $shift->client) {
            $mar = app(EnhancedMarService::class)->build(
                $shift->client,
                ($shift->starts_at ?? now())->copy()->startOfDay(),
                now(),
                $shift->id,
            );

            $shiftMedicationSummary = app(EnhancedMarService::class)->getShiftSummary($shift->id);

            $medicationSummary = [
                'stats' => $mar['stats'],
                'allergies' => $mar['allergies'],
                'due' => collect($mar['scheduled'] ?? [])
                    ->filter(fn ($row) => in_array($row['schedule_state'] ?? null, ['due', 'due_soon', 'late', 'missed_auto'], true))
                    ->values()
                    ->take(8)
                    ->all(),
                'prn' => collect($mar['prn'] ?? [])
                    ->values()
                    ->take(6)
                    ->all(),
                'recent_history' => array_slice($shiftMedicationSummary['administrations'] ?? [], 0, 10),
                'by_status' => $shiftMedicationSummary['by_status'] ?? [],
            ];
        }

        if ($canRecordMedications) {
            $medicationWitnesses = $this->siteAccess()->applyStaffScope(
                User::staff(),
                $auth,
                $this->shiftStaffBypassPermissions(),
            )
                ->where(function ($query) {
                    $query->whereHas('roles.permissions', fn ($rolePermissions) => $rolePermissions->where('key', 'medications.controlled.witness'))
                        ->orWhereHas('permissionOverrides', fn ($overrides) => $overrides
                            ->where('permissions.key', 'medications.controlled.witness')
                            ->where('permission_user.allowed', true));
                })
                ->whereDoesntHave('permissionOverrides', fn ($overrides) => $overrides
                    ->where('permissions.key', 'medications.controlled.witness')
                    ->where('permission_user.allowed', false))
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $assignmentCandidates = [];
        if ($auth->canDo('shifts.manageAny') && in_array($shift->status, ['draft', 'scheduled', 'in_progress'], true)) {
            try {
                $assignmentCandidates = app(ShiftAssignmentRecommendationService::class)->forShift(
                    $shift,
                    $auth,
                    8,
                    $this->shiftStaffBypassPermissions(),
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        $coverage = app(ShiftCoverageService::class)->coverageStatusForShift($shift);

        return inertia('operations/shifts/show', [
            'shift' => $shift,
            'handover' => $handover->map(fn ($entry) => [
                'id' => $entry->id,
                'type' => 'handover',
                'occurred_at' => optional($entry->created_at)->toISOString(),
                'subject' => $entry->incomingStaff?->name
                    ? 'Handover to '.$entry->incomingStaff->name
                    : 'Shift handover',
                'body' => $entry->handover_notes,
                'actor' => $entry->outgoingStaff ? ['id' => $entry->outgoingStaff->id, 'name' => $entry->outgoingStaff->name] : null,
            ])->values(),
            'notes' => $notes->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'occurred_at' => optional($e->occurred_at)->toISOString(),
                'subject' => $e->subject,
                'body' => $e->body,
                'meta' => $e->meta ?? [],
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
            ])->values(),
            'incidents' => $incidents,
            'incidentTemplates' => $incidentTemplates,
            'forms' => [
                'available' => $availableForms->map(fn (CustomForm $form) => [
                    'id' => $form->id,
                    'name' => $form->name,
                    'description' => $form->description,
                    'form_type' => $form->form_type ?: 'general',
                    'schema' => $form->schema ?? [],
                ])->values(),
                'submissions' => $formSubmissions->map(fn (CustomFormSubmission $submission) => [
                    'id' => $submission->id,
                    'status' => $submission->status ?? 'submitted',
                    'submitted_at' => optional($submission->created_at)->toISOString(),
                    'data' => $submission->data ?? [],
                    'submitter' => $submission->submitter
                        ? ['id' => $submission->submitter->id, 'name' => $submission->submitter->name]
                        : null,
                    'form' => $submission->form
                        ? [
                            'id' => $submission->form->id,
                            'name' => $submission->form->name,
                            'form_type' => $submission->form->form_type ?: 'general',
                        ]
                        : null,
                ])->values(),
            ],
            'medications' => $medicationSummary,
            'medicationWitnesses' => $medicationWitnesses->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
            ])->values(),
            'client_safety' => $shift->client ? ClientSafetyPayload::forClient($shift->client) : null,
            'links' => [
                'client_care' => $shift->client ? route('operations.clients.care', $shift->client) : null,
            ],
            'transports' => $transports->map(fn (FleetResidentTransport $transport) => [
                'id' => $transport->id,
                'status' => $transport->status,
                'transport_type' => $transport->transport_type,
                'resident_name' => $transport->resident_name,
                'pickup_location' => $transport->pickup_location,
                'dropoff_location' => $transport->dropoff_location,
                'departed_at' => optional($transport->departed_at)->toISOString(),
                'arrived_at' => optional($transport->arrived_at)->toISOString(),
                'asset' => $transport->asset ? [
                    'id' => $transport->asset->id,
                    'name' => $transport->asset->name,
                    'asset_tag' => $transport->asset->asset_tag,
                ] : null,
                'driver' => $transport->driver ? [
                    'id' => $transport->driver->id,
                    'name' => $transport->driver->name,
                ] : null,
            ])->values(),
            'replacementRequest' => $latestReplacement ? [
                'id' => $latestReplacement->id,
                'status' => $latestReplacement->status,
                'reason' => $latestReplacement->reason,
                'notes' => $latestReplacement->notes,
                'required_skills' => $latestReplacement->required_skills ?? [],
                'requested_at' => optional($latestReplacement->requested_at)->toISOString(),
                'claimed_at' => optional($latestReplacement->claimed_at)->toISOString(),
                'approved_at' => optional($latestReplacement->approved_at)->toISOString(),
                'cancelled_at' => optional($latestReplacement->cancelled_at)->toISOString(),
                'requested_by' => $latestReplacement->requester
                    ? ['id' => $latestReplacement->requester->id, 'name' => $latestReplacement->requester->name]
                    : null,
                'current_staff' => $latestReplacement->currentStaff
                    ? ['id' => $latestReplacement->currentStaff->id, 'name' => $latestReplacement->currentStaff->name]
                    : null,
                'replacement_staff' => $latestReplacement->replacementStaff
                    ? ['id' => $latestReplacement->replacementStaff->id, 'name' => $latestReplacement->replacementStaff->name]
                    : null,
                'approved_by' => $latestReplacement->approver
                    ? ['id' => $latestReplacement->approver->id, 'name' => $latestReplacement->approver->name]
                    : null,
                'cancelled_by' => $latestReplacement->canceller
                    ? ['id' => $latestReplacement->canceller->id, 'name' => $latestReplacement->canceller->name]
                    : null,
                'is_active' => in_array($latestReplacement->status, [ShiftReplacementService::REQUESTED, ShiftReplacementService::CLAIMED], true),
                'open_position' => $latestReplacement->openPosition ? [
                    'id' => $latestReplacement->openPosition->id,
                    'status' => $latestReplacement->openPosition->status,
                    'expires_at' => optional($latestReplacement->openPosition->expires_at)->toISOString(),
                    'claimed_by' => $latestReplacement->openPosition->claimer
                        ? ['id' => $latestReplacement->openPosition->claimer->id, 'name' => $latestReplacement->openPosition->claimer->name]
                        : null,
                    'approved_by' => $latestReplacement->openPosition->approver
                        ? ['id' => $latestReplacement->openPosition->approver->id, 'name' => $latestReplacement->openPosition->approver->name]
                        : null,
                ] : null,
            ] : null,
            'assignmentCandidates' => $assignmentCandidates,
            'coverage' => $coverage,
            'linkedTimesheet' => (function () use ($shift) {
                $columns = ['id', 'status', 'work_date', 'starts_at', 'ends_at', 'exported_to_payroll_at', 'payroll_reference'];

                if (Schema::hasColumn('timesheets', 'reconciliation_status')) {
                    $columns[] = 'reconciliation_status';
                }

                $timesheet = Timesheet::where('shift_id', $shift->id)
                    ->select($columns)
                    ->first();

                if ($timesheet && ! array_key_exists('reconciliation_status', $timesheet->getAttributes())) {
                    $timesheet->setAttribute('reconciliation_status', null);
                }

                return $timesheet;
            })(),
            'handoverSummary' => (function () use ($shift) {
                $columns = ['id', 'incoming_staff_id'];

                if (Schema::hasColumn('shift_handovers', 'status')) {
                    $columns[] = 'status';
                }

                if (Schema::hasColumn('shift_handovers', 'observations_summary')) {
                    $columns[] = 'observations_summary';
                }

                $h = ShiftHandover::where('outgoing_shift_id', $shift->id)
                    ->select($columns)
                    ->with(['incomingStaff:id,name'])
                    ->latest()
                    ->first();

                return $h ? [
                    'id' => $h->id,
                    'status' => $h->getAttribute('status'),
                    'incoming_staff_name' => $h->incomingStaff?->name,
                    'observations_summary' => $h->getAttribute('observations_summary'),
                ] : null;
            })(),
            'can' => [
                'add_note' => $auth->canDo('timeline.create'),
                'create_incident' => $auth->canDo('incidents.create'),
                'mark_tasks' => true,
                'view_forms' => $canViewForms,
                'submit_form' => $canSubmitForms,
                'view_medication' => $canViewMedications,
                'record_medication' => $canRecordMedications,
                'request_replacement' => $this->canRequestReplacement($auth, $shift),
                'cancel_replacement' => $latestReplacement ? $this->canCancelReplacement($auth, $latestReplacement) : false,
                'assign_shift' => $auth->canDo('shifts.manageAny'),
                'override_eligibility' => $auth->canDo('shifts.overrideEligibility'),
                'view_transport' => $auth->canDo('fleet.viewAny') || $auth->canDo('assets.viewAny'),
                'record_observation' => $auth->canDo('clinical.observations.record') || $auth->canDo('clinical.observations.recordClinical'),
                'record_clinical_observation' => $auth->canDo('clinical.observations.recordClinical'),
                'record_event' => $auth->canDo('clinical.events.record'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.create'), 403);

        $defaultSiteId = $request->query('site_id');
        $coverageRuleId = $request->query('coverage_rule_id');

        if (! $defaultSiteId && $coverageRuleId) {
            $defaultSiteId = \App\Models\SiteCoverageRequirement::query()
                ->whereKey($coverageRuleId)
                ->value('site_id');
        }

        if ($defaultSiteId) {
            $this->siteAccess()->assertCanAccessSiteId(
                $auth,
                (int) $defaultSiteId,
                $this->shiftBypassPermissions(),
                'You are not authorized to create shifts for that site.',
            );
        }

        if ($request->filled('client_id')) {
            $this->siteAccess()->assertCanAccessClientId(
                $auth,
                (int) $request->query('client_id'),
                $this->shiftBypassPermissions(),
                'You are not authorized to create shifts for that site.',
            );
        }

        $clients = $this->siteAccess()->applyClientScope(
            Client::query(),
            $auth,
            $this->shiftBypassPermissions(),
        )
            ->with('site:id,name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'service_context_id', 'site_id']);
        $staff = $this->siteAccess()->applyStaffScope(
            User::staff(),
            $auth,
            $this->shiftStaffBypassPermissions(),
        )->orderBy('name')->get(['id', 'name', 'email']);

        $serviceContexts = ServiceContext::query()
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'is_active']);

        $defaultClientId = $request->query('client_id');
        $defaultUserId = $request->query('user_id');
        $coverageReservation = null;
        $coverageContext = null;

        if ($defaultSiteId && $request->filled('starts_at') && $request->filled('ends_at')) {
            if ($request->filled('coverage_reservation_token')) {
                $coverageReservation = app(CoverageReservationService::class)->validateToken(
                    (string) $request->query('coverage_reservation_token'),
                    $auth,
                    [
                        'site_id' => (int) $defaultSiteId,
                        'coverage_requirement_id' => $coverageRuleId ? (int) $coverageRuleId : null,
                        'window_starts_at' => (string) $request->query('starts_at'),
                        'window_ends_at' => (string) $request->query('ends_at'),
                    ],
                );
            }

            $coverageContext = $this->coverageContextFromWindow(
                (int) $defaultSiteId,
                (string) $request->query('starts_at'),
                (string) $request->query('ends_at'),
                $coverageRuleId ? (int) $coverageRuleId : null,
            );
        }

        if (! $defaultClientId) {
            $defaultClientId = $coverageContext['preferred_client_id'] ?? null;
        }

        if (! $defaultClientId && $defaultSiteId) {
            $defaultClientId = Client::query()
                ->where('site_id', $defaultSiteId)
                ->orderBy('first_name')
                ->value('id');
        }

        return inertia('operations/shifts/create', [
            'clients' => $clients,
            'staff' => $staff,
            'serviceContexts' => $serviceContexts,
            'defaultServiceContextId' => $request->query('service_context_id') ?? ServiceContext::defaultId(),
            'defaultClientId' => $defaultClientId,
            'defaultSiteId' => $defaultSiteId,
            'defaultUserId' => $defaultUserId,
            'defaultStartsAt' => $request->query('starts_at'),
            'defaultEndsAt' => $request->query('ends_at'),
            'defaultLocation' => $request->query('location'),
            'defaultShiftType' => $request->query('shift_type'),
            'defaultOpenShift' => $request->boolean('open_shift'),
            'defaultRepeatWeekly' => $request->boolean('repeat_weekly'),
            'defaultRepeatEndDate' => $request->query('repeat_end_date'),
            'defaultReturnTo' => $request->query('return_to'),
            'coverageReservationToken' => $coverageReservation?->reservation_token,
            'coverageContext' => $coverageContext ?? [
                'rule_id' => $coverageRuleId,
                'rule_name' => $request->query('coverage_rule_name'),
                'required_staff' => $request->query('coverage_required_staff'),
                'missing_staff' => $request->query('coverage_missing_staff'),
                'site_id' => $defaultSiteId,
                'preferred_client_id' => $defaultClientId,
                'role_shortages' => collect(json_decode((string) $request->query('coverage_role_shortages', '[]'), true) ?: [])->values()->all(),
            ],
        ]);
    }

    /**
     * Lightweight eligibility preview for the create/edit form.
     * Returns EligibilityResult as JSON without persisting anything.
     */
    public function eligibilityPreview(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('shifts.create') || $auth->canDo('shifts.update')), 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'site_id' => ['nullable', 'integer'],
            'shift_type' => ['nullable', 'string'],
            'coverage_roles' => ['nullable', 'array'],
            'shift_id' => ['nullable', 'integer'],
        ]);

        $assignee = User::findOrFail($data['user_id']);
        $this->assertCanAssignShiftToUser($auth, (int) $data['user_id']);
        $tempShift = new Shift([
            'user_id' => $data['user_id'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'site_id' => $data['site_id'] ?? null,
            'shift_type' => $data['shift_type'] ?? 'standard',
            'coverage_roles' => $data['coverage_roles'] ?? [],
        ]);

        // If editing an existing shift, set the ID so conflict detection can exclude it.
        if (! empty($data['shift_id'])) {
            $tempShift->id = (int) $data['shift_id'];
            $tempShift->exists = true;
        }

        $result = app(ShiftStaffEligibilityService::class)->evaluate($tempShift, $assignee);

        return response()->json($result->toArray());
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
            // user_id may be null to create an "open" / unassigned shift for rostering.
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date', 'after_or_equal:today'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
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
            'tasks' => ['sometimes', 'array', 'max:50'],
            'tasks.*.label' => ['required_with:tasks', 'string', 'max:255'],
        ]);

        $data = $this->normalizeShiftData($data);
        $data['site_id'] = $this->resolveSiteIdForPayload($data);
        $this->siteAccess()->assertCanAccessSiteId(
            $auth,
            $data['site_id'],
            $this->shiftBypassPermissions(),
            'You are not authorized to create shifts for that site.',
        );
        $this->assertCoverageClientMatchesContext(
            (int) $data['client_id'],
            $data['site_id'],
            ! empty($data['coverage_rule_id']) ? (int) $data['coverage_rule_id'] : null,
        );
        $data['status'] = app(ShiftStateGuardService::class)->normalizePlanningStatus(
            $data['status'] ?? null,
            ! empty($data['user_id']),
        );

        // Additional validation: shift duration cannot exceed 24 hours
        $startsAt = \Carbon\Carbon::parse($data['starts_at']);
        $endsAt = \Carbon\Carbon::parse($data['ends_at']);
        if ($startsAt->diffInHours($endsAt) > 24) {
            return back()->withErrors([
                'ends_at' => 'Shift duration cannot exceed 24 hours.',
            ])->withInput();
        }

        // Resolve service context using dedicated service
        $data['service_context_id'] = app(ServiceContextResolver::class)
            ->resolveForClient(
                $data['client_id'],
                $data['service_context_id'] ?? null
            );

        // Full eligibility check when assigning staff during creation.
        // Covers conflicts, compliance, fatigue, availability, leave, site, and driver checks.
        if (! empty($data['user_id'])) {
            $this->assertCanAssignShiftToUser($auth, (int) $data['user_id']);
            try {
                $assignee = User::findOrFail($data['user_id']);
                $tempShift = new Shift(Arr::except($data, ['tasks', 'coverage_reservation_token', 'coverage_rule_id']));
                $eligibility = app(ShiftStaffEligibilityService::class)->evaluate($tempShift, $assignee);

                if ($eligibility->hasBlocks()) {
                    return back()->withErrors([
                        'user_id' => $eligibility->blocking_reasons[0] ?? 'This staff member cannot be assigned to this shift.',
                    ])->withInput();
                }

                if ($eligibility->hasWarnings()) {
                    session()->flash('assignment_warnings', $eligibility->warnings);
                }
            } catch (\Throwable $e) {
                Log::warning('Eligibility check failed during shift creation', ['error' => $e->getMessage()]);
            }
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
            $reservation = app(CoverageReservationService::class)->reserveForCoveragePayload($auth, $data, 'shift_store');
        }

        try {
            $shift = DB::transaction(function () use ($auth, $data, $reservation) {
                $shift = Shift::create([
                    ...\Illuminate\Support\Arr::except($data, ['tasks', 'coverage_reservation_token', 'coverage_rule_id']),
                    'status' => $data['status'],
                    'created_by' => $auth->id,
                ]);

                $tasks = collect($data['tasks'] ?? [])
                    ->map(fn ($t, $i) => ['label' => (string) ($t['label'] ?? ''), 'sort_order' => $i])
                    ->filter(fn ($t) => trim($t['label']) !== '')
                    ->values();

                foreach ($tasks as $t) {
                    ShiftTask::create([
                        'shift_id' => $shift->id,
                        'label' => $t['label'],
                        'sort_order' => $t['sort_order'],
                    ]);
                }

                app(CoverageReservationService::class)->fulfill($reservation, $shift);

                return $shift;
            });
        } catch (\Throwable $e) {
            app(CoverageReservationService::class)->release($reservation);
            throw $e;
        }

        $timeline = app(ShiftTimelineService::class);
        $freshShift = $shift->fresh();
        if ($freshShift?->status === 'in_progress') {
            $timeline->recordStarted($freshShift, $auth, $freshShift->actual_starts_at ?? $freshShift->starts_at ?? now());
        } elseif ($freshShift?->status === 'completed') {
            $timeline->recordCompleted($freshShift, $auth, $freshShift->actual_ends_at ?? $freshShift->ends_at ?? now());
        } elseif ($freshShift?->status === 'cancelled') {
            $timeline->recordCancelled($freshShift, $auth);
        }

        // Notify assigned staff only (open shifts have no assignee).
        // Wrapped in try-catch to prevent notification failures from breaking the request.
        if (! empty($shift->user_id)) {
            try {
                $client = Client::query()->find($shift->client_id);
                $targetUserIds = $shift->user_id ? [$shift->user_id] : [];
                app(NotificationService::class)->notifyCrud($request->user(), 'created', 'shift', $shift, $client, [
                    'title' => 'Shift created',
                    'body' => $client ? ("Client: {$client->first_name} {$client->last_name}") : null,
                    'url' => url("/operations/shifts/{$shift->id}"),
                    'target_user_ids' => $targetUserIds,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to send shift creation notification', [
                    'shift_id' => $shift->id,
                    'error' => $e->getMessage(),
                ]);
                // Continue - don't fail the request due to notification issues
            }
        }

        return redirect($data['return_to'] ?? route('operations.shifts.index'))->with('success', 'Shift created.');
    }

    public function duplicate(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.create'), 403);
        $this->assertCanAccessShift($auth, $shift);

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'return_to' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $shift->starts_at || ! $shift->ends_at) {
            return back()->withErrors([
                'shift' => 'Only shifts with a start and end time can be duplicated.',
            ]);
        }

        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $sourceStart = $shift->starts_at->copy()->timezone($timezone);
        $sourceEnd = $shift->ends_at->copy()->timezone($timezone);
        $durationMinutes = $sourceStart->diffInMinutes($sourceEnd);

        if (! empty($data['date'])) {
            $targetDate = Carbon::createFromFormat('Y-m-d', $data['date'], $timezone)->startOfDay();
            $targetStart = $targetDate->copy()->setTime(
                (int) $sourceStart->format('H'),
                (int) $sourceStart->format('i'),
                (int) $sourceStart->format('s'),
            );
        } else {
            $targetStart = $sourceStart->copy();
        }

        $targetEnd = $targetStart->copy()->addMinutes($durationMinutes);
        $period = $shift->rosterPeriod;
        if ($period) {
            $targetStartDate = $targetStart->toDateString();
            $targetEndDate = $targetEnd->copy()->subSecond()->toDateString();
            $periodStart = $period->week_start->toDateString();
            $periodEnd = $period->week_end->toDateString();

            if ($targetStartDate < $periodStart || $targetEndDate > $periodEnd) {
                return back()->withErrors([
                    'date' => "Duplicate stays within the {$periodStart} to {$periodEnd} roster period.",
                ]);
            }
        }

        $copy = DB::transaction(function () use ($auth, $shift, $targetStart, $targetEnd) {
            $copy = new Shift();
            $copy->forceFill([
                'organization_id' => $shift->organization_id,
                'roster_period_id' => $shift->roster_period_id,
                'shift_series_id' => null,
                'client_id' => $shift->client_id,
                'site_id' => $shift->site_id,
                'respite_booking_id' => null,
                'service_context_id' => $shift->service_context_id,
                'user_id' => null,
                'starts_at' => $targetStart->copy()->utc(),
                'ends_at' => $targetEnd->copy()->utc(),
                'actual_starts_at' => null,
                'actual_ends_at' => null,
                'started_by' => null,
                'completed_by' => null,
                'handover_waiver_reason' => null,
                'handover_waived_at' => null,
                'handover_waived_by' => null,
                'location' => $shift->location,
                'notes' => $shift->notes,
                'status' => 'draft',
                'shift_type' => $shift->shift_type,
                'is_sleepover' => $shift->is_sleepover,
                'is_on_call' => $shift->is_on_call,
                'expected_break_minutes' => $shift->expected_break_minutes,
                'coverage_roles' => $shift->coverage_roles,
                'published_at' => null,
                'publish_dirty_at' => null,
                'created_by' => $auth->id,
            ]);
            $copy->save();

            $shift->tasks()
                ->orderBy('sort_order')
                ->get()
                ->each(function (ShiftTask $task) use ($copy): void {
                    ShiftTask::create([
                        'shift_id' => $copy->id,
                        'label' => $task->label,
                        'is_completed' => false,
                        'completed_at' => null,
                        'completed_by' => null,
                        'sort_order' => $task->sort_order,
                    ]);
                });

            return $copy;
        });

        $requestedReturnTo = (string) ($data['return_to'] ?? '');
        $returnTo = str_starts_with($requestedReturnTo, '/') && ! str_starts_with($requestedReturnTo, '//')
            ? $requestedReturnTo
            : route('operations.shifts.edit', $copy);

        return redirect($returnTo)->with('success', 'Shift duplicated as draft.');
    }

    public function edit(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.update'), 403);

        // Staff can edit only own shifts unless manageAny
        if (! $auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }

        $this->assertCanAccessShift($auth, $shift);

        $shift->load(['client:id,first_name,last_name,service_context_id', 'staff:id,name,email', 'tasks', 'serviceContext:id,name,type,is_active']);
        $clients = $this->siteAccess()->applyClientScope(
            Client::query(),
            $auth,
            $this->shiftBypassPermissions(),
        )
            ->with('site:id,name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'service_context_id', 'site_id']);
        $staff = $this->siteAccess()->applyStaffScope(
            User::staff(),
            $auth,
            $this->shiftStaffBypassPermissions(),
        )->orderBy('name')->get(['id', 'name', 'email']);

        $serviceContexts = ServiceContext::query()
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'is_active']);

        return inertia('operations/shifts/edit', [
            'shift' => $shift,
            'clients' => $clients,
            'staff' => $staff,
            'serviceContexts' => $serviceContexts,
            'defaultServiceContextId' => ServiceContext::defaultId(),
        ]);
    }

    public function update(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.update'), 403);
        $originalStatus = $shift->status;
        $notificationSnapshotBefore = [
            'user_id' => $shift->user_id,
            'starts_at' => $shift->starts_at?->toISOString(),
            'ends_at' => $shift->ends_at?->toISOString(),
            'location' => $shift->location,
            'service_context_id' => $shift->service_context_id,
            'status' => $shift->status,
        ];

        if (! $auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }

        $this->assertCanAccessShift($auth, $shift);

        // Lock: completed and cancelled shifts are immutable workflow records.
        if (in_array($shift->status, ['completed', 'cancelled'], true)) {
            return back()->with('error', 'This shift is locked and can no longer be edited.');
        }

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
            // user_id may be null to keep this as an "open" / unassigned shift.
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', 'in:draft,scheduled'],
            'shift_type' => ['nullable', 'in:standard,sleepover,on_call,split,travel'],
            'is_sleepover' => ['nullable', 'boolean'],
            'is_on_call' => ['nullable', 'boolean'],
            'expected_break_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'coverage_rule_id' => ['nullable', 'integer', 'exists:site_coverage_requirements,id'],
            'coverage_roles' => ['nullable', 'array'],
            'coverage_roles.*' => ['string', 'in:caregiver,driver,med_competent'],
            'coverage_reservation_token' => ['nullable', 'string', 'max:120'],
            'series_scope' => ['nullable', 'in:this,future'],
            'tasks' => ['sometimes', 'array', 'max:50'],
            'tasks.*.id' => ['sometimes', 'integer', 'exists:shift_tasks,id'],
            'tasks.*.label' => ['required_with:tasks', 'string', 'max:255'],
            'tasks.*.is_completed' => ['sometimes', 'boolean'],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);

        $data = $this->normalizeShiftData($data);
        if (array_key_exists('client_id', $data) || empty($shift->site_id)) {
            $data['site_id'] = $this->resolveSiteIdForPayload($data, $shift);
        }
        $this->siteAccess()->assertCanAccessSiteId(
            $auth,
            $data['site_id'] ?? $this->siteAccess()->shiftSiteId($shift),
            $this->shiftBypassPermissions(),
            'You are not authorized to update shifts for this site.',
        );
        $this->assertCoverageClientMatchesContext(
            (int) ($data['client_id'] ?? $shift->client_id),
            $data['site_id'] ?? $shift->site_id,
            ! empty($data['coverage_rule_id']) ? (int) $data['coverage_rule_id'] : null,
        );
        app(ShiftStateGuardService::class)->assertEditableFromPlanning($shift, $data['status'] ?? null);
        $seriesScope = $shift->shift_series_id && ($data['series_scope'] ?? 'this') === 'future'
            ? 'future'
            : 'this';
        $originalUserId = $shift->user_id;

        // Additional validation: shift duration cannot exceed 24 hours
        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = Carbon::parse($data['ends_at']);
        if ($startsAt->diffInHours($endsAt) > 24) {
            return back()->withErrors([
                'ends_at' => 'Shift duration cannot exceed 24 hours.',
            ])->withInput();
        }

        if ($seriesScope === 'future' && ! $auth->canDo('shifts.manageAny')) {
            return back()->withErrors([
                'series_scope' => 'Only schedulers or managers can update future occurrences in a recurring series.',
            ])->withInput();
        }

        if ($shift->status === 'in_progress' && array_key_exists('user_id', $data) && empty($data['user_id'])) {
            return back()->withErrors([
                'user_id' => 'In-progress shifts cannot be unassigned from planning edits. Use the replacement workflow instead.',
            ])->withInput();
        }

        if (
            $seriesScope === 'future'
            && $shift->starts_at
            && $startsAt->toDateString() !== $shift->starts_at->copy()->toDateString()
        ) {
            return back()->withErrors([
                'starts_at' => 'Future recurring updates can change the time pattern, but not move this occurrence to a different date.',
            ])->withInput();
        }

        // Resolve service context using dedicated service
        $data['service_context_id'] = app(ServiceContextResolver::class)
            ->resolveForClient(
                $data['client_id'],
                $data['service_context_id'] ?? null
            );

        $resolvedHasAssignee = array_key_exists('user_id', $data)
            ? ! empty($data['user_id'])
            : ! empty($shift->user_id);
        $resolvedCoverageRoles = array_key_exists('coverage_roles', $data)
            ? array_values($data['coverage_roles'] ?? [])
            : array_values($shift->coverage_roles ?? []);

        if (array_key_exists('status', $data)) {
            $data['status'] = app(ShiftStateGuardService::class)->normalizePlanningStatus(
                $data['status'],
                $resolvedHasAssignee,
            );
        } elseif (array_key_exists('user_id', $data)) {
            $data['status'] = $resolvedHasAssignee
                ? ($shift->status === 'draft' ? 'scheduled' : $shift->status)
                : 'draft';
        }

        $reservationContext = [
            ...$data,
            'site_id' => $data['site_id'] ?? $shift->site_id,
            'starts_at' => $data['starts_at'] ?? $shift->starts_at?->toIso8601String(),
            'ends_at' => $data['ends_at'] ?? $shift->ends_at?->toIso8601String(),
            'coverage_roles' => $resolvedCoverageRoles,
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
            $reservation = app(CoverageReservationService::class)->reserveForCoveragePayload($auth, $reservationContext, 'shift_update');
        }

        if ($seriesScope === 'future') {
            if ($shift->status === 'in_progress') {
                return back()->withErrors([
                    'series_scope' => 'Future updates cannot start from an in-progress occurrence. Open a later occurrence in the series instead.',
                ])->withInput();
            }

            try {
                $this->applyFutureSeriesUpdate($shift, $data);
                app(CoverageReservationService::class)->fulfill($reservation, $shift->fresh());
            } catch (\Throwable $e) {
                app(CoverageReservationService::class)->release($reservation);
                throw $e;
            }
        } else {
            // Full eligibility check when staff is assigned or shift times change.
            // Use array_key_exists to distinguish "user_id not sent" from "user_id sent as null (unassign)".
            $explicitlySetUserId = array_key_exists('user_id', $data);
            $resolvedUserId = $explicitlySetUserId ? $data['user_id'] : $shift->user_id;
            $userChanged = $resolvedUserId && ((int) $resolvedUserId !== (int) $shift->user_id);
            $timesChanged = (array_key_exists('starts_at', $data) && $startsAt->ne($shift->starts_at))
                         || (array_key_exists('ends_at', $data) && $endsAt->ne($shift->ends_at));

            if ($resolvedUserId && ($userChanged || $timesChanged)) {
                $this->assertCanAssignShiftToUser($auth, (int) $resolvedUserId);
                try {
                    $assignee = User::findOrFail($resolvedUserId);
                    $evalShift = clone $shift;
                    $evalShift->fill(Arr::except($data, ['tasks', 'series_scope', 'coverage_reservation_token', 'coverage_rule_id']));
                    $eligibility = app(ShiftStaffEligibilityService::class)->evaluate($evalShift, $assignee);

                    if ($eligibility->hasBlocks()) {
                        return back()->withErrors([
                            'user_id' => $eligibility->blocking_reasons[0] ?? 'This staff member cannot be assigned to this shift.',
                        ])->withInput();
                    }

                    if ($eligibility->hasWarnings()) {
                        session()->flash('assignment_warnings', $eligibility->warnings);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Eligibility check failed during shift update', ['error' => $e->getMessage()]);
                }
            } elseif (! $resolvedUserId) {
                // Open shift (no assignee) — only check client-level conflicts for overlaps.
                $conflicts = $this->shiftHasConflict(null, (int) $data['client_id'], $startsAt, $endsAt, $shift->id);
                if ($conflicts) {
                    return back()->withErrors([
                        'starts_at' => 'This client already has another shift during that time.',
                    ])->withInput();
                }
            }

            try {
                DB::transaction(function () use ($shift, $data, $reservation) {
                    $lockedShift = Shift::query()->lockForUpdate()->findOrFail($shift->id);
                    $lockedShift->update(Arr::except($data, ['tasks', 'series_scope', 'coverage_reservation_token', 'coverage_rule_id']));

                    if (array_key_exists('tasks', $data)) {
                        $this->syncShiftTasks($lockedShift, $data['tasks'] ?? []);
                    }

                    app(CoverageReservationService::class)->fulfill($reservation, $lockedShift);
                });
            } catch (\Throwable $e) {
                app(CoverageReservationService::class)->release($reservation);
                throw $e;
            }
        }

        $freshShift = $shift->fresh();
        if ($freshShift) {
            $timeline = app(ShiftTimelineService::class);

            if ($originalStatus !== 'in_progress' && $freshShift->status === 'in_progress') {
                $timeline->recordStarted($freshShift, $auth, $freshShift->actual_starts_at ?? $freshShift->starts_at ?? now());
            }

            if ($originalStatus !== 'completed' && $freshShift->status === 'completed') {
                $timeline->recordCompleted($freshShift, $auth, $freshShift->actual_ends_at ?? $freshShift->ends_at ?? now());
            }

            if ($originalStatus !== 'cancelled' && $freshShift->status === 'cancelled') {
                $timeline->recordCancelled($freshShift, $auth);
            }

            if (
                $freshShift->user_id
                && $originalUserId
                && (int) $freshShift->user_id !== (int) $originalUserId
            ) {
                app(ShiftReplacementService::class)->resolveFromManualAssignment($freshShift, (int) $freshShift->user_id, $auth);
            }
        }

        // Notify assigned staff - wrapped in try-catch to prevent failures from breaking the request
        $shouldNotifyUpdate = $freshShift && [
            'user_id' => $freshShift->user_id,
            'starts_at' => $freshShift->starts_at?->toISOString(),
            'ends_at' => $freshShift->ends_at?->toISOString(),
            'location' => $freshShift->location,
            'service_context_id' => $freshShift->service_context_id,
            'status' => $freshShift->status,
        ] !== $notificationSnapshotBefore;

        try {
            $client = Client::query()->find($shift->client_id);
            $targetUserIds = $shift->user_id ? [$shift->user_id] : [];
            if ($shouldNotifyUpdate) {
                app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'shift', $shift, $client, [
                    'title' => 'Shift updated',
                    'body' => $client ? ("Client: {$client->first_name} {$client->last_name}") : null,
                    'url' => url("/operations/shifts/{$shift->id}"),
                    'target_user_ids' => $targetUserIds,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send shift update notification', [
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
            ]);
        }

        $returnTo = is_string($data['return_to'] ?? null) && $data['return_to'] !== ''
            ? $data['return_to']
            : route('operations.shifts.index');

        return redirect($returnTo)->with(
            'success',
            $seriesScope === 'future' ? 'Recurring shift series updated.' : 'Shift updated.',
        );
    }

    public function start(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.update'), 403);
        $this->assertCanAccessShift($auth, $shift);

        if (! $auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }

        try {
            app(ShiftLifecycleService::class)->start(
                $shift,
                $auth,
                now(),
                ShiftLifecycleSource::Manual,
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', 'Shift started.');
    }

    public function complete(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.update'), 403);
        $this->assertCanAccessShift($auth, $shift);

        if (! $auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }

        $data = $request->validate([
            'final_note_subject' => ['nullable', 'string', 'max:255'],
            // Required only if no other shift notes exist.
            'final_note_body' => ['nullable', 'string', 'max:20000'],
            'allow_incomplete_tasks' => ['nullable', 'boolean'],
            'incomplete_tasks_reason' => ['nullable', 'string', 'max:2000'],
            'handover_waiver_reason' => ['nullable', 'string', 'max:2000'],
            'ended_early_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $wasCompleted = $shift->status === 'completed';
        $hadTimesheet = $wasCompleted
            && Timesheet::where('shift_id', $shift->id)
                ->where('user_id', $shift->user_id)
                ->exists();

        if ($wasCompleted && $hadTimesheet) {
            return back()->with('success', 'Shift already completed.');
        }

        $lifecycle = app(ShiftLifecycleService::class);
        $completedShift = $lifecycle->complete($shift, $auth, CompleteShiftData::fromManualRequest($data));
        $timesheetResult = $lifecycle->lastDraftTimesheetResult();

        // Notify manager if timesheet creation failed.
        if (! $timesheetResult['success']) {
            $this->notifyTimesheetCreationFailure($completedShift, $timesheetResult['reason'] ?? 'Unknown error');
        }

        if ($wasCompleted) {
            return $timesheetResult['success']
                ? back()->with('success', 'Shift already completed. Missing draft timesheet has been created.')
                : back()->with('warning', 'Shift already completed, but timesheet creation failed and requires follow-up.');
        }

        $flashKey = $timesheetResult['success'] ? 'success' : 'warning';
        $flashMessage = $timesheetResult['success']
            ? 'Shift completed. Draft timesheet created.'
            : 'Shift completed, but timesheet creation failed and requires follow-up.';

        return back()->with($flashKey, $flashMessage);
    }

    public function cancelOccurrence(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.manageAny'), 403);
        $this->assertCanAccessShift($auth, $shift);

        if ($shift->status === 'cancelled') {
            return back()->with('success', 'Shift is already cancelled.');
        }

        app(ShiftLifecycleService::class)->cancel($shift, $auth);

        return back()->with('success', 'Shift occurrence cancelled.');
    }

    public function broadcastNeedsCover(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.manageAny'), 403);
        $this->assertCanAccessShift($auth, $shift);

        if ($shift->user_id) {
            return back()->with('error', 'Only unassigned shifts can be broadcast for cover.');
        }

        if (in_array($shift->status, ['completed', 'cancelled'], true)) {
            return back()->with('error', 'Locked shifts cannot be broadcast.');
        }

        if (! $shift->starts_at || ! $shift->ends_at) {
            return back()->with('error', 'Shift must have a start and end time before broadcasting.');
        }

        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $eligibility = app(ShiftStaffEligibilityService::class);

        try {
            $candidates = $eligibility->candidatesFor($shift);
        } catch (\Throwable $e) {
            Log::warning('Broadcast candidate enumeration failed', [
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Unable to enumerate eligible candidates for broadcast.');
        }

        $tz = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $start = $shift->starts_at->copy()->timezone($tz);
        $end = $shift->ends_at->copy()->timezone($tz);
        $clientName = $shift->client
            ? trim($shift->client->first_name.' '.$shift->client->last_name)
            : null;

        $sent = 0;
        foreach ($candidates as $candidate) {
            try {
                $check = $eligibility->evaluate($shift, $candidate);
            } catch (\Throwable $e) {
                continue;
            }

            if ($check->hasBlocks()) {
                continue;
            }

            try {
                $candidate->notify(new \App\Notifications\ShiftBroadcastNotification(
                    shiftId: $shift->id,
                    shiftDate: $start->format('D j M Y'),
                    shiftTime: $start->format('H:i').' – '.$end->format('H:i'),
                    clientName: $clientName,
                    siteName: $shift->site?->name,
                    message: $data['message'] ?? null,
                ));
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Broadcast notify failed', [
                    'shift_id' => $shift->id,
                    'user_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($sent === 0) {
            return back()->with('warning', 'No eligible candidates were notified.');
        }

        return back()->with('success', "Broadcast sent to {$sent} eligible staff.");
    }

    public function promoteToSeries(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.manageAny'), 403);
        $this->assertCanAccessShift($auth, $shift);

        if (! in_array($shift->status, ['draft', 'scheduled'], true)) {
            return back()->with('error', 'Only draft or scheduled shifts can be promoted to a recurring series.');
        }

        if ($shift->shift_series_id) {
            return back()->with('error', 'This shift is already part of a recurring series.');
        }

        if (! $shift->starts_at || ! $shift->ends_at) {
            return back()->with('error', 'Source shift must have a start and end time.');
        }

        $data = $request->validate([
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['integer', 'min:0', 'max:6'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);

        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $sourceStart = $shift->starts_at->copy()->timezone($timezone);
        $sourceEnd = $shift->ends_at->copy()->timezone($timezone);
        $endDate = \Carbon\Carbon::createFromFormat('Y-m-d', $data['end_date'], $timezone)->endOfDay();

        $startDate = $sourceStart->copy()->startOfDay();
        if ($endDate->lt($startDate)) {
            return back()->withErrors([
                'end_date' => 'End date must be on or after the source shift date.',
            ]);
        }

        $series = DB::transaction(function () use ($shift, $auth, $sourceStart, $sourceEnd, $startDate, $endDate, $timezone, $data) {
            $series = ShiftSeries::create([
                'client_id' => $shift->client_id,
                'site_id' => $shift->site_id,
                'service_context_id' => $shift->service_context_id,
                'user_id' => $shift->user_id,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'timezone' => $timezone,
                'by_weekday' => array_values(array_unique(array_map('intval', $data['weekdays']))),
                'starts_time' => $sourceStart->format('H:i:s'),
                'ends_time' => $sourceEnd->format('H:i:s'),
                'location' => $shift->location,
                'notes' => $shift->notes,
                'status' => 'active',
                'shift_type' => $shift->shift_type ?? 'standard',
                'is_sleepover' => (bool) $shift->is_sleepover,
                'is_on_call' => (bool) $shift->is_on_call,
                'expected_break_minutes' => $shift->expected_break_minutes,
                'coverage_roles' => $shift->coverage_roles,
                'created_by' => $auth->id,
            ]);

            $shift->forceFill(['shift_series_id' => $series->id])->save();

            return $series;
        });

        return back()->with('success', "Shift promoted to recurring series (series #{$series->id}). Future occurrences will need to be generated separately.");
    }

    public function publishShift(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.manageAny'), 403);
        $this->assertCanAccessShift($auth, $shift);

        if ($shift->status !== 'draft') {
            return back()->with('error', 'Only draft shifts can be published.');
        }

        $hasAssignee = ! empty($shift->user_id);

        $shift->forceFill([
            'status' => $hasAssignee ? 'scheduled' : 'draft',
            'published_at' => now(),
            'publish_dirty_at' => null,
        ])->save();

        $message = $hasAssignee
            ? 'Shift published — staff will see it in their roster.'
            : 'Shift published as open — visible for assignment.';

        return back()->with('success', $message);
    }

    public function reopenOccurrence(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.manageAny'), 403);
        $this->assertCanAccessShift($auth, $shift);

        if (! in_array($shift->status, ['cancelled', 'completed'], true)) {
            return back()->with('error', 'Only cancelled or completed occurrences can be reopened.');
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        // Completed shifts require an audit reason. Cancelled shifts may
        // omit it (the original cancel event already carries its own
        // reason).
        if ($shift->status === 'completed' && empty(trim((string) ($data['reason'] ?? '')))) {
            return back()->withErrors([
                'reason' => 'A correction reason is required to reopen a completed shift.',
            ]);
        }

        app(ShiftLifecycleService::class)->reopen($shift, $auth, $data['reason'] ?? null);

        $message = $shift->status === 'completed'
            ? 'Shift occurrence reopened for correction.'
            : 'Shift occurrence reopened.';

        return back()->with('success', $message);
    }

    protected function notifyTimesheetCreationFailure(Shift $shift, string $reason): void
    {
        $staffProfile = \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $shift->user_id)
            ->where('is_active', true)
            ->first(['manager_user_id']);

        $manager = $staffProfile?->manager_user_id
            ? \App\Models\User::find($staffProfile->manager_user_id)
            : null;

        if (! $manager) {
            $manager = \App\Models\User::whereHas('roles', fn ($q) => $q->where('name', 'provider_manager'))
                ->first();
        }

        if (! $manager) {
            return;
        }

        $manager->notify(new \App\Notifications\TimesheetCreationFailedNotification(
            shiftId: $shift->id,
            staffName: $shift->staff?->name ?? 'Unknown',
            shiftDate: $shift->starts_at?->format('D j M, g:i A') ?? 'Unknown',
            siteName: $shift->site?->name ?? 'Unknown site',
            reason: $reason,
        ));
    }

    protected function normalizeShiftData(array $data): array
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

    public function assign(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.manageAny'), 403);
        $this->assertCanAccessShift($auth, $shift);

        // Lock: once completed, immutable.
        if (in_array($shift->status, ['completed', 'cancelled'], true)) {
            return back()->with('error', 'This shift is locked and can no longer be reassigned.');
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'coverage_reservation_token' => ['nullable', 'string', 'max:120'],
            'return_to' => ['nullable', 'string'],
            'override_acknowledged' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        // Only allow assigning staff users
        $this->assertCanAssignShiftToUser($auth, (int) $data['user_id']);

        // Full eligibility check: block assignment if any hard-stop rule fails.
        $overrideData = null;
        try {
            $assignee = User::findOrFail($data['user_id']);
            $eligibility = app(ShiftStaffEligibilityService::class)->evaluate($shift, $assignee);

            if ($eligibility->hasBlocks()) {
                return back()->withErrors([
                    'user_id' => $eligibility->blocking_reasons[0] ?? 'This staff member cannot be assigned to the shift.',
                ])->with('compliance_warnings', $eligibility->toArray()['compliance_warnings'] ?? []);
            }

            if ($eligibility->hasWarnings()) {
                if (empty($data['override_acknowledged'])) {
                    // Return warnings to the UI for manager acknowledgement.
                    return back()
                        ->with('eligibility_result', $eligibility->toArray())
                        ->with('assignment_warnings', $eligibility->warnings)
                        ->withInput();
                }

                // Override acknowledged — validate permission and reason.
                if (! empty($eligibility->overrideable_warnings)) {
                    abort_unless(
                        $auth->canDo('shifts.overrideEligibility'),
                        403,
                        'You do not have permission to override eligibility warnings.',
                    );

                    if (empty(trim($data['override_reason'] ?? ''))) {
                        return back()->withErrors([
                            'override_reason' => 'A reason is required when overriding eligibility warnings.',
                        ])->with('eligibility_result', $eligibility->toArray())->withInput();
                    }

                    $overrideData = [
                        'user_id' => (int) $data['user_id'],
                        'overridden_by' => $auth->id,
                        'override_reason' => trim($data['override_reason']),
                        'rules_overridden' => collect($eligibility->overrideable_warnings)->pluck('rule')->values()->all(),
                        'acknowledged_warnings' => $eligibility->overrideable_warnings,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Don't block assignment if eligibility service fails
            Log::warning('Eligibility check failed during shift assignment', ['error' => $e->getMessage()]);
        }

        $reservation = app(CoverageReservationService::class)->reserveForAssignment($shift, $auth, 'assignment');
        try {
            app(ShiftLifecycleService::class)->assign(
                $shift,
                $auth,
                User::findOrFail($data['user_id']),
                $overrideData,
                $reservation,
            );
        } catch (\Throwable $e) {
            app(CoverageReservationService::class)->release($reservation);
            throw $e;
        }

        return redirect($data['return_to'] ?? url('/operations/rostering'))->with('success', 'Shift assigned.');
    }

    public function autoFill(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.manageAny'), 403);
        $this->assertCanAccessShift($auth, $shift);

        if (in_array($shift->status, ['completed', 'cancelled'], true)) {
            return back()->with('error', 'This shift is locked and cannot be auto-filled.');
        }

        if ($shift->user_id !== null) {
            return back()->with('error', 'This shift is already assigned.');
        }

        $data = $request->validate([
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);

        $eligibility = app(ShiftStaffEligibilityService::class);

        try {
            $candidates = $eligibility->candidatesFor($shift);
        } catch (\Throwable $e) {
            Log::warning('Auto-fill candidate prefilter failed', [
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Auto-fill could not enumerate eligible candidates.');
        }

        $best = null;
        $fallback = null;
        foreach ($candidates as $candidate) {
            try {
                $result = $eligibility->evaluate($shift, $candidate);
            } catch (\Throwable $e) {
                continue;
            }

            if ($result->hasBlocks()) {
                continue;
            }

            if (! $result->hasWarnings()) {
                $best = $candidate;
                break;
            }

            if (! $fallback) {
                $fallback = $candidate;
            }
        }

        $assignee = $best ?? $fallback;

        if (! $assignee) {
            return back()->with('warning', 'No eligible candidate was found for auto-fill.');
        }

        $reservation = app(CoverageReservationService::class)->reserveForAssignment($shift, $auth, 'auto_fill');
        try {
            app(ShiftLifecycleService::class)->assign(
                $shift,
                $auth,
                $assignee,
                null,
                $reservation,
            );
        } catch (\Throwable $e) {
            app(CoverageReservationService::class)->release($reservation);
            throw $e;
        }

        $message = $best
            ? "Auto-filled with {$assignee->name}."
            : "Auto-filled with {$assignee->name} (warnings present — review on the shift detail).";

        $returnTo = $data['return_to'] ?? url('/operations/rostering');

        return redirect($returnTo)->with('success', $message);
    }

    public function unassign(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.manageAny'), 403);
        $this->assertCanAccessShift($auth, $shift);

        if (in_array($shift->status, ['completed', 'cancelled'], true)) {
            return back()->with('error', 'This shift is locked and can no longer be unassigned.');
        }

        if ($shift->status === 'in_progress') {
            return back()->with('error', 'In-progress shifts cannot be unassigned. Use the replacement workflow instead.');
        }

        $returnTo = $request->input('return_to') ?: url('/operations/rostering');
        app(ShiftLifecycleService::class)->unassign($shift, $auth);

        return redirect($returnTo)->with('success', 'Shift unassigned.');
    }

    public function requestReplacement(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canRequestReplacement($auth, $shift), 403);
        $this->assertCanAccessShift($auth, $shift);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'required_skills' => ['nullable', 'array'],
            'required_skills.*' => ['string', 'max:100'],
            'publish_to_job_board' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        app(ShiftReplacementService::class)->request($shift, $auth, $data);

        return back()->with('success', 'Replacement request created.');
    }

    public function cancelReplacement(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth, 403);
        $this->assertCanAccessShift($auth, $shift);

        $replacement = $shift->replacementRequests()
            ->active()
            ->latest('requested_at')
            ->firstOrFail();

        abort_unless($this->canCancelReplacement($auth, $replacement), 403);

        app(ShiftReplacementService::class)->cancel($replacement, $auth);

        return back()->with('success', 'Replacement request cancelled.');
    }

    protected function canRequestReplacement(?User $auth, Shift $shift): bool
    {
        if (! $auth) {
            return false;
        }

        if ($auth->canDo('shifts.manageAny')) {
            return true;
        }

        return $auth->canDo('shifts.update') && (int) $shift->user_id === (int) $auth->id;
    }

    protected function canCancelReplacement(?User $auth, ShiftReplacementRequest $replacement): bool
    {
        if (! $auth) {
            return false;
        }

        if ($auth->canDo('shifts.manageAny')) {
            return true;
        }

        if (! $auth->canDo('shifts.update')) {
            return false;
        }

        return in_array((int) $auth->id, [
            (int) $replacement->requested_by,
            (int) $replacement->current_staff_id,
        ], true);
    }

    protected function applyFutureSeriesUpdate(Shift $shift, array $data): void
    {
        $editedStart = Carbon::parse($data['starts_at']);
        $editedEnd = Carbon::parse($data['ends_at']);

        $futureShifts = Shift::query()
            ->with('tasks')
            ->where('shift_series_id', $shift->shift_series_id)
            ->where('starts_at', '>=', $shift->starts_at)
            ->whereNotIn('status', ['in_progress', 'completed', 'cancelled'])
            ->orderBy('starts_at')
            ->get();

        $conflictDates = [];
        foreach ($futureShifts as $futureShift) {
            [$targetStart, $targetEnd] = $futureShift->id === $shift->id
                ? [$editedStart->copy(), $editedEnd->copy()]
                : $this->buildSeriesWindowForShift($futureShift, $editedStart, $editedEnd);

            if ($this->shiftHasConflict(
                ! empty($data['user_id']) ? (int) $data['user_id'] : null,
                (int) $data['client_id'],
                $targetStart,
                $targetEnd,
                $futureShift->id,
            )) {
                $conflictDates[] = $targetStart->format('D j M g:i A');
            }
        }

        $futureWindows = $futureShifts
            ->map(function (Shift $futureShift) use ($shift, $editedStart, $editedEnd) {
                [$targetStart, $targetEnd] = $futureShift->id === $shift->id
                    ? [$editedStart->copy(), $editedEnd->copy()]
                    : $this->buildSeriesWindowForShift($futureShift, $editedStart, $editedEnd);

                return [
                    'starts_at' => $targetStart,
                    'ends_at' => $targetEnd,
                ];
            })
            ->values()
            ->all();

        if ($this->hasOverlappingFutureSeriesWindows($futureWindows)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'series_scope' => 'Updating future occurrences would make this recurring pattern overlap itself. Adjust the time pattern first.',
            ]);
        }

        if ($conflictDates !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'series_scope' => 'Updating future occurrences would create conflicts on: '.collect($conflictDates)->take(4)->implode(', ').(count($conflictDates) > 4 ? '...' : ''),
            ]);
        }

        DB::transaction(function () use ($shift, $data, $editedStart, $editedEnd, $futureShifts) {
            $series = $shift->series()->first();
            if ($series) {
                $series->update(array_merge(
                    Arr::only($data, [
                        'client_id',
                        'site_id',
                        'service_context_id',
                        'user_id',
                        'location',
                        'notes',
                        'status',
                        'shift_type',
                        'is_sleepover',
                        'is_on_call',
                        'expected_break_minutes',
                        'coverage_roles',
                    ]),
                    [
                        'starts_time' => $editedStart->format('H:i'),
                        'ends_time' => $editedEnd->format('H:i'),
                    ],
                ));
            }

            foreach ($futureShifts as $futureShift) {
                [$targetStart, $targetEnd] = $futureShift->id === $shift->id
                    ? [$editedStart->copy(), $editedEnd->copy()]
                    : $this->buildSeriesWindowForShift($futureShift, $editedStart, $editedEnd);

                $futureShift->update(array_merge(
                    Arr::except($data, ['tasks', 'series_scope', 'coverage_reservation_token', 'coverage_rule_id']),
                    [
                        'site_id' => $this->resolveSiteIdForPayload($data, $futureShift),
                        'starts_at' => $targetStart,
                        'ends_at' => $targetEnd,
                    ],
                ));

                if (array_key_exists('tasks', $data)) {
                    $this->syncShiftTasks($futureShift, $data['tasks'] ?? []);
                }
            }
        });
    }

    protected function buildSeriesWindowForShift(Shift $shift, Carbon $editedStart, Carbon $editedEnd): array
    {
        $durationMinutes = $editedStart->diffInMinutes($editedEnd);
        $targetStart = $shift->starts_at
            ? $shift->starts_at->copy()->setTime(
                (int) $editedStart->format('H'),
                (int) $editedStart->format('i'),
                0,
            )
            : $editedStart->copy();

        return [
            $targetStart,
            $targetStart->copy()->addMinutes($durationMinutes),
        ];
    }

    /**
     * @param  array<int, array{starts_at: Carbon, ends_at: Carbon}>  $windows
     */
    protected function hasOverlappingFutureSeriesWindows(array $windows): bool
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

    protected function shiftHasConflict(?int $userId, int $clientId, Carbon $startsAt, Carbon $endsAt, ?int $ignoreShiftId = null): bool
    {
        return app(ShiftConflictService::class)
            ->findBlockingStaffConflicts($userId, $startsAt, $endsAt, $ignoreShiftId)
            ->isNotEmpty();
    }

    protected function syncShiftTasks(Shift $shift, array $tasks): void
    {
        $existing = $shift->tasks()->get()->keyBy('id');
        $incoming = collect($tasks)
            ->map(fn ($task, $index) => [
                'id' => $task['id'] ?? null,
                'label' => (string) ($task['label'] ?? ''),
                'sort_order' => $index,
            ])
            ->filter(fn ($task) => trim($task['label']) !== '')
            ->values();

        $keepIds = $incoming->pluck('id')->filter()->all();
        if ($keepIds === []) {
            $shift->tasks()->delete();
        } else {
            $shift->tasks()->whereNotIn('id', $keepIds)->delete();
        }

        foreach ($incoming as $task) {
            if ($task['id'] && $existing->has($task['id'])) {
                $existing[$task['id']]->update([
                    'label' => $task['label'],
                    'sort_order' => $task['sort_order'],
                ]);

                continue;
            }

            ShiftTask::create([
                'shift_id' => $shift->id,
                'label' => $task['label'],
                'sort_order' => $task['sort_order'],
            ]);
        }
    }

    protected function resolveSiteIdForPayload(array $data, ?Shift $existingShift = null): ?int
    {
        $clientId = $data['client_id'] ?? $existingShift?->client_id;
        if (! $clientId) {
            return $existingShift?->site_id;
        }

        return Client::query()->whereKey($clientId)->value('site_id');
    }

    protected function assertCoverageClientMatchesContext(int $clientId, ?int $siteId, ?int $coverageRuleId = null): void
    {
        $clientSiteId = Client::query()->whereKey($clientId)->value('site_id');

        if ($siteId && (int) $clientSiteId !== (int) $siteId) {
            abort(422, 'The selected planning client does not belong to the site coverage window you are filling.');
        }

        if ($coverageRuleId) {
            $ruleSiteId = \App\Models\SiteCoverageRequirement::query()
                ->whereKey($coverageRuleId)
                ->value('site_id');

            if ($ruleSiteId && (int) $clientSiteId !== (int) $ruleSiteId) {
                abort(422, 'The selected planning client no longer matches the linked site coverage rule.');
            }
        }
    }

    protected function coverageContextFromWindow(
        int $siteId,
        string $startsAt,
        string $endsAt,
        ?int $coverageRuleId = null,
    ): ?array {
        try {
            $window = app(ShiftCoverageService::class)->findCoverageWindow(
                $siteId,
                Carbon::parse($startsAt),
                Carbon::parse($endsAt),
                $coverageRuleId,
            );
        } catch (\Throwable $e) {
            return null;
        }

        if (! $window) {
            return null;
        }

        return [
            'rule_id' => $window['rule_id'] ?? $coverageRuleId,
            'rule_name' => $window['rule_name'] ?? null,
            'required_staff' => $window['required_staff'] ?? null,
            'missing_staff' => $window['missing_staff'] ?? null,
            'site_id' => $window['site_id'] ?? $siteId,
            'site_name' => $window['site_name'] ?? null,
            'site_client_count' => $window['site_client_count'] ?? null,
            'site_clients' => $window['site_clients'] ?? [],
            'preferred_client_id' => $window['preferred_client_id'] ?? null,
            'preferred_client_name' => $window['preferred_client_name'] ?? null,
            'role_shortages' => $window['planned_role_shortages'] ?? $window['role_shortages'] ?? [],
            'fill_intent' => $window['fill_intent'] ?? null,
            'coverage_slots' => $window['coverage_slots'] ?? [],
        ];
    }

    protected function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }

    /**
     * @return array<int, string>
     */
    protected function shiftBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }

    /**
     * Staff pickers and assignment actions should still respect explicit site assignments
     * unless the user has broader reporting-level bypass access.
     *
     * @return array<int, string>
     */
    protected function shiftStaffBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }

    protected function assertCanAssignShiftToUser(User $auth, int $userId): void
    {
        $query = User::query()
            ->staff()
            ->whereKey($userId);

        $this->siteAccess()->applyStaffScope(
            $query,
            $auth,
            $this->shiftStaffBypassPermissions(),
        );

        abort_unless(
            $query->exists(),
            403,
            'You are not authorized to assign that staff member to this shift.',
        );
    }

    protected function assertCanAccessShift(User $auth, Shift $shift): void
    {
        $this->siteAccess()->assertCanAccessShift(
            $auth,
            $shift,
            $this->shiftBypassPermissions(),
            'You are not authorized to access shifts for this site.',
        );
    }
}
