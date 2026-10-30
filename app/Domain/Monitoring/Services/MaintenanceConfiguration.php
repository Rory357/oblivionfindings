<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;

final class MaintenanceConfiguration
{
    public static function snapshot(MonitoringMaintenanceWindow $window): array
    {
        return [
            'id' => (int) $window->id, 'version' => (int) $window->version, 'state' => $window->status,
            'site_id' => (int) $window->site_id, 'device_id' => $window->device_id === null ? null : (int) $window->device_id,
            'monitor_id' => $window->monitor_id === null ? null : (int) $window->monitor_id,
            'name' => $window->name, 'reason' => $window->reason, 'policy' => $window->policy,
            'starts_at' => $window->starts_at->utc()->toIso8601String(), 'ends_at' => $window->ends_at->utc()->toIso8601String(),
            'recurrence' => $window->recurrence, 'recurrence_until' => $window->recurrence_until?->utc()->toIso8601String(),
            'timezone' => $window->timezone, 'dst_overlap' => $window->dst_overlap, 'dst_gap' => $window->dst_gap,
            'created_by_user_id' => $window->created_by_user_id, 'updated_by_user_id' => $window->updated_by_user_id,
            'cancelled_at' => $window->cancelled_at?->utc()->toIso8601String(),
            'cancelled_by_user_id' => $window->cancelled_by_user_id, 'cancellation_reason' => $window->cancellation_reason,
        ];
    }

    public static function hash(array $snapshot): string
    {
        ksort($snapshot);

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
