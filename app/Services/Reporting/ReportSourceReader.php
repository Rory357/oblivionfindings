<?php

namespace App\Services\Reporting;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\FleetFuelLog;
use App\Models\FleetSignal;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\FleetWorkOrder;
use App\Models\LoneWorkerSession;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\Fleet\TransportRequestService;
use App\Services\Fleet\VehicleBookingAccessService;
use App\Services\HealthSafety\LoneWorkerSessionScope;
use App\Services\Integration\IntegrationEventHistoryService;
use App\Services\Tracking\ClientLocationReportWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class ReportSourceReader
{
    public const MAX_ROWS = 100000;

    public function __construct(private readonly ReportAccess $access) {}

    public function read(User $user, array $definition): array
    {
        $context = $this->access->context($user, $definition);
        $from = CarbonImmutable::parse($definition['date_from'], 'Pacific/Auckland')->startOfDay()->utc();
        $to = CarbonImmutable::parse($definition['date_to'], 'Pacific/Auckland')->endOfDay()->utc()->min(CarbonImmutable::now());
        $source = $definition['source'];
        $watermark = ['captured_at' => now()->toISOString()];
        $evidence = new ReportSourceEvidence;
        $rows = [];
        $authority = [];
        $retentionExpires = null;
        if (in_array($source, ['obligations', 'custody', 'stocktakes', 'downtime', 'finance_bills', 'resource_costs'])) {
            $rows = app(FleetSupplementalReports::class)->read($source, $context, $from, $to, $watermark, $evidence);
        } elseif ($context['domain'] === 'fleet') {
            [$query, $dateField] = match ($source) {
                'journeys' => [FleetTrip::query()->whereIn('asset_id', $context['asset_ids'])->where('is_personal', false)->where('consent_blocked', false), 'started_at'],
                'bookings' => [app(VehicleBookingAccessService::class)->accessibleBookings($context['actor'], true)->whereIn('asset_id', $context['asset_ids']), 'starts_at'],
                'maintenance' => [FleetWorkOrder::query()->whereIn('asset_id', $context['asset_ids']), 'created_at'],
                'costs' => [FleetFuelLog::query()->whereIn('asset_id', $context['asset_ids']), 'logged_at'],
                'demand' => [app(TransportRequestService::class)->query($context['actor'])->whereHas('client', fn ($q) => $q->whereIn('site_id', $context['site_ids'])), 'scheduled_at'],
                'resources' => [Asset::query()->with('fleetState.lastTrip', 'site', 'homeSite')->whereIn('id', $context['asset_ids']), null],
            };
            if ($dateField) {
                $query->whereBetween($dateField, [$from, $to]);
            }
            if ($source === 'demand') {
                $query->with('client.site', 'fleetBooking.asset');
            }
            if ($source !== 'resources' && $source !== 'demand') {
                $query->with('asset.site', 'asset.homeSite');
            }
            $rows = $this->queryRows($query, $watermark, $evidence, function ($item) use ($source, $to) {
                $asset = $source === 'resources' ? $item : ($source === 'demand' ? $item->fleetBooking?->asset : $item->asset);
                $at = match ($source) {
                    'journeys' => $item->started_at, 'bookings' => $item->starts_at, 'costs' => $item->logged_at, 'demand' => $item->scheduled_at, 'resources' => now(), default => $item->created_at
                };
                $row = $this->base($item, $at, $asset?->name, $asset?->site?->name ?? $asset?->homeSite?->name ?? ($source === 'demand' ? $item->client?->site?->name : null));

                return $row + match ($source) {
                    'journeys' => ['distance' => $this->number($item->distance_km), 'duration' => $item->duration_s === null ? null : $item->duration_s / 3600],
                    'bookings' => ['booked_hours' => $this->interval($item->starts_at, $item->ends_at, 60), 'used_hours' => $this->interval($item->checked_out_at, $item->returned_at, 60), 'distance' => $item->odometer_out !== null && $item->odometer_in !== null && $item->odometer_in >= $item->odometer_out ? (float) $item->odometer_in - (float) $item->odometer_out : null],
                    'maintenance' => ['waiting_reason' => $item->waiting_reason, 'age_days' => max(0, CarbonImmutable::parse($item->created_at)->diffInDays($item->completed_at ? min(CarbonImmutable::parse($item->completed_at), $to) : $to)), 'completed_at' => $item->completed_at?->toISOString()],
                    'costs' => ['cost' => $this->number($item->total_cost), 'litres' => $this->number($item->quantity_litres), 'cost_stage' => 'Recorded fuel log'],
                    'demand' => ['seats' => $item->required_seats, 'wheelchair' => $item->wheelchair_required ? 'Required' : 'Not required', 'escort' => $item->escort_required ? 'Required' : 'Not required', 'reason' => null],
                    'resources' => ['last_contact' => ($item->fleetState && ! $item->fleetState->consent_blocked && ! $item->fleetState->lastTrip?->is_personal && ! $item->fleetState->lastTrip?->consent_blocked) ? $item->fleetState->last_seen_at?->toISOString() : null, 'distance' => $this->number($item->odometer_km), 'tracking' => ($item->fleetState?->last_seen_at && ! $item->fleetState->consent_blocked && ! $item->fleetState->lastTrip?->is_personal && ! $item->fleetState->lastTrip?->consent_blocked) ? 'Contact recorded' : 'Unknown'],
                };
            });
        } elseif ($context['domain'] === 'client') {
            $assignment = $context['assignment'];
            $evidence->record($assignment->withoutRelations());
            $evidence->record($assignment->device->withoutRelations());
            ['from' => $from, 'to' => $to] = app(ClientLocationReportWindow::class)->resolve($assignment, $definition, 31);
            if ($from <= $to) {
                if ($source === 'client_authority') {
                    $rows = [$this->base($assignment, $assignment->assigned_at, $assignment->device->name, null) + [
                        'purpose' => $assignment->tracking_purpose, 'assigned_at' => $assignment->assigned_at->toISOString(),
                        'collection_started_at' => $assignment->collection_started_at->toISOString(), 'retention_days' => $assignment->retention_days,
                    ]];
                } elseif ($source === 'client_alerts') {
                    $query = app(ControlRoomAlertAccessService::class)->applyReadableScope(ControlRoomAlert::query(), $context['actor'])->where('client_id', $context['client']->id)
                        ->where('site_id', $assignment->custody_site_id)->with('originSignal')
                        ->whereBetween('triggered_at', [$from->utc(), $to->utc()]);
                    $rows = $this->queryRows($query, $watermark, $evidence, fn ($a) => app(PersonalReportEvidence::class)->clientAlert($a, $assignment, $from, $to, $evidence) ? $this->alert($a) : null);
                } elseif ($source === 'client_zones') {
                    $query = FleetSignal::where('device_id', $assignment->device_id)->where('signal_type', 'resident.zone_breach')->whereBetween('occurred_at', [$from, $to]);
                    $rows = $this->queryRows($query, $watermark, $evidence, fn ($signal) => app(PersonalReportEvidence::class)->zone($signal, $assignment, $from, $to, $evidence));
                } else {
                    $rows = $this->deviceRows($assignment, $from, $to, $source === 'client_locations', $source === 'client_zones', $watermark, $evidence);
                }
            }
            $retentionExpires = $this->retentionExpiry($rows, (int) $assignment->retention_days);
        } elseif ($context['domain'] === 'self' || in_array($source, ['staff_sessions', 'staff_alerts'])) {
            $sessions = $context['domain'] === 'self'
                ? LoneWorkerSession::query()->where('user_id', $context['actor']->id)
                : app(LoneWorkerSessionScope::class)->apply(LoneWorkerSession::query(), $context['actor'])->whereIn('site_id', $context['site_ids']);
            if (isset($context['session'])) {
                $sessions->whereKey($context['session']->id);
            }
            if ($source === 'staff_alerts') {
                $allowed = $sessions->get()->keyBy('id');
                $query = app(ControlRoomAlertAccessService::class)->applyReadableScope(ControlRoomAlert::query(), $context['actor'])->where('source', 'lone_worker')->with('originSignal')->whereBetween('triggered_at', [$from, $to])->whereIn('site_id', $context['site_ids']);
                $rows = $this->queryRows($query, $watermark, $evidence, function ($alert) use ($allowed, $evidence) {
                    $session = $allowed->get(data_get($alert->context, 'normalized_data.lone_worker_session_id'));
                    if (! $session || ! app(PersonalReportEvidence::class)->staffAlert($alert, $session, $evidence) || (int) data_get($alert->context, 'normalized_data.worker_user_id') !== (int) $session->user_id
                        || (int) $alert->site_id !== (int) $session->site_id || (int) $alert->client_id !== (int) $session->client_id) {
                        return null;
                    }

                    return $this->alert($alert);
                });
            } else {
                $sessions->whereBetween('started_at', [$from, $to])->with('user:id,name', 'site:id,name')->with('checkIns')->withCount('checkIns');
                $rows = $this->queryRows($sessions, $watermark, $evidence, fn ($s) => $this->base($s, $s->started_at, $source === 'my_safety' ? 'My session' : ($s->user?->name ?? 'Safety session '.$s->id), $source === 'my_safety' ? null : $s->site?->name) + [
                    'started_at' => $s->started_at?->toISOString(), 'ended_at' => $s->ended_at?->toISOString(),
                    'expected_end_at' => $s->expected_end_at?->toISOString(), 'interval_minutes' => $s->check_in_interval_minutes,
                    'check_ins' => $s->check_ins_count, 'duration' => $this->interval($s->started_at, $s->ended_at, 60),
                ]);
            }
        } else {
            $session = $context['session'];
            // Historical authority is explicit; ending a session never restarts collection.
            $assignments = DeviceAssignment::query()->with('device')->where('assignable_type', 'staff')->where('assignable_id', $session->user_id)
                ->where('custody_site_id', $session->site_id)->where('authority_basis', 'active_lone_worker_session')
                ->whereJsonContains('access_audience', 'health_and_safety')->whereNotNull('collection_started_at')
                ->where('assigned_at', '<=', $session->ended_at ?? $to)->where(fn ($q) => $q->whereNull('released_at')->orWhere('released_at', '>', $session->started_at))->get();
            foreach ($assignments as $assignment) {
                if (! $assignment->device || $assignment->retention_days <= 0) {
                    continue;
                }
                $aFrom = collect([$from, CarbonImmutable::parse($session->started_at), CarbonImmutable::parse($assignment->assigned_at), CarbonImmutable::parse($assignment->collection_started_at), CarbonImmutable::now()->subDays($assignment->retention_days)])->max();
                $aTo = collect([$to, $session->ended_at ? CarbonImmutable::parse($session->ended_at) : null, $assignment->released_at ? CarbonImmutable::parse($assignment->released_at)->subMicrosecond() : null, $assignment->collection_stopped_at ? CarbonImmutable::parse($assignment->collection_stopped_at)->subMicrosecond() : null])->filter()->min();
                if ($aFrom > $aTo) {
                    continue;
                }
                $conflict = DeviceAssignment::query()->where('device_id', $assignment->device_id)->where('id', '!=', $assignment->id)
                    ->where('assigned_at', '<=', $aTo)->where(fn ($q) => $q->whereNull('released_at')->orWhere('released_at', '>', $aFrom))->exists();
                abort_if($conflict, 422, 'A tracker has overlapping assignment evidence. Resolve the assignment before reporting.');
                $evidence->record($assignment->withoutRelations());
                $evidence->record($assignment->device->withoutRelations());
                $authority[$assignment->id] = hash('sha256', json_encode($assignment->getAttributes(), JSON_THROW_ON_ERROR));
                $observations = $this->deviceRows($assignment, $aFrom, $aTo, $source === 'staff_locations', false, $watermark, $evidence);
                $expires = $this->retentionExpiry($observations, (int) $assignment->retention_days);
                if ($expires && (! $retentionExpires || $expires < $retentionExpires)) {
                    $retentionExpires = $expires;
                }
                array_push($rows, ...$observations);
                abort_if(count($rows) > self::MAX_ROWS, 422, 'Too many observations. Choose a shorter period.');
            }
        }
        $this->access->recheck($user, $definition, $context['fingerprint']);
        foreach ($authority as $id => $hash) {
            abort_unless(hash_equals($hash, hash('sha256', json_encode(DeviceAssignment::findOrFail($id)->getAttributes(), JSON_THROW_ON_ERROR))), 403);
        }
        $allowed = array_keys(config('operational-reports.sources.'.$source.'.fields'));
        foreach ($rows as &$row) {
            $row = array_intersect_key($row, array_flip($allowed));
            if ($definition['precision'] !== 'exact') {
                foreach (['latitude', 'longitude'] as $key) {
                    if (array_key_exists($key, $row)) {
                        $row[$key] = $definition['precision'] === 'redacted' || $row[$key] === null ? null : round($row[$key], 2);
                    }
                }
            }
        }

        return ['rows' => $rows, 'fingerprint' => $context['fingerprint'], 'assignment_fingerprints' => $authority, 'watermark' => $watermark,
            'retention_expires_at' => $retentionExpires?->toISOString(), 'evidence' => $evidence->snapshot(),
            'window' => ['from' => $from->toISOString(), 'to' => $to->toISOString(), 'timezone' => 'Pacific/Auckland'],
            'coverage' => 'Complete rows that passed source identity and current authority checks. Unverifiable records are excluded; observation continuity is not guaranteed.'];
    }

    private function queryRows(Builder $query, array &$watermark, ReportSourceEvidence $evidence, callable $map): array
    {
        $max = (int) (clone $query)->max($query->qualifyColumn('id'));
        $watermark[$query->getModel()->getTable()] = $max;
        $rows = [];
        $scanned = 0;
        foreach ($query->where($query->qualifyColumn('id'), '<=', $max)->lazyById(500) as $item) {
            abort_if(++$scanned > self::MAX_ROWS, 422, 'This report exceeds 100,000 source rows. Choose a shorter period; no partial result was created.');
            $evidence->record($item);
            $row = $map($item);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function deviceRows(DeviceAssignment $assignment, CarbonImmutable $from, CarbonImmutable $to, bool $locations, bool $zones, array &$watermark, ReportSourceEvidence $evidence): array
    {
        if ($locations) {
            $history = app(IntegrationEventHistoryService::class);
            $watermark += $history->reportWatermark();
            $rows = [];
            foreach ($history->reportForDevice($assignment->device, ['date_from' => $from->utc()->toDateTimeString(), 'date_to' => $to->utc()->toDateTimeString()], $assignment->retention_days, $watermark, fn ($record) => $evidence->record($record)) as $point) {
                abort_if(count($rows) >= self::MAX_ROWS, 422, 'Too many observations. Choose a shorter period.');
                $rows[] = ['reference' => $point['source'].':'.$point['source_id'], 'date' => CarbonImmutable::parse($point['timestamp'])->setTimezone('Pacific/Auckland')->toDateString(),
                    'resource' => $assignment->device->name, 'site' => null, 'status' => 'Observed',
                    'observed_at' => $point['timestamp'], 'received_at' => $point['received_at'], 'latitude' => $point['lat'], 'longitude' => $point['lng'],
                    'accuracy' => $this->number($point['accuracy']), 'battery' => $this->battery($point['battery']), 'speed' => $this->number($point['speed']),
                    'event_type' => $point['event_type'], 'source' => $point['source'], 'source_id' => (string) $point['source_id'], 'gap_minutes' => null];
            }
            usort($rows, fn ($a, $b) => [$a['observed_at'], $a['reference']] <=> [$b['observed_at'], $b['reference']]);
            foreach ($rows as $i => &$row) {
                $row['gap_minutes'] = $i ? CarbonImmutable::parse($rows[$i - 1]['observed_at'])->diffInSeconds(CarbonImmutable::parse($row['observed_at'])) / 60 : null;
            }

            return $rows;
        }
        $query = FleetTelemetryEvent::query()->where('device_id', $assignment->device_id)->where('consent_blocked', false)->whereBetween('occurred_at', [$from->utc(), $to->utc()]);
        if ($zones) {
            $query->whereIn('event_type', ['geofence_enter', 'geofence_exit', 'boundary_enter', 'boundary_exit']);
        }

        return $this->queryRows($query, $watermark, $evidence, function ($e) use ($assignment) {
            $charging = data_get($e->raw_payload, 'charging_status');

            return $this->base($e, $e->occurred_at, $assignment->device->name, null) + [
                'observed_at' => $e->occurred_at?->toISOString(), 'received_at' => $e->received_at?->toISOString(),
                'battery' => $this->battery($e->battery_pct), 'charging' => in_array($charging, ['charging', 'charge_full', 'stopped_charging', 'not_charging']) ? $charging : null,
                'external_power' => $e->external_power === null ? null : ($e->external_power ? 'Reported connected' : 'Reported disconnected'),
                'motion' => match ($e->motion_status) {
                    'motion','moving' => 'Moving', 'rest','stationary' => 'Stationary', default => null
                },
                'event_type' => $e->event_type, 'rule_id' => data_get($e->raw_payload, 'rule_id'), 'geometry_version' => data_get($e->raw_payload, 'geometry_version'),
            ];
        });
    }

    private function base($item, $at, ?string $resource, ?string $site): array
    {
        return ['reference' => $item->reference_number ?: (string) $item->id, 'date' => $at ? CarbonImmutable::parse($at)->setTimezone('Pacific/Auckland')->toDateString() : null,
            'resource' => $resource, 'site' => $site, 'status' => $item->workflow_state ?? $item->status ?? null];
    }

    private function alert(ControlRoomAlert $alert): array
    {
        return $this->base($alert, $alert->triggered_at, 'Safety alert', null) + ['__observed_at' => $alert->triggered_at?->toISOString(), 'event_type' => $alert->alert_type,
            'ack_minutes' => $this->interval($alert->triggered_at, $alert->acknowledged_at),
            'resolution_minutes' => $this->interval($alert->triggered_at, $alert->resolved_at)];
    }

    private function interval($from, $to, int $divisor = 1): ?float
    {
        if (! $from || ! $to || $to < $from) {
            return null;
        }

        return CarbonImmutable::parse($from)->diffInSeconds(CarbonImmutable::parse($to)) / 60 / $divisor;
    }

    private function retentionExpiry(array $rows, int $days): ?CarbonImmutable
    {
        $times = array_values(array_filter(array_map(fn ($row) => $row['observed_at'] ?? $row['__observed_at'] ?? null, $rows)));
        if ($times === []) {
            return null;
        }

        return collect($times)->map(fn ($at) => CarbonImmutable::parse($at)->addDays($days))->min();
    }

    private function number($value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function battery($value): ?float
    {
        $n = $this->number($value);

        return $n !== null && $n >= 0 && $n <= 100 ? $n : null;
    }
}
