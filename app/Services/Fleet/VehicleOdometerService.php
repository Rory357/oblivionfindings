<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetVehicleOdometerObservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VehicleOdometerService
{
    public const OBSERVED_SOURCES = ['dashboard_manual', 'booking_checkout', 'booking_return', 'inspection'];

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /** @param array<string,mixed> $data */
    public function record(User $actor, int $assetId, array $data, string $requestKey, bool $manualEndpoint = false): FleetVehicleOdometerObservation
    {
        return DB::transaction(function () use ($actor, $assetId, $data, $requestKey, $manualEndpoint): FleetVehicleOdometerObservation {
            $currentActor = User::query()->findOrFail($actor->id);
            abort_unless($currentActor->canDo('fleet.manage'), 403);
            $asset = $this->access->assignableVehicle($currentActor, $assetId, true) ?? abort(404);
            if (trim($requestKey) === '' || mb_strlen($requestKey) > 100) {
                throw ValidationException::withMessages(['request_key' => 'Provide an idempotency key of at most 100 characters.']);
            }
            abort_if(($data['source_kind'] ?? null) === 'legacy_unverified' || ($data['source_type'] ?? null) === 'legacy_asset_field', 422, 'Legacy source provenance is reserved for migration.');
            if ($manualEndpoint && (($data['source_kind'] ?? 'dashboard_manual') !== 'dashboard_manual'
                || array_key_exists('source_type', $data) || array_key_exists('source_id', $data))) {
                throw ValidationException::withMessages(['source_kind' => 'Manual profile observations must use dashboard_manual and cannot claim a booking or inspection source.']);
            }
            if ($manualEndpoint) {
                $data['source_kind'] = 'dashboard_manual';
            }
            Validator::make($data, [
                'value_km' => ['required', 'numeric', 'min:0'],
                'observed_at' => ['nullable', 'date'],
                'source_kind' => ['required', 'in:'.implode(',', self::OBSERVED_SOURCES)],
                'source_type' => ['nullable', 'string', 'max:80'],
                'source_id' => ['nullable', 'integer', 'min:1'],
                'source_reference' => ['nullable', 'string', 'max:255'],
                'corrects_observation_id' => ['nullable', 'integer', 'min:1'],
                'correction_reason' => ['nullable', 'string', 'max:5000'],
            ])->validate();
            $fingerprint = MaintenanceFingerprint::of(['actor_id' => (int) $currentActor->id, 'asset_id' => (int) $asset->id, 'data' => $data]);
            $prior = FleetVehicleOdometerObservation::query()->where('asset_id', $asset->id)->where('request_key', $requestKey)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->request_fingerprint, $fingerprint), 409);
                return $prior;
            }
            $corrects = null;
            if (! empty($data['corrects_observation_id'])) {
                $corrects = FleetVehicleOdometerObservation::query()->whereKey((int) $data['corrects_observation_id'])
                    ->where('asset_id', $asset->id)->lockForUpdate()->firstOrFail();
                if (trim((string) ($data['correction_reason'] ?? '')) === '') {
                    throw ValidationException::withMessages(['correction_reason' => 'Record why this observation is corrected.']);
                }
                if (FleetVehicleOdometerObservation::query()->where('corrects_observation_id', $corrects->id)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['corrects_observation_id' => 'This observation already has a correction.']);
                }
            }
            $observation = FleetVehicleOdometerObservation::query()->create([
                ...collect($data)->only(['value_km', 'observed_at', 'source_kind', 'source_type', 'source_id', 'source_reference', 'correction_reason'])->all(),
                'asset_id' => $asset->id,
                'observed_at' => $data['observed_at'] ?? now(),
                'recorded_by_user_id' => $currentActor->id,
                'corrects_observation_id' => $corrects?->id,
                'request_key' => $requestKey,
                'request_fingerprint' => $fingerprint,
                'created_at' => now(),
            ]);
            $current = $this->currentObserved((int) $asset->id, true);
            if ($current && (int) $current->id === (int) $observation->id) {
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
        $query = FleetVehicleOdometerObservation::query()
            ->where('asset_id', $assetId)->whereIn('source_kind', self::OBSERVED_SOURCES)
            ->where('observed_at', '<=', now())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fleet_vehicle_odometer_observations as correction')
                ->whereColumn('correction.corrects_observation_id', 'fleet_vehicle_odometer_observations.id'))
            ->orderByDesc('observed_at')->orderByDesc('id');
        return ($lock ? $query->lockForUpdate() : $query)->first();
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
            ->first(['id', 'odometer_km', 'occurred_at', 'received_at']);
        return $event ? ['event_id' => $event->id, 'value_km' => (float) $event->odometer_km,
            'observed_at' => $event->occurred_at?->toISOString(), 'received_at' => $event->received_at?->toISOString()] : null;
    }
}
