<?php

namespace Tests\Feature\Incidents;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\{CctvRecordingReference, CctvSource};
use App\Domain\SecurityDevices\Cctv\Services\{CctvContract, CctvRecordingReferences};
use App\Domain\SecurityDevices\Models\{Device, DeviceAssignment};
use App\Models\{AuditLog, ClientIncident, IncidentEvidenceAggregate, IncidentEvidenceRevision, IncidentEvidenceWindow, IncidentEvidenceWindowSource, IncidentRecordingAttachment, IncidentRecordingCommandReceipt};
use App\Services\{AuditLogger, CurrentAuthorizationReads};
use App\Services\Incidents\{IncidentEvidenceWindowPolicySettings, IncidentRecordingAuditValues, IncidentRecordingContext, IncidentRecordingReconstructionValues as Values, IncidentRegisterReadService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Native shared foundation/model/policy fixture only; real Devices resolver, never mocked authority. */
abstract class IncidentRecordingCommandTestCase extends IncidentIndependenceTestCase
{
    protected function receiver(): Request
    {
        $this->actingAs($this->manager);
        $request = Request::create('/incidents?severity=high', 'GET');
        $request->setUserResolver(fn () => $this->manager); app()->instance('request', $request);
        return $request;
    }

    protected function fixture(int $count = 0): array
    {
        foreach (['securityDevices.viewAny', CctvRecordingReferences::VIEW, 'clients.viewAny', IncidentEvidenceWindowPolicySettings::PERMISSION] as $key) {
            $this->allow($this->manager, $key);
        }
        $this->receiver();
        $incident = $this->incident(['type' => 'fall', 'source' => 'manual', 'occurred_at' => '2026-10-12 02:00:00.123456']);
        $camera = Device::factory()->security()->create(['name' => 'Private camera', 'provider' => 'unifi', 'external_ref' => ['host_id' => 'console1']]);
        $recorder = Device::factory()->security()->create(['name' => 'Private recorder', 'subcategory' => 'nvr', 'provider' => 'unifi', 'external_ref' => ['host_id' => 'console1']]);
        foreach ([$camera, $recorder] as $device) {
            DeviceAssignment::create(['device_id' => $device->id, 'assignable_type' => 'site', 'assignable_id' => $this->site->id,
                'custody_site_id' => $this->site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subDay(), 'assigned_by_user_id' => $this->manager->id]);
        }
        $collector = MonitoringCollector::factory()->create(['site_id' => $this->site->id]);
        $scope = DiscoveryScope::factory()->create(['site_id' => $this->site->id, 'collector_id' => $collector->id]);
        $configuration = ['camera_mac' => $camera->mac_address, 'recorder_mac' => $recorder->mac_address,
            'console_id' => 'console1', 'provider_camera_id' => 'camera1', 'recorder_provider_id' => 'recorder1', 'application_version' => '6.0.0'];
        $source = CctvSource::create(['camera_id' => $camera->id, 'recorder_device_id' => $recorder->id, 'site_id' => $this->site->id,
            'collector_id' => $collector->id, 'discovery_scope_id' => $scope->id, 'actor_id' => $this->reporter->id,
            'revision' => 1, 'enabled' => false, 'provider' => 'unifi', 'fingerprint' => CctvContract::hash($configuration),
            'provider_fingerprint' => str_repeat('a', 64), 'configuration' => $configuration, 'verified_at' => now()]);
        $policy = app(IncidentEvidenceWindowPolicySettings::class)->save($this->manager, ['expected_actor_id' => $this->manager->id,
            'expected_policy_revision' => 0, 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Explicit native fixture policy decision.',
            'policy' => ['schema_version' => 1, 'enabled' => true, 'rules' => [['identifier' => 'fall-camera', 'trigger' => 'fall',
                'site_id' => $this->site->id, 'before_seconds' => 60, 'after_seconds' => 60,
                'max_before_seconds' => 120, 'max_after_seconds' => 120, 'source_types' => ['camera']]]]]);
        $this->travel(1)->seconds();
        $anchor = app(IncidentRegisterReadService::class)->consumeSource($this->receiver(), $incident->id,
            fn (Request $request, int $id, CurrentAuthorizationReads $reads, $parent): array => IncidentRecordingContext::capture($parent, $id, $reads));
        // Window digest excludes audit_log_id to avoid a circular audit/hash dependency.
        $audit = 1;
        $recordedAt = '2026-10-12T03:00:01.000000Z';
        $window = Values::windowRecord(['incident_id' => $incident->id, 'window_revision' => 1, 'introduced_evidence_version' => 1,
            'actor_user_id' => $this->manager->id, 'recorded_at' => $recordedAt, 'start_at' => '2026-10-12T01:59:00Z', 'end_at' => '2026-10-12T02:01:00Z',
            'rule_identifier' => 'fall-camera', 'rule_revision' => $policy['policy_revision'], 'policy_change_id' => $policy['setting_change_id'],
            'rule_snapshot_sha256' => $policy['policy_digest'], 'override_reason' => null, 'context_anchor' => $anchor,
            'approved_sources' => [['camera_id' => $camera->id, 'recorder_device_id' => $recorder->id, 'site_id' => $this->site->id]], 'audit_log_id' => $audit]);
        AuditLogger::logOrFail(IncidentRecordingAuditValues::WINDOW_RECORDED, $incident, IncidentRecordingAuditValues::window($window));
        $audit = AuditLog::latest('id')->firstOrFail()->id; $window['audit_log_id'] = $audit;
        IncidentEvidenceAggregate::create(['incident_id' => $incident->id, 'incident_evidence_version' => $count + 1,
            'active_window_revision' => null, 'created_by_user_id' => $this->manager->id, 'updated_by_user_id' => $this->manager->id]);
        $stored = $window;
        foreach (['override_reason', 'context_anchor', 'approved_sources'] as $key) { $stored['encrypted_'.$key] = $stored[$key]; unset($stored[$key]); }
        IncidentEvidenceWindow::create($stored);
        IncidentEvidenceAggregate::whereKey($incident->id)->update(['active_window_revision' => 1]);
        IncidentEvidenceWindowSource::create(['incident_id' => $incident->id, 'window_revision' => 1, 'camera_id' => $camera->id,
            'recorder_device_id' => $recorder->id, 'site_id' => $this->site->id]);
        IncidentEvidenceRevision::create(['incident_id' => $incident->id, 'incident_evidence_version' => 1, 'kind' => 'window_recorded',
            'window_revision' => 1, 'attachment_uuid' => null, 'predecessor_attachment_uuid' => null,
            'actor_user_id' => $this->manager->id, 'recorded_at' => $recordedAt, 'encrypted_reason' => null, 'audit_log_id' => $audit]);
        $attachments = [];
        for ($i = 0; $i < $count; $i++) {
            $uuid = (string) Str::uuid(); $attachmentUuid = (string) Str::uuid();
            $metadata = ['schema_version' => 1, 'reference_uuid' => $uuid, 'camera_id' => $camera->id, 'recorder_device_id' => $recorder->id,
                'site_id' => $this->site->id, 'provider' => 'unifi', 'console_id' => 'console1', 'provider_camera_id' => 'camera1',
                'provider_recorder_id' => 'recorder1', 'source_revision' => 1, 'protect_version' => '6.0.0',
                'requested_start_at' => $window['start_at'], 'requested_end_at' => $window['end_at'], 'created_at' => '2026-10-12T02:30:00Z',
                'source_verified_at' => null, 'recording_verified_at' => null, 'vendor_retention_until' => null,
                'state' => 'reference_only', 'preserved' => false, 'binding_basis' => 'reviewed_source_revision', 'window_coverage' => 'not_verified',
                'created_by' => ['id' => $this->reporter->id, 'name' => $this->reporter->name], 'purpose' => 'Original source purpose.',
                'reader_href' => '/security-devices/cctv/recording-references/'.$uuid];
            $input = ['expected_actor_id' => $this->manager->id, 'idempotency_key' => (string) Str::uuid(),
                'expected_incident_evidence_version' => $i + 1, 'evidence_window_revision' => 1,
                'reference_uuid' => $uuid, 'purpose' => 'Review incident camera reference.'];
            $fingerprint = Values::attachFingerprint($this->manager->id, $incident->id, $input);
            AuditLogger::logOrFail(IncidentRecordingAuditValues::RECORDING_ATTACHED, $incident,
                IncidentRecordingAuditValues::attachment($window, $i + 2, $attachmentUuid, $uuid,
                    $input['idempotency_key'], $fingerprint, Values::digest($metadata)));
            $attachmentAudit = AuditLog::latest('id')->firstOrFail()->id;
            CctvRecordingReference::create(['uuid' => $uuid, 'source_id' => $source->id, 'camera_id' => $camera->id,
                'recorder_device_id' => $recorder->id, 'site_id' => $this->site->id, 'actor_id' => $this->reporter->id,
                'request_key' => (string) Str::uuid(), 'input_hash' => str_repeat('b', 64), 'metadata_hash' => CctvContract::hash($metadata),
                'metadata' => $metadata, 'created_at' => '2026-10-12 02:30:00']);
            // The original revision exists before attachment (ira_revision_fk); receipt follows attachment.
            IncidentEvidenceRevision::create(['incident_id' => $incident->id, 'incident_evidence_version' => $i + 2,
                'kind' => 'recording_attached', 'window_revision' => 1, 'attachment_uuid' => $attachmentUuid,
                'predecessor_attachment_uuid' => null, 'actor_user_id' => $this->manager->id, 'recorded_at' => $recordedAt,
                'encrypted_reason' => null, 'audit_log_id' => $attachmentAudit]);
            $attachments[] = IncidentRecordingAttachment::create(['incident_id' => $incident->id, 'window_revision' => 1,
                'attachment_uuid' => $attachmentUuid, 'reference_uuid' => $uuid, 'attached_evidence_version' => $i + 2,
                'camera_id' => $camera->id, 'recorder_device_id' => $recorder->id, 'site_id' => $this->site->id,
                'actor_user_id' => $this->manager->id, 'recorded_at' => $recordedAt, 'audit_log_id' => $attachmentAudit,
                'requested_start_at' => $window['start_at'], 'requested_end_at' => $window['end_at'],
                'encrypted_purpose' => 'Review incident camera reference.', 'encrypted_source_snapshot' => $metadata,
                'source_snapshot_sha256' => Values::digest($metadata)]);
            IncidentRecordingCommandReceipt::create(['incident_id' => $incident->id, 'actor_user_id' => $this->manager->id,
                'idempotency_key' => $input['idempotency_key'], 'command' => Values::ATTACH,
                'fingerprint' => $fingerprint,
                'submitted_evidence_version' => $i + 1, 'window_revision' => 1, 'confirmed_evidence_version' => $i + 2,
                'attachment_uuid' => $attachmentUuid, 'confirmed_at' => $recordedAt, 'audit_log_id' => $attachmentAudit,
                'confirmed_result' => Values::attachConfirmation($this->manager->id, $incident->id, $input, $window,
                    $metadata, $attachmentUuid, $i + 2, $recordedAt)]);
        }
        return compact('incident', 'camera', 'recorder', 'source', 'attachments', 'window');
    }

    /**
     * Reassign a fixture Device to a staff member (private provenance). A Device has at most one active
     * assignment (device_assignments_one_active_device_uq), so its current Site assignment is released at the
     * same instant, as DeviceAssignmentService::assign() does.
     */
    protected function assignToStaff(Device $device, \App\Models\User $staff): DeviceAssignment
    {
        $at = now()->subHour();
        DeviceAssignment::query()->where('device_id', $device->id)->active()
            ->update(['released_at' => $at, 'released_by_user_id' => $this->manager->id]);

        return DeviceAssignment::create(['device_id' => $device->id, 'assignable_type' => 'staff', 'assignable_id' => $staff->id,
            'custody_site_id' => $this->site->id, 'assignment_type' => 'permanent', 'assigned_at' => $at, 'assigned_by_user_id' => $this->manager->id]);
    }
}
