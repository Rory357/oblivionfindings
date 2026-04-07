<?php

namespace App\Services\Fleet;

use App\Models\AuditLog;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class FleetTimelineService
{
    /**
     * Build a unified timeline for a vehicle (asset).
     */
    public function forVehicle(int $assetId, ?Carbon $since = null, int $limit = 50): Collection
    {
        $since ??= now()->subDays(7);
        $entries = collect();

        // 1. Audit logs for fleet models related to this asset
        $entries = $entries->merge($this->auditEntriesForAsset($assetId, $since));

        // 2. Fleet signals
        if (Schema::hasTable('fleet_signals')) {
            $entries = $entries->merge($this->signalEntries($assetId, $since));
        }

        // 3. Control room alerts
        $entries = $entries->merge($this->alertEntries($assetId, $since));

        return $entries
            ->sortByDesc('timestamp')
            ->take($limit)
            ->values();
    }

    /**
     * Audit log entries for fleet-related models linked to this asset.
     */
    private function auditEntriesForAsset(int $assetId, Carbon $since): Collection
    {
        $fleetModelTypes = [
            'App\\Models\\FleetTrip',
            'App\\Models\\FleetVehicleBooking',
            'App\\Models\\FleetIncident',
            'App\\Models\\FleetDriverSession',
            'App\\Models\\FleetShiftHandover',
            'App\\Models\\FleetOuting',
            'App\\Models\\FleetFuelLog',
            'App\\Models\\FleetChecklistRun',
            'App\\Models\\Asset',
        ];

        // Get audit logs where the auditable is a fleet model AND the meta contains this asset_id
        // OR the auditable is the Asset itself
        // asset_id may be at root ($.asset_id) or nested under after ($.after.asset_id)
        $logs = AuditLog::query()
            ->where('created_at', '>=', $since)
            ->where(function ($q) use ($assetId, $fleetModelTypes) {
                // Direct asset audits
                $q->where(function ($q2) use ($assetId) {
                    $q2->where('auditable_type', 'App\\Models\\Asset')
                       ->where('auditable_id', $assetId);
                });

                // Fleet model audits that reference this asset via meta (root or nested)
                $q->orWhere(function ($q2) use ($assetId, $fleetModelTypes) {
                    $q2->whereIn('auditable_type', $fleetModelTypes)
                       ->where(function ($q3) use ($assetId) {
                           $q3->whereRaw("JSON_EXTRACT(meta, '$.asset_id') = ?", [$assetId])
                              ->orWhereRaw("JSON_EXTRACT(meta, '$.after.asset_id') = ?", [$assetId]);
                       });
                });

                // Fleet-specific audit actions with asset_id in meta (root or nested)
                $q->orWhere(function ($q2) use ($assetId) {
                    $q2->where('action', 'like', 'fleet.%')
                       ->where(function ($q3) use ($assetId) {
                           $q3->whereRaw("JSON_EXTRACT(meta, '$.asset_id') = ?", [$assetId])
                              ->orWhereRaw("JSON_EXTRACT(meta, '$.after.asset_id') = ?", [$assetId]);
                       });
                });

                // Fleet model audits where auditable is a booking/outing/incident
                // linked to this asset (lookup by auditable_id through the model's asset_id)
                $q->orWhere(function ($q2) use ($assetId) {
                    $q2->where('auditable_type', 'App\\Models\\FleetVehicleBooking')
                       ->whereIn('auditable_id', function ($sub) use ($assetId) {
                           $sub->select('id')->from('fleet_vehicle_bookings')->where('asset_id', $assetId);
                       });
                });
                $q->orWhere(function ($q2) use ($assetId) {
                    $q2->where('auditable_type', 'App\\Models\\FleetOuting')
                       ->whereIn('auditable_id', function ($sub) use ($assetId) {
                           $sub->select('id')->from('fleet_outings')->where('asset_id', $assetId);
                       });
                });
                $q->orWhere(function ($q2) use ($assetId) {
                    $q2->where('auditable_type', 'App\\Models\\FleetIncident')
                       ->whereIn('auditable_id', function ($sub) use ($assetId) {
                           $sub->select('id')->from('fleet_incidents')->where('asset_id', $assetId);
                       });
                });
            })
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return $logs->map(function (AuditLog $log) {
            return [
                'id' => 'audit_' . $log->id,
                'timestamp' => $log->created_at->toISOString(),
                'type' => $this->mapAuditAction($log->action),
                'category' => 'audit',
                'severity' => 'low',
                'actor' => $log->user?->name,
                'detail' => $this->formatAuditDetail($log),
                'meta' => [
                    'action' => $log->action,
                    'fields' => $log->meta['fields'] ?? null,
                ],
            ];
        });
    }

    /**
     * Fleet signal entries for this asset.
     */
    private function signalEntries(int $assetId, Carbon $since): Collection
    {
        $signals = FleetSignal::query()
            ->where('asset_id', $assetId)
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->limit(30)
            ->get();

        return $signals->map(function (FleetSignal $signal) {
            return [
                'id' => 'signal_' . $signal->id,
                'timestamp' => optional($signal->occurred_at)->toISOString(),
                'type' => $this->mapSignalType($signal->signal_type),
                'category' => 'signal',
                'severity' => $signal->severity_hint ?? 'low',
                'actor' => null,
                'detail' => $this->formatSignalDetail($signal),
                'meta' => [
                    'signal_type' => $signal->signal_type,
                    'payload' => $signal->payload,
                    'trip_id' => $signal->trip_id,
                    'geofence_id' => $signal->geofence_id,
                ],
            ];
        });
    }

    /**
     * Control room alert entries for this asset.
     */
    private function alertEntries(int $assetId, Carbon $since): Collection
    {
        $alerts = ControlRoomAlert::query()
            ->where('asset_id', $assetId)
            ->where('triggered_at', '>=', $since)
            ->orderByDesc('triggered_at')
            ->limit(20)
            ->get();

        return $alerts->map(function (ControlRoomAlert $alert) {
            return [
                'id' => 'alert_' . $alert->id,
                'timestamp' => optional($alert->triggered_at)->toISOString(),
                'type' => 'control_room_alert',
                'category' => 'alert',
                'severity' => $alert->severity ?? 'medium',
                'actor' => null,
                'detail' => $alert->alert_type . ($alert->status ? " ({$alert->status})" : ''),
                'meta' => [
                    'alert_id' => $alert->id,
                    'alert_type' => $alert->alert_type,
                    'status' => $alert->status,
                ],
            ];
        });
    }

    // ── Formatting helpers ──────────────────────────────────────────

    private function mapAuditAction(string $action): string
    {
        $map = [
            'fleet.booking.create' => 'booking_created',
            'fleet.booking.approve' => 'booking_approved',
            'fleet.booking.reject' => 'booking_rejected',
            'fleet.booking.checkout' => 'vehicle_checkout',
            'fleet.booking.return' => 'vehicle_returned',
            'fleet.outing.create' => 'outing_created',
            'fleet.outing.start' => 'outing_started',
            'fleet.outing.complete' => 'outing_completed',
            'fleet.incident.create' => 'incident_reported',
            'fleet.incident.update' => 'incident_updated',
            'fleet.checklist.run' => 'inspection_completed',
            'fleet.fuel.create' => 'fuel_logged',
            'fleetvehiclebooking.create' => 'booking_created',
            'fleetvehiclebooking.update' => 'booking_updated',
            'fleetincident.create' => 'incident_reported',
            'fleetincident.update' => 'incident_updated',
            'fleettripclose' => 'trip_ended',
            'fleetouting.create' => 'outing_created',
            'fleetouting.update' => 'outing_updated',
            'fleetdriversession.create' => 'driver_session_started',
            'fleetdriversession.update' => 'driver_session_ended',
            'fleetshifthandover.create' => 'handover_created',
            'fleetfuellog.create' => 'fuel_logged',
            'fleetchecklistrun.create' => 'inspection_completed',
            'asset.update' => 'vehicle_updated',
        ];

        return $map[$action] ?? $action;
    }

    private function mapSignalType(string $signalType): string
    {
        $map = [
            'geofence.enter' => 'geofence_enter',
            'geofence.breach' => 'geofence_breach',
            'geofence.exit' => 'geofence_exit',
            'geofence.dwell' => 'geofence_dwell',
            'vehicle.sos' => 'sos_alert',
            'device.tamper' => 'device_tamper',
            'vehicle_offline' => 'vehicle_offline',
            'device.offline' => 'device_offline',
            'wof_expiring' => 'wof_expiring',
            'wof_expired' => 'wof_expired',
            'registration_expiring' => 'registration_expiring',
            'maintenance_overdue' => 'maintenance_overdue',
            'low_battery' => 'low_battery',
            'vehicle_overdue' => 'vehicle_overdue',
            'incident.reported' => 'incident_signal',
        ];

        return $map[$signalType] ?? 'signal_' . str_replace('.', '_', $signalType);
    }

    private function formatAuditDetail(AuditLog $log): string
    {
        $action = $log->action;
        $meta = $log->meta ?? [];

        if (str_ends_with($action, '.create')) {
            $model = str_replace('.create', '', $action);
            return ucfirst(str_replace(['fleet.', '_'], ['', ' '], $model)) . ' created';
        }
        if (str_ends_with($action, '.update')) {
            $fields = $meta['fields'] ?? [];
            $fieldStr = count($fields) > 3
                ? implode(', ', array_slice($fields, 0, 3)) . '…'
                : implode(', ', $fields);
            return 'Updated' . ($fieldStr ? ": {$fieldStr}" : '');
        }
        if (str_ends_with($action, '.delete')) {
            return 'Deleted';
        }

        // Named fleet actions
        $labels = [
            'fleet.booking.approve' => 'Booking approved',
            'fleet.booking.reject' => 'Booking rejected',
            'fleet.booking.checkout' => 'Vehicle checked out',
            'fleet.booking.return' => 'Vehicle returned',
            'fleet.outing.start' => 'Outing started',
            'fleet.outing.complete' => 'Outing completed',
            'fleet.incident.create' => 'Incident reported',
            'fleet.checklist.run' => 'Inspection completed',
            'fleet.fuel.create' => 'Fuel logged',
        ];

        return $labels[$action] ?? str_replace(['.', '_'], ' ', $action);
    }

    private function formatSignalDetail(FleetSignal $signal): string
    {
        $payload = $signal->payload ?? [];
        $type = $signal->signal_type;

        if (str_starts_with($type, 'geofence.')) {
            $name = $payload['geofence_name'] ?? 'geofence';
            return match ($type) {
                'geofence.enter' => "Entered {$name}",
                'geofence.exit' => "Left {$name}",
                'geofence.breach' => "Breached {$name}",
                'geofence.dwell' => "Dwelling in {$name}" . (isset($payload['dwell_minutes']) ? " ({$payload['dwell_minutes']}m)" : ''),
                default => ucfirst(str_replace('.', ' ', $type)),
            };
        }

        $labels = [
            'vehicle.sos' => 'SOS alert triggered',
            'device.tamper' => 'Device tamper detected',
            'vehicle_offline' => 'Vehicle went offline',
            'device.offline' => 'Tracking device offline',
            'vehicle_overdue' => 'Vehicle return overdue',
            'low_battery' => 'Low battery' . (isset($payload['battery_pct']) ? " ({$payload['battery_pct']}%)" : ''),
            'wof_expiring' => 'WOF expiring' . (isset($payload['days_remaining']) ? " in {$payload['days_remaining']} days" : ''),
            'wof_expired' => 'WOF expired',
            'registration_expiring' => 'Registration expiring' . (isset($payload['days_remaining']) ? " in {$payload['days_remaining']} days" : ''),
            'maintenance_overdue' => 'Maintenance overdue',
            'incident.reported' => 'Incident signal' . (isset($payload['incident_type']) ? ": {$payload['incident_type']}" : ''),
        ];

        return $labels[$type] ?? ucfirst(str_replace(['.', '_'], ' ', $type));
    }
}
