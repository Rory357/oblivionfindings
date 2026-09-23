<?php

namespace App\Services\Fleet;

use App\Models\FleetVehicleUnavailablePeriod;
use App\Models\FleetWorkOrder;
use App\Models\User;
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
 */
class VehicleAppointmentService
{
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
        abort_unless($this->access->canManage($actor), 403);
        abort_unless(mb_strlen($requestKey) >= 8, 422, 'A request key is required.');
        $operation = (string) ($data['operation'] ?? 'plan');
        Validator::make($data, [
            'operation' => ['nullable', 'in:plan,cancel,overrun'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        if ($operation !== 'plan') {
            if (empty($data['work_order_id'])) {
                throw ValidationException::withMessages(['work_order_id' => 'Choose the work order that holds the appointment.']);
            }
            if (trim((string) ($data['change_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['change_reason' => $operation === 'cancel'
                    ? 'Record why the appointment is cancelled.' : 'Record why the appointment ran over.']);
            }
        }
        // A retry of a change that already went through returns the current
        // state: the checks below describe the appointment before the change.
        if (! empty($data['work_order_id'])) {
            $asset = $this->access->asset($actor, $assetId);
            $order = $this->access->scopedWorkOrders($actor)->whereKey((int) $data['work_order_id'])
                ->where('asset_id', $asset->id)->first() ?? abort(404);
            if ($this->replayed($actor, (int) $order->id, $requestKey.($operation === 'cancel' ? ':cancel' : ':plan'))) {
                return $this->current((int) $asset->id, $order);
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

        return DB::transaction(function () use ($actor, $asset, $data, $requestKey, $files, $operation): array {
            if (! empty($data['work_order_id'])) {
                $order = $this->access->scopedWorkOrders($actor)->whereKey((int) $data['work_order_id'])
                    ->where('asset_id', $asset->id)->first() ?? abort(404);
                if (! in_array($order->status, ['open', 'in_progress', 'on_hold'], true)) {
                    throw ValidationException::withMessages(['work_order_id' => 'Choose an open Maintenance record.']);
                }
            } else {
                abort_unless($this->access->canReport($actor), 403);
                $order = $this->reports->submit($actor, [
                    'asset_id' => $asset->id,
                    'title' => trim((string) $data['title']),
                    'description' => trim((string) $data['notes']),
                    'priority' => 'medium',
                    'request_key' => $requestKey.':report',
                ]);
                // The report is replay-safe on its own; a retry after the plan
                // landed must not re-run the notes, files or unavailable period.
                if ($this->replayed($actor, (int) $order->id, $requestKey.':plan')) {
                    return $this->current((int) $asset->id, $order);
                }
            }

            $order = $this->transitions->execute($actor, (int) $order->id, 'plan_provider', (int) $order->version,
                $requestKey.':plan', [
                    'provider_name' => trim((string) $data['provider_name']),
                    'starts_local' => (string) $data['starts_local'],
                    'ends_local' => (string) $data['ends_local'],
                    'starts_offset' => $data['starts_offset'] ?? null,
                    'ends_offset' => $data['ends_offset'] ?? null,
                ]);
            $reference = trim((string) ($data['provider_reference'] ?? ''));
            if ($reference !== '') {
                $order = $this->transitions->execute($actor, (int) $order->id, 'record_provider_confirmation',
                    (int) $order->version, $requestKey.':confirm', [
                        'response_method' => 'Recorded on the vehicle calendar',
                        'provider_reference' => $reference,
                    ]);
            }
            if (! empty($data['work_order_id'])) {
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
                    ? $this->unavailable->update($actor, (int) $asset->id, (int) $existing->id,
                        $window + ['change_reason' => $operation === 'overrun'
                            ? 'Appointment overrun recorded on the vehicle calendar.'
                            : 'Appointment rescheduled on the vehicle calendar.'],
                        (int) $existing->lock_version)
                    : $this->unavailable->create($actor, (int) $asset->id, $window,
                        $requestKey.':unavailable', (int) $order->id);
            } elseif ($existing) {
                $this->unavailable->cancel($actor, (int) $asset->id, (int) $existing->id,
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
                $this->unavailable->cancel($actor, (int) $asset->id, (int) $existing->id,
                    'Appointment cancelled: '.$reason, (int) $existing->lock_version);
            }

            return ['work_order' => $order->fresh(), 'unavailable_period' => null];
        });
    }

    /** Whether this request's appointment step already ran (by this person). */
    private function replayed(User $actor, int $workOrderId, string $key): bool
    {
        $prior = DB::table('fleet_maintenance_actions')->where('work_order_id', $workOrderId)
            ->where('idempotency_key', $key)->lockForUpdate()->first(['actor_user_id']);
        if (! $prior) {
            return false;
        }
        abort_unless((int) $prior->actor_user_id === (int) $actor->id, 409, 'This request was already used for a different change.');

        return true;
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
