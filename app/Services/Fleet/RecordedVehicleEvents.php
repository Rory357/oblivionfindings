<?php

namespace App\Services\Fleet;

use App\Models\Asset;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recorded vehicle events that a person can send to Control Room from the
 * vehicle profile, and their routing identity.
 *
 * Overspeed episodes come from the trip behaviour analysis of business trips
 * (never personal or consent-blocked trips). Power events are the tracker's
 * own reports: power disconnected (GTPFA) and external power outside its
 * configured range (GTEPS). One recorded event always maps to one Fleet
 * signal, so sending it again joins its existing Control Room response.
 */
final class RecordedVehicleEvents
{
    /** Tracker event types that can be sent, and the kind they are sent as. */
    public const TELEMETRY_KINDS = [
        'power_off' => 'power_disconnected',
        'external_power' => 'low_voltage',
    ];

    /** Fleet signal type per kind sent from the vehicle profile. */
    public const SIGNAL_TYPES = [
        'overspeed' => 'vehicle.overspeed',
        'power_disconnected' => 'vehicle.power_disconnected',
        'low_voltage' => 'vehicle.low_voltage',
    ];

    public const KIND_LABELS = [
        'overspeed' => 'Overspeed threshold',
        'power_disconnected' => 'Power disconnected',
        'low_voltage' => 'Vehicle power alert',
    ];

    /** Driving events a person can review. */
    public const REVIEWABLE_TYPES = ['harsh-braking', 'harsh-acceleration', 'harsh-cornering', 'harsh-unclassified', 'overspeed'];

    public function __construct(private readonly VehicleTripHistoryService $trips) {}

    /** The analyzer's event key without its position counter, stable while the recorded data is. */
    public static function stableKey(string $key): string
    {
        return (string) preg_replace('/-\d+$/', '', $key);
    }

    /** The telemetry row a stable event key names, or null. */
    public static function sourceRowId(string $stableKey): ?int
    {
        return preg_match('/-(\d+)$/', $stableKey, $match) === 1 ? (int) $match[1] : null;
    }

    public static function overspeedSourceKey(int $tripId, string $eventKey): string
    {
        return 'overspeed:'.$tripId.':'.$eventKey;
    }

    public static function telemetrySourceKey(int $eventId): string
    {
        return 'telemetry:'.$eventId;
    }

    /** The Fleet signal identity of a recorded event: one event, one signal. */
    public static function signalKey(int $assetId, string $sourceKey): string
    {
        return hash('sha256', 'pkg02b-vehicle-route|'.$assetId.'|'.$sourceKey);
    }

    /**
     * Recorded overspeed episodes of the business trips in an insight data
     * set (VehicleTripHistoryService::insightData), newest first.
     *
     * @param  array<string,mixed>  $data
     * @return list<array<string,mixed>>
     */
    public function episodesFrom(array $data): array
    {
        $zone = VehicleTripHistoryService::zone();
        $episodes = [];
        foreach ($data['trips'] as $trip) {
            if ($trip->is_personal || $trip->consent_blocked) {
                continue;
            }
            foreach ($data['analysis'][$trip->id]['events'] ?? [] as $event) {
                if (($event['type'] ?? null) !== 'overspeed') {
                    continue;
                }
                $key = self::stableKey((string) $event['key']);
                $at = CarbonImmutable::createFromTimestampUTC((int) $event['at']);
                $episodes[] = [
                    'source_key' => self::overspeedSourceKey((int) $trip->id, $key),
                    'trip_id' => (int) $trip->id,
                    'trip_reference' => 'Trip #'.$trip->id,
                    'local_date' => $trip->started_at?->copy()->setTimezone($zone)->toDateString(),
                    'event_key' => $key,
                    'at' => $at->toIso8601String(),
                    'at_timestamp' => $at->getTimestamp(),
                    'peak_kph' => isset($event['peak_kph']) ? round((float) $event['peak_kph'], 1) : null,
                    'seconds' => isset($event['seconds']) ? (int) $event['seconds'] : null,
                    'source' => $event['source'] ?? null,
                    'title' => (string) $event['title'],
                    'detail' => (string) $event['detail'],
                    'driver' => $data['driver_views'][$trip->id] ?? null,
                ];
            }
        }
        usort($episodes, fn (array $a, array $b): int => $b['at_timestamp'] <=> $a['at_timestamp']);

        return $episodes;
    }

    /**
     * Recorded overspeed episodes of a vehicle's business trips between two
     * Auckland days, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function overspeedEpisodes(User $viewer, Asset $vehicle, string $from, string $to): array
    {
        return $this->episodesFrom($this->trips->insightData($viewer, $vehicle, $from, $to));
    }

    /** One recorded overspeed episode, freshly read from the trip's telemetry. @return array<string,mixed>|null */
    public function overspeedEpisode(User $viewer, Asset $vehicle, int $tripId, string $eventKey): ?array
    {
        $data = $this->trips->insightData($viewer, $vehicle, null, null, $tripId);
        foreach ($this->episodesFrom($data) as $episode) {
            if ($episode['event_key'] === $eventKey) {
                return $episode;
            }
        }

        return null;
    }

    /**
     * The tracker's recorded power events since a time, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function powerEvents(Asset $vehicle, CarbonImmutable $since, int $limit = 30): array
    {
        $rows = FleetTelemetryEvent::query()
            ->where('asset_id', $vehicle->getKey())
            ->whereIn('event_type', array_keys(self::TELEMETRY_KINDS))
            ->where('occurred_at', '>=', $since->utc())
            ->where('occurred_at', '<=', now()->addMinutes(5))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'asset_id', 'occurred_at', 'received_at', 'event_type', 'battery_pct', 'external_power',
                'latitude', 'longitude', 'consent_blocked']);

        return $rows->map(fn (FleetTelemetryEvent $row): array => $this->powerEventRow($vehicle, $row))->values()->all();
    }

    /** One recorded power event of the vehicle, or null. @return array<string,mixed>|null */
    public function powerEvent(Asset $vehicle, int $eventId): ?array
    {
        $row = FleetTelemetryEvent::query()->whereKey($eventId)->where('asset_id', $vehicle->getKey())
            ->whereIn('event_type', array_keys(self::TELEMETRY_KINDS))
            ->first(['id', 'asset_id', 'occurred_at', 'received_at', 'event_type', 'battery_pct', 'external_power',
                'latitude', 'longitude', 'consent_blocked']);

        return $row ? $this->powerEventRow($vehicle, $row) : null;
    }

    /**
     * Whether recorded events were sent: their Fleet signal, its delivery and
     * (only for viewers who may read alerts) the Control Room response.
     *
     * @param  list<string>  $sourceKeys
     * @return array<string,array<string,mixed>>
     */
    public function routeStates(Asset $vehicle, array $sourceKeys, bool $withResponses): array
    {
        if ($sourceKeys === []) {
            return [];
        }
        $identities = [];
        foreach (array_unique($sourceKeys) as $sourceKey) {
            $identities[self::signalKey((int) $vehicle->getKey(), $sourceKey)] = $sourceKey;
        }
        $signals = FleetSignal::query()->where('asset_id', $vehicle->getKey())
            ->whereIn('idempotency_key', array_keys($identities))
            ->with('outbox:id,fleet_signal_id,status')
            ->get(['id', 'asset_id', 'idempotency_key', 'signal_type', 'occurred_at']);
        $responses = [];
        if ($withResponses && $signals->isNotEmpty()) {
            $controlKeys = $signals->mapWithKeys(fn (FleetSignal $signal): array => [
                hash('sha256', 'safety-signal|fleet|'.$signal->idempotency_key) => (int) $signal->id,
            ]);
            $controlSignals = Signal::query()->whereIn('idempotency_key', $controlKeys->keys()->all())
                ->get(['id', 'idempotency_key', 'alert_id', 'correlated_alert_id']);
            $alertIds = $controlSignals->map(fn (Signal $signal): ?int => $signal->alert_id ?? $signal->correlated_alert_id)
                ->filter()->unique()->values()->all();
            $alerts = ControlRoomAlert::query()->whereIn('id', $alertIds)->where('asset_id', $vehicle->getKey())
                ->get(['id', 'reference_number', 'status'])->keyBy('id');
            foreach ($controlSignals as $controlSignal) {
                $alert = $alerts->get($controlSignal->alert_id ?? $controlSignal->correlated_alert_id);
                if ($alert !== null) {
                    $responses[$controlKeys[$controlSignal->idempotency_key]] = [
                        'id' => (int) $alert->id,
                        'reference' => $alert->reference_number ?: 'CR-'.$alert->id,
                        'status' => (string) $alert->status,
                    ];
                }
            }
        }

        $states = [];
        foreach ($signals as $signal) {
            $states[$identities[$signal->idempotency_key]] = [
                'signal_id' => (int) $signal->id,
                'delivery' => (string) ($signal->outbox?->status ?? 'pending'),
                'response' => $responses[(int) $signal->id] ?? null,
            ];
        }

        return $states;
    }

    /** @return array<string,mixed> */
    private function powerEventRow(Asset $vehicle, FleetTelemetryEvent $row): array
    {
        $kind = self::TELEMETRY_KINDS[(string) $row->event_type];
        $at = CarbonImmutable::instance($row->occurred_at);
        $personal = $this->personalAt($vehicle, $at);
        $withheld = $row->consent_blocked ? 'consent' : ($personal ? 'personal' : null);

        return [
            'source_key' => self::telemetrySourceKey((int) $row->id),
            'event_id' => (int) $row->id,
            'kind' => $kind,
            'title' => self::KIND_LABELS[$kind],
            'at' => $at->toIso8601String(),
            'detail' => $kind === 'power_disconnected'
                ? 'The tracker reported losing its main power. Check the vehicle battery and wiring.'
                : 'The tracker reported its external power supply outside the configured range. This is a supply sample, not a battery health test.',
            'battery_pct' => $row->battery_pct,
            'withheld' => $withheld,
            'lat' => $withheld === null && $row->latitude !== null ? round((float) $row->latitude, 7) : null,
            'lng' => $withheld === null && $row->longitude !== null ? round((float) $row->longitude, 7) : null,
            'trip_id' => $withheld === null ? $this->businessTripAt($vehicle, $at) : null,
        ];
    }

    private function personalAt(Asset $vehicle, CarbonImmutable $at): bool
    {
        $moment = $at->utc()->format('Y-m-d H:i:s');

        return FleetTrip::query()->where('asset_id', $vehicle->getKey())->where('is_personal', true)
            ->where('started_at', '<=', $moment)
            ->where(fn (Builder $end) => $end->whereNull('ended_at')->orWhere('ended_at', '>=', $moment))
            ->exists();
    }

    private function businessTripAt(Asset $vehicle, CarbonImmutable $at): ?int
    {
        $moment = $at->utc()->format('Y-m-d H:i:s');
        $id = FleetTrip::query()->where('asset_id', $vehicle->getKey())
            ->where('is_personal', false)->where('consent_blocked', false)
            ->where(fn (Builder $status) => $status->whereNull('status')->orWhere('status', '!=', 'cancelled'))
            ->where('started_at', '<=', $moment)
            ->where(fn (Builder $end) => $end->whereNull('ended_at')->orWhere('ended_at', '>=', $moment))
            ->orderByDesc('started_at')->value('id');

        return $id === null ? null : (int) $id;
    }
}
