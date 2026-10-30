<?php

namespace App\Services\Fleet;

use App\Models\FleetVehicleUnavailablePeriod;
use App\Models\FleetWorkOrder;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * "Schedule service or inspection" from the vehicle calendar. The appointment
 * lives on a Maintenance work order (new or existing): an internal provider
 * plan, an optional recorded confirmation, the purpose as a note, evidence as
 * work attachments, and — when asked — an unavailable period for the window.
 *
 * "Plan linked appointment" passes the due item it came from (a service
 * schedule or compliance record of the vehicle). New work keeps it as its
 * report's source; open work already reported from that item is planned on
 * instead, so planning the same due item again never opens a second record.
 */
class VehicleAppointmentService
{
    /** Due items an appointment can be planned from. */
    public const LINKED_SOURCES = ['service_schedule', 'compliance_record'];

    private const OPEN_STATUSES = ['open', 'in_progress', 'on_hold'];

    public function __construct(
        private readonly MaintenanceAccessService $access,
        private readonly MaintenanceReportService $reports,
        private readonly MaintenanceTransitionService $transitions,
        private readonly MaintenanceAttachmentService $attachments,
        private readonly VehicleUnavailablePeriodService $unavailable,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  list<UploadedFile>  $files
     * @return array{work_order: FleetWorkOrder, unavailable_period: ?FleetVehicleUnavailablePeriod}
     */
    public function schedule(User $actor, int $assetId, array $data, string $requestKey, array $files = []): array
    {
        $current = User::query()->findOrFail($actor->id);
        abort_unless($this->access->canManage($current), 403);
        abort_unless(mb_strlen($requestKey) >= 8 && mb_strlen($requestKey) <= 100, 422, 'A request key is required.');
        $fileIdentities = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ValidationException::withMessages(['files' => 'A file could not be read. Remove it and try again.']);
            }
            $fileIdentities[] = ['name' => $file->getClientOriginalName(), 'sha256' => hash_file('sha256', $file->getRealPath())];
        }
        $fingerprint = MaintenanceFingerprint::of(['actor' => $current->id, 'asset' => $assetId, 'data' => $data, 'files' => $fileIdentities]);

        return DB::transaction(function () use ($current, $assetId, $data, $requestKey, $files, $fingerprint): array {
            $asset = $this->access->asset($current, $assetId, true);
            $receipt = DB::table('fleet_vehicle_appointment_commands')->where('asset_id', $assetId)
                ->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->fingerprint, $fingerprint), 409, 'This request was already used for a different appointment change.');
                $order = $this->access->scopedWorkOrders($current)->whereKey($receipt->work_order_id)->firstOrFail();

                return $this->current($assetId, $order) + ['undo' => $this->undoDescriptor($receipt, $order)];
            }
            $legacy = DB::table('fleet_maintenance_actions as action')
                ->join('fleet_work_orders as work', 'work.id', '=', 'action.work_order_id')
                ->where('work.asset_id', $assetId)->whereIn('action.idempotency_key', [$requestKey.':plan', $requestKey.':cancel'])->exists();
            abort_if($legacy, 409, 'This older appointment request cannot be replayed safely. Reload its work order.');
            $order = empty($data['work_order_id']) ? null : $this->access->scopedWorkOrders($current)
                ->whereKey((int) $data['work_order_id'])->where('asset_id', $assetId)->lockForUpdate()->firstOrFail();
            if ($order) {
                Validator::make($data, ['expected_version' => ['required', 'integer', 'min:0']])->validate();
                abort_unless((int) $data['expected_version'] === (int) $order->version, 409,
                    'This work order changed while you were editing. Reload before changing its appointment.');
            }
            $before = $order ? $this->appointmentSnapshot($order) : null;
            $result = $this->performSchedule($current, $assetId, $data, $requestKey, $files);
            $saved = $result['work_order'];
            $canUndo = ($data['operation'] ?? 'plan') === 'plan' && $before !== null;
            $id = DB::table('fleet_vehicle_appointment_commands')->insertGetId([
                'asset_id' => $assetId, 'work_order_id' => $saved->id, 'actor_user_id' => $current->id,
                'request_key' => $requestKey, 'fingerprint' => $fingerprint,
                'before_json' => $canUndo ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                'resulting_version' => (int) $saved->version, 'created_at' => now(),
            ]);
            AuditLogger::logOrFail('fleet.vehicle.appointment.command', $saved, ['actor_id' => $current->id,
                'asset_id' => $assetId, 'command_id' => $id, 'operation' => $data['operation'] ?? 'plan']);

            return $result + ['undo' => $canUndo ? ['command_id' => $id, 'expected_version' => (int) $saved->version] : null];
        }, 3);
    }

    /** The prior internal plan is restored through Maintenance; external bookings are never sent. */
    public function undo(User $actor, int $assetId, int $commandId, int $expectedVersion, string $requestKey): array
    {
        return DB::transaction(function () use ($actor, $assetId, $commandId, $expectedVersion, $requestKey): array {
            $current = User::query()->findOrFail($actor->id);
            abort_unless($this->access->canManage($current), 403);
            $this->access->asset($current, $assetId, true);
            $receipt = DB::table('fleet_vehicle_appointment_commands')->where('asset_id', $assetId)
                ->where('id', $commandId)->where('actor_user_id', $current->id)->first() ?? abort(404);
            $before = json_decode((string) $receipt->before_json, true);
            abort_unless(is_array($before) && $expectedVersion === (int) $receipt->resulting_version, 409,
                'This appointment change cannot be undone.');

            return $this->schedule($current, $assetId, $before + [
                'work_order_id' => (int) $receipt->work_order_id, 'expected_version' => $expectedVersion,
                'notes' => 'Undo: previous internal appointment restored.',
                'change_reason' => 'Undo appointment command '.$commandId,
            ], $requestKey);
        }, 3);
    }

    private function undoDescriptor(object $receipt, FleetWorkOrder $order): ?array
    {
        return $receipt->before_json !== null && (int) $receipt->resulting_version === (int) $order->version
            ? ['command_id' => (int) $receipt->id, 'expected_version' => (int) $order->version] : null;
    }

    private function appointmentSnapshot(FleetWorkOrder $order): ?array
    {
        $plan = $this->currentPlan((int) $order->id);
        if ($plan === null) {
            return null;
        }
        $payload = json_decode((string) DB::table('fleet_maintenance_actions')->where('work_order_id', $order->id)
            ->where('action_type', 'plan_provider')->orderByDesc('id')->value('payload_json'), true);
        $start = CarbonImmutable::parse($plan['starts_at'])->setTimezone('Pacific/Auckland');
        $end = CarbonImmutable::parse($plan['ends_at'])->setTimezone('Pacific/Auckland');

        return ['provider_name' => $payload['provider_name'],
            'starts_local' => $start->format('Y-m-d\TH:i'), 'ends_local' => $end->format('Y-m-d\TH:i'),
            'starts_offset' => $start->format('P'), 'ends_offset' => $end->format('P'),
            'unavailable' => FleetVehicleUnavailablePeriod::query()->where('work_order_id', $order->id)->where('state', 'active')->exists()];
    }

    private function performSchedule(User $actor, int $assetId, array $data, string $requestKey, array $files = []): array
    {
        abort_unless($this->access->canManage($actor), 403);
        abort_unless(mb_strlen($requestKey) >= 8, 422, 'A request key is required.');
        $operation = (string) ($data['operation'] ?? 'plan');
        Validator::make($data, [
            'operation' => ['nullable', 'in:plan,cancel,overrun'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
            'source_type' => ['nullable', 'required_with:source_id', 'in:'.implode(',', self::LINKED_SOURCES)],
            'source_id' => ['nullable', 'required_with:source_type', 'integer', 'min:1'],
        ], [
            'source_type.required_with' => 'Choose the due item this appointment is planned from.',
            'source_type.in' => 'Plan a linked appointment from a service schedule or compliance record.',
            'source_id.required_with' => 'Choose the due item this appointment is planned from.',
        ])->validate();
        $source = empty($data['source_type']) ? null
            : ['type' => (string) $data['source_type'], 'id' => (int) $data['source_id']];
        if ($source !== null && $operation !== 'plan') {
            throw ValidationException::withMessages(['source_type' => 'A due item can only be linked when planning an appointment.']);
        }
        if ($operation !== 'plan') {
            if (empty($data['work_order_id'])) {
                throw ValidationException::withMessages(['work_order_id' => 'Choose the work order that holds the appointment.']);
            }
            if (trim((string) ($data['change_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['change_reason' => $operation === 'cancel'
                    ? 'Record why the appointment is cancelled.' : 'Record why the appointment ran over.']);
            }
        }
        if ($operation === 'cancel') {
            return $this->cancel($actor, $assetId, (int) $data['work_order_id'], trim((string) $data['change_reason']), $requestKey);
        }
        Validator::make($data, [
            'work_order_id' => ['nullable', 'integer', 'min:1'],
            'title' => ['required_without:work_order_id', 'nullable', 'string', 'max:255'],
            'provider_name' => ['required', 'string', 'max:255'],
            'starts_local' => ['required', 'string'],
            'ends_local' => ['required', 'string'],
            'starts_offset' => ['nullable', 'string', 'max:6'],
            'ends_offset' => ['nullable', 'string', 'max:6'],
            'unavailable' => ['nullable', 'boolean'],
            'provider_reference' => ['nullable', 'string', 'max:120'],
            'notes' => [$operation === 'overrun' ? 'nullable' : 'required', 'string', 'max:5000'],
        ], [
            'title.required_without' => 'Choose the service or inspection type.',
            'notes.required' => 'Record the purpose or reason for scheduling.',
        ], [
            'provider_name' => 'service provider', 'starts_local' => 'appointment start', 'ends_local' => 'appointment end',
        ])->validate();
        if ($operation === 'overrun') {
            // An overrun keeps the planned start; only the end moves later.
            $plan = $this->currentPlan((int) $data['work_order_id']);
            if ($plan === null) {
                throw ValidationException::withMessages(['work_order_id' => 'This work order has no planned appointment to extend.']);
            }
            $start = CarbonImmutable::parse($plan['starts_at'], 'UTC')->setTimezone('Pacific/Auckland');
            $data['starts_local'] = $start->format('Y-m-d\TH:i');
            $data['starts_offset'] = $start->format('P');
            $newEnd = $this->utc($data['ends_local'], $data['ends_offset'] ?? null, 'ends_local');
            if (! $newEnd->greaterThan(CarbonImmutable::parse($plan['ends_at'], 'UTC'))) {
                throw ValidationException::withMessages(['ends_local' => 'An overrun ends after the planned end.']);
            }
            $data['notes'] = 'Appointment overrun: '.trim((string) $data['change_reason']);
        }
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ValidationException::withMessages(['files' => 'One of the files could not be read. Remove it and try again.']);
            }
        }
        $starts = $this->utc($data['starts_local'], $data['starts_offset'] ?? null, 'starts_local');
        $ends = $this->utc($data['ends_local'], $data['ends_offset'] ?? null, 'ends_local');
        if (! $ends->greaterThan($starts)) {
            throw ValidationException::withMessages(['ends_local' => 'The appointment end must be after its start.']);
        }
        if ($operation === 'plan' && $starts->lessThan(now()->subMinutes(5))) {
            throw ValidationException::withMessages(['starts_local' => 'Choose a future appointment time.']);
        }
        $asset = $this->access->asset($actor, $assetId);

        return DB::transaction(function () use ($actor, $asset, $data, $requestKey, $files, $operation, $source): array {
            $planPayload = [
                'provider_name' => trim((string) $data['provider_name']),
                'starts_local' => (string) $data['starts_local'],
                'ends_local' => (string) $data['ends_local'],
                'starts_offset' => $data['starts_offset'] ?? null,
                'ends_offset' => $data['ends_offset'] ?? null,
            ];
            // What PKG-01 stores for this request's plan step, so a retry is
            // recognised only when it asks for the same plan.
            $planFingerprint = MaintenanceFingerprint::of([
                'actor_id' => (int) $actor->id, 'operation' => 'plan_provider', 'payload' => $planPayload,
            ]);
            $linked = [];
            if ($source !== null) {
                // The vehicle first (Asset → work): two plans from one due item
                // can't both open new work.
                $this->access->asset($actor, (int) $asset->id, true);
                // A retry reports the plan this request already made, wherever it landed.
                $planned = $this->plannedWith($actor, (int) $asset->id, $requestKey.':plan', $planFingerprint);
                abort_if($planned !== null, 409, 'This older appointment request cannot be replayed safely. Reload its work order.');
                $this->reports->assertSourceBelongsToAsset($source['type'], $source['id'], (int) $asset->id);
                $linked = $this->linkedWorkIds($actor, (int) $asset->id, $source);
            }
            // Existing work keeps its own description; the purpose is added as a note.
            $existingWork = true;
            if (! empty($data['work_order_id'])) {
                $order = $this->access->scopedWorkOrders($actor)->whereKey((int) $data['work_order_id'])
                    ->where('asset_id', $asset->id)->first() ?? abort(404);
                if (! in_array($order->status, self::OPEN_STATUSES, true)) {
                    throw ValidationException::withMessages(['work_order_id' => 'Choose an open Maintenance record.']);
                }
                if ($source !== null && ! in_array((int) $order->id, $linked, true)) {
                    throw ValidationException::withMessages(['work_order_id' => 'This Maintenance record isn\'t linked to the due item. Choose its linked work, or create new work from the due item.']);
                }
            } elseif ($linked !== []) {
                // Open work already reported from this due item takes the appointment.
                $order = FleetWorkOrder::query()->findOrFail($linked[0]);
                abort_if($this->currentPlan((int) $order->id) !== null, 409,
                    'This due item already has an appointment. Open that appointment to review its current plan before changing it.');
                abort_if($this->replayed($actor, (int) $order->id, $requestKey.':plan', $planFingerprint), 409,
                    'This older appointment request cannot be replayed safely. Reload its work order.');
            } else {
                abort_unless($this->access->canReport($actor), 403);
                $order = $this->reports->submit($actor, [
                    'asset_id' => $asset->id,
                    'title' => trim((string) $data['title']),
                    'description' => trim((string) $data['notes']),
                    'priority' => 'medium',
                    'source_type' => $source['type'] ?? null,
                    'source_id' => $source['id'] ?? null,
                    'request_key' => $requestKey.':report',
                ]);
                // The report is replay-safe on its own; a retry after the plan
                // landed must not re-run the notes, files or unavailable period.
                abort_if($this->replayed($actor, (int) $order->id, $requestKey.':plan', $planFingerprint), 409,
                    'This older appointment request cannot be replayed safely. Reload its work order.');
                $existingWork = false;
            }

            $order = $this->transitions->execute($actor, (int) $order->id, 'plan_provider', (int) $order->version,
                $requestKey.':plan', $planPayload);
            $reference = trim((string) ($data['provider_reference'] ?? ''));
            if ($reference !== '') {
                $order = $this->transitions->execute($actor, (int) $order->id, 'record_provider_confirmation',
                    (int) $order->version, $requestKey.':confirm', [
                        'response_method' => 'Recorded on the vehicle calendar',
                        'provider_reference' => $reference,
                    ]);
            }
            if ($existingWork) {
                // New work carries the purpose as its description already.
                $change = trim((string) ($data['change_reason'] ?? ''));
                $note = $operation === 'overrun'
                    ? trim((string) $data['notes'])
                    : 'Calendar appointment: '.trim((string) $data['notes']).($change !== '' ? ' · Change reason: '.$change : '');
                $order = $this->transitions->execute($actor, (int) $order->id, 'note', (int) $order->version,
                    $requestKey.':note', ['note' => $note]);
            }
            foreach (array_values($files) as $index => $file) {
                $this->attachments->upload($actor, (int) $order->id, 'work', (int) $order->id,
                    $requestKey.':file-'.$index, $file, 'Service appointment');
            }

            // One block per work order: a reschedule moves it, and unticking
            // "unavailable" releases it.
            $period = null;
            $existing = FleetVehicleUnavailablePeriod::query()->where('asset_id', $asset->id)
                ->where('work_order_id', $order->id)->where('state', FleetVehicleUnavailablePeriod::STATE_ACTIVE)
                ->orderByDesc('id')->first();
            $blockReason = 'Service appointment · '.($order->reference_number ?: 'work #'.$order->id)
                .' · '.trim((string) $data['provider_name']);
            $window = [
                'starts_local' => $data['starts_local'], 'ends_local' => $data['ends_local'],
                'starts_offset' => $data['starts_offset'] ?? null, 'ends_offset' => $data['ends_offset'] ?? null,
                'reason' => $blockReason,
            ];
            if (filter_var($data['unavailable'] ?? false, FILTER_VALIDATE_BOOL)) {
                $period = $existing
                    ? $this->unavailable->moveAppointmentHold($actor, (int) $asset->id, (int) $existing->id, (int) $order->id,
                        $window + ['change_reason' => $operation === 'overrun'
                            ? 'Appointment overrun recorded on the vehicle calendar.'
                            : 'Appointment rescheduled on the vehicle calendar.'],
                        (int) $existing->lock_version)
                    : $this->unavailable->create($actor, (int) $asset->id, $window,
                        $requestKey.':unavailable', (int) $order->id);
            } elseif ($existing) {
                $this->unavailable->releaseAppointmentHold($actor, (int) $asset->id, (int) $existing->id, (int) $order->id,
                    'The appointment no longer makes the vehicle unavailable.', (int) $existing->lock_version);
            }

            return ['work_order' => $order->fresh(), 'unavailable_period' => $period];
        });
    }

    /**
     * Cancel the provider appointment on a work order and release the
     * unavailable period it held. The work order itself stays open.
     *
     * @return array{work_order: FleetWorkOrder, unavailable_period: null}
     */
    private function cancel(User $actor, int $assetId, int $workOrderId, string $reason, string $requestKey): array
    {
        $asset = $this->access->asset($actor, $assetId);

        return DB::transaction(function () use ($actor, $asset, $workOrderId, $reason, $requestKey): array {
            $order = $this->access->scopedWorkOrders($actor)->whereKey($workOrderId)
                ->where('asset_id', $asset->id)->first() ?? abort(404);
            if ($this->currentPlan((int) $order->id) === null) {
                throw ValidationException::withMessages(['work_order_id' => 'This work order has no appointment to cancel.']);
            }
            $order = $this->transitions->execute($actor, (int) $order->id, 'record_provider_cancellation',
                (int) $order->version, $requestKey.':cancel', ['reason' => $reason]);
            $existing = FleetVehicleUnavailablePeriod::query()->where('asset_id', $asset->id)
                ->where('work_order_id', $order->id)->where('state', FleetVehicleUnavailablePeriod::STATE_ACTIVE)
                ->orderByDesc('id')->first();
            if ($existing) {
                $this->unavailable->releaseAppointmentHold($actor, (int) $asset->id, (int) $existing->id, (int) $order->id,
                    'Appointment cancelled: '.$reason, (int) $existing->lock_version);
            }

            return ['work_order' => $order->fresh(), 'unavailable_period' => null];
        });
    }

    /**
     * Whether this request's appointment step already ran (by this person).
     * With a fingerprint, the step must also have asked for the same thing.
     */
    private function replayed(User $actor, int $workOrderId, string $key, ?string $fingerprint = null): bool
    {
        $prior = DB::table('fleet_maintenance_actions')->where('work_order_id', $workOrderId)
            ->where('idempotency_key', $key)->lockForUpdate()->first(['actor_user_id', 'payload_sha256']);
        if (! $prior) {
            return false;
        }
        abort_unless((int) $prior->actor_user_id === (int) $actor->id
            && ($fingerprint === null || hash_equals((string) $prior->payload_sha256, $fingerprint)),
            409, 'This request was already used for a different change.');

        return true;
    }

    /** The work order this person already planned on with the key, on this vehicle. */
    private function plannedWith(User $actor, int $assetId, string $key, string $fingerprint): ?FleetWorkOrder
    {
        $prior = DB::table('fleet_maintenance_actions as action')
            ->join('fleet_work_orders as work', 'work.id', '=', 'action.work_order_id')
            ->where('work.asset_id', $assetId)->where('action.idempotency_key', $key)
            ->where('action.actor_user_id', $actor->id)->lockForUpdate()
            ->first(['action.work_order_id', 'action.payload_sha256']);
        if (! $prior) {
            return null;
        }
        abort_unless(hash_equals((string) $prior->payload_sha256, $fingerprint), 409,
            'This request was already used for a different change.');

        return FleetWorkOrder::query()->find((int) $prior->work_order_id);
    }

    /**
     * Open work of this vehicle reported from the due item (not as a
     * duplicate), newest first. Current reads under the vehicle lock, so work
     * a concurrent plan just opened is seen.
     *
     * @param  array{type:string,id:int}  $source
     * @return list<int>
     */
    private function linkedWorkIds(User $actor, int $assetId, array $source): array
    {
        $reported = DB::table('fleet_maintenance_reports')->where('asset_id', $assetId)
            ->where('source_type', $source['type'])->where('source_id', $source['id'])
            ->whereNull('duplicate_of_report_id')->orderBy('id')->lockForUpdate()
            ->pluck('work_order_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        if ($reported === []) {
            return [];
        }

        return $this->access->scopedWorkOrders($actor)->whereKey($reported)->where('asset_id', $assetId)
            ->whereIn('status', self::OPEN_STATUSES)->orderByDesc('id')->lockForUpdate()
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** @return array{work_order: FleetWorkOrder, unavailable_period: ?FleetVehicleUnavailablePeriod} */
    private function current(int $assetId, FleetWorkOrder $order): array
    {
        return [
            'work_order' => $order->fresh(),
            'unavailable_period' => FleetVehicleUnavailablePeriod::query()->where('asset_id', $assetId)
                ->where('work_order_id', $order->id)->where('state', FleetVehicleUnavailablePeriod::STATE_ACTIVE)
                ->orderByDesc('id')->first(),
        ];
    }

    /**
     * The live provider plan on a work order: the latest plan, unless a later
     * cancellation or completion closed it.
     *
     * @return array{starts_at:string,ends_at:string}|null
     */
    private function currentPlan(int $workOrderId): ?array
    {
        $latest = DB::table('fleet_maintenance_actions')->where('work_order_id', $workOrderId)
            ->whereIn('action_type', ['plan_provider', 'record_provider_confirmation',
                'record_provider_cancellation', 'record_provider_completion'])
            ->orderByDesc('id')->first(['action_type']);
        if (! $latest || in_array($latest->action_type, ['record_provider_cancellation', 'record_provider_completion'], true)) {
            return null;
        }
        $plan = DB::table('fleet_maintenance_actions')->where('work_order_id', $workOrderId)
            ->where('action_type', 'plan_provider')->orderByDesc('id')->value('payload_json');
        $payload = json_decode((string) $plan, true);

        return is_array($payload) && ! empty($payload['starts_at']) && ! empty($payload['ends_at'])
            ? ['starts_at' => (string) $payload['starts_at'], 'ends_at' => (string) $payload['ends_at']]
            : null;
    }

    private function utc(mixed $local, mixed $offset, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(MaintenanceLocalTime::toUtc((string) $local, $offset === null ? null : (string) $offset), 'UTC');
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$field => collect($exception->errors())->flatten()->first()]);
        }
    }
}
