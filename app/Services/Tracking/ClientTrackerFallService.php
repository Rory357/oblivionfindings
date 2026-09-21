<?php

namespace App\Services\Tracking;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Exceptions\SafetySignalUnroutable;
use App\Models\Asset;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoom\TriageQueue;
use App\Models\FleetSignal;
use App\Models\FleetTelemetryEvent;
use App\Models\Site;
use App\Services\Consents\CurrentConsentEvidence;
use App\Services\Fleet\FleetSignalService;

/** Vendor adapters supply an explicit fall_detected event; never infer a fall from motion or man-down. */
final class ClientTrackerFallService
{
    public const SIGNAL = 'resident.fall_detected';

    public function evaluate(FleetTelemetryEvent $event): void
    {
        if ($event->event_type !== 'fall_detected' || $event->consent_blocked || ! $event->device_id) {
            return;
        }
        $assignments = DeviceAssignment::query()->where('device_id', $event->device_id)->active()
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)->get();
        if ($assignments->count() !== 1) {
            return;
        }
        $assignment = $assignments->first();
        $context = $this->context($assignment->id, $event);
        if (! $context) {
            return;
        }
        app(FleetSignalService::class)->emit([
            'asset_id' => $event->asset_id, 'device_id' => $event->device_id, 'asset_tracker_id' => $event->asset_tracker_id,
            'signal_type' => self::SIGNAL, 'severity_hint' => 'critical', 'occurred_at' => $event->occurred_at,
            'idempotency_key' => $this->key($assignment->id, $event->id),
            'payload' => ['event_id' => $event->id, 'assignment_id' => $assignment->id,
                'access_fingerprint' => $context['fingerprint']],
        ]);
    }

    public function projection(FleetSignal $signal): array
    {
        $event = FleetTelemetryEvent::query()->whereKey(data_get($signal->payload, 'event_id'))->lock('for share nowait')->first();
        $assignmentId = (int) data_get($signal->payload, 'assignment_id');
        $context = $event ? $this->context($assignmentId, $event) : null;
        if (! $event || ! $context || $signal->signal_type !== self::SIGNAL
            || ! hash_equals($this->key($assignmentId, $event->id), $signal->idempotency_key)
            || ! hash_equals($context['fingerprint'], (string) data_get($signal->payload, 'access_fingerprint'))
            || (int) $signal->device_id !== (int) $event->device_id || (int) $signal->asset_id !== (int) $event->asset_id
            || ! $signal->occurred_at->equalTo($event->occurred_at)) {
            throw new SafetySignalUnroutable('Fall report or current client tracking authority could not be verified.');
        }
        $source = SignalSource::query()->where('slug', 'personal_tracker')->where('status', 'active')->lock('for share nowait')->first();
        $type = SignalType::query()->where('code', 'fall_detected')->where('is_active', true)->lock('for share nowait')->first();
        if (! $source || ! $type || ! TriageQueue::findForAlert('critical', 'personal_tracker', 'fall_detected')) {
            throw new SafetySignalUnroutable('Control Room needs an active Personal Tracker source, Fall Detected signal and critical-priority queue.');
        }

        return [
            'signal_source_id' => $source->id, 'signal_type_code' => 'fall_detected',
            'idempotency_key' => hash('sha256', 'safety-signal|fleet|'.$signal->idempotency_key),
            'asset_id' => $event->asset_id, 'client_id' => $context['client_id'], 'site_id' => $context['site_id'],
            'external_ref' => 'fleet_signal_'.$signal->id, 'severity_hint' => 'critical', 'occurred_at' => $event->occurred_at,
            'payload' => ['tracker_event' => ['type' => 'fall_detected', 'reported_at' => $event->occurred_at->toISOString(),
                'received_at' => $event->received_at?->toISOString(), 'response' => 'The tracker reported a possible fall. Check the person and follow their response plan.']],
            'normalized_data' => ['fleet_signal_id' => $signal->id, 'client_id' => $context['client_id'], 'client_tracker_fall' => true],
        ];
    }

    private function context(int $assignmentId, FleetTelemetryEvent $event): ?array
    {
        if ($event->event_type !== 'fall_detected' || $event->consent_blocked || ! $event->occurred_at || $event->occurred_at->isFuture()) {
            return null;
        }
        $assignment = DeviceAssignment::query()->whereKey($assignmentId)->where('device_id', $event->device_id)->active()
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)->lock('for share nowait')->first();
        if (! $assignment || ! $assignment->consent_id || ! in_array('control_room', $assignment->access_audience ?? [], true)) {
            return null;
        }
        $currentAssignments = DeviceAssignment::query()->where('device_id', $event->device_id)->active()
            ->whereIn('assignable_type', [DeviceAssignment::TARGET_CLIENT, DeviceAssignment::TARGET_STAFF])->lock('for share nowait')->get();
        if ($currentAssignments->count() !== 1 || (int) $currentAssignments->first()->id !== $assignmentId) {
            return null;
        }
        $evidence = CurrentConsentEvidence::lock($assignment->consent_id);
        $device = Device::query()->whereKey($event->device_id)->lock('for share nowait')->first();
        $client = $evidence->client((int) $assignment->assignable_id);
        $asset = Asset::query()->whereKey($event->asset_id)->lock('for share nowait')->first();
        $site = Site::query()->whereKey($assignment->custody_site_id)->lock('for share nowait')->first();
        $links = DeviceAssetLink::query()->where('device_id', $event->device_id)->whereNull('unlinked_at')->lock('for share nowait')->get();
        if (! $device || ! $client || ! $asset || ! $site || ! $site->is_active || $site->archived
            || $links->count() !== 1 || (int) $links->first()->asset_id !== (int) $asset->id
            || $links->first()->linked_at?->greaterThan($event->occurred_at)
            || DeviceAssetLink::query()->where('asset_id', $asset->id)->whereNull('unlinked_at')->where('device_id', '!=', $device->id)->lock('for share nowait')->exists()
            || (int) ($asset->site_id ?: $asset->home_site_id) !== (int) $site->id
            || (int) $client->site_id !== (int) $site->id
            || ($asset->client_id !== null && (int) $asset->client_id !== (int) $client->id)
            || ! app(PersonalTrackingPrivacyService::class)->assignmentAuthorisesResidentLocationFromCurrentEvidence($assignment, $device, $evidence, $client)
            || $event->occurred_at->lessThan($assignment->assigned_at) || $event->occurred_at->lessThan($assignment->collection_started_at)
            || $event->occurred_at->lessThan($evidence->consent->given_at)
            || $event->occurred_at->lessThan(now()->subDays($assignment->retention_days))) {
            return null;
        }
        $assignment->setRelation('consent', $evidence->consent);

        return ['client_id' => (int) $client->id, 'site_id' => (int) $site->id,
            'fingerprint' => app(ClientLocationAccessService::class)->fingerprint($assignment)];
    }

    private function key(int $assignmentId, int $eventId): string
    {
        return hash('sha256', "client-fall:{$assignmentId}:event:{$eventId}");
    }
}
