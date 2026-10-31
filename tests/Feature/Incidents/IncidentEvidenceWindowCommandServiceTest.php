<?php

namespace Tests\Feature\Incidents;

use App\Models\{AuditLog, ClientIncident, IncidentEvidenceAggregate, IncidentEvidenceRevision, IncidentEvidenceWindow, IncidentEvidenceWindowCommandReceipt};
use App\Services\Incidents\{IncidentRecordingAuditValues, IncidentRecordingCommandService, IncidentRecordingReferenceReadService};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Real settings approval, source authority and immutable models. Source-only; UNRUN. */
final class IncidentEvidenceWindowCommandServiceTest extends IncidentRecordingCommandTestCase
{
    private function windowFixture(): array
    {
        $f = $this->fixture();
        $f['incident'] = $this->incident(['type' => 'fall', 'source' => 'manual', 'occurred_at' => '2026-10-12 02:00:00.123456']);
        foreach ([IncidentRecordingCommandService::WINDOW_PERMISSION, 'incidents.update'] as $permission) { $this->allow($this->manager, $permission); }
        return $f;
    }

    private function windowCommand(array $f, array $changes = []): array
    {
        return ['expected_actor_id' => $this->manager->id, 'idempotency_key' => (string) Str::uuid(),
            'expected_incident_evidence_version' => 0, 'expected_policy_revision' => $f['window']['rule_revision'],
            'requested_start_at' => '2026-10-12T01:59:00Z', 'requested_end_at' => '2026-10-12T02:01:00Z',
            'approved_sources' => $f['window']['approved_sources'], 'reason' => 'Record this exact incident evidence window.', ...$changes];
    }

    private function record(array $f, array $command): array
    {
        return app(IncidentRecordingCommandService::class)->recordWindow($this->receiver(), $f['incident']->id, $command);
    }

    private function noWindow(array $f): void
    {
        $id = $f['incident']->id;
        $this->assertNull(IncidentEvidenceAggregate::find($id));
        foreach ([IncidentEvidenceWindow::class, IncidentEvidenceRevision::class, IncidentEvidenceWindowCommandReceipt::class] as $model) {
            $this->assertSame(0, $model::where('incident_id', $id)->count());
        }
        $this->assertSame(0, AuditLog::where('auditable_id', $id)->where('action', IncidentRecordingAuditValues::WINDOW_RECORDED)->count());
    }

    public function test_initial_and_successor_windows_have_exact_receipts_and_reader_proof(): void
    {
        $f = $this->windowFixture(); $command = $this->windowCommand($f); $first = $this->record($f, $command);
        $this->assertTrue($first['created']); $this->assertSame(1, $first['response']['current']['incident_evidence_version']);
        $this->assertNull(IncidentEvidenceWindow::where('incident_id', $f['incident']->id)->firstOrFail()->encrypted_override_reason);
        $next = $this->record($f, $this->windowCommand($f, ['expected_incident_evidence_version' => 1]));
        $this->assertSame(2, $next['response']['current']['evidence_window_revision']);
        $this->assertSame(2, IncidentEvidenceWindowCommandReceipt::where('incident_id', $f['incident']->id)->count());
        $reader = app(IncidentRecordingReferenceReadService::class)->forIncident($this->receiver(), $f['incident']->id);
        $this->assertSame(2, $reader['window']['revision']);
    }

    public function test_exact_replay_and_recovery_survive_closure_but_not_source_scope_loss(): void
    {
        $f = $this->windowFixture(); $command = $this->windowCommand($f); $first = $this->record($f, $command);
        ClientIncident::withoutEvents(fn () => $f['incident']->update(['status' => 'closed']));
        $replay = $this->record($f, $command); $this->assertFalse($replay['created']);
        $this->assertSame($first['response'], $replay['response']);
        $recovered = app(IncidentRecordingCommandService::class)->recoverWindow($this->receiver(), $f['incident']->id, $this->manager->id, $command['idempotency_key']);
        $this->assertSame($first['response'], $recovered);
        $this->allow($this->manager, 'securityDevices.viewAny', false);
        $this->denial(fn () => $this->record($f, $command), 404);
        $this->denial(fn () => app(IncidentRecordingCommandService::class)->recoverWindow($this->receiver(), $f['incident']->id, $this->manager->id, $command['idempotency_key']), 404);
    }

    public function test_expansion_needs_distinct_permission_and_preserves_exact_bounded_reason(): void
    {
        $f = $this->windowFixture(); $command = $this->windowCommand($f, ['requested_start_at' => '2026-10-12T01:58:00Z', 'reason' => str_repeat('a', 500)]);
        $this->denial(fn () => $this->record($f, $command), 403); $this->noWindow($f);
        $this->allow($this->manager, IncidentRecordingCommandService::EXPAND_PERMISSION);
        $this->record($f, $command);
        $this->assertSame($command['reason'], IncidentEvidenceWindow::where('incident_id', $f['incident']->id)->firstOrFail()->encrypted_override_reason);
        try {
            $this->record($f, $this->windowCommand($f, ['expected_incident_evidence_version' => 1, 'requested_start_at' => '2026-10-12T01:57:59Z']));
            $this->fail('Configured maximum must reject this interval.');
        } catch (\Illuminate\Validation\ValidationException $error) { $this->assertArrayHasKey('window', $error->errors()); }
    }

    public function test_stale_policy_and_version_and_closed_incident_cannot_initialize_an_aggregate(): void
    {
        $f = $this->windowFixture();
        $this->denial(fn () => $this->record($f, $this->windowCommand($f, ['expected_policy_revision' => 0])), 409);
        $this->denial(fn () => $this->record($f, $this->windowCommand($f, ['expected_incident_evidence_version' => 1])), 409);
        ClientIncident::withoutEvents(fn () => $f['incident']->update(['status' => 'closed']));
        $this->denial(fn () => $this->record($f, $this->windowCommand($f)), 403); $this->noWindow($f);
    }

    public function test_missing_policy_has_no_default_and_empty_source_selection_grants_nothing(): void
    {
        foreach ([IncidentRecordingCommandService::WINDOW_PERMISSION, 'incidents.update'] as $permission) { $this->allow($this->manager, $permission); }
        $f = ['incident' => $this->incident(['type' => 'fall', 'source' => 'manual', 'occurred_at' => '2026-10-12 02:00:00.123456']),
            'window' => ['rule_revision' => 0, 'approved_sources' => []]];
        $this->denial(fn () => $this->record($f, $this->windowCommand($f)), 409); $this->noWindow($f);
    }

    public function test_exact_key_with_changed_reason_conflicts_without_advancing_the_version(): void
    {
        $f = $this->windowFixture(); $command = $this->windowCommand($f); $this->record($f, $command);
        $this->denial(fn () => $this->record($f, [...$command, 'reason' => 'A different reviewed reason for this window.']), 409);
        $this->assertSame(1, IncidentEvidenceAggregate::findOrFail($f['incident']->id)->incident_evidence_version);
        $this->denial(fn () => app(IncidentRecordingCommandService::class)->recoverWindow($this->receiver(), $f['incident']->id, $this->reporter->id, $command['idempotency_key']), 403);
    }

    public function test_window_model_veto_rolls_back_audit_and_initial_aggregate(): void
    {
        $f = $this->windowFixture(); $events = Model::getEventDispatcher(); Model::setEventDispatcher(clone $events);
        try {
            IncidentEvidenceWindow::creating(fn () => false);
            try { $this->record($f, $this->windowCommand($f)); $this->fail('Expected immutable insert veto.'); }
            catch (\LogicException $error) { $this->assertStringContainsString('vetoed', $error->getMessage()); }
        } finally { Model::setEventDispatcher($events); }
        $this->noWindow($f);
    }

    public function test_late_source_permission_revocation_rolls_back_all_window_records(): void
    {
        $f = $this->windowFixture(); $events = Model::getEventDispatcher(); Model::setEventDispatcher(clone $events);
        try {
            IncidentEvidenceWindowCommandReceipt::created(function (): void { $this->allow($this->manager, 'securityDevices.viewAny', false); });
            $this->denial(fn () => $this->record($f, $this->windowCommand($f)), 404);
        } finally { Model::setEventDispatcher($events); }
        $this->noWindow($f);
    }

    public function test_clock_crossing_during_audit_save_preserves_server_capture_and_exact_reader_proof(): void
    {
        $f = $this->windowFixture();
        $captured = now('UTC')->toImmutable()->setMicrosecond(654321); $this->travelTo($captured);
        $events = Model::getEventDispatcher(); Model::setEventDispatcher(clone $events);
        AuditLog::saving(function (AuditLog $audit): void {
            if ($audit->action === IncidentRecordingAuditValues::WINDOW_RECORDED) { $this->travel(2)->seconds(); }
        });
        try {
            $result = $this->record($f, $this->windowCommand($f)); $this->assertTrue($result['created']);
            $window = IncidentEvidenceWindow::where('incident_id', $f['incident']->id)->firstOrFail();
            $audit = AuditLog::findOrFail($window->audit_log_id);
            $this->assertSame($captured->format('Y-m-d\TH:i:s.u\Z'), $window->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z'));
            $this->assertSame($captured->format('Y-m-d\TH:i:s\Z'), $audit->created_at->utc()->format('Y-m-d\TH:i:s\Z'));
            $this->assertSame($audit->created_at->format('Y-m-d H:i:s'), $audit->updated_at->format('Y-m-d H:i:s'));
            $this->assertSame($window->window_sha256, $audit->meta['window_sha256']);
            $reader = app(IncidentRecordingReferenceReadService::class)->forIncident($this->receiver(), $f['incident']->id);
            $this->assertSame(1, $reader['window']['revision']);
        } finally { Model::setEventDispatcher($events); $this->travelTo($captured); }
    }

    public function test_explicit_audit_save_veto_rolls_back_window_and_audit(): void
    {
        $f = $this->windowFixture(); $events = Model::getEventDispatcher(); Model::setEventDispatcher(clone $events);
        AuditLog::saving(fn (AuditLog $audit) => $audit->action === IncidentRecordingAuditValues::WINDOW_RECORDED ? false : null);
        try {
            try { $this->record($f, $this->windowCommand($f)); $this->fail('An audit save veto cannot confirm a window.'); }
            catch (\LogicException $error) { $this->assertStringContainsString('strict audit insert was vetoed', $error->getMessage()); }
        } finally { Model::setEventDispatcher($events); }
        $this->noWindow($f);
    }

    public function test_audit_observer_cannot_change_bound_timestamp_or_window_digest(): void
    {
        $f = $this->windowFixture();
        foreach (['timestamp', 'digest'] as $mutation) {
            $events = Model::getEventDispatcher(); Model::setEventDispatcher(clone $events);
            AuditLog::saving(function (AuditLog $audit) use ($mutation): void {
                if ($audit->action !== IncidentRecordingAuditValues::WINDOW_RECORDED) { return; }
                if ($mutation === 'timestamp') { $audit->setCreatedAt($audit->created_at->copy()->addSecond()); }
                else { $meta = $audit->meta; $meta['window_sha256'] = str_repeat('0', 64); $audit->meta = $meta; }
            });
            try { $this->denial(fn () => $this->record($f, $this->windowCommand($f)), $mutation === 'timestamp' ? 409 : 404); }
            finally { Model::setEventDispatcher($events); }
            $this->noWindow($f);
        }
    }
}