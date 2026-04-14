<?php

namespace App\Services\Integration;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Integration\IntegrationEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class IntegrationEventHistoryService
{
    public function forDevice(?Device $device, array $filters = [], bool $includeEventType = false): Collection
    {
        if (!$device || !Schema::hasTable('integration_events')) {
            return collect();
        }

        $hasCanonicalColumn = $this->hasCanonicalDeviceColumn();
        $legacyHardwareId = $device->legacy_location_hardware_id;

        if (!$hasCanonicalColumn && !$legacyHardwareId) {
            return collect();
        }

        $query = IntegrationEvent::query()
            ->select($this->selectColumns())
            ->where(function (Builder $query) use ($device, $legacyHardwareId, $hasCanonicalColumn): void {
                if ($hasCanonicalColumn) {
                    $query->where('canonical_device_id', $device->id);
                }

                if (!$legacyHardwareId) {
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

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        return $query->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn (IntegrationEvent $event) => $this->mapLocationEvent($event, $includeEventType))
            ->filter()
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

        $location = [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'timestamp' => $event->created_at?->toISOString() ?? $event->created_at,
            'speed' => $payload['speed'] ?? $payload['speed_kph'] ?? null,
            'battery' => $payload['battery'] ?? $payload['battery_level'] ?? $payload['battery_pct'] ?? null,
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

    private function selectColumns(): array
    {
        $columns = [
            'id',
            'hardware_id',
            'event_type',
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
