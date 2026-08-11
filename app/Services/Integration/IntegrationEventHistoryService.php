<?php

namespace App\Services\Integration;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\FleetTelemetryEvent;
use App\Models\Integration\IntegrationEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class IntegrationEventHistoryService
{
    private const int MAX_DEVICE_LIMIT = 100;

    private const int MAX_HISTORY_LIMIT = 500;

    public function forDevice(
        ?Device $device,
        array $filters = [],
        bool $includeEventType = false,
        ?int $retentionDays = null,
    ): Collection {
        if (! $device) {
            return collect();
        }

        return $this->forDevices(
            collect([$device]),
            $filters,
            $includeEventType,
            $retentionDays,
        )->map(fn (array $location): array => collect($location)->except('device_id')->all());
    }

    /**
     * Read a bounded authorised Device set without issuing one history query
     * per Device. The returned device_id is canonical and always belongs to
     * the supplied allowlist; callers remain responsible for authorising that
     * allowlist before invoking this method.
     *
     * @param  Collection<int, Device>  $devices
     */
    public function forDevices(
        Collection $devices,
        array $filters = [],
        bool $includeEventType = false,
        ?int $retentionDays = null,
        int $limit = self::MAX_HISTORY_LIMIT,
    ): Collection {
        $devices = $devices
            ->filter(fn (mixed $device): bool => $device instanceof Device)
            ->unique(fn (Device $device): int => (int) $device->id)
            ->take(self::MAX_DEVICE_LIMIT)
            ->keyBy(fn (Device $device): int => (int) $device->id);
        if ($devices->isEmpty()) {
            return collect();
        }

        $limit = max(1, min(self::MAX_HISTORY_LIMIT, $limit));
        $retentionDays = max(
            1,
            $retentionDays ?? (int) config('fleet.retention.telemetry_days', 365),
        );
        $retentionCutoff = now()->subDays($retentionDays);
        $candidateLimit = self::MAX_HISTORY_LIMIT;
        $locations = $this->integrationEventLocationsForDevices(
            $devices,
            $filters,
            $includeEventType,
            $retentionCutoff,
            $candidateLimit,
        )->merge($this->fleetTelemetryLocationsForDevices(
            $devices,
            $filters,
            $includeEventType,
            $retentionCutoff,
            $candidateLimit,
        ));

        return $locations
            ->sortByDesc(fn (array $location) => $location['timestamp'] ?? '')
            ->take($limit)
            ->values();
    }

    /** @param Collection<int, Device> $devices */
    private function integrationEventLocationsForDevices(
        Collection $devices,
        array $filters,
        bool $includeEventType,
        \DateTimeInterface $retentionCutoff,
        int $candidateLimit,
    ): Collection {
        if (! Schema::hasTable('integration_events')) {
            return collect();
        }

        $hasCanonicalColumn = $this->hasCanonicalDeviceColumn();
        $legacyHardwareMap = $this->uniqueLegacyDeviceMap($devices, 'legacy_location_hardware_id');

        if (! $hasCanonicalColumn && $legacyHardwareMap->isEmpty()) {
            return collect();
        }

        $query = IntegrationEvent::query()
            ->select($this->selectColumns())
            ->where('occurred_at', '>=', $retentionCutoff)
            ->where(function (Builder $query) use ($devices, $legacyHardwareMap, $hasCanonicalColumn): void {
                if ($hasCanonicalColumn) {
                    $query->whereIn('canonical_device_id', $devices->keys()->all());
                }

                if ($legacyHardwareMap->isEmpty()) {
                    return;
                }

                if ($hasCanonicalColumn) {
                    $query->orWhere(function (Builder $fallback) use ($legacyHardwareMap): void {
                        $fallback->whereNull('canonical_device_id')
                            ->whereIn('hardware_id', $legacyHardwareMap->keys()->all());
                    });

                    return;
                }

                $query->whereIn('hardware_id', $legacyHardwareMap->keys()->all());
            });

        if (! empty($filters['date_from'])) {
            $query->where('occurred_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('occurred_at', '<=', $filters['date_to'].' 23:59:59');
        }

        if (! empty($filters['event_types'])) {
            $types = is_array($filters['event_types'])
                ? $filters['event_types']
                : array_filter(array_map('trim', explode(',', (string) $filters['event_types'])));
            if (! empty($types)) {
                $query->whereIn('event_type', $types);
            }
        }

        return $query->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($candidateLimit)
            ->get()
            ->toBase()
            ->map(function (IntegrationEvent $event) use ($devices, $legacyHardwareMap, $hasCanonicalColumn, $includeEventType): ?array {
                $deviceId = $hasCanonicalColumn && $event->canonical_device_id !== null
                    ? (int) $event->canonical_device_id
                    : $legacyHardwareMap->get($event->hardware_id);
                if (! is_int($deviceId) || ! $devices->has($deviceId)) {
                    return null;
                }

                $location = $this->mapLocationEvent($event, $includeEventType);

                return $location === null ? null : ['device_id' => $deviceId, ...$location];
            })
            ->filter()
            ->values();
    }

    /** @param Collection<int, Device> $devices */
    private function fleetTelemetryLocationsForDevices(
        Collection $devices,
        array $filters,
        bool $includeEventType,
        \DateTimeInterface $retentionCutoff,
        int $candidateLimit,
    ): Collection {
        if (! Schema::hasTable('fleet_telemetry_events')) {
            return collect();
        }

        $legacyTrackerMap = $this->uniqueLegacyDeviceMap($devices, 'legacy_asset_tracker_id');

        $query = FleetTelemetryEvent::query()
            ->where('consent_blocked', false)
            ->where('occurred_at', '>=', $retentionCutoff)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(function (Builder $query) use ($devices, $legacyTrackerMap): void {
                $query->whereIn('device_id', $devices->keys()->all());

                if ($legacyTrackerMap->isNotEmpty()) {
                    $query->orWhere(function (Builder $fallback) use ($legacyTrackerMap): void {
                        $fallback->whereNull('device_id')
                            ->whereIn('asset_tracker_id', $legacyTrackerMap->keys()->all());
                    });
                }
            });

        if (! empty($filters['date_from'])) {
            $query->where('occurred_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('occurred_at', '<=', $filters['date_to'].' 23:59:59');
        }

        if (! empty($filters['event_types'])) {
            $types = is_array($filters['event_types'])
                ? $filters['event_types']
                : array_filter(array_map('trim', explode(',', (string) $filters['event_types'])));
            if (! empty($types)) {
                $query->whereIn('event_type', $types);
            }
        }

        return $query->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($candidateLimit)
            ->get()
            ->toBase()
            ->map(function (FleetTelemetryEvent $event) use ($devices, $legacyTrackerMap, $includeEventType): ?array {
                $deviceId = $event->device_id !== null
                    ? (int) $event->device_id
                    : $legacyTrackerMap->get($event->asset_tracker_id);
                if (! is_int($deviceId) || ! $devices->has($deviceId)) {
                    return null;
                }

                return ['device_id' => $deviceId, ...$this->mapFleetTelemetryEvent($event, $includeEventType)];
            })
            ->filter()
            ->values();
    }

    /** @param Collection<int, Device> $devices */
    private function uniqueLegacyDeviceMap(Collection $devices, string $attribute): Collection
    {
        return $devices
            ->filter(fn (Device $device): bool => is_numeric($device->getAttribute($attribute))
                && (int) $device->getAttribute($attribute) > 0)
            ->groupBy(fn (Device $device): int => (int) $device->getAttribute($attribute))
            ->filter(fn (Collection $matches): bool => $matches->count() === 1)
            ->map(fn (Collection $matches): int => (int) $matches->first()->id);
    }

    private function mapLocationEvent(IntegrationEvent $event, bool $includeEventType): ?array
    {
        $payload = $this->resolvePayload($event);

        $lat = $payload['lat']
            ?? $payload['latitude']
            ?? data_get($payload, 'location.lat')
            ?? data_get($payload, 'gps.lat');

        $lng = $payload['lng']
            ?? $payload['lon']
            ?? $payload['longitude']
            ?? data_get($payload, 'location.lng')
            ?? data_get($payload, 'location.lon')
            ?? data_get($payload, 'gps.lng')
            ?? data_get($payload, 'gps.lon');

        if ($lat === null || $lng === null) {
            return null;
        }

        $coordinates = $this->formatCoordinates($lat, $lng);
        $address = $this->addressFromPayload($payload);

        $location = [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'address' => $address,
            'coordinates' => $coordinates,
            'display_location' => $address ?: $coordinates,
            'timestamp' => $event->occurred_at?->toISOString()
                ?? $event->created_at?->toISOString()
                ?? $event->created_at,
            'speed' => $payload['speed'] ?? $payload['speed_kph'] ?? null,
            'battery' => $payload['battery'] ?? $payload['battery_level'] ?? $payload['battery_pct'] ?? null,
        ];

        if ($includeEventType) {
            $location['event_type'] = $event->event_type;
        }

        return $location;
    }

    private function mapFleetTelemetryEvent(FleetTelemetryEvent $event, bool $includeEventType): array
    {
        $coordinates = $this->formatCoordinates($event->latitude, $event->longitude);

        $location = [
            'lat' => (float) $event->latitude,
            'lng' => (float) $event->longitude,
            'address' => $event->address,
            'coordinates' => $coordinates,
            'display_location' => $event->address ?: $coordinates,
            'timestamp' => $event->occurred_at?->toISOString()
                ?? $event->created_at?->toISOString()
                ?? $event->created_at,
            'speed' => $event->speed_kph !== null ? (float) $event->speed_kph : null,
            'battery' => $event->battery_pct,
        ];

        if ($includeEventType) {
            $location['event_type'] = $event->event_type;
        }

        return $location;
    }

    private function resolvePayload(IntegrationEvent $event): array
    {
        foreach ([
            $event->getAttribute('legacy_payload'),
            $event->raw_payload,
            $event->normalized_payload,
        ] as $payload) {
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }

            if (is_array($payload) && $payload !== []) {
                return $payload;
            }
        }

        return [];
    }

    private function addressFromPayload(array $payload): ?string
    {
        $address = $payload['address']
            ?? $payload['formatted_address']
            ?? data_get($payload, 'location.address')
            ?? data_get($payload, 'gps.address');

        return is_string($address) && trim($address) !== '' ? trim($address) : null;
    }

    private function formatCoordinates(mixed $lat, mixed $lng): string
    {
        return sprintf('%.6f, %.6f', (float) $lat, (float) $lng);
    }

    private function selectColumns(): array
    {
        $columns = [
            'id',
            'hardware_id',
            'event_type',
            'occurred_at',
            'created_at',
            'raw_payload',
            'normalized_payload',
        ];

        if ($this->hasCanonicalDeviceColumn()) {
            $columns[] = 'canonical_device_id';
        }

        if ($this->hasLegacyPayloadColumn()) {
            $columns[] = 'payload as legacy_payload';
        }

        return $columns;
    }

    private function hasCanonicalDeviceColumn(): bool
    {
        static $hasCanonicalColumn;

        if ($hasCanonicalColumn === null) {
            $hasCanonicalColumn = Schema::hasColumn('integration_events', 'canonical_device_id');
        }

        return $hasCanonicalColumn;
    }

    private function hasLegacyPayloadColumn(): bool
    {
        static $hasLegacyPayloadColumn;

        if ($hasLegacyPayloadColumn === null) {
            $hasLegacyPayloadColumn = Schema::hasColumn('integration_events', 'payload');
        }

        return $hasLegacyPayloadColumn;
    }
}
