<?php

namespace App\Support\HealthSafety;

use App\Models\AuditLog;
use App\Models\LoneWorkerCheckIn;
use App\Models\LoneWorkerEmergencyEpisode;
use App\Models\LoneWorkerSession;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\LoneWorkerCoverageService;
use App\Services\HealthSafety\LoneWorkerSessionAccessService;
use App\Services\HealthSafety\LoneWorkerSessionConnectionsService;
use Illuminate\Support\Carbon;

/** Source observations, accepted requests and independently authorized response facts. */
final class LoneWorkerSessionPresenter
{
    private const TITLES = ['healthSafety.loneWorker.session.start' => 'Session started', 'healthSafety.loneWorker.session.update' => 'Session updated',
        'healthSafety.loneWorker.session.checkIn' => 'Check-in recorded', 'healthSafety.loneWorker.session.end' => 'Session ended',
        'healthSafety.loneWorker.session.delete' => 'Session removed', 'healthSafety.loneWorker.session.emergency' => 'Help requested',
        'healthSafety.loneWorker.session.emergencyResolved' => 'Source emergency resumed',
        'healthSafety.loneWorker.session.responseAcknowledged' => 'Operational response acknowledged',
        'healthSafety.loneWorker.session.responseResolved' => 'Operational response resolved',
        'healthSafety.loneWorker.session.locationManagementOpened' => 'Location management opened',
        'healthSafety.loneWorker.session.trackerPanicAcknowledged' => 'Tracker panic flag acknowledged'];

    public function __construct(private readonly LoneWorkerSessionAccessService $access, private readonly LoneWorkerCoverageService $coverage,
        private readonly LoneWorkerSessionConnectionsService $connections) {}

    public function detail(LoneWorkerSession $session, User $viewer, ?string $returnTo = null): array
    {
        return $this->access->withCurrentViewer($viewer, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($session, $returnTo) {
            $record = $reads->query(LoneWorkerSession::query())->findOrFail($session->id);
            $this->access->assertRecord($record, $current, $sites);
            $observation = $this->coverage->observation($record, $current);
            $can = $this->access->commandCan($record, $current);
            $tracker = $this->access->trackerForViewer($record, $current);
            $connections = $this->connections->forViewer($record, $current, $returnTo);
            $checks = $reads->query(LoneWorkerCheckIn::query())->where('lone_worker_session_id', $record->id)->orderByDesc('checked_in_at')->orderByDesc('id')->get();
            $actors = $reads->query(User::query())->whereIn('id', $checks->pluck('recorded_by_user_id')->filter()->unique())->get(['id', 'name'])->keyBy('id');
            $checkIns = $checks->map(fn ($check) => ['id' => (int) $check->id, 'checked_in_at' => $check->checked_in_at?->toIso8601String(),
                'received_at' => ($check->received_at ?? $check->created_at)?->toIso8601String(),
                'receipt_source' => $check->received_at ? 'recorded_receipt' : ($check->created_at ? 'legacy_created_at' : 'unknown'),
                'status' => $check->status, 'notes' => $check->notes, 'recorded_by' => $this->actor($actors->get($check->recorded_by_user_id)),
                'location_lat' => $current->canDo('assets.telemetry.view') ? $check->location_lat : null,
                'location_lng' => $current->canDo('assets.telemetry.view') ? $check->location_lng : null])->all();
            $legacy = $reads->query($record->alerts()->getQuery())->orderByDesc('triggered_at')->orderByDesc('id')->get()->map(fn ($alert) => [
                'id' => 'legacy_'.$alert->id, 'type' => $alert->alert_type, 'triggered_at' => $alert->triggered_at?->toIso8601String(),
                'status' => 'historical', 'recorded_status' => $alert->status, 'source' => 'legacy_history', 'label' => 'Historical record - read only'])->all();
            $delivery = $this->delivery($record, $reads);
            $trackerCan = $tracker['tracker'] !== null && in_array($tracker['state'], ['available', 'stale', 'unknown'], true);
            $can['locate'] = $can['locate'] && $trackerCan;
            $can['acknowledge_panic'] = $can['acknowledge_panic'] && $trackerCan && ($tracker['tracker']['panic_active'] ?? false);

            return [...$this->base($record, $observation, $current, $reads), 'user' => $this->actor($record->user), 'worker' => $this->actor($record->user),
                'location' => $record->location, 'location_lat' => null, 'location_lng' => null,
                'location_state' => 'unverified', 'location_provenance' => 'unverified_source_coordinates',
                'emergency_triggered_at' => $record->emergency_triggered_at?->toIso8601String(), 'emergency_notes' => $record->emergency_notes,
                'planned_location' => $this->plannedLocation($record->planned_location, $record, $reads),
                'created_at' => $record->created_at?->toIso8601String(), 'shift' => $connections['shift'], 'can' => $can,
                'check_ins' => $checkIns, 'legacy_alert_history' => $legacy, 'alerts' => $connections['control_room'],
                'tracker_state' => $tracker['state'], 'tracker' => $tracker['tracker'], 'source_delivery' => $delivery,
                'current_episode' => $delivery, 'connections' => $connections,
                'history' => $this->history($record, $reads, array_column($checkIns, 'id'), array_column($connections['control_room'], 'id')),
                'href' => LoneWorkerSessionReturn::href($record->id, $returnTo)];
        });
    }

    public function selfSummary(LoneWorkerSession $session, User $viewer): array
    {
        return $this->access->withCurrentViewer($viewer, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($session) {
            $record = $reads->query(LoneWorkerSession::query())->where('user_id', $current->id)->findOrFail($session->id);
            $this->access->assertRecord($record, $current, $sites, false, true);
            $observation = $this->coverage->observation($record, $current);

            return [...$this->base($record, $observation, $current, $reads), 'can' => $this->access->commandCan($record, $current),
                'source_delivery' => $this->delivery($record, $reads), 'href' => LoneWorkerSessionReturn::href($record->id, '/my-day')];
        });
    }

    public function row(LoneWorkerSession $session, User $viewer, ?string $returnTo = null): array
    {
        return $this->access->withCurrentViewer($viewer, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($session, $returnTo) {
            $record = $reads->query(LoneWorkerSession::query())->findOrFail($session->id);
            $this->access->assertRecord($record, $current, $sites);
            $observation = $this->coverage->observation($record, $current);

            return [...$this->base($record, $observation, $current, $reads), 'user' => $this->actor($record->user),
                'user_name' => $record->user?->name, 'site_name' => $record->site?->name, 'client_name' => $this->client($record, $current, $reads)['name'] ?? null,
                'location' => $record->location, 'can' => $this->access->commandCan($record, $current),
                'source_delivery' => $this->delivery($record, $reads), 'href' => LoneWorkerSessionReturn::href($record->id, $returnTo)];
        });
    }

    public function pickers(User $viewer, ?LoneWorkerSession $session = null): array
    {
        $options = $this->access->pickerOptions($viewer, $session);

        return ['clients' => collect($options['clients'] ?? [])->map(fn ($client) => ['id' => (int) data_get($client, 'id'), 'name' => data_get($client, 'name'), 'site_id' => (int) data_get($client, 'site_id')])->values()->all(),
            'sites' => collect($options['sites'] ?? [])->map(fn ($site) => ['id' => (int) data_get($site, 'id'), 'name' => data_get($site, 'name'),
                'address' => data_get($site, 'address'), 'latitude' => data_get($site, 'latitude'), 'longitude' => data_get($site, 'longitude')])->values()->all(),
            'workers' => collect($options['workers'] ?? [])->map(fn ($worker) => ['id' => (int) data_get($worker, 'id'), 'name' => data_get($worker, 'name'), 'site_ids' => data_get($worker, 'site_ids', [])])->values()->all(),
            'shifts' => collect($options['shifts'] ?? [])->map(fn ($shift) => ['id' => (int) data_get($shift, 'id'), 'user_id' => (int) data_get($shift, 'user_id', data_get($shift, 'worker.id')), 'worker' => data_get($shift, 'worker'),
                'site_id' => data_get($shift, 'site_id'), 'client_id' => data_get($shift, 'client_id'), 'status' => data_get($shift, 'status'),
                'location' => data_get($shift, 'location'), 'address' => data_get($shift, 'address'), 'latitude' => null, 'longitude' => null,
                'starts_at' => $this->instant(data_get($shift, 'starts_at')), 'ends_at' => $this->instant(data_get($shift, 'ends_at')),
                'actual_starts_at' => $this->instant(data_get($shift, 'actual_starts_at')), 'is_lone_worker' => data_get($shift, 'is_lone_worker')])->values()->all(),
            'can_geocode' => ($options['can_geocode'] ?? false) === true,
            'check_in_statuses' => ['ok', 'concern'], 'check_in_intervals' => [15, 30, 60, 90, 120]];
    }

    private function base(LoneWorkerSession $record, array $observation, User $current, CurrentAuthorizationReads $reads): array
    {
        $due = $observation['next_due_at'] ?? null;
        $client = $this->client($record, $current, $reads);

        return ['id' => (int) $record->id, 'reference' => 'LW-'.$record->id, 'status' => $record->status,
            'lock_version' => (int) $record->lock_version, 'expected_version' => (int) $record->lock_version,
            'user_id' => (int) $record->user_id, 'site_id' => (int) $record->site->id, 'client_id' => $client['id'] ?? null, 'shift_id' => $record->shift_id,
            'started_at' => $record->started_at?->toIso8601String(), 'expected_end_at' => $record->expected_end_at?->toIso8601String(),
            'ended_at' => $record->ended_at?->toIso8601String(), 'last_check_in_at' => $observation['real_check_in_at'],
            'check_in_interval_minutes' => (int) $record->check_in_interval_minutes, 'next_check_in_at' => $due,
            'is_check_in_overdue' => $record->isCheckInOverdue($due ? Carbon::parse($due) : null),
            'activity_description' => $record->activity_description,
            'site' => ['id' => $record->site->id, 'name' => $record->site->name],
            'client' => $client,
            'observation' => $observation];
    }

    private function client(LoneWorkerSession $record, User $current, CurrentAuthorizationReads $reads): ?array
    {
        $client = $record->client;

        return $client && app(ClientPolicy::class)->viewFromCurrentEvidence($current, $client, $reads)
            ? ['id' => (int) $client->id, 'name' => trim($client->first_name.' '.$client->last_name)] : null;
    }

    private function delivery(LoneWorkerSession $record, CurrentAuthorizationReads $reads): ?array
    {
        if ((int) $record->emergency_episode_number < 1) {
            return null;
        }
        $episode = $reads->query(LoneWorkerEmergencyEpisode::query())->where('lone_worker_session_id', $record->id)->where('episode_number', $record->emergency_episode_number)->first();
        if (! $episode) {
            return null;
        }

        return ['id' => (int) $episode->id, 'episode_number' => (int) $episode->episode_number,
            'status' => in_array($episode->status, ['pending', 'failed', 'delivered', 'suppressed', 'skipped'], true) ? $episode->status : 'unknown',
            'attempts' => (int) $episode->attempts, 'occurred_at' => $episode->occurred_at?->toIso8601String(),
            'failure_code' => in_array($episode->failure_code, ['source_inactive', 'source_authority_revoked', 'queue_unavailable', 'signal_delivery_failed', 'correlation_conflict'], true) ? $episode->failure_code : null,
            'next_attempt_at' => $episode->next_attempt_at?->toIso8601String(), 'delivered_at' => $episode->delivered_at?->toIso8601String()];
    }

    private function history(LoneWorkerSession $record, CurrentAuthorizationReads $reads, array $checkIds, array $alertIds): array
    {
        $logs = $reads->query(AuditLog::query())->whereIn('auditable_type', array_unique([LoneWorkerSession::class, $record->getMorphClass()]))->where('auditable_id', $record->id)->orderBy('created_at')->orderBy('id')->get();
        $actors = $reads->query(User::query())->whereIn('id', $logs->pluck('user_id')->filter()->unique())->get(['id', 'name'])->keyBy('id');
        $episodeIds = $reads->query(LoneWorkerEmergencyEpisode::query())->where('lone_worker_session_id', $record->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $rows = [];
        foreach ($logs as $log) {
            $meta = is_array($log->meta) ? $log->meta : [];
            $semantic = isset(self::TITLES[$log->action]);
            if (in_array($log->action, ['healthSafety.loneWorker.session.responseAcknowledged', 'healthSafety.loneWorker.session.responseResolved'], true)
                && (! is_int($meta['alert_id'] ?? null) || ! in_array($meta['alert_id'], $alertIds, true))) {
                continue;
            }
            $snapshot = [];
            if ($semantic && is_array($meta['snapshot'] ?? null)) {
                if (array_key_exists('planned_location', $meta['snapshot'])) {
                    $snapshot['planned_location'] = $this->plannedLocation($meta['snapshot']['planned_location'], $record, $reads);
                }
                foreach (['status', 'location', 'activity_description', 'emergency_notes'] as $key) {
                    if (array_key_exists($key, $meta['snapshot']) && (is_string($meta['snapshot'][$key]) || $meta['snapshot'][$key] === null)) {
                        $snapshot[$key] = $meta['snapshot'][$key];
                    }
                }
                foreach (['check_in_interval_minutes', 'emergency_episode_number'] as $key) {
                    if (is_int($meta['snapshot'][$key] ?? null)) {
                        $snapshot[$key] = $meta['snapshot'][$key];
                    }
                }
                foreach (['started_at', 'expected_end_at', 'ended_at', 'last_check_in_at', 'emergency_triggered_at'] as $key) {
                    $value = $meta['snapshot'][$key] ?? null;
                    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
                        $snapshot[$key] = $value;
                    }
                }
            }
            if ($semantic) {
                if (is_int($meta['alert_id'] ?? null) && in_array($meta['alert_id'], $alertIds, true)) {
                    $snapshot['alert_id'] = $meta['alert_id'];
                }
                foreach (['from', 'to'] as $key) {
                    if (is_string($meta[$key] ?? null) && strlen($meta[$key]) <= 50) {
                        $snapshot[$key] = $meta[$key];
                    }
                }
                if (is_int($meta['check_in_id'] ?? null) && in_array($meta['check_in_id'], $checkIds, true)) {
                    $snapshot['check_in_id'] = $meta['check_in_id'];
                    $check = is_array($meta['check_in_snapshot'] ?? null) ? $meta['check_in_snapshot'] : [];
                    $snapshot['check_in'] = array_filter(['status' => is_string($check['status'] ?? null) ? $check['status'] : null,
                        'notes' => is_string($check['notes'] ?? null) ? $check['notes'] : null,
                        'checked_in_at' => $this->auditInstant($check['checked_in_at'] ?? null), 'received_at' => $this->auditInstant($check['received_at'] ?? null)], fn ($value) => $value !== null);
                }
                if (is_int($meta['episode_id'] ?? null) && in_array($meta['episode_id'], $episodeIds, true)) {
                    $snapshot['episode_id'] = $meta['episode_id'];
                }
            }
            $rows[] = ['id' => 'audit-'.$log->id, 'action' => $log->action, 'title' => self::TITLES[$log->action] ?? 'Legacy session activity - details incomplete',
                'actor' => $this->actor($actors->get($log->user_id)), 'occurred_at' => $log->created_at?->toIso8601String(),
                'note' => $semantic && is_string($meta['note'] ?? null) ? $meta['note'] : null, 'snapshot' => $snapshot, 'legacy' => ! $semantic || ! is_array($meta['snapshot'] ?? null)];
        }

        return $rows;
    }

    /** An attributed intended place, never an inferred observation or a legacy coordinate backfill. */
    private function plannedLocation(mixed $value, LoneWorkerSession $record, CurrentAuthorizationReads $reads): ?array
    {
        if (! is_array($value) || ($value['kind'] ?? null) !== 'planned'
            || ! in_array($value['method'] ?? null, ['manual', 'selected_address', 'site_address', 'shift_address'], true)
            || ! $this->auditInstant($value['recorded_at'] ?? null)
            || ! is_string($value['description'] ?? null) || mb_strlen($value['description']) > 500) {
            return null;
        }
        $lat = $value['latitude'] ?? null;
        $lng = $value['longitude'] ?? null;
        if (($lat !== null || $lng !== null) && (! (is_int($lat) || is_float($lat)) || ! (is_int($lng) || is_float($lng))
            || ! is_finite((float) $lat) || ! is_finite((float) $lng) || abs($lat) > 90 || abs($lng) > 180)) {
            return null;
        }
        $siteId = $value['source_site_id'] ?? null;
        $shiftId = $value['source_shift_id'] ?? null;
        if (($siteId !== null && (! is_int($siteId) || $siteId !== (int) $record->site_id))
            || ($shiftId !== null && (! is_int($shiftId) || ! $record->shift_id || $shiftId !== (int) $record->shift_id))) {
            return null;
        }
        $actorId = $value['recorded_by_user_id'] ?? null;
        $actor = is_int($actorId) && $actorId > 0 ? $reads->query(User::query())->find($actorId, ['id', 'name']) : null;

        return ['kind' => 'planned', 'method' => $value['method'], 'description' => $value['description'],
            'lat' => $lat === null ? null : (float) $lat, 'lng' => $lng === null ? null : (float) $lng,
            'recorded_at' => $value['recorded_at'], 'recorded_by' => $this->actor($actor),
            'source_site_id' => $siteId, 'source_shift_id' => $shiftId];
    }

    private function auditInstant(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) ? $value : null;
    }

    private function actor(?User $user): ?array
    {
        return $user ? ['id' => (int) $user->id, 'name' => $user->name] : null;
    }

    private function instant(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : (is_string($value) ? Carbon::parse($value)->toIso8601String() : null);
    }
}
