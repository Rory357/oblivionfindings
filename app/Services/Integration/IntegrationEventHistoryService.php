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
    public function forDevice(
        ?Device $device,
        array $filters = [],
        bool $includeEventType = false,
        ?int $retentionDays = null,
    ): Collection {
        if (! $device) {
            return collect();
        }

        $retentionDays = max(
            1,
            $retentionDays ?? (int) config('fleet.retention.telemetry_days', 365),
        );
        $retentionCutoff = now()->subDays($retentionDays);
        $locations = $this->integrationEventLocations(
            $device,
            $filters,
            $includeEventType,
            $retentionCutoff,
        )->merge($this->fleetTelemetryLocations(
            $device,
            $filters,
            $includeEventType,
            $retentionCutoff,
        ));

        return $locations
            ->sortByDesc(fn (array $location) => $location['timestamp'] ?? '')
            ->take(500)
            ->values();
    }

    private function integrationEventLocations(
        Device $device,
        array $filters,
        bool $includeEventType,
        \DateTimeInterface $retentionCutoff,
    ): Collection {
        if (! Schema::hasTable('integration_events')) {
            return collect();
        }

        $hasCanonicalColumn = $this->hasCanonicalDeviceColumn();
        $legacyHardwareId = $device->legacy_location_hardware_id;

        if (! $hasCanonicalColumn && ! $legacyHardwareId) {
            return collect();
        }

        $query = IntegrationEvent::query()
            ->select($this->selectColumns())
            ->where('occurred_at', '>=', $retentionCutoff)
            ->where(function (Builder $query) use ($device, $legacyHardwareId, $hasCanonicalColumn): void {
                if ($hasCanonicalColumn) {
                    $query->where('canonical_device_id', $device->id);
                }

                if (! $legacyHardwareId) {
                    return;
                }

                if ($hasCanonicalColumn) {
                    $query->orWhere(function (Builder $fallback) use ($legacyHardwareId): void {
                        $fallback->whereNull('canonical_device_id')
                            ->where('hardware_id', $legacyHardwareId);
                    });

                    return;
                }

                $query->where('hardware_id', $legacyHardwareId);
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
            ->limit(500)
            ->get()
            ->toBase()
            ->map(fn (IntegrationEvent $event) => $this->mapLocationEvent($event, $includeEventType))
            ->filter()
            ->values();
    }

    private function fleetTelemetryLocations(
        Device $device,
        array $filters,
        bool $includeEventType,
        \DateTimeInterface $retentionCutoff,
    ): Collection {
        if (! Schema::hasTable('fleet_telemetry_events')) {
            return collect();
        }

        $legacyTrackerId = $device->legacy_asset_tracker_id;

        $query = FleetTelemetryEvent::query()
            ->where('consent_blocked', false)
            ->where('occurred_at', '>=', $retentionCutoff)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(function (Builder $query) use ($device, $legacyTrackerId): void {
                $query->where('device_id', $device->id);

                if ($legacyTrackerId) {
                    $query->orWhere(function (Builder $fallback) use ($legacyTrackerId): void {
                        $fallback->whereNull('device_id')
                            ->where('asset_tracker_id', $legacyTrackerId);
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
            ->limit(500)
            ->get()
            ->toBase()
            ->map(fn (FleetTelemetryEvent $event) => $this->mapFleetTelemetryEvent($event, $includeEventType))
            ->values();
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
