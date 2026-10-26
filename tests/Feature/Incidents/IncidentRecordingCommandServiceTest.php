<?php

namespace Tests\Feature\Incidents;

use App\Domain\SecurityDevices\Cctv\Models\CctvRecordingReference;
use App\Domain\SecurityDevices\Cctv\Services\{CctvContract, CctvRecordingReferences};
use App\Models\{AuditLog, ClientIncident, IncidentEvidenceAggregate, IncidentEvidenceRevision, IncidentRecordingAttachment, IncidentRecordingCommandReceipt};
use App\Services\Incidents\{IncidentRecordingAuditValues, IncidentRecordingCommandService, IncidentRecordingReferenceReadService};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Actual native policy, encrypted provenance, resolver and transaction/event controls. ALL UNRUN. */
final class IncidentRecordingCommandServiceTest extends IncidentRecordingCommandTestCase
{
    private function sourceReceipt(array $f): string
    {
        $uuid = (string) Str::uuid();
        $metadata = ['schema_version' => 1, 'reference_uuid' => $uuid, 'camera_id' => $f['camera']->id,
            'recorder_device_id' => $f['recorder']->id, 'site_id' => $this->site->id, 'provider' => 'unifi',
            'console_id' => 'console1', 'provider_camera_id' => 'camera1', 'provider_recorder_id' => 'recorder1',
            'source_revision' => 1, 'protect_version' => '6.0.0', 'requested_start_at' => $f['window']['start_at'],
            'requested_end_at' => $f['window']['end_at'], 'created_at' => '2026-10-12T02:30:00Z',
            'source_verified_at' => null, 'recording_verified_at' => null, 'vendor_retention_until' => null,
            'state' => 'reference_only', 'preserved' => false, 'binding_basis' => 'reviewed_source_revision',
            'window_coverage' => 'not_verified', 'created_by' => ['id' => $this->reporter->id, 'name' => $this->reporter->name],
            'purpose' => 'Original source review purpose.', 'reader_href' => '/security-devices/cctv/recording-references/'.$uuid];
        CctvRecordingReference::create(['uuid' => $uuid, 'source_id' => $f['source']->id, 'camera_id' => $f['camera']->id,
            'recorder_device_id' => $f['recorder']->id, 'site_id' => $this->site->id, 'actor_id' => $this->reporter->id,
            'request_key' => (string) Str::uuid(), 'input_hash' => str_repeat('b', 64),
            'metadata_hash' => CctvContract::hash($metadata), 'metadata' => $metadata, 'created_at' => '2026-10-12 02:30:00']);
        return $uuid;
    }

    private function attachCommand(array $f, array $changes = []): array
    {
        foreach ([IncidentRecordingCommandService::ATTACH_PERMISSION, 'incidents.update'] as $permission) { $this->allow($this->manager, $permission); }
        return ['expected_actor_id' => $this->manager->id, 'idempotency_key' => (string) Str::uuid(),
            'expected_incident_evidence_version' => 1, 'evidence_window_revision' => 1,
            'reference_uuid' => $this->sourceReceipt($f), 'purpose' => 'Review camera context for this incident.', ...$changes];
    }

    private function attach(array $f, array $command): array
    {
        return app(IncidentRecordingCommandService::class)->attach($this->receiver(), $f['incident']->id, $command);
    }

    private function zeroAttachments(array $f): void
    {
        $this->assertSame(0, IncidentRecordingAttachment::where('incident_id', $f['incident']->id)->count());
        $this->assertSame(0, IncidentRecordingCommandReceipt::where('incident_id', $f['incident']->id)->count());
        $this->assertSame(1, IncidentEvidenceAggregate::findOrFail($f['incident']->id)->incident_evidence_version);
        $this->assertSame(1, IncidentEvidenceRevision::where('incident_id', $f['incident']->id)->count());
        $this->assertSame(0, AuditLog::where('auditable_id', $f['incident']->id)->where('action', IncidentRecordingAuditValues::RECORDING_ATTACHED)->count());
    }

    public function test_real_attach_persists_exact_original_confirmation_and_reader_accepts_it(): void
    {
        $f = $this->fixture(); $command = $this->attachCommand($f); $result = $this->attach($f, $command);
        $this->assertTrue($result['created']); $this->assertSame(2, $result['response']['current']['incident_evidence_version']);
        $this->assertSame('withdrawn', $result['response']['current']['current_source']['state']);
        $this->assertSame('reference_only', $result['response']['attachment']['state']); $this->assertFalse($result['response']['attachment']['preserved']);
        $this->assertSame(1, IncidentRecordingAttachment::where('incident_id', $f['incident']->id)->count());
        $reader = app(IncidentRecordingReferenceReadService::class)->forIncident($this->receiver(), $f['incident']->id);
        $this->assertSame('readable', $reader['references']['items'][0]['access']);
        $this->assertArrayNotHasKey('reader_href', $reader['references']['items'][0]['reference']);
    }

    public function test_lost_response_replay_and_actor_private_recovery_survive_incident_closure(): void
    {
        $f = $this->fixture(); $command = $this->attachCommand($f); $first = $this->attach($f, $command);
        ClientIncident::withoutEvents(fn () => $f['incident']->update(['status' => 'closed']));
        $replay = $this->attach($f, $command); $this->assertFalse($replay['created']);
        $this->assertSame($first['response']['command'], $replay['response']['command']);
        $recovered = app(IncidentRecordingCommandService::class)->recoverAttachment($this->receiver(), $f['incident']->id, $this->manager->id, $command['idempotency_key']);
        $this->assertSame($first['response']['attachment'], $recovered['attachment']);
        $this->assertSame(1, IncidentRecordingAttachment::where('incident_id', $f['incident']->id)->count());
        $this->allow($this->manager, CctvRecordingReferences::VIEW, false);
        $this->denial(fn () => $this->attach($f, $command), 404);
        $this->denial(fn () => app(IncidentRecordingCommandService::class)->recoverAttachment($this->receiver(), $f['incident']->id, $this->manager->id, $command['idempotency_key']), 404);
    }

    public function test_same_identity_with_a_new_key_conflicts_and_changed_payload_cannot_replay(): void
    {
        $f = $this->fixture(); $command = $this->attachCommand($f); $this->attach($f, $command);
        $this->denial(fn () => $this->attach($f, [...$command, 'idempotency_key' => (string) Str::uuid(), 'expected_incident_evidence_version' => 2]), 409);
        $this->denial(fn () => $this->attach($f, [...$command, 'purpose' => 'A deliberately different purpose.']), 409);
        $this->assertSame(1, IncidentRecordingAttachment::where('incident_id', $f['incident']->id)->count());
    }

    public function test_new_closed_stale_and_corrected_context_commands_make_no_link(): void
    {
        $f = $this->fixture(); $command = $this->attachCommand($f);
        $this->denial(fn () => $this->attach($f, [...$command, 'expected_incident_evidence_version' => 2]), 409);
        ClientIncident::withoutEvents(fn () => $f['incident']->update(['type' => 'injury']));
        $this->denial(fn () => $this->attach($f, $command), 409);
        ClientIncident::withoutEvents(fn () => $f['incident']->update(['type' => 'fall', 'status' => 'closed']));
        $this->denial(fn () => $this->attach($f, $command), 403); $this->zeroAttachments($f);
    }

    public function test_current_aggregate_requires_its_exact_journal_and_original_audit_before_any_write(): void
    {
        $f = $this->fixture(); $command = $this->attachCommand($f);
        DB::table('incident_evidence_aggregates')->where('incident_id', $f['incident']->id)->update(['active_window_revision' => null]);
        $this->denial(fn () => $this->attach($f, $command), 404); $this->zeroAttachments($f);
        DB::table('incident_evidence_aggregates')->where('incident_id', $f['incident']->id)->update(['active_window_revision' => 1]);
        DB::table('audit_logs')->where('id', $f['window']['audit_log_id'])->update(['meta' => json_encode(['schema_version' => 1])]);
        $this->denial(fn () => $this->attach($f, $command), 404); $this->zeroAttachments($f);
    }

    public function test_false_model_save_and_false_create_roll_back_the_entire_new_command(): void
    {
        $f = $this->fixture(); $command = $this->attachCommand($f);
        foreach ([IncidentEvidenceAggregate::class, IncidentRecordingAttachment::class] as $class) {
            $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
            if ($class === IncidentEvidenceAggregate::class) { $class::saving(fn () => false); } else { $class::creating(fn () => false); }
            try { $this->attach($f, $command); $this->fail('A vetoed persistence event cannot confirm a command.'); }
            catch (\LogicException) { $this->zeroAttachments($f); }
            finally { Model::setEventDispatcher($dispatcher); }
        }
        $this->assertSame(1, CctvRecordingReference::where('uuid', $command['reference_uuid'])->count());
    }

    public function test_late_source_revocation_from_audit_listener_rolls_back_without_deleting_bookmark(): void
    {
        $f = $this->fixture(); $command = $this->attachCommand($f); $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        AuditLog::created(function (AuditLog $audit): void {
            if ($audit->action === IncidentRecordingAuditValues::RECORDING_ATTACHED) { $this->allow($this->manager, CctvRecordingReferences::VIEW, false); }
        });
        try { $this->denial(fn () => $this->attach($f, $command), 404); $this->zeroAttachments($f); }
        finally { Model::setEventDispatcher($dispatcher); }
        $this->assertSame(1, CctvRecordingReference::where('uuid', $command['reference_uuid'])->count());
    }

    public function test_late_incident_capability_revocation_from_model_listener_rolls_back(): void
    {
        $f = $this->fixture(); $command = $this->attachCommand($f); $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        IncidentRecordingAttachment::created(fn () => $this->allow($this->manager, IncidentRecordingCommandService::ATTACH_PERMISSION, false));
        try { $this->denial(fn () => $this->attach($f, $command), 403); $this->zeroAttachments($f); }
        finally { Model::setEventDispatcher($dispatcher); }
    }

    public function test_strict_audit_failure_rolls_back_all_incident_side_effects(): void
    {
        $f = $this->fixture(); $command = $this->attachCommand($f); $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->action === IncidentRecordingAuditValues::RECORDING_ATTACHED) { throw new \RuntimeException('Synthetic strict audit refusal.'); }
        });
        try { $this->attach($f, $command); $this->fail('Strict audit refusal must abort the command.'); }
        catch (\RuntimeException $error) { $this->assertSame('Synthetic strict audit refusal.', $error->getMessage()); $this->zeroAttachments($f); }
        finally { Model::setEventDispatcher($dispatcher); }
    }
}
