<?php

namespace Tests\Feature\Incidents;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\{CctvRecordingReference, CctvSource};
use App\Domain\SecurityDevices\Cctv\Services\{CctvContract, CctvRecordingReferences};
use App\Domain\SecurityDevices\Models\{Device, DeviceAssignment};
use App\Models\{AuditLog, ClientIncident, IncidentEvidenceAggregate, IncidentEvidenceRevision, IncidentEvidenceWindow, IncidentEvidenceWindowSource, IncidentRecordingAttachment, IncidentRecordingCommandReceipt};
use App\Services\{AuditLogger, CurrentAuthorizationReads};
use App\Services\Incidents\{IncidentEvidenceWindowPolicySettings, IncidentRecordingAuditValues, IncidentRecordingContext, IncidentRecordingReconstructionValues as Values, IncidentRecordingReferenceReadService, IncidentRegisterReadService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Native model/policy/Devices integration fixtures; no resolver, authority or policy mock. UNRUN. */
final class IncidentRecordingReferenceReadTest extends IncidentIndependenceTestCase
{
    private function receiver(): Request
    {
        $this->actingAs($this->manager);
        $request = Request::create('/incidents?tab=investigation&return_to=untrusted', 'GET');
        $request->setUserResolver(fn () => $this->manager);
        app()->instance('request', $request);
        return $request;
    }

    private function read(ClientIncident $incident, int $page = 1): array
    {
        return app(IncidentRecordingReferenceReadService::class)->forIncident($this->receiver(), $incident->id, $page);
    }

    /** Explicit source-owned reference fixture, with withdrawn current source and a different original creator. */
    private function fixture(int $count = 1): array
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

    public function test_actual_resolver_allows_different_original_creator_and_nullable_metadata_verification(): void
    {
        $f = $this->fixture(); $dto = $this->read($f['incident']); $row = $dto['references']['items'][0];
        $this->assertSame('current', $dto['window']['context_state']);
        $this->assertSame('readable', $row['access']);
        $this->assertSame('withdrawn', $row['reference']['current_source']['state']);
        $this->assertSame('reference_only', $row['reference']['state']);
        $this->assertFalse($row['reference']['preserved']);
        $this->assertNull($row['reference']['recording_verified_at']);
        foreach (['reader_href', 'provider', 'console_id', 'created_by'] as $key) { $this->assertArrayNotHasKey($key, $row['reference']); }
    }

    public function test_unconfigured_incident_returns_empty_history_without_manufacturing_a_window(): void
    {
        $incident = $this->incident(); $dto = $this->read($incident);
        $this->assertNull($dto['incident_evidence_version']); $this->assertNull($dto['window']);
        $this->assertSame([], $dto['references']['items']); $this->assertSame(0, $dto['references']['total']);
        $this->assertSame(0, IncidentEvidenceAggregate::whereKey($incident->id)->count());
    }

    public function test_parent_permission_denial_precedes_all_recording_metadata_queries(): void
    {
        $f = $this->fixture();
        $this->allow($this->manager, 'incidents.viewAny', false); $this->allow($this->manager, 'incidents.viewAssigned', false);
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $this->denial(fn () => $this->read($f['incident']), 403);
            $queries = array_filter(DB::getQueryLog(), fn ($entry) => str_contains($entry['query'], 'incident_recording_attachments') || str_contains($entry['query'], 'cctv_recording_references'));
            $this->assertSame([], array_values($queries));
        } finally { DB::disableQueryLog(); DB::flushQueryLog(); }
    }

    public function test_revoked_source_permission_returns_bound_restricted_row_without_decrypting_saved_metadata(): void
    {
        $f = $this->fixture(); $this->manager->load(['roles.permissions', 'permissionOverrides']);
        $this->allow($this->manager, CctvRecordingReferences::VIEW, false);
        DB::table('incident_recording_attachments')->where('id', $f['attachments'][0]->id)->update(['encrypted_source_snapshot' => 'not ciphertext', 'encrypted_purpose' => 'not ciphertext']);
        $row = $this->read($f['incident'])['references']['items'][0];
        $this->assertSame('restricted', $row['access']); $this->assertNull($row['reference']);
        $this->assertSame($f['attachments'][0]->attachment_uuid, $row['attachment_uuid']);
    }

    public function test_new_private_provenance_cannot_hide_behind_visible_site_assignment(): void
    {
        $f = $this->fixture();
        DeviceAssignment::create(['device_id' => $f['camera']->id, 'assignable_type' => 'staff', 'assignable_id' => $this->lead->id,
            'custody_site_id' => $this->site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(), 'assigned_by_user_id' => $this->manager->id]);
        $row = $this->read($f['incident'])['references']['items'][0];
        $this->assertSame('restricted', $row['access']); $this->assertNull($row['reference']);
    }

    public function test_original_window_and_attachment_journals_are_required_even_when_source_is_readable(): void
    {
        $f = $this->fixture();
        DB::table('incident_evidence_revisions')->where('incident_id', $f['incident']->id)->where('incident_evidence_version', 2)->update(['recorded_at' => '2026-10-12 03:00:01.000001']);
        $this->denial(fn () => $this->read($f['incident']), 404);
    }

    public function test_canonical_type_correction_marks_original_context_changed_without_rewriting_history(): void
    {
        $f = $this->fixture();
        ClientIncident::withoutEvents(fn () => $f['incident']->update(['type' => 'injury']));
        $dto = $this->read($f['incident']);
        $this->assertSame('changed', $dto['window']['context_state']);
        $this->assertSame('readable', $dto['references']['items'][0]['access']);
    }

    public function test_history_pagination_is_bounded_and_stable_for_the_incident(): void
    {
        $f = $this->fixture(21); $first = $this->read($f['incident']); $second = $this->read($f['incident'], 2);
        $this->assertCount(20, $first['references']['items']); $this->assertCount(1, $second['references']['items']);
        $this->assertSame(21, $first['references']['total']); $this->assertSame(2, $first['references']['next_page']);
        $this->assertSame(1, $second['references']['previous_page']); $this->assertNull($second['references']['next_page']);
        $this->assertSame($f['attachments'][20]->attachment_uuid, $first['references']['items'][0]['attachment_uuid']);
    }

    public function test_missing_original_command_receipt_is_not_an_accepted_link(): void
    {
        $f = $this->fixture();
        DB::table('incident_recording_command_receipts')->where('attachment_uuid', $f['attachments'][0]->attachment_uuid)->delete();
        $this->denial(fn () => $this->read($f['incident']), 404);
    }

    public function test_changed_protected_purpose_cannot_bypass_original_command_fingerprint(): void
    {
        $f = $this->fixture();
        DB::table('incident_recording_attachments')->where('id', $f['attachments'][0]->id)
            ->update(['encrypted_purpose' => \Illuminate\Support\Facades\Crypt::encryptString('Different unauthorized purpose.')]);
        $this->denial(fn () => $this->read($f['incident']), 404);
    }

    public function test_conflicting_duplicate_strict_audit_is_rejected(): void
    {
        $f = $this->fixture(); $audit = AuditLog::findOrFail($f['attachments'][0]->audit_log_id);
        $duplicate = $audit->replicate(); $duplicate->saveOrFail();
        $this->denial(fn () => $this->read($f['incident']), 404);
    }

    public function test_half_initialized_aggregate_does_not_project_empty_success(): void
    {
        $incident = $this->incident();
        IncidentEvidenceAggregate::create(['incident_id' => $incident->id, 'incident_evidence_version' => 1,
            'active_window_revision' => null, 'created_by_user_id' => $this->manager->id, 'updated_by_user_id' => $this->manager->id]);
        $this->denial(fn () => $this->read($incident), 404);
    }

    public function test_explicit_audited_invalidation_allows_null_active_window_without_erasing_history(): void
    {
        $f = $this->fixture(); $this->travel(1)->seconds(); $reason = 'Original incident context corrected.';
        AuditLogger::logOrFail(IncidentRecordingAuditValues::WINDOW_INVALIDATED, $f['incident'],
            IncidentRecordingAuditValues::invalidation($f['window'], 3, $reason));
        $audit = AuditLog::latest('id')->firstOrFail()->id;
        IncidentEvidenceRevision::create(['incident_id' => $f['incident']->id, 'incident_evidence_version' => 3,
            'kind' => 'window_invalidated', 'window_revision' => 1, 'attachment_uuid' => null, 'predecessor_attachment_uuid' => null,
            'actor_user_id' => $this->manager->id, 'recorded_at' => '2026-10-12T03:00:02.000000Z',
            'encrypted_reason' => $reason, 'audit_log_id' => $audit]);
        IncidentEvidenceAggregate::whereKey($f['incident']->id)->update(['incident_evidence_version' => 3, 'active_window_revision' => null]);
        $dto = $this->read($f['incident']); $this->assertNull($dto['window']);
        $this->assertCount(1, $dto['references']['items']); $this->assertSame('readable', $dto['references']['items'][0]['access']);
        DB::table('audit_logs')->where('id', $audit)->update(['meta' => json_encode(['reason_sha256' => str_repeat('a', 64)])]);
        $this->denial(fn () => $this->read($f['incident']), 404);
    }
}
