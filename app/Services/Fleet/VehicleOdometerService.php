<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetVehicleOdometerObservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Observed odometer readings. Readings are retained; a correction is a new
 * reading that points at the one it replaces. Tracker distance is a separate
 * planning estimate and never becomes an observed reading.
 */
class VehicleOdometerService
{
    public const OBSERVED_SOURCES = ['dashboard_manual', 'booking_checkout', 'booking_return', 'inspection'];

    /** Clock skew tolerated between a device and the server for "now". */
    private const FUTURE_TOLERANCE_MINUTES = 5;

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  bool  $workflowAuthorized  the calling workflow (service completion) has
     *                                    already authorised this actor for its own reading
     */
    public function record(User $actor, int $assetId, array $data, string $requestKey, bool $manualEndpoint = false, bool $workflowAuthorized = false): FleetVehicleOdometerObservation
    {
        return DB::transaction(function () use ($actor, $assetId, $data, $requestKey, $manualEndpoint, $workflowAuthorized): FleetVehicleOdometerObservation {
            $currentActor = User::query()->findOrFail($actor->id);
            abort_unless($workflowAuthorized || $currentActor->canDo('fleet.manage'), 403);
            $asset = $this->access->fleetVehicle($currentActor, $assetId, true) ?? abort(404);
            if (trim($requestKey) === '' || mb_strlen($requestKey) > 100) {
                throw ValidationException::withMessages(['request_key' => 'Provide an idempotency key of at most 100 characters.']);
            }
            abort_if(($data['source_kind'] ?? null) === 'legacy_unverified' || ($data['source_type'] ?? null) === 'legacy_asset_field', 422, 'Legacy source provenance is reserved for migration.');
            if ($manualEndpoint && (($data['source_kind'] ?? 'dashboard_manual') !== 'dashboard_manual'
                || array_key_exists('source_type', $data) || array_key_exists('source_id', $data))) {
                throw ValidationException::withMessages(['source_kind' => 'A reading recorded here is a dashboard reading; booking and inspection readings are recorded by those workflows.']);
            }
            if ($manualEndpoint) {
                $data['source_kind'] = 'dashboard_manual';
            }
            Validator::make($data, [
                'value_km' => ['required', 'numeric', 'min:0', 'max:9999999'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'observed_at' => ['nullable', 'date'],
                'observed_local' => ['nullable', 'string'],
                'observed_offset' => ['nullable', 'string', 'max:6'],
                'source_kind' => ['required', 'in:'.implode(',', self::OBSERVED_SOURCES)],
                'source_type' => ['nullable', 'string', 'max:80'],
                'source_id' => ['nullable', 'integer', 'min:1'],
                'source_reference' => ['nullable', 'string', 'max:255'],
                'corrects_observation_id' => ['nullable', 'integer', 'min:1'],
                'correction_reason' => ['nullable', 'string', 'max:5000'],
            ], [], ['value_km' => 'reading', 'observed_at' => 'reading time'])->validate();

            $fingerprint = MaintenanceFingerprint::of(['actor_id' => (int) $currentActor->id, 'asset_id' => (int) $asset->id, 'data' => $data]);
            $prior = FleetVehicleOdometerObservation::query()->where('asset_id', $asset->id)->where('request_key', $requestKey)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->request_fingerprint, $fingerprint), 409, 'This request was already used for a different reading.');

                return $prior;
            }

            $corrects = null;
            if (! empty($data['corrects_observation_id'])) {
                $corrects = FleetVehicleOdometerObservation::query()->whereKey((int) $data['corrects_observation_id'])
                    ->where('asset_id', $asset->id)->lockForUpdate()->first();
                if (! $corrects) {
                    throw ValidationException::withMessages(['corrects_observation_id' => 'Choose a reading recorded for this vehicle.']);
                }
                if (trim((string) ($data['correction_reason'] ?? '')) === '') {
                    throw ValidationException::withMessages(['correction_reason' => 'Record why this reading is being corrected.']);
                }
                if (FleetVehicleOdometerObservation::query()->where('corrects_observation_id', $corrects->id)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['corrects_observation_id' => 'This reading has already been corrected. Correct the latest version instead.']);
                }
            }

            // A correction keeps the time of the reading it corrects unless a
            // different observation time is given explicitly.
            $observedAt = $this->observedAt($data) ?? $corrects?->observed_at?->toImmutable() ?? now()->toImmutable();
            if ($observedAt->greaterThan(now()->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) {
                throw ValidationException::withMessages(['observed_at' => 'The reading time can\'t be in the future.']);
            }
            // A new dashboard reading must fit between the readings recorded
            // before and after it; a wrong earlier figure is fixed by a correction.
            if ($manualEndpoint && ! $corrects) {
                $before = $this->currentObservedAt((int) $asset->id, $observedAt, true);
                $after = $this->effectiveQuery()->where('asset_id', $asset->id)->where('observed_at', '>', $observedAt)
                    ->orderBy('observed_at')->orderBy('id')->lockForUpdate()->first();
                if (($before && (float) $data['value_km'] < (float) $before->value_km)
                    || ($after && (float) $data['value_km'] > (float) $after->value_km)) {
                    throw ValidationException::withMessages(['value_km' => 'This conflicts with readings before or after this time. Review the source or use Correct reading with a reason.']);
                }
            }

            $observation = FleetVehicleOdometerObservation::query()->create([
                ...collect($data)->only(['value_km', 'source_kind', 'source_type', 'source_id', 'source_reference', 'correction_reason', 'notes'])->all(),
                'asset_id' => $asset->id,
                'observed_at' => $observedAt->utc(),
                'recorded_by_user_id' => $currentActor->id,
                'corrects_observation_id' => $corrects?->id,
                'request_key' => $requestKey,
                'request_fingerprint' => $fingerprint,
                'created_at' => now(),
            ]);

            // Keep the legacy column as a projection of the current reading.
            $current = $this->currentObserved((int) $asset->id, true);
            if ($current && (string) $asset->odometer_km !== (string) $current->value_km) {
                $asset->forceFill(['odometer_km' => $current->value_km])->save();
            }

            return $observation;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function recordManual(User $actor, int $assetId, array $data, string $requestKey): FleetVehicleOdometerObservation
    {
        return $this->record($actor, $assetId, $data, $requestKey, true);
    }

    public function currentObserved(int $assetId, bool $lock = false): ?FleetVehicleOdometerObservation
    {
        $query = $this->effectiveQuery()->where('asset_id', $assetId)
            ->orderByDesc('observed_at')->orderByDesc('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** The effective reading at or before a moment. */
    public function currentObservedAt(int $assetId, \DateTimeInterface $at, bool $lock = false): ?FleetVehicleOdometerObservation
    {
        $query = $this->effectiveQuery()->where('asset_id', $assetId)->where('observed_at', '<=', $at)
            ->orderByDesc('observed_at')->orderByDesc('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * Current observed reading for several vehicles in one query.
     *
     * @param  list<int>  $assetIds
     * @return array<int, FleetVehicleOdometerObservation>
     */
    public function currentObservedMany(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }
        $ranked = $this->effectiveQuery()->whereIn('asset_id', $assetIds)
            ->select('fleet_vehicle_odometer_observations.id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY asset_id ORDER BY observed_at DESC, id DESC) AS reading_rank');
        $ids = DB::query()->fromSub($ranked->toBase(), 'ranked')->where('reading_rank', 1)->pluck('id');

        return FleetVehicleOdometerObservation::query()->whereKey($ids)->get()
            ->keyBy(fn (FleetVehicleOdometerObservation $observation): int => (int) $observation->asset_id)->all();
    }

    public function latestTrackerEstimate(int $assetId): ?array
    {
        $event = FleetTelemetryEvent::query()->where('asset_id', $assetId)
            ->where('consent_blocked', false)->whereNotNull('odometer_km')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fleet_trips as private_trip')
                ->whereColumn('private_trip.asset_id', 'fleet_telemetry_events.asset_id')
                ->where('private_trip.is_personal', true)
                ->whereColumn('private_trip.started_at', '<=', 'fleet_telemetry_events.occurred_at')
                ->where(fn ($end) => $end->whereNull('private_trip.ended_at')
                    ->orWhereColumn('private_trip.ended_at', '>=', 'fleet_telemetry_events.occurred_at')))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->first(['id', 'device_id', 'odometer_km', 'occurred_at', 'received_at']);

        return $event ? ['event_id' => $event->id, 'device_id' => $event->device_id, 'value_km' => (float) $event->odometer_km,
            'observed_at' => $event->occurred_at?->toISOString(), 'received_at' => $event->received_at?->toISOString()] : null;
    }

    /** Readings that count: observed sources, not in the future, not replaced by a correction. */
    private function effectiveQuery()
    {
        return FleetVehicleOdometerObservation::query()
            ->whereIn('source_kind', self::OBSERVED_SOURCES)
            ->where('observed_at', '<=', now())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fleet_vehicle_odometer_observations as correction')
                ->whereColumn('correction.corrects_observation_id', 'fleet_vehicle_odometer_observations.id'));
    }

    /** @param array<string,mixed> $data */
    private function observedAt(array $data): ?\Carbon\CarbonImmutable
    {
        if (! empty($data['observed_local'])) {
            try {
                return Carbon::parse(MaintenanceLocalTime::toUtc((string) $data['observed_local'], $data['observed_offset'] ?? null), 'UTC')->toImmutable();
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['observed_at' => collect($exception->errors())->flatten()->first()]);
            }
        }

        return empty($data['observed_at']) ? null : Carbon::parse((string) $data['observed_at'])->toImmutable();
    }
}
