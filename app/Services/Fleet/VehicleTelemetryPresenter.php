<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Presenters\FleetVehicleTechnologyProjectionPresenter;
use App\Models\Asset;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The vehicle profile's Vehicle telemetry view, built on the canonical
 * technology projection (FleetVehicleTechnologyProjectionPresenter): the
 * installed tracker comes from Security & Devices, and the recorded samples
 * from Fleet telemetry.
 *
 * Only people who may see the vehicle's technology see telemetry. A sample
 * older than FRESH_MINUTES is last known, never current. Consent-blocked
 * samples and samples recorded during a personal trip keep their device
 * state (ignition, power, battery) but never their speed or distance.
 */
final class VehicleTelemetryPresenter
{
    public const FRESH_MINUTES = 15;

    public const SAMPLE_LIMIT = 20;

    public function __construct(private readonly FleetVehicleTechnologyProjectionPresenter $technology) {}

    public function canView(User $viewer, Asset $vehicle): bool
    {
        return $this->technology->canView($viewer, $vehicle);
    }

    /** @return array<string,mixed> */
    public function present(User $viewer, Asset $vehicle): array
    {
        $projection = $this->technology->present($viewer, $vehicle);
        abort_if($projection === null, 403);

        $events = FleetTelemetryEvent::query()
            ->where('asset_id', $vehicle->getKey())
            ->where('occurred_at', '<=', now()->addMinutes(5))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(self::SAMPLE_LIMIT)
            ->get(['id', 'device_id', 'vendor', 'occurred_at', 'received_at', 'event_type', 'ignition', 'motion_status',
                'speed_kph', 'battery_pct', 'external_power', 'odometer_km', 'consent_blocked']);
        $personal = $this->personalTrips($vehicle, $events);
        $latest = $events->first();
        $fresh = $latest !== null && $latest->occurred_at->greaterThanOrEqualTo(now()->subMinutes(self::FRESH_MINUTES));

        return [
            'technology' => $projection,
            'telemetry' => [
                'as_of' => now()->toIso8601String(),
                'timezone' => VehicleLocationService::zone(),
                'fresh_minutes' => self::FRESH_MINUTES,
                'tracker' => $this->tracker($projection, $latest),
                'current_sample_id' => $fresh ? (int) $latest->id : null,
                'samples' => $events->map(fn (FleetTelemetryEvent $event): array => $this->sample($event, $personal))->values()->all(),
                'vehicle' => [
                    'id' => (int) $vehicle->getKey(),
                    'name' => (string) $vehicle->name,
                    'vin' => $this->text($vehicle->serial_number ?? null),
                ],
                'can' => [
                    'view_alerts' => $viewer->canDo('controlRoom.viewAny')
                        || $viewer->canDo('controlRoom.alerts.view')
                        || $viewer->canDo('assets.alerts.view'),
                ],
            ],
        ];
    }

    /**
     * The installed tracker: the device behind the latest sample when this
     * viewer can see it, else the first tracking device on the vehicle.
     *
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>|null
     */
    private function tracker(array $projection, ?FleetTelemetryEvent $latest): ?array
    {
        $devices = collect($projection['devices'] ?? []);
        $device = ($latest?->device_id ? $devices->firstWhere('id', (int) $latest->device_id) : null)
            ?? $devices->firstWhere('domain', 'tracking');
        if ($device === null) {
            return $latest === null ? null : [
                'device_id' => null,
                'name' => null,
                'model' => null,
                'family' => null,
                'provider' => $latest->vendor ? ucfirst((string) $latest->vendor) : null,
                'firmware' => null,
                'connectivity' => null,
                'last_seen_at' => null,
                'href' => null,
            ];
        }
        $model = $this->text($device['model'] ?? null);

        return [
            'device_id' => (int) $device['id'],
            'name' => (string) $device['name'],
            'model' => $model,
            'family' => self::family($model),
            'provider' => $device['provider'] ?? null,
            'firmware' => $device['firmware']['current_version'] ?? null,
            'connectivity' => $device['connectivity'] ?? null,
            'last_seen_at' => $device['last_seen_at'] ?? null,
            'href' => $device['href'] ?? null,
        ];
    }

    /** Capabilities are only described for an exact, reviewed model. */
    public static function family(?string $model): ?string
    {
        $normalised = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $model));

        return $normalised === 'GV500CG' ? 'gv500cg' : null;
    }

    /**
     * @param  list<array{from:CarbonImmutable,to:?CarbonImmutable}>  $personal
     * @return array<string,mixed>
     */
    private function sample(FleetTelemetryEvent $event, array $personal): array
    {
        $at = CarbonImmutable::instance($event->occurred_at);
        $withheld = match (true) {
            (bool) $event->consent_blocked => 'consent',
            collect($personal)->contains(fn (array $trip): bool => $trip['from']->lessThanOrEqualTo($at)
                && ($trip['to'] === null || $trip['to']->greaterThanOrEqualTo($at))) => 'personal',
            default => null,
        };

        return [
            'id' => (int) $event->id,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'received_at' => $event->received_at?->toIso8601String(),
            'event_type' => $event->event_type,
            'ignition' => $event->ignition,
            'motion' => VehicleLocationService::motion($event->motion_status, $withheld === null ? $event->speed_kph : null),
            'speed_kph' => $withheld === null && $event->speed_kph !== null ? round((float) $event->speed_kph, 1) : null,
            'battery_pct' => $event->battery_pct,
            'external_power' => $event->external_power,
            'odometer_km' => $withheld === null && $event->odometer_km !== null ? round((float) $event->odometer_km, 1) : null,
            'withheld' => $withheld,
        ];
    }

    /**
     * Personal trips overlapping the samples.
     *
     * @param  Collection<int,FleetTelemetryEvent>  $events
     * @return list<array{from:CarbonImmutable,to:?CarbonImmutable}>
     */
    private function personalTrips(Asset $vehicle, Collection $events): array
    {
        if ($events->isEmpty()) {
            return [];
        }
        $from = CarbonImmutable::instance($events->last()->occurred_at)->utc()->format('Y-m-d H:i:s');
        $to = CarbonImmutable::instance($events->first()->occurred_at)->utc()->format('Y-m-d H:i:s');

        return FleetTrip::query()->where('asset_id', $vehicle->getKey())->where('is_personal', true)
            ->where('started_at', '<=', $to)
            ->where(fn ($end) => $end->whereNull('ended_at')->orWhere('ended_at', '>=', $from))
            ->get(['started_at', 'ended_at'])
            ->map(fn (FleetTrip $trip): array => [
                'from' => CarbonImmutable::instance($trip->started_at),
                'to' => $trip->ended_at ? CarbonImmutable::instance($trip->ended_at) : null,
            ])->values()->all();
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
