<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\RestraintDebrief;
use App\Models\RestraintMonitoringObservation;
use App\Models\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

class RestraintMonitoringDebriefTest extends RestraintEventCommandTestCase
{
    public function test_observation_records_actual_observer_without_inventing_cadence_and_correction_retains_original(): void
    {
        $row = $this->event();
        $observer = $this->staff([]);
        $first = $this->service()->addObservation($row, $this->actor, $this->command($row, ['observed_by' => $observer->id, 'observed_at' => now()->subMinutes(10)->toIso8601String(), 'condition' => 'Observed condition', 'response' => 'Person response']));
        $original = RestraintMonitoringObservation::findOrFail($first['observation_id']);
        $this->assertSame($observer->id, $original->actual_actor_id);
        $this->assertNull($original->facts['next_check_at']);
        $second = $this->service()->correctObservation($row, $this->actor, $this->command($row, ['observation_id' => $original->id, 'observed_at' => now()->subMinutes(10)->toIso8601String(), 'condition' => 'Corrected observation', 'reason' => 'Correct transcription']));
        $this->assertSame($original->id, $second['supersedes_id']);
        $corrected = RestraintMonitoringObservation::findOrFail($second['observation_id']);
        $this->assertSame($observer->id, $corrected->actual_actor_id);
        $this->assertSame($original->actual_actor_snapshot, $corrected->actual_actor_snapshot);
        $this->assertSame($this->actor->id, $corrected->recorder_id);
        $this->assertSame('Observed condition', $original->fresh()->facts['condition']);
        $this->invalid(fn () => $this->service()->correctObservation($row, $this->actor, $this->command($row, ['observation_id' => $original->id, 'observed_at' => now()->subMinutes(10)->toIso8601String(), 'condition' => 'Incorrect predecessor', 'reason' => 'Must use latest'])), 'observation_id');
    }

    public static function retainedObserverInputs(): array
    {
        return ['omitted' => [[]], 'explicit null' => [['observed_by' => null]]];
    }

    #[DataProvider('retainedObserverInputs')]
    public function test_correction_preserves_offboarded_observer_exact_snapshot_and_separate_current_recorder(array $attribution): void
    {
        $row = $this->event();
        $observer = $this->staff([]);
        $first = $this->service()->addObservation($row, $this->actor, $this->command($row, ['observed_by' => $observer->id, 'observed_at' => now()->subMinutes(10)->toIso8601String(), 'condition' => 'Original factual observation']));
        $original = RestraintMonitoringObservation::findOrFail($first['observation_id']);
        $snapshot = $original->actual_actor_snapshot;
        $observer->load('hrEmployeeProfile', 'roles.permissions');
        $observer->hrEmployeeProfile()->update(['is_active' => false]);
        $observer->updateQuietly(['name' => 'Changed directory name']);
        $corrector = $this->staff(['restraints.review', 'clients.viewAny']);
        $input = $this->command($row, ['observation_id' => $original->id, 'observed_at' => now()->subMinutes(9)->toIso8601String(), 'condition' => 'Corrected time and condition', 'response' => 'Retained factual response', 'reason' => 'Correct transcription', ...$attribution]);
        $accepted = $this->service()->correctObservation($row, $corrector, $input);
        $corrected = RestraintMonitoringObservation::findOrFail($accepted['observation_id']);
        $this->assertNotSame($original->id, $corrected->id);
        $this->assertSame($original->id, $corrected->supersedes_id);
        $this->assertSame($observer->id, $corrected->actual_actor_id);
        $this->assertSame($snapshot, $corrected->actual_actor_snapshot);
        $this->assertSame($observer->id, $corrected->facts['observed_by']);
        $this->assertSame($corrector->id, $corrected->recorder_id);
        $this->assertSame($corrector->id, $corrected->actor_snapshot['id']);
        $this->assertSame($input['idempotency_key'], $corrected->command_key);
        $this->assertSame($row->source_revision, $corrected->source_revision);
        $this->assertSame('Original factual observation', $original->fresh()->facts['condition']);
        $this->assertSame($snapshot, $original->fresh()->actual_actor_snapshot);
        $replayed = $this->service()->correctObservation($row, $corrector, $input);
        $this->assertTrue($replayed['replayed']);
        $this->assertSame($accepted['observation_id'], $replayed['observation_id']);
        $this->assertSame(3, $row->fresh()->lock_version);
        $this->assertDatabaseCount('restraint_monitoring_observations', 2);
        $this->assertDatabaseCount('restraint_command_receipts', 3);
    }

    public static function unknownObserverSnapshots(): array
    {
        return ['unknown' => [null], 'retained external identity' => [['name' => 'Historical observer', 'id' => null, 'roles' => []]], 'retained empty JSON' => [[]]];
    }

    #[DataProvider('unknownObserverSnapshots')]
    public function test_correction_does_not_invent_an_actual_observer_for_legacy_unknown_attribution(?array $snapshot): void
    {
        $row = $this->event();
        $original = RestraintMonitoringObservation::create(['restraint_event_id' => $row->id, 'source_revision' => $row->source_revision,
            'recorder_id' => $this->actor->id, 'actor_snapshot' => $this->service()->actorSnapshot($this->actor),
            'actual_actor_id' => null, 'actual_actor_snapshot' => $snapshot, 'occurred_at' => now()->subMinutes(10),
            'facts' => ['condition' => 'Retained legacy condition'], 'evidence_ids' => [], 'command_key' => (string) Str::uuid()]);
        $accepted = $this->service()->correctObservation($row, $this->actor, $this->command($row, ['observation_id' => $original->id, 'observed_by' => null, 'observed_at' => now()->subMinutes(9)->toIso8601String(), 'condition' => 'Corrected legacy condition', 'reason' => 'Actual transcription correction']));
        $corrected = RestraintMonitoringObservation::findOrFail($accepted['observation_id']);
        $this->assertNull($corrected->actual_actor_id);
        $this->assertSame($snapshot, $corrected->actual_actor_snapshot);
        $this->assertNull($corrected->facts['observed_by']);
        $this->assertSame($this->actor->id, $corrected->recorder_id);
        $this->assertSame($original->id, $corrected->supersedes_id);
        $this->assertSame($snapshot, $original->fresh()->actual_actor_snapshot);
        $this->assertSame('Retained legacy condition', $original->fresh()->facts['condition']);
    }

    public function test_explicit_replacement_records_current_observer_and_new_immutable_command_provenance(): void
    {
        $row = $this->event();
        $originalObserver = $this->staff([]);
        $replacement = $this->staff([]);
        $first = $this->service()->addObservation($row, $this->actor, $this->command($row, ['observed_by' => $originalObserver->id, 'observed_at' => now()->subMinutes(10)->toIso8601String(), 'condition' => 'Original condition']));
        $original = RestraintMonitoringObservation::findOrFail($first['observation_id']);
        $input = $this->command($row, ['observation_id' => $original->id, 'observed_by' => $replacement->id, 'observed_at' => now()->subMinutes(10)->toIso8601String(), 'condition' => 'Original condition', 'reason' => 'Correct actual observer identity']);
        $accepted = $this->service()->correctObservation($row, $this->actor, $input);
        $corrected = RestraintMonitoringObservation::findOrFail($accepted['observation_id']);
        $this->assertSame($replacement->id, $corrected->actual_actor_id);
        $this->assertSame($replacement->id, $corrected->actual_actor_snapshot['id']);
        $this->assertSame($replacement->name, $corrected->actual_actor_snapshot['name']);
        $this->assertSame($replacement->id, $corrected->facts['observed_by']);
        $this->assertSame($original->id, $corrected->supersedes_id);
        $this->assertNotSame($original->command_key, $corrected->command_key);
        $this->assertSame($input['idempotency_key'], $corrected->command_key);
        $this->assertSame($originalObserver->id, $original->fresh()->actual_actor_id);
        $this->assertSame(3, $accepted['version']);
        $this->invalid(fn () => $this->service()->correctObservation($row, $this->actor, [...$input, 'idempotency_key' => (string) Str::uuid()]), 'expected_version');
        $replacement->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertTrue($this->service()->correctObservation($row, $this->actor, $input)['replayed']);
        $this->assertDatabaseCount('restraint_monitoring_observations', 2);
    }

    public static function ineligibleReplacementStates(): array
    {
        return ['offboarded' => ['offboarded'], 'foreign Site' => ['foreign_site'], 'not approved' => ['not_approved']];
    }

    #[DataProvider('ineligibleReplacementStates')]
    public function test_explicit_observer_replacement_requires_fresh_staff_eligibility_without_partial_history(string $state): void
    {
        $row = $this->event();
        $first = $this->service()->addObservation($row, $this->actor, $this->command($row, ['observed_at' => now()->subMinutes(10)->toIso8601String(), 'condition' => 'Original condition']));
        $replacement = $this->staff([]);
        $replacement->load('hrEmployeeProfile', 'roles.permissions');
        match ($state) {
            'offboarded' => $replacement->hrEmployeeProfile()->update(['is_active' => false]),
            'foreign_site' => $replacement->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create()->id]),
            'not_approved' => $replacement->updateQuietly(['approved_at' => null]),
        };
        $auditCount = AuditLog::count();
        $input = $this->command($row, ['observation_id' => $first['observation_id'], 'observed_by' => $replacement->id, 'observed_at' => now()->subMinutes(10)->toIso8601String(), 'condition' => 'Original condition', 'reason' => 'Actual identity correction']);
        $this->denied(fn () => $this->service()->correctObservation($row, $this->actor, $input));
        $this->assertSame(2, $row->fresh()->lock_version);
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertDatabaseCount('restraint_monitoring_observations', 1);
        $this->assertDatabaseCount('restraint_command_receipts', 2);
        $this->assertSame($this->actor->id, RestraintMonitoringObservation::findOrFail($first['observation_id'])->actual_actor_id);
    }

    public function test_explicit_reselection_of_offboarded_historical_observer_is_not_an_eligibility_bypass(): void
    {
        $row = $this->event();
        $observer = $this->staff([]);
        $first = $this->service()->addObservation($row, $this->actor, $this->command($row, ['observed_by' => $observer->id, 'observed_at' => now()->subMinutes(10)->toIso8601String(), 'condition' => 'Original condition']));
        $observer->hrEmployeeProfile()->update(['is_active' => false]);
        $this->denied(fn () => $this->service()->correctObservation($row, $this->actor, $this->command($row, ['observation_id' => $first['observation_id'], 'observed_by' => $observer->id, 'observed_at' => now()->subMinutes(10)->toIso8601String(), 'condition' => 'Corrected condition', 'reason' => 'Explicit observer reselection'])));
        $this->assertSame(2, $row->fresh()->lock_version);
        $this->assertDatabaseCount('restraint_monitoring_observations', 1);
    }

    public function test_retained_historical_observer_never_substitutes_for_current_correction_authority(): void
    {
        $row = $this->event();
        $first = $this->service()->addObservation($row, $this->actor, $this->command($row, ['observed_at' => now()->subMinutes(10)->toIso8601String(), 'condition' => 'Original condition']));
        $this->actor->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->revoke($this->actor, 'restraints.review');
        $this->revoke($this->actor, 'restraints.manage');
        $this->denied(fn () => $this->service()->correctObservation($row, $this->actor, $this->command($row, ['observation_id' => $first['observation_id'], 'observed_by' => null, 'observed_at' => now()->subMinutes(10)->toIso8601String(), 'condition' => 'Corrected condition', 'reason' => 'Actual correction'])));
        $this->assertSame(2, $row->fresh()->lock_version);
        $this->assertDatabaseCount('restraint_monitoring_observations', 1);
        $this->assertDatabaseCount('restraint_command_receipts', 2);
    }

    public function test_debrief_pending_declined_and_actual_person_voice_are_separate_retained_facts(): void
    {
        $row = $this->event();
        $pending = $this->service()->recordDebrief($row, $this->actor, $this->command($row, ['status' => 'not_yet_done', 'reason' => 'Person requested later support']));
        $this->assertNull(RestraintDebrief::findOrFail($pending['debrief_id'])->occurred_at);
        $done = $this->service()->correctDebrief($row, $this->actor, $this->command($row, ['debrief_id' => $pending['debrief_id'], 'status' => 'completed', 'occurred_at' => now()->subMinute()->toIso8601String(), 'actual_participants' => ['Person', 'Chosen advocate'], 'person_voice' => 'Actual person account', 'communication_support' => 'Chosen communication method', 'advocacy_support' => 'Advocate present', 'cultural_preferences' => 'Person preference', 'contact_outcome' => 'Actual contact', 'learning' => 'Actual learning', 'reason' => 'Later contact occurred']));
        $this->assertSame('not_yet_done', RestraintDebrief::findOrFail($pending['debrief_id'])->facts['status']);
        $this->assertSame('Actual person account', RestraintDebrief::findOrFail($done['debrief_id'])->facts['person_voice']);
        $this->assertTrue($row->fresh()->needsReview());
    }

    public function test_missing_actual_contact_future_fact_and_foreign_child_deny_without_appending_rows(): void
    {
        $row = $this->event();
        $other = $this->event();
        $this->invalid(fn () => $this->service()->recordDebrief($row, $this->actor, $this->command($row, ['status' => 'completed'])), 'contact_outcome');
        $this->invalid(fn () => $this->service()->addObservation($row, $this->actor, $this->command($row, ['observed_at' => now()->addMinute()->toIso8601String(), 'condition' => 'Future invented fact'])), 'observed_at');
        $fact = $this->service()->addObservation($other, $this->actor, $this->command($other, ['observed_at' => now()->subMinute()->toIso8601String(), 'condition' => 'Other source']));
        try {
            $this->service()->correctObservation($row, $this->actor, $this->command($row, ['observation_id' => $fact['observation_id'], 'observed_at' => now()->subMinute()->toIso8601String(), 'condition' => 'Wrong source', 'reason' => 'Forged child']));
            $this->fail('Foreign child must deny.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('restraint_monitoring_observations', 1);
        }
    }

    public function test_suppressed_factual_child_cannot_create_a_success_receipt(): void
    {
        $row = $this->event();
        RestraintMonitoringObservation::creating(fn () => false);
        try {
            $this->service()->addObservation($row, $this->actor, $this->command($row, ['observed_at' => now()->subMinute()->toIso8601String(), 'condition' => 'Must persist']));
            $this->fail('Suppressed actual fact cannot succeed.');
        } catch (LogicException) {
            $this->assertSame(1, $row->fresh()->lock_version);
            $this->assertDatabaseCount('restraint_command_receipts', 1);
        } finally {
            RestraintMonitoringObservation::flushEventListeners();
            RestraintMonitoringObservation::clearBootedModels();
        }
    }
}
