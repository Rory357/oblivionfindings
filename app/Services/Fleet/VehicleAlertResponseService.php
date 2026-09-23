<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Presenters\FleetVehicleTechnologyProjectionPresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\FleetVehicleAlertAction;
use App\Models\FleetWorkOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\ControlRoom\ControlRoomAlertProvenanceService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The vehicle profile's Alerts & Control Room view.
 *
 * The response record is always the canonical Control Room alert: this
 * service lists the vehicle's alerts in the viewer's alert scope, and acts on
 * them only through ControlRoomAlertLifecycleService (acknowledge, triage,
 * resolve) with Control Room's own permissions and site checks. Escalation
 * records the same level, history and audit as Control Room's escalate
 * action. The vehicle-side ledger (FleetVehicleAlertAction) keeps the triage
 * decision, the Maintenance work created or linked through the PKG-01 report
 * path and follow-up reminders, so one source event, one response and its
 * follow-up stay linked. Resolving never releases the vehicle or closes the
 * linked work.
 */
final class VehicleAlertResponseService
{
    public const DECISIONS = [
        'maintenance' => 'Maintenance assessment required',
        'monitor' => 'Monitor and follow up',
        'escalate' => 'Escalate response',
        'no_action' => 'No further action · evidence recorded',
    ];

    public const OUTCOMES = [
        'incident_confirmed' => 'Incident confirmed',
        'false_alarm' => 'False alarm',
        'duplicate' => 'Duplicate of reviewed incident',
        'unable_to_confirm' => 'Unable to confirm · follow-up assigned',
    ];

    public const ACTIONS = ['acknowledge', 'triage', 'escalate', 'resolve'];

    /** Control Room signal type => [kind key, plain label]. */
    private const SIGNAL_KINDS = [
        'fleet_vehicle_overspeed' => ['overspeed', 'Overspeed threshold'],
        'fleet_vehicle_power_disconnected' => ['power_disconnected', 'Power disconnected'],
        'fleet_vehicle_low_voltage' => ['low_voltage', 'Low vehicle voltage'],
        'fleet_device_tamper' => ['towing', 'Unexpected towing or tamper'],
        'fleet_device_offline' => ['tracker_overdue', 'Tracker overdue'],
        'fleet_device_online' => ['tracker_online', 'Tracker reporting again'],
        'fleet_device_low_battery' => ['tracker_battery', 'Tracker backup battery low'],
        'fleet_geofence_breach' => ['geofence', 'Geofence breach'],
        'fleet_geofence_exit' => ['geofence', 'Left a geofence'],
        'fleet_geofence_enter' => ['geofence', 'Entered a geofence'],
        'fleet_geofence_dwell' => ['geofence', 'Stayed in a geofence'],
        'fleet_vehicle_sos' => ['sos', 'SOS alarm'],
        'fleet_vehicle_overdue' => ['booking_overdue', 'Vehicle overdue'],
    ];

    /** @var array<int,?string> */
    private array $assignees = [];

    public function __construct(
        private readonly SecurityDevicesAccessService $vehicles,
        private readonly ControlRoomAlertAccessService $alertAccess,
        private readonly UserSiteAccessService $siteAccess,
        private readonly ControlRoomAlertProvenanceService $provenance,
        private readonly ControlRoomAlertLifecycleService $lifecycle,
        private readonly MaintenanceReportService $reports,
        private readonly MaintenanceAccessService $maintenance,
        private readonly VehicleReminderService $reminders,
        private readonly VehicleAlertPlanService $plans,
        private readonly VehicleAlertRoutingService $routing,
        private readonly RecordedVehicleEvents $events,
        private readonly VehicleTripHistoryService $trips,
        private readonly VehicleLocationService $location,
        private readonly FleetVehicleTechnologyProjectionPresenter $technology,
    ) {}

    /**
     * The response queue, delivery failures, the draft plan and the recorded
     * events this viewer may send.
     *
     * @return array<string,mixed>
     */
    public function queue(User $viewer, Asset $vehicle, string $filter): array
    {
        $filter = in_array($filter, ['open', 'all', 'resolved'], true) ? $filter : 'open';
        $canView = VehicleDrivingInsightsService::canViewAlerts($viewer);
        $items = [];
        $counts = null;
        if ($canView) {
            $all = $this->scoped($viewer, $vehicle)->with(['sla', 'assignedTo:id,name'])
                ->orderByDesc('triggered_at')->orderByDesc('id')->limit(200)->get()
                ->filter(fn (ControlRoomAlert $alert): bool => $this->provenance->assetMatchesAlert($alert, $vehicle))->values();
            $failures = $this->deliveryFailures($vehicle);
            $ledger = $this->ledgerFor($vehicle, $all);
            $open = $all->filter(fn (ControlRoomAlert $alert): bool => $alert->isActionable());
            $resolved = $all->filter(fn (ControlRoomAlert $alert): bool => $alert->isTerminal());
            $counts = [
                'open' => $open->count() + count($failures),
                'resolved' => $resolved->count(),
                'all' => $all->count() + count($failures),
            ];
            $shown = match ($filter) {
                'open' => $open,
                'resolved' => $resolved,
                default => $all,
            };
            $items = $shown->map(fn (ControlRoomAlert $alert): array => $this->item($alert, $ledger, $viewer))->values()->all();
            if ($filter !== 'resolved') {
                $items = [...$failures, ...$items];
            }
        }

        return [
            'as_of' => now()->toIso8601String(),
            'timezone' => VehicleTripHistoryService::zone(),
            'vehicle' => [
                'id' => (int) $vehicle->getKey(),
                'name' => (string) $vehicle->name,
                'registration_number' => $vehicle->registration_number ?: null,
            ],
            'tracker' => $this->tracker($viewer, $vehicle),
            'filter' => $filter,
            'counts' => $counts,
            'items' => $items,
            'plan' => $this->plans->present($this->plans->current($vehicle)),
            'recorded_events' => $this->routing->canRoute($viewer) ? $this->recordedEvents($viewer, $vehicle) : [],
            'can' => $this->can($viewer, $vehicle),
        ];
    }

    /** @return array<string,mixed> */
    public function detail(User $viewer, Asset $vehicle, int $alertId): array
    {
        abort_unless(VehicleDrivingInsightsService::canViewAlerts($viewer), 403);
        $alert = $this->scoped($viewer, $vehicle)->whereKey($alertId)
            ->with(['sla', 'acknowledgedBy:id,name', 'resolvedBy:id,name'])->first() ?? abort(404);
        abort_unless($this->provenance->assetMatchesAlert($alert, $vehicle), 404);
        $context = $this->provenance->sanitiseContextForRead($alert);
        $signalId = self::positiveInt(data_get($context, 'normalized_data.fleet_signal_id'));
        $actions = FleetVehicleAlertAction::query()->where('asset_id', $vehicle->getKey())
            ->where(fn (Builder $query) => $query->where('control_room_alert_id', $alert->id)
                ->when($signalId !== null, fn (Builder $bySignal) => $bySignal->orWhere('fleet_signal_id', $signalId)))
            ->with(['actor:id,name', 'workOrder:id,reference_number,title,status', 'reminder.owner:id,name'])
            ->orderBy('id')->get();
        $routed = $actions->where('action', 'routed')->values();
        $evidence = (array) ($routed->first()?->evidence ?? []);
        [$kindKey, $kind] = $this->kind($alert);
        $privacyBlocked = data_get($context, 'normalized_data.privacy_blocked') === true;
        $positions = $this->location->positionsVisible($viewer, $vehicle);

        $tripId = self::positiveInt($evidence['trip_id'] ?? data_get($context, 'normalized_data.trip_id'));
        $trip = $tripId !== null && $positions && ! $privacyBlocked
            ? FleetTrip::query()->whereKey($tripId)->where('asset_id', $vehicle->getKey())->first() : null;
        $businessTrip = $trip !== null && ! $trip->is_personal && ! $trip->consent_blocked ? $trip : null;
        $driverView = $businessTrip
            ? ($this->trips->insightData($viewer, $vehicle, null, null, (int) $businessTrip->id, false)['driver_views'][$businessTrip->id] ?? null)
            : null;
        $withheld = ! $positions ? 'access' : ($privacyBlocked
            ? (($evidence['withheld'] ?? null) === 'personal' ? 'personal' : 'consent')
            : ($trip !== null && $trip->is_personal ? 'personal' : null));
        $point = $evidence['location'] ?? data_get($context, 'fleet_context.location');
        $location = $withheld === null && is_array($point) && is_numeric($point['lat'] ?? null) && is_numeric($point['lng'] ?? null)
            ? ['lat' => round((float) $point['lat'], 7), 'lng' => round((float) $point['lng'], 7),
                'basis' => isset($evidence['location']) ? 'recorded_event' : 'vehicle_when_received']
            : null;
        $decision = $actions->where('control_room_alert_id', $alert->id)->where('action', 'triaged')->last();
        $work = $actions->whereIn('action', ['maintenance_created', 'maintenance_linked'])->last()?->workOrder;
        $duplicates = count((array) data_get($context, 'correlated_signals', [])) + max(0, $routed->count() - 1);
        $canManage = $viewer->canDo('controlRoom.alerts.manage');
        $status = (string) $alert->status;
        $decisionKey = $decision?->decision;
        $zone = VehicleTripHistoryService::zone();

        return [
            'id' => (int) $alert->id,
            'reference' => $alert->reference_number ?: 'CR-'.$alert->id,
            'kind' => $kind,
            'kind_key' => $kindKey,
            'severity' => (string) $alert->severity,
            'priority' => self::priority($alert),
            'status' => $status,
            'escalation_level' => (int) $alert->escalation_level,
            'owner' => $this->assignee($alert, $viewer),
            'observed_at' => $alert->triggered_at?->toIso8601String(),
            'received_at' => $alert->created_at?->toIso8601String(),
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            'acknowledge_by' => $alert->sla?->acknowledge_deadline?->toIso8601String(),
            'acknowledge_breached' => (bool) ($alert->sla?->acknowledge_breached ?? false),
            'decision' => $decisionKey && isset(self::DECISIONS[$decisionKey])
                ? ['key' => $decisionKey, 'label' => self::DECISIONS[$decisionKey], 'by' => $decision->actor?->name,
                    'at' => $decision->created_at?->toIso8601String()]
                : null,
            'resolution' => $alert->resolution_code ? (self::OUTCOMES[$alert->resolution_code] ?? str_replace('_', ' ', (string) $alert->resolution_code)) : null,
            'work' => $work ? ['id' => (int) $work->id, 'reference' => $work->reference_number ?: 'Work #'.$work->id,
                'title' => $work->title, 'status' => (string) $work->status] : null,
            'follow_ups' => $actions->where('action', 'follow_up_created')->filter(fn (FleetVehicleAlertAction $action): bool => $action->reminder !== null)
                ->map(fn (FleetVehicleAlertAction $action): array => [
                    'id' => (int) $action->reminder->id,
                    'title' => (string) $action->reminder->title,
                    'due_at' => $action->reminder->due_at?->toIso8601String(),
                    'state' => (string) $action->reminder->state,
                    'owner' => $action->reminder->owner?->name,
                ])->values()->all(),
            'vehicle' => trim(implode(' · ', array_filter([(string) $vehicle->name, $vehicle->registration_number ?: $vehicle->asset_tag]))),
            'device' => $this->tracker($viewer, $vehicle)['model'],
            'location' => $location,
            'location_withheld' => $withheld,
            'driver' => $this->driverEvidence($driverView, $withheld, $tripId !== null),
            'source' => [
                'label' => $businessTrip
                    ? 'Trip #'.$businessTrip->id.' · '.$businessTrip->started_at?->copy()->setTimezone($zone)->format('j M Y, g:i a')
                    : ($signalId !== null ? 'Vehicle signal #'.$signalId : 'Control Room '.str_replace('_', ' ', (string) $alert->source)),
                'trip' => $businessTrip ? [
                    'id' => (int) $businessTrip->id,
                    'reference' => 'Trip #'.$businessTrip->id,
                    'local_date' => $businessTrip->started_at?->copy()->setTimezone($zone)->toDateString(),
                ] : null,
                'sent_by' => $routed->first()?->actor?->name,
            ],
            'correlation' => [
                'duplicates' => $duplicates,
                'signal_id' => $signalId,
            ],
            'evidence' => $this->evidenceText($alert, $context, $evidence),
            'review_source' => $kindKey === 'overspeed' && $businessTrip && isset($evidence['event_key'])
                && ($viewer->canDo('fleet.manage') || $viewer->canDo('fleet.trips.manage'))
                ? ['trip_id' => (int) $businessTrip->id, 'event_key' => (string) $evidence['event_key']] : null,
            'history' => $this->history($alert, $context, $actions),
            'version' => $this->version($alert, $this->lastActionId($alert)),
            'can' => [
                'acknowledge' => $canManage && $status === ControlRoomAlert::STATUS_OPEN,
                'triage' => $canManage && $alert->isActionable(),
                'escalate' => $viewer->canDo('controlRoom.alerts.escalate') && $alert->isActionable(),
                'resolve' => $canManage && in_array($status, [ControlRoomAlert::STATUS_ACK, ControlRoomAlert::STATUS_TRIAGING, ControlRoomAlert::STATUS_CONFIRMED], true),
                'maintenance_create' => $canManage && $this->maintenance->canReport($viewer) && $decisionKey === 'maintenance' && $work === null,
                'maintenance_link' => $canManage && $this->maintenance->canManage($viewer) && $decisionKey === 'maintenance' && $work === null,
                'open_work' => $work !== null && $this->maintenance->canRead($viewer),
                'follow_up' => $this->reminders->canManage($viewer),
                'review_source' => $viewer->canDo('fleet.manage') || $viewer->canDo('fleet.trips.manage'),
            ],
        ];
    }

    /**
     * Acknowledge, triage (with a decision), escalate or resolve a response
     * through Control Room's lifecycle.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed> The response after the change.
     */
    public function act(User $actor, int $assetId, int $alertId, string $action, array $data, string $requestKey): array
    {
        abort_unless(in_array($action, self::ACTIONS, true), 404);
        DrivingScorePolicyStore::assertKey($requestKey);
        Validator::make($data, [
            'note' => ['required', 'string', 'max:2000'],
            'decision' => [$action === 'triage' ? 'required' : 'nullable', 'in:'.implode(',', array_keys(self::DECISIONS))],
            'outcome' => [$action === 'resolve' ? 'required' : 'nullable', 'in:'.implode(',', array_keys(self::OUTCOMES))],
            'expected_version' => ['required', 'string', 'size:64'],
        ], [
            'note.required' => match ($action) {
                'resolve' => 'Record the assessment and follow-up notes.',
                'escalate' => 'Record why this response is escalated.',
                default => 'Record the action notes.',
            },
            'decision.required' => 'Choose the triage decision.',
            'outcome.required' => 'Choose the assessment outcome.',
        ])->validate();
        $note = trim((string) $data['note']);
        $decision = $action === 'triage' ? (string) $data['decision'] : null;
        $outcome = $action === 'resolve' ? (string) $data['outcome'] : null;
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'alert' => $alertId, 'action' => $action,
            'note' => $note, 'decision' => $decision, 'outcome' => $outcome]);

        DB::transaction(function () use ($actor, $assetId, $alertId, $action, $note, $decision, $outcome, $data, $requestKey, $fingerprint): void {
            $current = User::query()->findOrFail($actor->id);
            abort_unless($current->canDo($action === 'escalate' ? 'controlRoom.alerts.escalate' : 'controlRoom.alerts.manage'), 403);
            abort_if($decision === 'escalate' && ! $current->canDo('controlRoom.alerts.escalate'), 403);
            [$vehicle, $alert] = $this->lockResponse($current, $assetId, $alertId);
            if ($this->replayed($vehicle, $requestKey, $fingerprint, self::pastTense($action))) {
                return;
            }
            abort_unless(hash_equals($this->version($alert, $this->lastActionId($alert, true)), (string) $data['expected_version']), 409,
                'This Control Room response changed while you were working on it. Review its latest state.');
            try {
                $alert = match ($action) {
                    'acknowledge' => $this->acknowledge($alert, $current, $note),
                    'triage' => $this->triage($alert, $current, (string) $decision, $note),
                    'escalate' => $this->escalate($alert, $current, $note),
                    'resolve' => $this->resolve($alert, $current, (string) $outcome, $note),
                };
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['note' => $exception->getMessage()]);
            }
            $this->record($vehicle, $alert, $current, self::pastTense($action), $requestKey, $fingerprint, [
                'decision' => $decision, 'outcome' => $outcome, 'note' => $note,
            ]);
            AuditLogger::logOrFail('fleet.vehicle.alert_'.self::pastTense($action), $alert, [
                'actor_id' => $current->id, 'asset_id' => $vehicle->id, 'alert_id' => $alert->id,
                'decision' => $decision, 'outcome' => $outcome, 'status' => $alert->status,
            ]);
        }, 3);

        return $this->fresh($actor, $assetId, $alertId);
    }

    /**
     * Create a Maintenance assessment from the response, or link its open
     * work, through the PKG-01 maintenance report path with the response as
     * the report's source. Needs a "Maintenance assessment required" triage
     * decision; a response links to one piece of work.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function maintenance(User $actor, int $assetId, int $alertId, array $data, string $requestKey): array
    {
        DrivingScorePolicyStore::assertKey($requestKey);
        Validator::make($data, [
            'mode' => ['required', 'in:create,link'],
            'title' => ['required_if:mode,create', 'nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'work_order_id' => ['required_if:mode,link', 'nullable', 'integer', 'min:1'],
            'reason' => ['required_if:mode,link', 'nullable', 'string', 'max:2000'],
            'expected_version' => ['required', 'string', 'size:64'],
        ], [
            'title.required_if' => 'Record what Maintenance should assess.',
            'work_order_id.required_if' => 'Choose the open work record.',
            'reason.required_if' => 'Record why this work covers the response.',
        ])->validate();
        $mode = (string) $data['mode'];
        $values = [
            'mode' => $mode,
            'title' => trim((string) ($data['title'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'work_order_id' => $mode === 'link' ? (int) $data['work_order_id'] : null,
            'reason' => trim((string) ($data['reason'] ?? '')),
        ];
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'alert' => $alertId, 'maintenance' => $values]);

        DB::transaction(function () use ($actor, $assetId, $alertId, $values, $data, $requestKey, $fingerprint): void {
            $current = User::query()->findOrFail($actor->id);
            abort_unless($current->canDo('controlRoom.alerts.manage') && $this->maintenance->canReport($current), 403);
            abort_if($values['mode'] === 'link' && ! $this->maintenance->canManage($current), 403);
            [$vehicle, $alert] = $this->lockResponse($current, $assetId, $alertId);
            $action = $values['mode'] === 'create' ? 'maintenance_created' : 'maintenance_linked';
            if ($this->replayed($vehicle, $requestKey, $fingerprint, $action)) {
                return;
            }
            abort_unless(hash_equals($this->version($alert, $this->lastActionId($alert, true)), (string) $data['expected_version']), 409,
                'This Control Room response changed while you were working on it. Review its latest state.');
            $decision = FleetVehicleAlertAction::query()->where('control_room_alert_id', $alert->id)->where('action', 'triaged')
                ->orderByDesc('id')->lockForUpdate()->value('decision');
            if ($decision !== 'maintenance') {
                throw ValidationException::withMessages(['mode' => 'Triage this response as “Maintenance assessment required” first.']);
            }
            $linked = FleetVehicleAlertAction::query()->where('control_room_alert_id', $alert->id)
                ->whereIn('action', ['maintenance_created', 'maintenance_linked'])->lockForUpdate()->exists();
            abort_if($linked, 409, 'This response already has linked Maintenance work.');
            if ($values['mode'] === 'link') {
                $open = FleetWorkOrder::query()->whereKey($values['work_order_id'])->where('asset_id', $vehicle->id)
                    ->whereNotIn('status', ['completed', 'cancelled'])->exists();
                if (! $open) {
                    throw ValidationException::withMessages(['work_order_id' => 'Choose open Maintenance work for this vehicle.']);
                }
            }
            [, $kind] = $this->kind($alert);
            $reference = $alert->reference_number ?: 'CR-'.$alert->id;
            // The PKG-01 report path: approved site routing, the Coordinator
            // assesses, and the response stays the report's source.
            $order = $this->reports->submit($current, [
                'asset_id' => (int) $vehicle->id,
                'title' => $values['mode'] === 'create' ? $values['title'] : $kind.' · Control Room '.$reference,
                'description' => ($values['mode'] === 'create' ? $values['description'] : $values['reason']) ?: null,
                'priority' => 'medium',
                'observed_at' => $alert->triggered_at ? CarbonImmutable::instance($alert->triggered_at)->utc()->format('Y-m-d H:i:s') : null,
                'estimated_start_date' => null,
                'estimated_end_date' => null,
                'source_type' => 'control_room_alert',
                'source_id' => (int) $alert->id,
                'existing_work_order_id' => $values['work_order_id'],
                'request_key' => $requestKey,
            ]);
            $workReference = $order->reference_number ?: 'Work #'.$order->id;
            $this->lifecycle->appendOperatorNote($alert, $current,
                ($values['mode'] === 'create' ? 'Maintenance assessment created: ' : 'Linked to existing Maintenance work: ')
                .$workReference.' · '.$order->title.($values['reason'] !== '' ? '. '.$values['reason'] : '')
                .'. Resolving this response does not release the vehicle or close the work.',
                'maintenance', OperatorNote::TYPE_ACTION);
            $this->record($vehicle, $alert->fresh(), $current, $action, $requestKey, $fingerprint, [
                'work_order_id' => (int) $order->id,
                'note' => $values['mode'] === 'create' ? ($values['description'] ?: null) : $values['reason'],
            ]);
            AuditLogger::logOrFail('fleet.vehicle.alert_'.$action, $alert, [
                'actor_id' => $current->id, 'asset_id' => $vehicle->id, 'alert_id' => $alert->id, 'work_order_id' => $order->id,
            ]);
        }, 3);

        return $this->fresh($actor, $assetId, $alertId);
    }

    /**
     * A vehicle reminder to follow the response up, linked to it here. The
     * reminder is an ordinary vehicle follow-up: completing it never resolves
     * the response.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function followUp(User $actor, int $assetId, int $alertId, array $data, string $requestKey): array
    {
        DrivingScorePolicyStore::assertKey($requestKey);
        Validator::make($data, [
            'action_text' => ['required', 'string', 'max:1800'],
        ], ['action_text.required' => 'Record the action to take.'], ['action_text' => 'action to take'])->validate();
        $values = [
            'title' => trim((string) ($data['title'] ?? '')),
            'action_text' => trim((string) $data['action_text']),
            'remind_local' => (string) ($data['remind_local'] ?? ''),
            'remind_offset' => $data['remind_offset'] ?? null,
            'owner_user_id' => (int) ($data['owner_user_id'] ?? 0),
            'backup_user_id' => empty($data['backup_user_id']) ? null : (int) $data['backup_user_id'],
            'repeat_months' => (int) ($data['repeat_months'] ?? 0),
        ];
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'alert' => $alertId, 'follow_up' => $values]);

        DB::transaction(function () use ($actor, $assetId, $alertId, $values, $requestKey, $fingerprint): void {
            $current = User::query()->findOrFail($actor->id);
            abort_unless($this->reminders->canManage($current) && VehicleDrivingInsightsService::canViewAlerts($current), 403);
            // A vehicle follow-up; the Control Room record itself is not changed.
            [$vehicle, $alert] = $this->lockResponse($current, $assetId, $alertId, false);
            if ($this->replayed($vehicle, $requestKey, $fingerprint, 'follow_up_created')) {
                return;
            }
            [, $kind] = $this->kind($alert);
            $reference = $alert->reference_number ?: 'CR-'.$alert->id;
            $reminder = $this->reminders->create($current, (int) $vehicle->id, [
                'title' => $values['title'],
                'action_text' => $values['action_text']."\n\nLinked Control Room response: ".$reference.' · '.$kind,
                'source_type' => 'vehicle',
                'source_id' => null,
                'remind_local' => $values['remind_local'],
                'remind_offset' => $values['remind_offset'],
                'repeat_months' => $values['repeat_months'],
                'owner_user_id' => $values['owner_user_id'] ?: null,
                'backup_user_id' => $values['backup_user_id'],
            ], mb_substr($requestKey.':follow-up', 0, 100));
            $this->record($vehicle, $alert, $current, 'follow_up_created', $requestKey, $fingerprint, [
                'reminder_id' => (int) $reminder->id,
                'note' => $values['title'],
            ]);
            AuditLogger::logOrFail('fleet.vehicle.alert_follow_up_created', $alert, [
                'actor_id' => $current->id, 'asset_id' => $vehicle->id, 'alert_id' => $alert->id, 'reminder_id' => $reminder->id,
            ]);
        }, 3);

        return $this->fresh($actor, $assetId, $alertId);
    }

    /** The vehicle's alerts in this viewer's canonical alert scope. */
    private function scoped(User $viewer, Asset $vehicle): Builder
    {
        $query = ControlRoomAlert::query()->where('asset_id', $vehicle->getKey());
        if ($this->alertAccess->canList($viewer)) {
            return $this->alertAccess->applyVisibleScope($query, $viewer);
        }
        if ($viewer->canDo('assets.alerts.view')) {
            // The Fleet alerts page's scope for asset alert viewers.
            return $this->siteAccess->applyAlertScope($query, $viewer, ['reports.viewAny', 'fleet.manage']);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Lock order: vehicle, then the response. A response outside the actor's
     * scope, of another vehicle or with unsafe provenance answers 404.
     *
     * @return array{0:Asset,1:ControlRoomAlert}
     */
    private function lockResponse(User $current, int $assetId, int $alertId, bool $controlRoomWrite = true): array
    {
        $vehicle = $this->vehicles->assignableVehicle($current, $assetId, true) ?? abort(404);
        $alert = ControlRoomAlert::query()->whereKey($alertId)->where('asset_id', $vehicle->id)->lockForUpdate()->first() ?? abort(404);
        abort_unless($this->scoped($current, $vehicle)->whereKey($alert->id)->exists(), 404);
        abort_unless($this->provenance->assetMatchesAlert($alert, $vehicle), 404);
        if ($controlRoomWrite) {
            // The same site check as Control Room's own write endpoints.
            $this->siteAccess->assertCanAccessAlert($current, $alert, ['reports.viewAny']);
        }

        return [$vehicle, $alert];
    }

    private function replayed(Asset $vehicle, string $requestKey, string $fingerprint, string $action): bool
    {
        $prior = FleetVehicleAlertAction::query()->where('asset_id', $vehicle->id)
            ->where('request_key', $requestKey)->lockForUpdate()->first();
        if ($prior === null) {
            return false;
        }
        abort_unless($prior->action === $action && hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
            'This request was already used for a different change.');

        return true;
    }

    private function acknowledge(ControlRoomAlert $alert, User $actor, string $note): ControlRoomAlert
    {
        abort_unless($alert->status === ControlRoomAlert::STATUS_OPEN, 409,
            'This response was already acknowledged. Review its latest state.');

        return $this->lifecycle->acknowledge($alert, $actor, $note);
    }

    private function triage(ControlRoomAlert $alert, User $actor, string $decision, string $note): ControlRoomAlert
    {
        abort_unless($alert->isActionable(), 409, 'This response is closed. Review its latest state.');
        $content = 'Triage decision: '.self::DECISIONS[$decision].'. '.$note;
        if ($alert->status === ControlRoomAlert::STATUS_OPEN) {
            $alert = $this->lifecycle->acknowledge($alert, $actor);
        }
        if ($alert->status === ControlRoomAlert::STATUS_ACK) {
            $alert = $this->lifecycle->startTriage($alert, $actor, $content);
        } else {
            // Already triaging: the new decision is kept as a decision note.
            $this->lifecycle->appendOperatorNote($alert, $actor, $content, 'triage', OperatorNote::TYPE_DECISION);
            $alert = $alert->fresh();
        }

        return $decision === 'escalate' ? $this->escalate($alert, $actor, $note) : $alert;
    }

    /** The same level, history and audit as Control Room's own escalate action. */
    private function escalate(ControlRoomAlert $alert, User $actor, string $reason): ControlRoomAlert
    {
        abort_unless($alert->isActionable(), 409, 'This response is closed and can no longer be escalated.');
        $currentLevel = min(max((int) ($alert->escalation_level ?? 0), 0), ControlRoomAlert::MAX_ESCALATION_LEVEL);
        $requested = $currentLevel + 1;
        $level = max($currentLevel, min($requested, ControlRoomAlert::MAX_ESCALATION_LEVEL));
        $at = now();
        $context = $alert->context ?? [];
        $history = $context['escalation_history'] ?? [];
        $history[] = [
            'level' => $level,
            'requested_level' => $requested,
            'reason' => $reason,
            'escalated_by' => $actor->id,
            'escalated_at' => $at->toIso8601String(),
        ];
        $context['escalation_history'] = $history;
        $alert->forceFill([
            'escalation_level' => $level,
            'escalated_at' => $at,
            'escalated_by_user_id' => $actor->id,
            'context' => $context,
        ])->save();
        AuditLogger::logOrFail('controlRoom.alert.escalate', $alert, [
            'actor_id' => $actor->id,
            'alert_id' => $alert->id,
            'escalation_level' => $level,
            'requested_level' => $requested,
            'escalated_by' => $actor->id,
        ]);

        return $alert;
    }

    private function resolve(ControlRoomAlert $alert, User $actor, string $outcome, string $note): ControlRoomAlert
    {
        abort_unless(in_array($alert->status, [ControlRoomAlert::STATUS_ACK, ControlRoomAlert::STATUS_TRIAGING, ControlRoomAlert::STATUS_CONFIRMED], true), 409,
            $alert->status === ControlRoomAlert::STATUS_OPEN
                ? 'Acknowledge this response before resolving it.'
                : 'This response is already closed. Review its latest state.');
        if ($alert->status === ControlRoomAlert::STATUS_ACK) {
            $alert = $this->lifecycle->startTriage($alert, $actor);
        }

        return $this->lifecycle->resolve($alert, $actor, self::OUTCOMES[$outcome].': '.$note, $outcome);
    }

    /** @param array<string,mixed> $values */
    private function record(Asset $vehicle, ControlRoomAlert $alert, User $actor, string $action, string $requestKey, string $fingerprint, array $values): void
    {
        FleetVehicleAlertAction::query()->create([
            'asset_id' => $vehicle->id,
            'control_room_alert_id' => $alert->id,
            'fleet_signal_id' => self::positiveInt(data_get($alert->context, 'normalized_data.fleet_signal_id')),
            'action' => $action,
            'decision' => $values['decision'] ?? null,
            'outcome' => $values['outcome'] ?? null,
            'work_order_id' => $values['work_order_id'] ?? null,
            'reminder_id' => $values['reminder_id'] ?? null,
            'note' => $values['note'] ?? null,
            'actor_user_id' => $actor->id,
            'request_key' => $requestKey,
            'request_fingerprint' => $fingerprint,
        ]);
    }

    /** @return array<string,mixed> */
    private function fresh(User $actor, int $assetId, int $alertId): array
    {
        $viewer = User::query()->findOrFail($actor->id);
        $vehicle = $this->vehicles->assignableVehicle($viewer, $assetId) ?? abort(404);

        return $this->detail($viewer, $vehicle, $alertId);
    }

    /**
     * A token for optimistic concurrency: it changes with the response's
     * status, escalation, owner, outcome and this vehicle's latest action.
     */
    private function version(ControlRoomAlert $alert, ?int $lastActionId): string
    {
        return hash('sha256', json_encode([
            (int) $alert->id, (string) $alert->status, (int) $alert->escalation_level,
            (int) $alert->assigned_to_user_id, (string) $alert->resolution_code, (int) $lastActionId,
        ], JSON_THROW_ON_ERROR));
    }

    private function lastActionId(ControlRoomAlert $alert, bool $lock = false): ?int
    {
        $query = FleetVehicleAlertAction::query()->where('control_room_alert_id', $alert->id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $id = $query->max('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Ledger rows for the listed responses, keyed by alert id.
     *
     * @param  Collection<int,ControlRoomAlert>  $alerts
     * @return array<int,Collection<int,FleetVehicleAlertAction>>
     */
    private function ledgerFor(Asset $vehicle, Collection $alerts): array
    {
        if ($alerts->isEmpty()) {
            return [];
        }
        $signalToAlert = [];
        foreach ($alerts as $alert) {
            $signalId = self::positiveInt(data_get($alert->context, 'normalized_data.fleet_signal_id'));
            if ($signalId !== null) {
                $signalToAlert[$signalId] = (int) $alert->id;
            }
        }
        $rows = FleetVehicleAlertAction::query()->where('asset_id', $vehicle->getKey())
            ->where(fn (Builder $query) => $query->whereIn('control_room_alert_id', $alerts->pluck('id')->all())
                ->when($signalToAlert !== [], fn (Builder $bySignal) => $bySignal->orWhereIn('fleet_signal_id', array_keys($signalToAlert))))
            ->with('workOrder:id,reference_number,title,status')
            ->orderBy('id')->get();
        $out = [];
        foreach ($rows as $row) {
            $alertId = $row->control_room_alert_id ?? ($signalToAlert[(int) $row->fleet_signal_id] ?? null);
            if ($alertId !== null) {
                $out[(int) $alertId] ??= collect();
                $out[(int) $alertId]->push($row);
            }
        }

        return $out;
    }

    /**
     * @param  array<int,Collection<int,FleetVehicleAlertAction>>  $ledger
     * @return array<string,mixed>
     */
    private function item(ControlRoomAlert $alert, array $ledger, User $viewer): array
    {
        [$kindKey, $kind] = $this->kind($alert);
        $actions = $ledger[(int) $alert->id] ?? collect();
        $decision = $actions->where('action', 'triaged')->last()?->decision;
        $work = $actions->whereIn('action', ['maintenance_created', 'maintenance_linked'])->last()?->workOrder;
        $routed = $actions->where('action', 'routed')->count();

        return [
            'id' => (int) $alert->id,
            'type' => 'response',
            'reference' => $alert->reference_number ?: 'CR-'.$alert->id,
            'kind' => $kind,
            'kind_key' => $kindKey,
            'severity' => (string) $alert->severity,
            'priority' => self::priority($alert),
            'status' => (string) $alert->status,
            'escalation_level' => (int) $alert->escalation_level,
            'owner' => $this->assignee($alert, $viewer),
            'observed_at' => $alert->triggered_at?->toIso8601String(),
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            'acknowledge_by' => $alert->sla?->acknowledge_deadline?->toIso8601String(),
            'acknowledge_breached' => (bool) ($alert->sla?->acknowledge_breached ?? false),
            'duplicates' => count((array) data_get($alert->context, 'correlated_signals', [])) + max(0, $routed - 1),
            'decision' => $decision && isset(self::DECISIONS[$decision]) ? ['key' => $decision, 'label' => self::DECISIONS[$decision]] : null,
            'work' => $work ? ['id' => (int) $work->id, 'reference' => $work->reference_number ?: 'Work #'.$work->id,
                'status' => (string) $work->status] : null,
            'delivery' => null,
            'attempts' => null,
            'signal_id' => self::positiveInt(data_get($alert->context, 'normalized_data.fleet_signal_id')),
        ];
    }

    /**
     * Vehicle signals whose Control Room delivery failed (no response exists
     * yet), newest first.
     *
     * @return list<array<string,mixed>>
     */
    private function deliveryFailures(Asset $vehicle): array
    {
        return FleetSignal::query()->where('asset_id', $vehicle->getKey())
            ->where('occurred_at', '>=', now()->subDays(30))
            ->whereHas('outbox', fn (Builder $outbox) => $outbox->whereIn('status', VehicleAlertRoutingService::FAILED_DELIVERIES))
            ->with('outbox:id,fleet_signal_id,status,attempts')
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(20)
            ->get(['id', 'asset_id', 'signal_type', 'occurred_at', 'created_at'])
            ->map(function (FleetSignal $signal): array {
                $code = 'fleet_'.str_replace('.', '_', (string) $signal->signal_type);
                [$kindKey, $kind] = self::SIGNAL_KINDS[$code] ?? ['other', ucfirst(str_replace(['.', '_'], ' ', (string) $signal->signal_type))];

                return [
                    'id' => 'signal:'.$signal->id,
                    'type' => 'delivery',
                    'reference' => 'Vehicle signal #'.$signal->id,
                    'kind' => $kind,
                    'kind_key' => $kindKey,
                    'severity' => null,
                    'priority' => null,
                    'status' => 'delivery_failed',
                    'escalation_level' => 0,
                    'owner' => null,
                    'observed_at' => $signal->occurred_at?->toIso8601String(),
                    'acknowledged_at' => null,
                    'acknowledge_by' => null,
                    'acknowledge_breached' => false,
                    'duplicates' => 0,
                    'decision' => null,
                    'work' => null,
                    'delivery' => (string) $signal->outbox?->status,
                    'attempts' => (int) ($signal->outbox?->attempts ?? 0),
                    'signal_id' => (int) $signal->id,
                ];
            })->values()->all();
    }

    /**
     * Recorded overspeed episodes and power events of the last 7 days that
     * can be sent to Control Room, with whether each was sent.
     *
     * @return list<array<string,mixed>>
     */
    private function recordedEvents(User $viewer, Asset $vehicle): array
    {
        if (! $this->location->positionsVisible($viewer, $vehicle)) {
            return [];
        }
        $zone = VehicleTripHistoryService::zone();
        $today = CarbonImmutable::now($zone)->startOfDay();
        $since = $today->subDays(VehicleAlertRoutingService::RECENT_DAYS - 1);
        $events = [];
        foreach ($this->events->overspeedEpisodes($viewer, $vehicle, $since->toDateString(), $today->toDateString()) as $episode) {
            $events[] = [
                'source_key' => $episode['source_key'],
                'kind' => 'overspeed',
                'title' => RecordedVehicleEvents::KIND_LABELS['overspeed'],
                'at' => $episode['at'],
                'detail' => $episode['detail'],
                'trip_id' => $episode['trip_id'],
                'trip_reference' => $episode['trip_reference'],
                'event_key' => $episode['event_key'],
                'event_id' => null,
                'peak_kph' => $episode['peak_kph'],
                'seconds' => $episode['seconds'],
            ];
        }
        foreach ($this->events->powerEvents($vehicle, $since) as $event) {
            $events[] = [
                'source_key' => $event['source_key'],
                'kind' => $event['kind'],
                'title' => $event['title'],
                'at' => $event['at'],
                'detail' => $event['detail'],
                'trip_id' => null,
                'trip_reference' => null,
                'event_key' => null,
                'event_id' => $event['event_id'],
                'peak_kph' => null,
                'seconds' => null,
            ];
        }
        usort($events, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
        $states = $this->events->routeStates($vehicle, array_column($events, 'source_key'),
            VehicleDrivingInsightsService::canViewAlerts($viewer));

        return array_map(fn (array $event): array => $event + ['route' => $states[$event['source_key']] ?? null], $events);
    }

    /** @return array{model:?string,family:?string} */
    private function tracker(User $viewer, Asset $vehicle): array
    {
        if (! $this->technology->canView($viewer, $vehicle)) {
            return ['model' => null, 'family' => null];
        }
        $deviceId = FleetTelemetryEvent::query()->where('asset_id', $vehicle->getKey())->whereNotNull('device_id')
            ->orderByDesc('occurred_at')->orderByDesc('id')->value('device_id');
        $device = $deviceId ? Device::query()->find($deviceId, ['id', 'name', 'model']) : null;
        $model = $device ? (trim((string) $device->model) ?: trim((string) $device->name) ?: null) : null;

        return ['model' => $model, 'family' => VehicleTelemetryPresenter::family($model)];
    }

    /** @return array{0:string,1:string} */
    private function kind(ControlRoomAlert $alert): array
    {
        $code = (string) data_get($alert->context, 'signal_type_code', '');
        if (isset(self::SIGNAL_KINDS[$code])) {
            return self::SIGNAL_KINDS[$code];
        }
        $text = strtolower($code.' '.$alert->alert_type);
        if (str_contains($text, 'collision') || str_contains($text, 'crash') || str_contains($text, 'impact')) {
            return ['collision', 'Potential collision'];
        }
        if (str_contains($text, 'diagnostic') || str_contains($text, 'dtc')) {
            return ['diagnostic', 'Vehicle diagnostic fault'];
        }

        return ['other', (string) ($alert->alert_type ?: 'Vehicle alert')];
    }

    private static function priority(ControlRoomAlert $alert): string
    {
        return match (strtolower((string) ($alert->priority ?: $alert->severity))) {
            'critical' => 'Urgent',
            'high' => 'High',
            'medium' => 'Review',
            'low' => 'Low',
            default => 'Information',
        };
    }

    private function assignee(ControlRoomAlert $alert, User $viewer): ?string
    {
        $id = $alert->assigned_to_user_id ? (int) $alert->assigned_to_user_id : null;
        if ($id === null) {
            return null;
        }
        if (! array_key_exists($id, $this->assignees)) {
            $this->assignees[$id] = $this->provenance->safeAssignedTo($alert, $viewer)?->name;
        }

        return $this->assignees[$id];
    }

    /**
     * @param  array<string,mixed>|null  $driver  trip history driver view
     * @return array{label:string,state:string}
     */
    private function driverEvidence(?array $driver, ?string $withheld, bool $hasTrip): array
    {
        if ($withheld !== null && $hasTrip) {
            return ['label' => 'Withheld · trip details are not shown here', 'state' => 'withheld'];
        }

        return match ($driver['state'] ?? 'none') {
            'confirmed' => ['label' => ($driver['name'] ?? 'Confirmed driver').' · confirmed for the whole trip', 'state' => 'confirmed'],
            'recorded' => ['label' => ($driver['name'] ?? 'Recorded driver').' · recorded, not confirmed', 'state' => 'recorded'],
            'hidden' => ['label' => 'A driver is recorded; their name shows only at their sites', 'state' => 'hidden'],
            default => ['label' => 'Unconfirmed · verify source identity', 'state' => 'none'],
        };
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $evidence
     */
    private function evidenceText(ControlRoomAlert $alert, array $context, array $evidence): string
    {
        if (($evidence['detail'] ?? null) !== null) {
            $summary = (string) $evidence['detail'];
            if (is_array($evidence['evaluation'] ?? null)) {
                // The recorded detail says the road limit was not checked; the
                // evaluation that was sent with it says what it was checked against.
                $summary = trim(str_replace(' The road speed limit is not checked.', '', $summary))
                    .' Evaluated: '.$evidence['evaluation']['summary'];
            }

            return $summary;
        }
        $payload = (array) data_get($context, 'signal_payload', []);
        $parts = [];
        if (is_numeric($payload['battery_pct'] ?? null)) {
            $parts[] = 'Tracker battery '.(int) $payload['battery_pct'].'%';
        }
        if (is_numeric($payload['hours_overdue'] ?? null)) {
            $parts[] = (int) $payload['hours_overdue'].' hours overdue';
        }
        if (filled($alert->notes)) {
            $parts[] = trim((string) $alert->notes);
        }

        return $parts === [] ? 'Reported by the vehicle\'s tracker. Review the original signal in Control Room.' : implode(' · ', $parts);
    }

    /**
     * The response's history: its signal, lifecycle, escalations and what
     * was done from the vehicle profile, oldest first.
     *
     * @param  array<string,mixed>  $context
     * @param  Collection<int,FleetVehicleAlertAction>  $actions
     * @return list<array{at:string,text:string}>
     */
    private function history(ControlRoomAlert $alert, array $context, Collection $actions): array
    {
        $entries = [];
        $add = function (mixed $at, string $text) use (&$entries): void {
            if ($at === null || $at === '') {
                return;
            }
            $moment = $at instanceof \DateTimeInterface ? CarbonImmutable::instance($at) : CarbonImmutable::parse((string) $at);
            $entries[] = ['at' => $moment->toIso8601String(), 'sort' => $moment->getTimestamp(), 'text' => $text];
        };
        $add($alert->triggered_at, 'Vehicle signal observed; Control Room response opened');
        $routedSeen = false;
        foreach ($actions as $action) {
            $who = $action->actor?->name ?? 'Someone';
            $text = match ($action->action) {
                'routed' => $routedSeen
                    ? $who.' sent the same recorded event again; added as a duplicate report'
                    : $who.' sent the recorded event to Control Room',
                'acknowledged' => null,
                'triaged' => $who.' · triage decision: '.(self::DECISIONS[$action->decision] ?? $action->decision),
                'escalated' => null,
                'resolved' => null,
                'maintenance_created' => $who.' · Maintenance assessment '.($action->workOrder?->reference_number ?? 'created'),
                'maintenance_linked' => $who.' · linked existing Maintenance work '.($action->workOrder?->reference_number ?? ''),
                'follow_up_created' => $who.' · follow-up reminder “'.($action->note ?? 'Follow-up').'”',
                'delivery_retried' => $who.' retried Control Room delivery',
                default => null,
            };
            if ($action->action === 'routed') {
                $routedSeen = true;
            }
            if ($text !== null) {
                $add($action->created_at, $text);
            }
        }
        foreach ((array) data_get($context, 'correlated_signals', []) as $signal) {
            $add($signal['occurred_at'] ?? null, 'Duplicate report correlated to this response');
        }
        $activity = collect((array) data_get($context, 'activity_log', []));
        foreach ($activity as $entry) {
            $label = match ($entry['transition'] ?? null) {
                'acknowledge' => 'acknowledged',
                'triage' => 'triage',
                'resolution' => 'resolved',
                'maintenance' => 'Maintenance',
                default => 'note',
            };
            $add($entry['created_at'] ?? null, trim(($entry['user_name'] ?? 'Control Room').' · '.$label.': '.($entry['content'] ?? '')));
        }
        if ($alert->acknowledged_at && ! $activity->contains(fn ($entry): bool => ($entry['transition'] ?? null) === 'acknowledge')) {
            $add($alert->acknowledged_at, 'Acknowledged'.($alert->acknowledgedBy ? ' by '.$alert->acknowledgedBy->name : ''));
        }
        $escalations = (array) data_get($context, 'escalation_history', []);
        $names = User::query()->whereIn('id', array_filter(array_map(fn ($row) => is_array($row) ? ($row['escalated_by'] ?? null) : null, $escalations)))
            ->pluck('name', 'id');
        foreach ($escalations as $row) {
            if (is_array($row)) {
                $add($row['escalated_at'] ?? null, 'Escalated to level '.($row['level'] ?? '?')
                    .(isset($row['escalated_by'], $names[$row['escalated_by']]) ? ' by '.$names[$row['escalated_by']] : '')
                    .(filled($row['reason'] ?? null) ? ': '.$row['reason'] : ''));
            }
        }
        if (data_get($context, 'last_escalation_reason') === 'sla_breach') {
            $add(data_get($context, 'last_escalation_at'), 'Response target missed; escalated under Control Room settings');
        }
        if ($alert->resolved_at && ! $activity->contains(fn ($entry): bool => ($entry['transition'] ?? null) === 'resolution')) {
            $add($alert->resolved_at, 'Resolved'.($alert->resolvedBy ? ' by '.$alert->resolvedBy->name : ''));
        }
        usort($entries, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(fn (array $entry): array => ['at' => $entry['at'], 'text' => $entry['text']], $entries);
    }

    /** @return array<string,bool> */
    private function can(User $viewer, Asset $vehicle): array
    {
        return [
            'view' => VehicleDrivingInsightsService::canViewAlerts($viewer),
            'manage' => $viewer->canDo('controlRoom.alerts.manage'),
            'escalate' => $viewer->canDo('controlRoom.alerts.escalate'),
            'route' => $this->routing->canRoute($viewer) && $this->location->positionsVisible($viewer, $vehicle),
            'retry' => $this->routing->canRetry($viewer),
            'plan' => $this->plans->canManage($viewer),
            'follow_up' => $this->reminders->canManage($viewer),
            'view_maintenance' => $this->maintenance->canRead($viewer),
        ];
    }

    private static function pastTense(string $action): string
    {
        return [
            'acknowledge' => 'acknowledged', 'triage' => 'triaged', 'escalate' => 'escalated', 'resolve' => 'resolved',
        ][$action];
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
