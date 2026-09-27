<?php

namespace App\Services\Reporting;

use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Private, encrypted provenance for every contributing record, before projection
 * or aggregation. A changed/deleted contributor invalidates the entire result.
 */
final class ReportSourceEvidence
{
    private const VERSION = 1;

    private const LIMIT = 500000;

    private const TABLES = [
        'assets', 'sites', 'users', 'clients', 'devices', 'device_assignments',
        'fleet_trips', 'fleet_fuel_logs', 'fleet_work_orders', 'fleet_vehicle_bookings',
        'fleet_vehicle_state_snapshots', 'client_transport_bookings',
        'fleet_telemetry_events', 'integration_events', 'fleet_signals',
        'control_room_alerts', 'control_room_signals', 'lone_worker_sessions',
        'lone_worker_check_ins', 'client_geofence_monitors', 'client_geofence_rules',
        'client_geofence_rule_versions', 'fleet_obligation_reminders',
        'asset_custody_movements', 'asset_stocktakes', 'fleet_maintenance_restrictions',
        'fin_bills', 'fin_journals',
    ];

    private array $groups = [];

    private array $columns = [];

    private int $count = 0;

    public function record(Model $model): void
    {
        $table = $model->getTable();
        abort_unless(in_array($table, self::TABLES, true), 422, 'Unsupported report evidence.');
        $columns = $this->columns[$table] ??= Schema::getColumnListing($table);
        $original = $model->getRawOriginal();
        // The canonical history reader aliases the old physical payload column.
        if ($table === 'integration_events' && array_key_exists('legacy_payload', $original) && in_array('payload', $columns, true)) {
            $original['payload'] = $original['legacy_payload'];
        }
        $attributes = array_intersect_key($original, array_flip($columns));
        ksort($attributes);
        $primaryKey = $table === 'fleet_vehicle_state_snapshots' ? 'asset_id' : 'id';
        abort_unless(isset($attributes[$primaryKey]), 422, 'Missing report evidence identity.');
        $fields = array_keys($attributes);
        $key = $table.':'.hash('sha256', implode(',', $fields));
        $id = (string) $attributes[$primaryKey];
        $hash = self::digest($attributes);
        $existing = $this->groups[$key]['records'][$id] ?? null;
        abort_if($existing !== null && ! hash_equals($existing, $hash), 403, 'Report access changed. Run it again.');
        if ($existing === null) {
            abort_if(++$this->count > self::LIMIT, 422, 'Too much supporting evidence. Choose a narrower report.');
            $this->groups[$key] ??= ['table' => $table, 'fields' => $fields, 'records' => []];
            $this->groups[$key]['records'][$id] = $hash;
        }
        // Only relationships used by the source reader are loaded.
        foreach ($model->getRelations() as $relation) {
            foreach ($relation instanceof Model ? [$relation] : (is_iterable($relation) ? $relation : []) as $related) {
                if ($related instanceof Model) {
                    $this->record($related);
                }
            }
        }
    }

    public function snapshot(): array
    {
        return ['version' => self::VERSION, 'groups' => array_values($this->groups)];
    }

    public static function assertCurrent(User $actor, mixed $snapshot): void
    {
        $message = 'The records or access behind this report have changed. Run it again.';
        // Older payloads cannot establish current privacy; never serve them.
        abort_unless(is_array($snapshot) && ($snapshot['version'] ?? null) === self::VERSION && is_array($snapshot['groups'] ?? null), 403, $message);
        $count = 0;
        $deviceIds = [];
        foreach ($snapshot['groups'] as $group) {
            if (($group['table'] ?? null) === 'devices') {
                $deviceIds += array_fill_keys(array_keys($group['records'] ?? []), true);
            }
        }
        foreach ($snapshot['groups'] as $group) {
            $table = $group['table'] ?? null;
            $fields = $group['fields'] ?? [];
            $records = $group['records'] ?? [];
            abort_unless(in_array($table, self::TABLES, true) && is_array($fields) && in_array($table === 'fleet_vehicle_state_snapshots' ? 'asset_id' : 'id', $fields, true) && is_array($records), 403, $message);
            abort_if(array_diff($fields, Schema::getColumnListing($table)) || ($count += count($records)) > self::LIMIT, 403, $message);
            $primaryKey = $table === 'fleet_vehicle_state_snapshots' ? 'asset_id' : 'id';
            foreach (array_chunk($records, 500, true) as $chunk) {
                $query = DB::table($table)->whereIn($primaryKey, array_keys($chunk));
                if ($table === 'control_room_alerts') {
                    $readable = app(ControlRoomAlertAccessService::class)->applyReadableScope(ControlRoomAlert::query(), $actor)->select('control_room_alerts.id');
                    $query->whereIn('id', $readable);
                }
                $current = $query->get($fields)->keyBy($primaryKey);
                if (in_array($table, ['integration_events', 'fleet_telemetry_events'], true)) {
                    self::assertLegacyIdentity($current, $table, $deviceIds, $message);
                }
                foreach ($chunk as $id => $hash) {
                    abort_unless(isset($current[$id]) && is_string($hash) && hash_equals($hash, self::digest((array) $current[$id])), 403, $message);
                }
            }
        }
    }

    private static function assertLegacyIdentity(Collection $records, string $table, array $deviceIds, string $message): void
    {
        [$canonical, $legacy, $link] = $table === 'integration_events'
            ? ['canonical_device_id', 'hardware_id', 'legacy_location_hardware_id']
            : ['device_id', 'asset_tracker_id', 'legacy_asset_tracker_id'];
        $ids = $records->filter(fn ($row) => ($row->$canonical ?? null) === null)->pluck($legacy)->filter()->unique()->values()->all();
        if ($ids === []) {
            return;
        }
        // The history reader includes soft-deleted Devices when rejecting an
        // ambiguous legacy link. A newly inserted duplicate also revokes it.
        $links = DB::table('devices')->whereIn($link, $ids)->get(['id', $link])->groupBy($link);
        foreach ($ids as $id) {
            $matches = $links->get($id, collect());
            abort_unless($matches->count() === 1 && isset($deviceIds[$matches->first()->id]), 403, $message);
        }
    }

    private static function digest(array $attributes): string
    {
        ksort($attributes);
        // PDO/Eloquent can represent the same scalar as an integer or a string.
        $attributes = array_map(fn ($value) => $value === null ? null : (string) $value, $attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }
}
