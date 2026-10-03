<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BreakGlassPolicy;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationEvent;
use App\Models\MedicationFollowup;
use App\Models\MedicationPrnEffectiveness;
use App\Models\MedicationRefusalFollowup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\Tasks\Providers\MedicationFollowupProvider;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MedicationFollowupWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Client $client;

    private Site $site;

    private ServiceContext $context;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true, 'name' => 'Synthetic follow-up house']);
        $this->context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active']);
        $this->worker = $this->staff();
        $this->shift = $this->onShift($this->worker);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_one_source_identity_is_projected_into_tasks_without_duplicate_records(): void
    {
        [$dose, $row] = $this->effect();
        DB::transaction(fn () => $this->work()->syncAdministration($dose));
        $this->assertDatabaseCount('medication_followups', 1);
        $this->assertDatabaseCount('medication_followup_events', 1);
        $tasks = (new MedicationFollowupProvider)->authorizedTasks($this->worker);
        $this->assertCount(1, $tasks);
        $this->assertSame('medication-followup-'.$row->id, $tasks[0]->id);
        $this->assertSame($row->due_at->toIso8601String(), $tasks[0]->dueAt);
        $this->assertSame($this->worker->id, $tasks[0]->assignee['id']);
    }

    public function test_a_prn_without_a_chosen_time_has_no_synthetic_default(): void
    {
        [, $row] = $this->effect(['effect_check_due_at' => null]);
        $this->assertNull($row->due_at);
        $this->actingAs($this->worker)->getJson('/medication-followups/'.$row->id)->assertOk()->assertJsonPath('due_at', null);
    }

    public function test_pending_and_rejected_given_corrections_do_not_create_phantom_effect_work(): void
    {
        [$original] = $this->effect();
        foreach (['pending', 'rejected'] as $status) {
            $correction = ClientMedicationAdministration::query()->create([
                'client_id' => $this->client->id, 'client_medication_id' => $original->client_medication_id,
                'administered_by' => $this->worker->id, 'status' => 'given', 'is_correction' => true,
                'corrected_of_id' => $original->id, 'correction_status' => $status,
                'administered_at' => now()->subMinutes(30), 'effect_check_due_at' => now()->addHour(),
            ]);
            DB::transaction(fn () => $this->work()->syncAdministration($correction));
            $this->assertDatabaseMissing('medication_followups', ['source_key' => 'effect:'.$correction->id]);
        }
        $this->assertCount(1, (new MedicationFollowupProvider)->authorizedTasks($this->worker));
        $this->artisan('emar:workflow-followups', ['--import' => true])->assertExitCode(0);
        $this->assertDatabaseCount('medication_followups', 1);
    }

    public function test_accepted_correction_retires_old_work_and_projects_only_its_given_replacement(): void
    {
        [$original, $old] = $this->effect();
        $replacement = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id, 'client_medication_id' => $original->client_medication_id,
            'administered_by' => $this->worker->id, 'status' => 'given', 'is_correction' => true,
            'corrected_of_id' => $original->id, 'correction_status' => 'approved',
            'correction_approved_at' => now(), 'administered_at' => now()->subMinutes(30),
            'effect_check_due_at' => now()->addHour(),
        ]);
        // Even before aggregate synchronization, an ineffective open row is concealed.
        $this->actingAs($this->worker)->getJson('/medication-followups/'.$old->id)->assertNotFound();
        $this->assertCount(0, (new MedicationFollowupProvider)->authorizedTasks($this->worker));
        DB::transaction(function () use ($original, $replacement) {
            $this->work()->syncAdministration($original);
            $this->work()->syncAdministration($replacement);
        });
        $this->assertNotNull($old->fresh()->completed_at);
        $this->assertSame(1, $old->events()->where('action', 'source_retired')->count());
        $this->assertSame(2, $old->events()->count());
        $new = MedicationFollowup::query()->where('source_key', 'effect:'.$replacement->id)->sole();
        $this->actingAs($this->worker)->getJson('/medication-followups/'.$old->id)->assertOk()->assertJsonPath('can_complete', false);
        $this->postFollowup($old->fresh(), ['action' => 'couldnt_check', 'reason' => 'Away', 'again_at' => '2026-10-03T10:00:00+13:00'])->assertNotFound();
        $this->postFollowup($old->fresh(), ['action' => 'amend_effect', 'outcome' => 'effective'])->assertNotFound();
        $tasks = (new MedicationFollowupProvider)->authorizedTasks($this->worker);
        $this->assertCount(1, $tasks);
        $this->assertSame('medication-followup-'.$new->id, $tasks[0]->id);
        $this->postFollowup($new, ['action' => 'effect', 'outcome' => 'effective'])->assertOk();
        $this->assertSame($replacement->id, MedicationPrnEffectiveness::query()->sole()->client_medication_administration_id);
    }

    public function test_a_not_given_accepted_correction_never_invents_a_replacement_effect_check(): void
    {
        [$original, $old] = $this->effect();
        $correction = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id, 'client_medication_id' => $original->client_medication_id,
            'status' => 'not_given', 'is_correction' => true, 'corrected_of_id' => $original->id,
            'administered_by' => $this->worker->id,
            'correction_status' => 'approved', 'correction_approved_at' => now(), 'administered_at' => now(),
        ]);
        DB::transaction(function () use ($original, $correction) {
            $this->work()->syncAdministration($original);
            $this->work()->syncAdministration($correction);
        });
        $this->assertDatabaseCount('medication_followups', 1);
        $this->assertNotNull($old->fresh()->completed_at);
        $this->assertCount(0, (new MedicationFollowupProvider)->authorizedTasks($this->worker));
        $this->assertDatabaseCount('client_medication_administrations', 2);
    }

    public function test_approval_endpoint_synchronizes_retirement_inside_the_correction_aggregate(): void
    {
        [$original, $old] = $this->effect();
        $correction = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id, 'client_medication_id' => $original->client_medication_id,
            'status' => 'withheld', 'is_correction' => true, 'corrected_of_id' => $original->id,
            'correction_status' => 'pending', 'correction_requested_by' => $this->worker->id,
            'administered_by' => $this->worker->id, 'administered_at' => now(),
        ]);
        $lead = $this->staff('team_lead');
        $this->grant($lead, ['medications.administer.correct', 'clients.viewAny']);
        $this->actingAs($lead)->post('/emar/corrections/'.$correction->id.'/approve')->assertRedirect();
        $this->assertSame('approved', $correction->fresh()->correction_status);
        $this->assertSame('retired', $old->fresh()->state);
        $this->assertSame(1, $old->events()->where('action', 'source_retired')->count());
        $this->assertCount(0, (new MedicationFollowupProvider)->authorizedTasks($this->worker));
        $this->assertDatabaseCount('client_medication_administrations', 2);
    }

    public function test_source_receipt_rejects_changed_facts_or_actor_and_normalizes_map_order(): void
    {
        $row = DB::transaction(fn () => $this->work()->ensureForSource('support-reassessment', 'receipt', $this->client, null, null, null, null));
        $facts = ['assessment_id' => 33, 'evidence' => ['b' => 1.0, 'a' => true]];
        DB::transaction(fn () => $this->work()->completeFromSource($row->source_key, $this->worker, 'assessed', $facts));
        DB::transaction(fn () => $this->work()->completeFromSource($row->source_key, $this->worker, 'assessed', ['evidence' => ['a' => true, 'b' => 1], 'assessment_id' => 33]));
        foreach ([[$this->worker, [...$facts, 'assessment_id' => 34]], [$this->staff(), $facts]] as [$actor, $attempt]) {
            try {
                DB::transaction(fn () => $this->work()->completeFromSource($row->source_key, $actor, 'assessed', $attempt));
                $this->fail('Changed evidence or actor must conflict.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('outcome', $e->errors());
            }
        }
        $this->assertSame(1, $row->events()->where('action', 'source_completed')->count());
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'followup.source_completed')->count());
    }

    public function test_source_facts_cannot_override_the_reserved_outcome(): void
    {
        $row = DB::transaction(fn () => $this->work()->ensureForSource('support-reassessment', 'reserved', $this->client, null, null, null, null));
        try {
            DB::transaction(fn () => $this->work()->completeFromSource($row->source_key, $this->worker, 'assessed', ['outcome' => 'changed']));
            $this->fail('Outcome must be reserved.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('outcome', $e->errors());
        }
        $this->assertNull($row->fresh()->completed_at);
        $this->assertSame(1, $row->events()->count());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'followup.source_completed')->count());
    }

    public function test_batch_completion_finishes_all_domain_work_before_one_audit_append_and_rolls_back_together(): void
    {
        $rows = DB::transaction(fn () => [
            $this->work()->ensureForSource('order-check', 'batch-a', $this->client, null, null, null, null),
            $this->work()->ensureForSource('second-check', 'batch-b', $this->client, null, null, null, null),
        ]);
        $commands = array_map(fn ($row) => ['source_key' => $row->source_key, 'actor' => $this->worker, 'outcome' => 'checked', 'facts' => ['check_id' => 91]], $rows);
        $mock = \Mockery::mock();
        $mock->shouldReceive('appendMany')->once()->withArgs(function (array $events) use ($rows) {
            $this->assertCount(2, $events);
            foreach ($rows as $row) {
                $this->assertNotNull($row->fresh()->completed_at);
            }

            return true;
        })->andThrow(new \RuntimeException('Synthetic batch audit failure'));
        $this->app->instance(MedicationEventRecorder::class, $mock);
        try {
            DB::transaction(fn () => $this->work()->completeSources($commands));
            $this->fail('The final audit failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic batch audit failure', $error->getMessage());
        }
        foreach ($rows as $row) {
            $this->assertNull($row->fresh()->completed_at);
            $this->assertSame(1, $row->fresh()->revision);
            $this->assertSame(1, $row->events()->count());
        }
        $this->app->forgetInstance(MedicationEventRecorder::class);
        DB::transaction(fn () => $this->work()->completeSources($commands));
        DB::transaction(fn () => $this->work()->completeSources($commands));
        $this->assertSame(2, MedicationEvent::query()->where('kind', 'followup.source_completed')->count());
    }

    public function test_lost_reoffer_response_replay_still_opens_the_exact_recorder_and_does_not_close_work(): void
    {
        [$dose, $row] = $this->refusal();
        $data = ['action' => 'refusal', 'outcome' => 'taken', 'revision' => 1, 'request_uuid' => (string) Str::uuid()];
        $this->postFollowup($row, $data)->assertOk()->assertJsonPath('next_action', 'record_reoffer');
        $this->postFollowup($row, $data)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('next_action', 'record_reoffer')->assertJsonPath('reoffer_of_id', $dose->id);
        $this->assertNull($row->fresh()->completed_at);
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_legacy_refusal_completion_cannot_bypass_the_full_assessment(): void
    {
        [, $row] = $this->refusal();
        $refusal = MedicationRefusalFollowup::query()->sole();
        $this->actingAs($this->worker)->postJson('/emar/refusal-followups/'.$refusal->id.'/complete', [
            'request_uuid' => (string) Str::uuid(), 'revision' => 1,
            'outcome' => 'refused_again', 'reason' => 'They chose not to take it.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reason_category', 'capacity', 'next_action']);
        $this->assertNull($row->fresh()->completed_at);
        $this->assertNull($refusal->fresh()->follow_up_completed_at);
    }

    public function test_legacy_effect_route_uses_canonical_receipt_and_requires_who_and_what(): void
    {
        [$dose, $row] = $this->effect();
        $this->actingAs($this->worker)->postJson('/meds/today/prn/effect', [
            'client_medication_administration_id' => $dose->id,
            'request_uuid' => (string) Str::uuid(), 'revision' => 1, 'effectiveness' => 'not_effective',
        ])->assertUnprocessable()->assertJsonValidationErrors(['told', 'escalation_action']);
        $this->assertNull($row->fresh()->completed_at);
        $this->assertDatabaseCount('medication_prn_effectiveness', 0);
    }

    public function test_legacy_effect_retry_uses_the_original_receipt_action_after_completion(): void
    {
        [$dose] = $this->effect();
        $data = ['client_medication_administration_id' => $dose->id, 'effectiveness' => 'effective',
            'request_uuid' => (string) Str::uuid(), 'revision' => 1];
        $this->actingAs($this->worker)->post('/meds/today/prn/effect', $data)->assertRedirect();
        $this->actingAs($this->worker)->post('/meds/today/prn/effect', $data)->assertRedirect();
        $this->assertDatabaseCount('medication_prn_effectiveness', 1);
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'followup.effect')->count());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'followup.amend_effect')->count());
    }

    public function test_couldnt_check_requires_a_reason_and_never_closes(): void
    {
        [, $row] = $this->effect();
        $this->postFollowup($row, ['action' => 'couldnt_check', 'again_at' => '2026-10-03T10:15:00+13:00'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postFollowup($row, ['action' => 'couldnt_check', 'reason' => 'Asleep', 'again_at' => '2026-10-03T10:15:00+13:00'])->assertOk()->assertJsonPath('state', 'couldnt_check');
        $this->assertNull($row->fresh()->completed_at);
        $this->assertSame('2026-10-02 21:15:00', $row->fresh()->due_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('medication_prn_effectiveness', 0);
    }

    public function test_reschedule_rejects_a_time_after_the_shift_end(): void
    {
        [, $row] = $this->effect();
        $this->postFollowup($row, ['action' => 'couldnt_check', 'reason' => 'Away', 'again_at' => '2026-10-03T15:01:00+13:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('again_at');
        $this->assertSame(1, $row->fresh()->revision);
    }

    public function test_reschedule_accepts_the_exact_end_of_shift(): void
    {
        [, $row] = $this->effect();
        $this->postFollowup($row, ['action' => 'couldnt_check', 'reason' => 'Away', 'again_at' => '2026-10-03T15:00:00+13:00'])->assertOk();
        $this->assertNull($row->fresh()->completed_at);
    }

    public function test_a_successful_effect_is_written_once_and_replay_is_a_duplicate(): void
    {
        [, $row] = $this->effect();
        $data = ['action' => 'effect', 'outcome' => 'effective', 'request_uuid' => (string) Str::uuid(), 'revision' => 1];
        $this->postFollowup($row, $data)->assertOk()->assertJsonPath('sync.status', 'processed');
        $this->postFollowup($row, $data)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('medication_prn_effectiveness', 1);
        $this->assertSame(2, $row->fresh()->revision);
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'followup.effect')->count());
    }

    public function test_reusing_a_request_for_different_details_is_rejected(): void
    {
        [, $row] = $this->effect();
        $data = ['action' => 'effect', 'outcome' => 'effective', 'request_uuid' => (string) Str::uuid(), 'revision' => 1];
        $this->postFollowup($row, $data)->assertOk();
        $this->postFollowup($row, [...$data, 'outcome' => 'partially_effective'])->assertUnprocessable()->assertJsonValidationErrors('request_uuid');
        $this->assertSame('effective', MedicationPrnEffectiveness::query()->sole()->effectiveness);
    }

    public function test_stale_revision_preserves_entries_and_changes_no_evidence(): void
    {
        [, $row] = $this->effect();
        $this->postFollowup($row, ['action' => 'couldnt_check', 'reason' => 'Away', 'again_at' => '2026-10-03T10:00:00+13:00'])->assertOk();
        $this->postFollowup($row, ['action' => 'effect', 'outcome' => 'effective', 'revision' => 1])->assertConflict();
        $this->assertNull($row->fresh()->completed_at);
    }

    public function test_didnt_help_requires_who_was_told_and_what_was_done(): void
    {
        [, $row] = $this->effect();
        $this->postFollowup($row, ['action' => 'effect', 'outcome' => 'not_effective'])->assertUnprocessable()->assertJsonValidationErrors(['told', 'escalation_action']);
        $this->postFollowup($row, ['action' => 'effect', 'outcome' => 'not_effective', 'told' => 'House lead', 'escalation_action' => 'Rang the lead; they will assess.'])->assertOk();
        $this->assertTrue(MedicationPrnEffectiveness::query()->sole()->escalation_needed);
    }

    public function test_lead_work_cannot_be_signed_off_by_a_worker(): void
    {
        [$dose] = $this->effect();
        $row = DB::transaction(fn () => $this->work()->ensure('synthetic-unconfirmed:'.$dose->id, 'unconfirmed', $this->client, $dose->medication, $dose, null, now()->addHour()));
        $this->postFollowup($row, ['action' => 'signoff', 'outcome' => 'Reviewed'])->assertForbidden();
        $lead = $this->staff('team_lead');
        $this->grant($lead, ['medications.followups.manage', 'clients.viewAny']);
        $this->actingAs($lead)->postJson('/medication-followups/'.$row->id.'/transition', ['action' => 'signoff', 'outcome' => 'Reviewed with recorder', 'revision' => 1, 'request_uuid' => (string) Str::uuid()])->assertOk();
        $this->assertNotNull($row->fresh()->completed_at);
    }

    public function test_reassignment_preserves_original_owner_and_requires_a_rostered_recipient(): void
    {
        [, $row] = $this->effect();
        $other = $this->staff();
        $this->postFollowup($row, ['action' => 'reassign', 'owner_id' => $other->id, 'reason' => 'Handing over'])->assertUnprocessable();
        $this->onShift($other);
        $this->client->supportWorkers()->syncWithoutDetaching([$other->id]);
        $this->postFollowup($row, ['action' => 'reassign', 'owner_id' => $other->id, 'reason' => 'Handing over'])->assertOk();
        $this->assertSame($other->id, $row->fresh()->owner_id);
        $this->assertSame($this->worker->id, $row->fresh()->original_owner_id);
    }

    public function test_an_unassigned_person_at_the_same_site_is_not_disclosed(): void
    {
        [, $row] = $this->effect();
        $other = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
        $row->update(['client_id' => $other->id]);
        $this->actingAs($this->worker)->getJson('/medication-followups/'.$row->id)->assertNotFound();
        $this->assertCount(0, (new MedicationFollowupProvider)->authorizedTasks($this->worker));
    }

    public function test_a_person_move_denies_the_old_site_everywhere(): void
    {
        [, $row] = $this->effect();
        $other = Site::factory()->create(['is_active' => true]);
        $this->client->update(['site_id' => $other->id]);
        $this->actingAs($this->worker)->getJson('/medication-followups/'.$row->id)->assertNotFound();
        $this->postFollowup($row, ['action' => 'effect', 'outcome' => 'effective'])->assertNotFound();
        $this->assertCount(0, (new MedicationFollowupProvider)->authorizedTasks($this->worker));
    }

    public function test_controlled_items_are_absent_from_list_detail_tasks_and_mutations_without_access(): void
    {
        [, $row] = $this->effect([], ['controlled_drug' => true, 'name' => 'Private controlled medicine']);
        $this->deny($this->worker, ['medications.controlled.view', 'medications.controlled.record']);
        $this->actingAs($this->worker)->getJson('/medication-followups')->assertOk()->assertJsonCount(0, 'followups.data');
        $this->actingAs($this->worker)->getJson('/medication-followups/'.$row->id)->assertNotFound();
        $this->postFollowup($row, ['action' => 'effect', 'outcome' => 'effective'])->assertNotFound();
        $this->assertCount(0, (new MedicationFollowupProvider)->authorizedTasks($this->worker));
    }

    public function test_lapsed_employment_blocks_mutations_even_for_the_owner(): void
    {
        [, $row] = $this->effect();
        $this->worker->hrEmployeeProfile->update(['is_active' => false]);
        $this->postFollowup($row, ['action' => 'effect', 'outcome' => 'effective'])->assertNotFound();
        $this->assertNull($row->fresh()->completed_at);
    }

    public function test_source_owned_checks_cannot_be_completed_from_the_generic_endpoint(): void
    {
        $lead = $this->staff('team_lead');
        $this->grant($lead, ['medications.followups.manage', 'clients.viewAny']);
        $row = DB::transaction(fn () => $this->work()->ensureForSource('order-check', 22, $this->client, null, null, null, null));
        $this->actingAs($lead)->postJson('/medication-followups/'.$row->id.'/transition', ['action' => 'signoff', 'outcome' => 'Checked', 'revision' => 1, 'request_uuid' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertNull($row->fresh()->completed_at);
    }

    public function test_source_completion_is_idempotent_and_audited_once(): void
    {
        $row = DB::transaction(fn () => $this->work()->ensureForSource('support-reassessment', 33, $this->client, null, null, $this->worker->id, null));
        DB::transaction(fn () => $this->work()->completeFromSource($row->source_key, $this->worker, 'Assessment recorded', ['assessment_id' => 33]));
        DB::transaction(fn () => $this->work()->completeFromSource($row->source_key, $this->worker, 'Assessment recorded', ['assessment_id' => 33]));
        $this->assertSame(2, $row->fresh()->revision);
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'followup.source_completed')->count());
        $this->assertSame(1, $row->events()->where('action', 'source_completed')->count());
    }

    public function test_pin2_negative_source_answer_creates_one_dispute_without_certifying_the_dose(): void
    {
        [$dose] = $this->effect();
        $row = DB::transaction(fn () => $this->work()->ensureForSource('confirm', 44, $this->client, $dose->medication, $dose, $this->worker->id, now()->addMinutes(30)));
        $this->postFollowup($row, ['action' => 'confirmation', 'outcome' => 'yes'])->assertUnprocessable();
        DB::transaction(fn () => $this->work()->completeFromSource($row->source_key, $this->worker, 'no', ['nomination_id' => 44]));
        DB::transaction(fn () => $this->work()->completeFromSource($row->source_key, $this->worker, 'no', ['nomination_id' => 44]));
        $this->assertSame(1, MedicationFollowup::query()->where('type', 'disputed')->count());
        $this->assertNull($dose->fresh()->witnessed_at);
    }

    public function test_short_refusal_not_needed_does_not_create_a_second_refusal(): void
    {
        [$dose, $row] = $this->refusal();
        $this->postFollowup($row, ['action' => 'refusal', 'outcome' => 'not_needed', 'reason' => 'Prescriber advised the next scheduled dose.'])->assertOk();
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertSame('refused', $dose->fresh()->status);
        $this->assertNotNull($row->fresh()->completed_at);
    }

    public function test_second_refusal_requires_the_full_assessment(): void
    {
        [, $row] = $this->refusal();
        $this->postFollowup($row, ['action' => 'refusal', 'outcome' => 'refused_again', 'reason' => 'They chose not to take it.'])->assertUnprocessable()
            ->assertJsonValidationErrors(['reason_category', 'capacity', 'next_action']);
        $this->assertNull($row->fresh()->completed_at);
    }

    public function test_reoffer_recording_cancellation_leaves_followup_open(): void
    {
        [$dose, $row] = $this->refusal();
        $this->postFollowup($row, ['action' => 'refusal', 'outcome' => 'taken'])->assertOk()->assertJsonPath('next_action', 'record_reoffer')->assertJsonPath('reoffer_of_id', $dose->id);
        $this->assertNull($row->fresh()->completed_at);
        $this->assertNull(MedicationRefusalFollowup::query()->sole()->follow_up_completed_at);
    }

    public function test_handover_acknowledgement_transfers_carried_work_without_closing_it(): void
    {
        [, $row] = $this->effect();
        $this->shift->update(['ends_at' => now()->subMinute(), 'actual_ends_at' => now()->subMinute(), 'status' => 'completed']);
        $incoming = $this->staff();
        $this->client->supportWorkers()->syncWithoutDetaching([$incoming->id]);
        $shift = $this->onShift($incoming);
        $shift->update(['starts_at' => now()->subMinute()]);
        $row->update(['created_at' => now()->subHour()]);
        $handover = ShiftHandover::query()->create([
            'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'outgoing_shift_id' => $this->shift->id, 'incoming_shift_id' => $shift->id,
            'outgoing_staff_id' => $this->worker->id, 'incoming_staff_id' => $incoming->id,
            'handover_notes' => 'Synthetic handover for carry-over regression only.',
            'status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => $incoming->id,
        ]);
        DB::transaction(fn () => $this->work()->acknowledged($handover, $shift, $incoming));
        DB::transaction(fn () => $this->work()->acknowledged($handover, $shift, $incoming));
        $this->assertSame($incoming->id, $row->fresh()->owner_id);
        $this->assertSame($this->worker->id, $row->fresh()->original_owner_id);
        $this->assertNull($row->fresh()->completed_at);
        $this->assertSame(1, $row->events()->where('action', 'carried')->count());
    }

    public function test_unacknowledged_handover_creates_one_lead_headsup_after_one_hour(): void
    {
        $incoming = $this->onShift($this->staff());
        $handover = ShiftHandover::query()->create([
            'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'outgoing_shift_id' => $this->shift->id, 'incoming_shift_id' => $incoming->id,
            'outgoing_staff_id' => $this->worker->id, 'incoming_staff_id' => $incoming->user_id,
            'handover_notes' => 'Synthetic handover for the missed acknowledgement regression only.',
            'status' => 'submitted',
        ]);
        $this->work()->headsUp($handover);
        $this->work()->headsUp($handover);
        $this->assertSame(1, MedicationFollowup::query()->where('type', 'handover')->count());
        $this->assertNull($handover->fresh()->acknowledged_at);
    }

    public function test_failure_of_the_final_audit_rolls_back_every_source_and_workflow_write(): void
    {
        [, $row] = $this->effect();
        $mock = \Mockery::mock();
        $mock->shouldReceive('append')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        $this->app->instance(MedicationEventRecorder::class, $mock);
        try {
            $this->work()->transition($this->worker, $row->id, ['action' => 'effect', 'outcome' => 'effective', 'revision' => 1, 'request_uuid' => (string) Str::uuid()]);
            $this->fail('The audit failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        }
        $this->assertNull($row->fresh()->completed_at);
        $this->assertSame(1, $row->fresh()->revision);
        $this->assertDatabaseCount('medication_prn_effectiveness', 0);
        $this->assertSame(1, $row->events()->count());
    }

    public function test_current_emergency_grant_can_complete_effect_work_and_replay_records_one_use(): void
    {
        [, $row] = $this->effect();
        $actor = $this->staff();
        $access = $this->emergencyGrant($actor);
        $this->actingAs($actor)->getJson('/medication-followups/'.$row->id)->assertOk()->assertJsonPath('can_complete', true);
        $payload = ['action' => 'effect', 'outcome' => 'effective', 'revision' => 1, 'request_uuid' => (string) Str::uuid()];
        $this->actingAs($actor)->postJson('/medication-followups/'.$row->id.'/transition', $payload)->assertOk();
        $this->actingAs($actor)->postJson('/medication-followups/'.$row->id.'/transition', $payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertNotNull($row->fresh()->completed_at);
        $this->assertSame(1, $access->accessEvents()->where('action', 'followup.effect')->count());
        $this->assertSame(1, MedicationEvent::query()->where('subject_type', 'emergency_access')->where('subject_id', $access->id)->count());
        $this->assertDatabaseCount('medication_prn_effectiveness', 1);
    }

    public function test_grant_ended_after_opening_returns_typed_conflict_without_followup_writes(): void
    {
        [, $row] = $this->effect();
        $actor = $this->staff();
        $access = $this->emergencyGrant($actor);
        $this->actingAs($actor)->getJson('/medication-followups/'.$row->id)->assertOk();
        $access->forceFill(['ended_at' => now(), 'ended_how' => 'done'])->save();
        $access->delete();
        $this->actingAs($actor)->postJson('/medication-followups/'.$row->id.'/transition', [
            'action' => 'effect', 'outcome' => 'effective', 'revision' => 1, 'request_uuid' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'emergency_access_ended');
        $this->assertNull($row->fresh()->completed_at);
        $this->assertSame(1, $row->fresh()->revision);
        $this->assertDatabaseCount('medication_prn_effectiveness', 0);
        $this->assertSame(0, $access->accessEvents()->count());
    }

    public function test_expired_grant_cannot_accept_an_offline_followup_claim(): void
    {
        [, $row] = $this->effect();
        $actor = $this->staff();
        $access = $this->emergencyGrant($actor);
        $access->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->actingAs($actor)->postJson('/medication-followups/'.$row->id.'/transition', [
            'action' => 'effect', 'outcome' => 'effective', 'revision' => 1, 'request_uuid' => (string) Str::uuid(),
            'queued_offline' => true, 'captured_offline_at' => '2026-10-03T08:59:00+13:00',
        ])->assertConflict()->assertJsonPath('code', 'emergency_access_ended');
        $this->assertNull($row->fresh()->completed_at);
        $this->assertDatabaseCount('medication_prn_effectiveness', 0);
    }

    public function test_followup_grant_uses_its_frozen_policy_and_not_a_later_policy_edit(): void
    {
        [, $row] = $this->effect();
        $actor = $this->staff();
        $this->emergencyGrant($actor);
        BreakGlassPolicy::updateApplicationPolicy(['max_minutes' => 5, 'second_person' => 'required']);
        $this->actingAs($actor)->postJson('/medication-followups/'.$row->id.'/transition', [
            'action' => 'effect', 'outcome' => 'effective', 'revision' => 1, 'request_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertNotNull($row->fresh()->completed_at);
    }

    public function test_invalid_emergency_acknowledgements_cannot_authorize_followup_work(): void
    {
        [, $row] = $this->effect();
        $actor = $this->staff();
        $this->emergencyGrant($actor, ['acknowledged_incident_report' => false]);
        $this->actingAs($actor)->postJson('/medication-followups/'.$row->id.'/transition', [
            'action' => 'effect', 'outcome' => 'effective', 'revision' => 1, 'request_uuid' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->assertNull($row->fresh()->completed_at);
        $this->assertDatabaseCount('medication_prn_effectiveness', 0);
    }

    public function test_effect_transition_rechecks_current_medication_activity(): void
    {
        [$dose, $row] = $this->effect();
        $this->actingAs($this->worker)->getJson('/medication-followups/'.$row->id)->assertOk();
        $dose->medication->update(['active' => false]);
        $this->postFollowup($row, ['action' => 'effect', 'outcome' => 'effective'])->assertUnprocessable()->assertJsonValidationErrors('medication');
        $this->assertNull($row->fresh()->completed_at);
        $this->assertDatabaseCount('medication_prn_effectiveness', 0);
    }

    public function test_original_owner_cannot_use_the_loose_read_grant_fallback_for_a_clinical_write(): void
    {
        [, $row] = $this->effect();
        $this->shift->update(['status' => 'completed', 'actual_ends_at' => now()->subMinute()]);
        $this->emergencyGrant($this->worker, ['acknowledged_min_necessary' => false]);
        $this->postFollowup($row, ['action' => 'effect', 'outcome' => 'effective'])->assertNotFound();
        $this->assertNull($row->fresh()->completed_at);
        $this->assertDatabaseCount('medication_prn_effectiveness', 0);
    }

    public function test_confirmation_affordance_requires_named_witness_authority_instead_of_recording_permission(): void
    {
        [$dose] = $this->effect();
        $nominee = $this->staff();
        $this->onShift($nominee);
        $this->deny($nominee, ['medications.administer.record']);
        $this->grant($nominee, ['medications.controlled.witness']);
        $row = DB::transaction(fn () => $this->work()->ensureForSource('confirm', 123, $this->client,
            $dose->medication, $dose, $nominee->id, now()->addMinutes(30), ['nomination_id' => 123]));
        $this->actingAs($nominee)->getJson('/medication-followups/'.$row->id)->assertOk()->assertJsonPath('can_complete', true);
        $this->actingAs($this->worker)->getJson('/medication-followups/'.$row->id)->assertOk()->assertJsonPath('can_complete', false);
        $this->deny($nominee, ['medications.controlled.witness']);
        $this->actingAs($nominee)->getJson('/medication-followups/'.$row->id)->assertOk()->assertJsonPath('can_complete', false);
    }

    public function test_emergency_use_evidence_rolls_back_when_final_audit_fails(): void
    {
        [, $row] = $this->effect();
        $actor = $this->staff();
        $access = $this->emergencyGrant($actor);
        $mock = \Mockery::mock();
        $mock->shouldReceive('appendMany')->once()->andThrow(new \RuntimeException('Synthetic emergency audit failure'));
        $this->app->instance(MedicationEventRecorder::class, $mock);
        try {
            $this->work()->transition($actor, $row->id, ['action' => 'effect', 'outcome' => 'effective', 'revision' => 1, 'request_uuid' => (string) Str::uuid()]);
            $this->fail('The emergency audit failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic emergency audit failure', $e->getMessage());
        }
        $this->assertNull($row->fresh()->completed_at);
        $this->assertSame(1, $row->fresh()->revision);
        $this->assertSame(0, $access->accessEvents()->count());
        $this->assertDatabaseCount('medication_prn_effectiveness', 0);
        $this->assertSame(1, $row->events()->count());
    }

    public function test_history_cannot_be_edited_or_deleted(): void
    {
        [, $row] = $this->effect();
        $event = $row->events()->sole();
        try {
            $event->update(['action' => 'changed']);
            $this->fail('History must be immutable.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        try {
            $event->delete();
            $this->fail('History must be immutable.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    private function work(): MedicationFollowupService
    {
        return app(MedicationFollowupService::class);
    }

    private function effect(array $doseOverrides = [], array $orderOverrides = []): array
    {
        $order = ClientMedication::query()->create(['client_id' => $this->client->id, 'name' => 'Synthetic PRN', 'dosage' => '1 tablet', 'frequency' => 'As needed', 'is_prn' => true, 'state' => 'active', 'active' => true, ...$orderOverrides]);
        $dose = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id, 'client_medication_id' => $order->id, 'shift_id' => $this->shift->id,
            'administered_by' => $this->worker->id, 'status' => 'given', 'administered_at' => now()->subMinutes(30),
            'effect_check_due_at' => now()->addMinutes(30), 'is_correction' => false, ...$doseOverrides,
        ]);
        DB::transaction(fn () => $this->work()->syncAdministration($dose));

        return [$dose, MedicationFollowup::query()->where('source_key', 'effect:'.$dose->id)->sole()];
    }

    private function refusal(): array
    {
        $order = ClientMedication::query()->create(['client_id' => $this->client->id, 'name' => 'Synthetic scheduled medicine', 'dosage' => '1 tablet', 'frequency' => 'Daily', 'is_prn' => false, 'state' => 'active', 'active' => true]);
        $dose = ClientMedicationAdministration::query()->create(['client_id' => $this->client->id, 'client_medication_id' => $order->id, 'administered_by' => $this->worker->id, 'status' => 'refused', 'administered_at' => now()->subMinutes(30), 'is_correction' => false]);
        MedicationRefusalFollowup::query()->create(['client_id' => $this->client->id, 'client_medication_administration_id' => $dose->id, 'created_by' => $this->worker->id, 'owner_id' => $this->worker->id, 'reason_category' => 'personal_choice', 'follow_up_due_at' => now()->addHour()]);
        DB::transaction(fn () => $this->work()->syncAdministration($dose));

        return [$dose, MedicationFollowup::query()->where('source_key', 'refusal:'.$dose->id)->sole()];
    }

    private function staff(string $roleName = 'support_worker'): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->value('id')]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subMonth(), 'end_date' => null]);
        $this->grant($user, ['medications.view', 'medications.administer.record']);

        return $user;
    }

    private function onShift(User $user): Shift
    {
        return Shift::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'user_id' => $user->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(6), 'actual_starts_at' => now()->subHour(), 'status' => 'in_progress']);
    }

    private function grant(User $user, array $keys): void
    {
        $user->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', $keys)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        $user->unsetRelation('roles')->unsetRelation('permissionOverrides');
    }

    private function emergencyGrant(User $actor, array $overrides = []): ClientBreakGlassAccess
    {
        $this->grant($actor, ['medications.breakglass']);

        $access = new ClientBreakGlassAccess;
        $access->forceFill([
            'client_id' => $this->client->id, 'user_id' => $actor->id,
            'reason' => 'Synthetic follow-up emergency regression only.', 'authorization_mode' => 'self',
            'acknowledged_min_necessary' => true, 'acknowledged_incident_report' => true,
            'created_at' => now()->subMinutes(10), 'expires_at' => now()->addMinutes(50),
            'policy_snapshot' => BreakGlassPolicy::defaults(), ...$overrides,
        ])->save();

        return $access;
    }

    private function deny(User $user, array $keys): void
    {
        $user->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', $keys)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
        $user->unsetRelation('roles')->unsetRelation('permissionOverrides');
    }

    private function postFollowup(MedicationFollowup $row, array $data)
    {
        return $this->actingAs($this->worker)->postJson('/medication-followups/'.$row->id.'/transition', ['revision' => $row->revision, 'request_uuid' => (string) Str::uuid(), ...$data]);
    }
}
