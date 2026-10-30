<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\FleetServiceCompletion;
use App\Models\FleetServiceSchedule;
use App\Models\FleetWorkOrder;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Planned vehicle servicing. Intervals are recorded by the operator (never a
 * built-in policy); calendar months use no-overflow arithmetic and historic
 * day intervals stay days. Recording a service keeps its history and moves
 * the next due point from what actually happened. It never releases a
 * maintenance hold, completes work or approves Finance.
 */
class VehicleServiceScheduleService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly VehicleStaffDirectory $staff,
        private readonly VehicleOdometerService $odometer,
    ) {}

    public function canManage(User $actor): bool
    {
        return $actor->canDo('fleet.manage') || $actor->canDo('fleet.maintenance.manage');
    }

    /**
     * Create (no schedule id) or update a schedule. A create with a request
     * key returns the schedule that key already made; the same key for other
     * details is refused.
     *
     * @param  array<string,mixed>  $data
     */
    public function save(User $actor, int $assetId, ?int $scheduleId, array $data, ?int $expectedVersion, ?string $requestKey = null): FleetServiceSchedule
    {
        if ($requestKey !== null && (trim($requestKey) === '' || mb_strlen($requestKey) > 100)) {
            throw ValidationException::withMessages(['request_key' => 'A request key is required.']);
        }

        try {
            return $this->saveSchedule($actor, $assetId, $scheduleId, $data, $expectedVersion, $requestKey);
        } catch (QueryException $error) {
            // Two concurrent retries of one create: the unique key keeps a single schedule.
            if ($requestKey !== null && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                abort(409, 'This schedule was saved while you were working. Reload to check it.');
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $data */
    private function saveSchedule(User $actor, int $assetId, ?int $scheduleId, array $data, ?int $expectedVersion, ?string $requestKey): FleetServiceSchedule
    {
        return DB::transaction(function () use ($actor, $assetId, $scheduleId, $data, $expectedVersion, $requestKey): FleetServiceSchedule {
            [$current, $asset] = $this->resolve($actor, $assetId);
            $schedule = $scheduleId === null ? null : $this->lockSchedule($asset, $scheduleId);
            if ($schedule) {
                abort_unless((int) $schedule->lock_version === (int) $expectedVersion, 409, 'This schedule changed while you were editing. Reload before saving.');
            }
            Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'interval_months' => ['nullable', 'integer', 'min:1', 'max:240'],
                // Day intervals remain for the legacy schedules list only.
                'interval_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'interval_km' => ['nullable', 'integer', 'min:1', 'max:1000000'],
                'next_due_at' => ['nullable', 'date_format:Y-m-d'],
                'next_due_km' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
                'owner_user_id' => ['nullable', 'integer', 'min:1'],
                'reminder_days_before' => ['nullable', 'integer', 'min:0', 'max:365'],
                'reminder_km_before' => ['nullable', 'integer', 'min:0', 'max:100000'],
                'is_active' => ['nullable', 'boolean'],
            ], [], [
                'name' => 'service type', 'interval_months' => 'interval in months', 'interval_km' => 'interval in kilometres',
                'owner_user_id' => 'responsible person',
            ])->validate();
            if (! empty($data['interval_months']) && ! empty($data['interval_days'])) {
                throw ValidationException::withMessages(['interval_months' => 'Use calendar months or days for the interval, not both.']);
            }
            $days = ! empty($data['interval_days']) ? (int) $data['interval_days']
                : (empty($data['interval_months']) && ! array_key_exists('interval_days', $data) ? $schedule?->interval_days : null);
            if (empty($data['interval_months']) && empty($data['interval_km']) && ! $days) {
                throw ValidationException::withMessages(['interval_months' => 'Record at least one positive interval.']);
            }
            if (empty($data['next_due_at']) && (! isset($data['next_due_km']) || $data['next_due_km'] === '')) {
                throw ValidationException::withMessages(['next_due_at' => 'Record at least one next-due trigger.']);
            }
            if (! empty($data['owner_user_id']) && ! $this->staff->isCandidate($asset, (int) $data['owner_user_id'])) {
                throw ValidationException::withMessages(['owner_user_id' => 'Choose a current staff member at this vehicle\'s site.']);
            }
            $values = [
                'name' => trim((string) $data['name']),
                // A months interval replaces a legacy day interval explicitly;
                // a row never owns both.
                'interval_months' => empty($data['interval_months']) ? null : (int) $data['interval_months'],
                'interval_days' => empty($data['interval_months']) ? $days : null,
                'interval_km' => empty($data['interval_km']) ? null : (int) $data['interval_km'],
                'next_due_at' => empty($data['next_due_at']) ? null : $data['next_due_at'],
                'next_due_km' => isset($data['next_due_km']) && $data['next_due_km'] !== '' ? (float) $data['next_due_km'] : null,
                'owner_user_id' => empty($data['owner_user_id']) ? $schedule?->owner_user_id : (int) $data['owner_user_id'],
                'reminder_days_before' => isset($data['reminder_days_before']) ? (int) $data['reminder_days_before'] : null,
                'reminder_km_before' => isset($data['reminder_km_before']) ? (int) $data['reminder_km_before'] : null,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($schedule?->is_active ?? true),
            ];
            if ($schedule) {
                $before = $schedule->only(array_keys($values));
                $schedule->forceFill([...$values, 'lock_version' => $schedule->lock_version + 1])->save();
                AuditLogger::logOrFail('fleet.service_schedule.update', $schedule, ['asset_id' => $asset->id, 'before' => $before]);
            } else {
                $fingerprint = $requestKey === null ? null : MaintenanceFingerprint::of([
                    'actor' => (int) $current->id, 'asset' => (int) $asset->id, 'schedule' => $values,
                ]);
                if ($requestKey !== null) {
                    $prior = FleetServiceSchedule::query()->where('asset_id', $asset->id)
                        ->where('request_key', $requestKey)->lockForUpdate()->first();
                    if ($prior) {
                        abort_unless(hash_equals((string) $prior->request_fingerprint, (string) $fingerprint), 409,
                            'This request was already used for a different schedule. Reload and try again.');

                        return $prior->fresh();
                    }
                }
                $schedule = FleetServiceSchedule::query()->create([...$values, 'asset_id' => $asset->id]);
                $schedule->forceFill(['lock_version' => 1, 'request_key' => $requestKey, 'request_fingerprint' => $fingerprint])->save();
                AuditLogger::logOrFail('fleet.service_schedule.create', $schedule, ['asset_id' => $asset->id]);
            }

            return $schedule->fresh();
        }, 3);
    }

    /** Record a completed service and move the next due point. @param array<string,mixed> $data */
    public function recordCompletion(User $actor, int $assetId, int $scheduleId, array $data, int $expectedVersion, string $requestKey): FleetServiceCompletion
    {
        return DB::transaction(function () use ($actor, $assetId, $scheduleId, $data, $expectedVersion, $requestKey): FleetServiceCompletion {
            [$current, $asset] = $this->resolve($actor, $assetId);
            if (trim($requestKey) === '' || mb_strlen($requestKey) > 90) {
                throw ValidationException::withMessages(['request_key' => 'Provide an idempotency key of at most 90 characters.']);
            }
            $schedule = $this->lockSchedule($asset, $scheduleId);
            $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $current->id, 'schedule' => $scheduleId, 'data' => $data]);
            $prior = FleetServiceCompletion::query()->where('schedule_id', $schedule->id)->where('request_key', $requestKey)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->request_fingerprint, $fingerprint), 409, 'This request was already used for a different service record.');

                return $prior;
            }
            abort_unless((int) $schedule->lock_version === $expectedVersion, 409, 'This schedule changed while you were editing. Reload before recording the service.');
            Validator::make($data, [
                'completed_on' => ['required', 'date_format:Y-m-d'],
                'odometer_km' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
                'work_order_id' => ['nullable', 'integer', 'min:1'],
                'provider' => ['nullable', 'string', 'max:160'],
                'evidence_reference' => ['nullable', 'string', 'max:160'],
                'notes' => ['required', 'string', 'max:5000'],
                'next_due_at' => ['nullable', 'date_format:Y-m-d'],
                'next_due_km' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            ], [], ['completed_on' => 'completion date', 'notes' => 'work performed and outcome'])->validate();

            $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
            $completedOn = CarbonImmutable::parse($data['completed_on'], $zone)->startOfDay();
            if ($completedOn->greaterThan(CarbonImmutable::now($zone)->startOfDay())) {
                throw ValidationException::withMessages(['completed_on' => 'The service can\'t be recorded as completed in the future.']);
            }
            $workOrderId = empty($data['work_order_id']) ? null : (int) $data['work_order_id'];
            if ($workOrderId && ! FleetWorkOrder::query()->whereKey($workOrderId)->where('asset_id', $asset->id)->exists()) {
                throw ValidationException::withMessages(['work_order_id' => 'Choose work recorded for this vehicle.']);
            }

            $observation = null;
            $km = isset($data['odometer_km']) && $data['odometer_km'] !== '' ? (float) $data['odometer_km'] : null;
            if ($km !== null) {
                // The observed completion reading can't go below a reading
                // already recorded at or before that day.
                $earlier = $this->odometer->currentObservedAt((int) $asset->id, $completedOn->endOfDay()->utc());
                if ($earlier && $km < (float) $earlier->value_km) {
                    throw ValidationException::withMessages(['odometer_km' => 'The completion odometer can\'t be lower than the reading of '
                        .number_format((float) $earlier->value_km).' km already recorded. Correct that reading first if it is wrong.']);
                }
            }

            $nextDueAt = ! empty($data['next_due_at'])
                ? CarbonImmutable::parse($data['next_due_at'], $zone)
                : ($schedule->interval_months ? $completedOn->addMonthsNoOverflow((int) $schedule->interval_months)
                    : ($schedule->interval_days ? $completedOn->addDays((int) $schedule->interval_days) : null));
            if ($nextDueAt && ! $nextDueAt->greaterThan($completedOn)) {
                throw ValidationException::withMessages(['next_due_at' => 'The next service must follow the completed service.']);
            }
            // Distance follows the actual completion reading, never the old due milestone.
            $nextDueKm = isset($data['next_due_km']) && $data['next_due_km'] !== ''
                ? (float) $data['next_due_km']
                : ($schedule->interval_km && $km !== null ? $km + (int) $schedule->interval_km : null);
            if ($nextDueKm !== null && $km !== null && $nextDueKm <= $km) {
                throw ValidationException::withMessages(['next_due_km' => 'The next service distance must be above the completion odometer.']);
            }

            $completion = FleetServiceCompletion::query()->create([
                'schedule_id' => $schedule->id, 'asset_id' => $asset->id, 'completed_on' => $completedOn->toDateString(),
                'odometer_km' => $km, 'work_order_id' => $workOrderId,
                'provider' => trim((string) ($data['provider'] ?? '')) ?: null,
                'evidence_reference' => trim((string) ($data['evidence_reference'] ?? '')) ?: null,
                'notes' => trim((string) $data['notes']),
                'previous_next_due_at' => $schedule->next_due_at?->toDateString(),
                'previous_next_due_km' => $schedule->next_due_km,
                'next_due_at' => $nextDueAt?->toDateString(), 'next_due_km' => $nextDueKm,
                'schedule_version' => (int) $schedule->lock_version,
                'recorded_by_user_id' => $current->id, 'request_key' => $requestKey,
                'request_fingerprint' => $fingerprint, 'created_at' => now(),
            ]);
            if ($km !== null) {
                $observation = $this->odometer->record($current, (int) $asset->id, [
                    'value_km' => $km,
                    // Midday on the completion day: the reading's time within the day is not recorded.
                    'observed_at' => $completedOn->setTime(12, 0)->utc()->min(now()->utc())->toIso8601String(),
                    'source_kind' => 'dashboard_manual', 'source_type' => 'fleet_service_completion',
                    'source_id' => $completion->id, 'source_reference' => $schedule->name,
                ], 'service-completion-'.$completion->id, manualEndpoint: false, workflowAuthorized: true);
                DB::table('fleet_service_completions')->where('id', $completion->id)->update(['odometer_observation_id' => $observation->id]);
            }
            $schedule->forceFill([
                'last_completed_at' => $completedOn->toDateString(), 'last_completed_km' => $km,
                'next_due_at' => $nextDueAt?->toDateString(), 'next_due_km' => $nextDueKm,
                'lock_version' => $schedule->lock_version + 1,
            ])->save();
            AuditLogger::logOrFail('fleet.service_schedule.complete', $schedule, [
                'asset_id' => $asset->id, 'completion_id' => $completion->id, 'odometer_observation_id' => $observation?->id,
            ]);

            return $completion->fresh();
        }, 3);
    }

    /** @return array{0: User, 1: Asset} */
    private function resolve(User $actor, int $assetId): array
    {
        $current = User::query()->findOrFail($actor->id);
        abort_unless($this->canManage($current), 403);
        $asset = $this->access->fleetVehicle($current, $assetId, true) ?? abort(404);

        return [$current, $asset];
    }

    private function lockSchedule(Asset $asset, int $scheduleId): FleetServiceSchedule
    {
        return FleetServiceSchedule::query()->whereKey($scheduleId)->where('asset_id', $asset->id)->lockForUpdate()->first() ?? abort(404);
    }
}
