<?php

namespace App\Services\Tracking;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceCustodySiteResolver;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Exceptions\SafetySignalUnroutable;
use App\Http\Requests\Operations\StoreClientLocationZoneDraftRequest;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ClientGeofenceMonitor;
use App\Models\ClientGeofenceRule;
use App\Models\ClientGeofenceRuleVersion;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoom\TriageQueue;
use App\Models\FleetSignal;
use App\Models\FleetTelemetryEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Consents\CurrentConsentEvidence;
use App\Services\Fleet\FleetSignalService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ClientZoneMonitoringService
{
    public const SIGNAL = 'resident.zone_breach';

    public const CONTROL_TYPE = 'wandering_geofence_breach';

    public function __construct(private ClientLocationAccessService $access, private ClientLocationZoneDraftService $drafts) {}

    public function change(User $actor, Client $client, int $ruleId, array $input): array
    {
        $candidate = $this->access->recheck($actor, $client, $input['access_fingerprint'], true);
        abort_unless(Schema::hasTable('client_geofence_monitors'), 503, 'Monitoring is not installed yet. Your draft is saved.');

        return DB::transaction(function () use ($actor, $client, $ruleId, $input, $candidate) {
            ClientConsent::query()->whereKey($candidate->consent_id)->lockForUpdate()->firstOrFail();
            Device::query()->whereKey($candidate->device_id)->lockForUpdate()->firstOrFail();
            app(DeviceCustodySiteResolver::class)->resolve(DeviceAssignment::TARGET_CLIENT, (int) $client->id, true);
            DeviceAssignment::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUser($actor, ['*']);
            $actor->setRelation('hrEmployeeProfile', HrEmployeeProfile::query()->where('user_id', $actor->id)->lockForUpdate()->first());
            $assignment = $this->access->recheck($actor, $client, $input['access_fingerprint'], true, true);
            $rule = ClientGeofenceRule::query()->where('client_id', $client->id)->where('site_id', $assignment->custody_site_id)
                ->where('status', 'draft')->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            abort_unless($rule->current_revision === $input['expected_revision'], 409, 'This zone changed. Reload and review the current revision.');
            $version = $rule->versions()->where('revision', $rule->current_revision)->firstOrFail();
            $latest = ClientGeofenceMonitor::query()->where('rule_id', $rule->id)->latest('id')->lockForUpdate()->first();
            // Exact monitor identity prevents a delayed pause/retry from stopping a later activation.
            if ($input['action'] === 'pause') {
                abort_unless($latest && $latest->id === ($input['monitor_id'] ?? null), 409, 'Monitoring changed. Reload this zone.');
                if (! $latest->ended_at) {
                    $latest->update(['ended_at' => now(), 'ended_by' => $actor->id]);
                    AuditLogger::logOrFail('client.location.zone_monitor.paused', $client, ['actor_id' => $actor->id, 'monitor_id' => $latest->id]);
                }
            } else {
                $key = hash('sha256', implode(':', [$actor->id, $rule->id, $input['idempotency_key']]));
                $replay = ClientGeofenceMonitor::query()->where('operation_key', $key)->first();
                if ($replay) {
                    abort_unless($replay->version_id === $version->id && $replay->id === $latest?->id && ! $replay->ended_at
                        && hash_equals($replay->access_fingerprint, $input['access_fingerprint']), 409, 'That activation has ended or changed. Review and start again.');
                } else {
                    abort_if($latest && ! $latest->ended_at, 409, 'This zone is already monitoring.');
                    abort_unless(in_array('control_room', $assignment->access_audience ?? [], true), 422, 'The tracker assignment does not permit Control Room alerts. Review its access audience before activating.');
                    $links = DeviceAssetLink::query()->where('device_id', $assignment->device_id)->whereNull('unlinked_at')->lock('for share nowait')->get();
                    $asset = $links->count() === 1 ? Asset::query()->whereKey($links->first()->asset_id)->lock('for share nowait')->first() : null;
                    abort_unless($asset && (int) ($asset->site_id ?: $asset->home_site_id) === (int) $assignment->custody_site_id
                        && ($asset->client_id === null || (int) $asset->client_id === (int) $client->id), 422, 'Link this tracker to its current site asset before activating monitoring.');
                    try {
                        $this->routing();
                    } catch (SafetySignalUnroutable $exception) {
                        abort(503, $exception->getMessage());
                    }
                    abort_if(trim((string) $version->response_proposal) === '', 422, 'Add Control Room response instructions before activating.');
                    // Existing site geometry is an external snapshot too; never
                    // turn a malformed legacy boundary into an outside alarm.
                    StoreClientLocationZoneDraftRequest::validateSnapshot([
                        'name' => $version->name, 'purpose' => $version->purpose, 'classification' => $version->classification,
                        'geometry_source' => 'custom', 'geometry' => $version->geometry_proposal, 'schedule' => $version->schedule_proposal,
                        'response_proposal' => $version->response_proposal, 'access_fingerprint' => $input['access_fingerprint'],
                        'idempotency_key' => $input['idempotency_key'],
                    ]);
                    $lastEnd = CarbonImmutable::parse($version->schedule_proposal['last_date'], 'Pacific/Auckland')
                        ->addDays($version->schedule_proposal['following_day'] ? 1 : 0)->setTimeFromTimeString($version->schedule_proposal['end']);
                    abort_if($lastEnd->lessThanOrEqualTo(now()), 422, 'This schedule has ended. Edit its dates before activating.');
                    if ($version->geometry_source === 'canonical') {
                        $boundary = $this->access->eligibleBoundaries($client, $assignment->custody_site_id)->whereKey($version->canonical_geofence_id)->lockForUpdate()->first();
                        abort_unless($boundary && hash_equals($version->canonical_geometry_hash, $this->drafts->geometryHash($boundary)), 409, 'The linked boundary changed. Edit and review it before activating.');
                    }
                    ClientGeofenceMonitor::query()->create([
                        'rule_id' => $rule->id, 'version_id' => $version->id, 'assignment_id' => $assignment->id,
                        'consent_id' => $assignment->consent_id, 'started_by' => $actor->id, 'started_at' => now(),
                        'access_fingerprint' => $input['access_fingerprint'], 'operation_key' => $key,
                    ]);
                    AuditLogger::logOrFail('client.location.zone_monitor.activated', $client, ['actor_id' => $actor->id, 'rule_id' => $rule->id, 'revision' => $version->revision, 'destination' => 'control_room']);
                }
            }
            $this->access->recheck($actor, $client, $input['access_fingerprint'], true, true);

            return $this->drafts->present($rule, $version, $input['access_fingerprint']);
        }, 3);
    }

    /** Called by the canonical ingestion transaction after its final privacy decision. */
    public function evaluate(FleetTelemetryEvent $event): void
    {
        if (! $event->device_id || $event->consent_blocked || $event->latitude === null || $event->longitude === null
            || ! $event->occurred_at || $event->occurred_at->lessThan(now()->subMinutes(5))
            || ! Schema::hasTable('client_geofence_monitors')) {
            return;
        }
        $ids = ClientGeofenceMonitor::query()->whereNull('ended_at')->whereIn('assignment_id', DeviceAssignment::query()
            ->where('device_id', $event->device_id)->where('assignable_type', DeviceAssignment::TARGET_CLIENT)->select('id'))->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $event) {
                $hint = ClientGeofenceMonitor::query()->findOrFail($id);
                $rule = ClientGeofenceRule::query()->whereKey($hint->rule_id)->lockForUpdate()->firstOrFail();
                $monitor = ClientGeofenceMonitor::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($monitor->ended_at || ! $this->currentContext($monitor, $rule, $event)) {
                    return;
                }
                if ($monitor->last_observed_at && $event->occurred_at->lessThanOrEqualTo($monitor->last_observed_at)) {
                    return;
                }
                $version = ClientGeofenceRuleVersion::query()->findOrFail($monitor->version_id);
                $window = app(ClientZoneSchedule::class)->window($version->schedule_proposal, $event->occurred_at);
                $position = $window ? app(ClientZonePosition::class)->classify($version->geometry_proposal, (float) $event->latitude, (float) $event->longitude, $event->accuracy_m) : 'outside_schedule';
                $previous = $monitor->schedule_window === $window ? $monitor->position_status : 'unknown';
                if ($monitor->schedule_window !== $window) {
                    $monitor->position_status = 'unknown';
                }
                $monitor->last_observed_at = $event->occurred_at;
                $monitor->schedule_window = $window;
                // Inaccurate boundary fixes must not end a breach and cause a duplicate alarm.
                if (in_array($position, ['uncertain', 'unknown'], true)) {
                    $monitor->save();

                    return;
                }
                $monitor->position_status = $position;
                $breachPosition = $version->classification === 'agreed' ? 'outside' : 'inside';
                if ($window && $position === $breachPosition && $previous !== $breachPosition) {
                    $monitor->breach_count++;
                    app(FleetSignalService::class)->emit([
                        'asset_id' => $event->asset_id, 'device_id' => $event->device_id, 'asset_tracker_id' => $event->asset_tracker_id,
                        'signal_type' => self::SIGNAL, 'severity_hint' => 'high', 'occurred_at' => $event->occurred_at,
                        'idempotency_key' => $this->signalKey($monitor->id, $event->id),
                        // Keep names, coordinates and response text out of intermediate fleet payloads.
                        'payload' => ['client_zone_monitor_id' => $monitor->id, 'event_id' => $event->id],
                    ]);
                }
                $monitor->save();
            }, 3);
        }
    }

    /** Canonical revalidation inside the existing durable Control Room outbox transaction. */
    public function projection(FleetSignal $signal): array
    {
        $monitor = ClientGeofenceMonitor::query()->whereKey(data_get($signal->payload, 'client_zone_monitor_id'))->lock('for share nowait')->first();
        $event = FleetTelemetryEvent::query()->whereKey(data_get($signal->payload, 'event_id'))->lock('for share nowait')->first();
        $rule = $monitor ? ClientGeofenceRule::query()->whereKey($monitor->rule_id)->lock('for share nowait')->first() : null;
        if (! $monitor || ! $event || ! $rule || $signal->signal_type !== self::SIGNAL
            || ! hash_equals($this->signalKey($monitor->id, $event->id), $signal->idempotency_key)
            || (int) $signal->asset_id !== (int) $event->asset_id || (int) $signal->device_id !== (int) $event->device_id
            || ! $signal->occurred_at->equalTo($event->occurred_at) || ! $this->currentContext($monitor, $rule, $event)) {
            throw new SafetySignalUnroutable('Client zone evidence or current tracking authority is unavailable.');
        }
        $version = ClientGeofenceRuleVersion::query()->findOrFail($monitor->version_id);
        $position = app(ClientZonePosition::class)->classify($version->geometry_proposal, (float) $event->latitude, (float) $event->longitude, $event->accuracy_m);
        $window = app(ClientZoneSchedule::class)->window($version->schedule_proposal, $event->occurred_at);
        if (! $window || $position !== ($version->classification === 'agreed' ? 'outside' : 'inside')) {
            throw new SafetySignalUnroutable('Client zone report does not establish a scheduled breach.');
        }
        [$source] = $this->routing();

        return [
            'signal_source_id' => $source->id, 'signal_type_code' => self::CONTROL_TYPE,
            'idempotency_key' => hash('sha256', 'safety-signal|fleet|'.$signal->idempotency_key),
            'asset_id' => $event->asset_id, 'client_id' => $rule->client_id, 'site_id' => $rule->site_id,
            'external_ref' => 'fleet_signal_'.$signal->id, 'severity_hint' => 'high', 'occurred_at' => $event->occurred_at,
            'payload' => ['safe_zone' => ['name' => $version->name, 'revision' => $version->revision,
                'trigger' => $position === 'outside' ? 'Reported outside the agreed zone' : 'Reported inside the attention zone',
                'response' => $version->response_proposal, 'schedule_window' => $window, 'timezone' => 'Pacific/Auckland',
                'accuracy_m' => $event->accuracy_m, 'reported_at' => $event->occurred_at->toISOString()]],
            'normalized_data' => ['fleet_signal_id' => $signal->id, 'client_id' => $rule->client_id,
                'client_zone_monitor_id' => $monitor->id, 'client_zone_rule_id' => $rule->id, 'client_zone_version_id' => $version->id],
        ];
    }

    private function currentContext(ClientGeofenceMonitor $monitor, ClientGeofenceRule $rule, FleetTelemetryEvent $event): bool
    {
        if ($event->consent_blocked || $event->latitude === null || $event->longitude === null || ! $event->occurred_at
            || $event->occurred_at->isFuture() || $event->occurred_at->lessThan($monitor->started_at)
            || ($monitor->ended_at && $event->occurred_at->greaterThanOrEqualTo($monitor->ended_at))) {
            return false;
        }
        $evidence = CurrentConsentEvidence::lock($monitor->consent_id);
        $device = Device::query()->whereKey($event->device_id)->lock('for share nowait')->first();
        $assignment = DeviceAssignment::query()->whereKey($monitor->assignment_id)->lock('for share nowait')->first();
        $client = $evidence->client($rule->client_id);
        $asset = Asset::query()->whereKey($event->asset_id)->lock('for share nowait')->first();
        $site = Site::query()->whereKey($rule->site_id)->lock('for share nowait')->first();
        $links = DeviceAssetLink::query()->where('device_id', $event->device_id)->whereNull('unlinked_at')->lock('for share nowait')->get();
        if (! $device || ! $assignment || ! $client || ! $asset || ! $site || ! $site->is_active || $site->archived
            || $links->count() !== 1 || (int) $links->first()->asset_id !== (int) $asset->id
            || DeviceAssetLink::query()->where('asset_id', $asset->id)->whereNull('unlinked_at')->where('device_id', '!=', $device->id)->lock('for share nowait')->exists()
            || $links->first()->linked_at?->greaterThan($event->occurred_at)
            || (int) $assignment->device_id !== (int) $device->id || (int) $assignment->custody_site_id !== (int) $rule->site_id
            || ! in_array('control_room', $assignment->access_audience ?? [], true)
            || (int) ($asset->site_id ?: $asset->home_site_id) !== (int) $rule->site_id
            || ($asset->client_id !== null && (int) $asset->client_id !== (int) $client->id)
            || ! app(PersonalTrackingPrivacyService::class)->assignmentAuthorisesResidentLocationFromCurrentEvidence($assignment, $device, $evidence, $client)) {
            return false;
        }
        $assignment->setRelation('consent', $evidence->consent);

        return hash_equals($monitor->access_fingerprint, $this->access->fingerprint($assignment))
            && $event->occurred_at->greaterThanOrEqualTo($assignment->assigned_at)
            && $event->occurred_at->greaterThanOrEqualTo($assignment->collection_started_at)
            && $event->occurred_at->greaterThanOrEqualTo($evidence->consent->given_at)
            && $event->occurred_at->greaterThanOrEqualTo(now()->subDays($assignment->retention_days));
    }

    private function signalKey(int $monitorId, int $eventId): string
    {
        return hash('sha256', "client-zone:{$monitorId}:event:{$eventId}");
    }

    public function routing(): array
    {
        $source = SignalSource::query()->where('slug', 'personal_tracker')->where('status', 'active')->lock('for share nowait')->first();
        $type = SignalType::query()->where('code', self::CONTROL_TYPE)->where('is_active', true)->lock('for share nowait')->first();
        $queue = TriageQueue::findForAlert('high', 'personal_tracker', self::CONTROL_TYPE);
        if (! $source || ! $type || ! $queue) {
            throw new SafetySignalUnroutable('Control Room needs an active Personal Tracker source, geofence signal type and high-priority queue.');
        }

        return [$source, $type, $queue];
    }
}
