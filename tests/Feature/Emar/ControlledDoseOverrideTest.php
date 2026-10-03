<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ControlledWitnessOverride;
use App\Models\ControlledWorkflowEvent;
use App\Models\MedicationAdminRule;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationEvent;
use App\Models\MedicationFollowup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\EnhancedMarService;
use App\Services\Medication\Controlled\ControlledDoseOverrideService;
use App\Services\Medication\MedicationSafetyPolicySettings;
use App\Services\Medication\Recording\RecordingContract;
use App\Services\Medication\Recording\RecordingContractEnforcer;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** P07a: an approved, prospective dose waiver is scoped and never becomes witness evidence. */
class ControlledDoseOverrideTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $recorder;

    private User $witness;

    private User $manager;

    private ClientMedication $medication;

    private ClientMedicationStock $stock;

    private ControlledWitnessOverride $override;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $this->recorder = $this->staff('Recorder');
        $this->witness = $this->staff('Witness');
        $this->client->supportWorkers()->attach($this->recorder->id);
        $this->manager = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
        $this->medication = $this->approvedOrder();
        $this->stock = ClientMedicationStock::query()->create(['client_medication_id' => $this->medication->id, 'on_hand' => 5, 'unit' => 'tablet']);
        $this->override = ControlledWitnessOverride::query()->create([
            'site_id' => $this->site->id, 'client_medication_id' => $this->medication->id, 'medicine_ids' => [$this->medication->id],
            'requested_by' => $this->recorder->id, 'reason' => 'Synthetic staffing request', 'status' => 'approved',
            'decided_by' => $this->manager->id, 'decision_reason' => 'Synthetic approval', 'decided_at' => now()->subMinutes(15),
            'starts_at' => now()->subMinutes(10), 'expires_at' => now()->addHour(), 'followup_due_at' => now()->addHours(7),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_requirements_show_only_active_override_facts_and_keep_default_policy(): void
    {
        Shift::query()->where('user_id', $this->witness->id)->update(['status' => 'cancelled']);
        $response = $this->requirements()->assertOk();
        $response->assertJsonPath('order.witness_required', true)->assertJsonPath('second_person.kind', null)
            ->assertJsonPath('block_given', null)->assertJsonPath('witness_override.id', $this->override->id)
            ->assertJsonPath('second_person.may_go_unconfirmed', false);
        $this->assertSame(['id', 'expires_at', 'followup_due_at'], array_keys($response->json('witness_override')));
    }

    public function test_online_given_dose_records_truthful_pending_provenance_and_one_source_followup(): void
    {
        $this->record()->assertOk();
        $dose = ClientMedicationAdministration::query()->sole();
        $this->assertSame($this->override->id, $dose->witness_override_id);
        $this->assertSame(RecordingContract::SECOND_WITNESS, $dose->second_person_kind);
        $this->assertSame(RecordingContract::SECOND_OVERRIDE_PENDING, $dose->second_person_status);
        $this->assertNull($dose->witnessed_by);
        $this->assertNull($dose->witnessed_at);
        $this->assertNull($dose->witness_method);
        $this->assertSame('4.00', $this->stock->fresh()->on_hand);
        $this->assertNull(ClientControlledDrugEntry::query()->sole()->witnessed_by);
        $event = ControlledWorkflowEvent::query()->where('subject_type', 'override_dose')->sole();
        $this->assertSame('recorded_under_override', $event->action);
        $this->assertSame($this->override->id, $event->payload['override_id']);
        $work = MedicationFollowup::query()->where('source_key', 'witness-override:dose-'.$dose->id)->sole();
        $this->assertSame('override', $work->type);
        $this->assertSame($dose->id, (int) $work->administration_id);
        $this->assertNull($work->owner_id);
        $this->assertTrue($work->due_at->equalTo($this->override->followup_due_at));
        $this->assertSame('/emar/safety/witness-overrides', $work->context['source_url']);
    }

    public function test_replay_and_source_adapter_retry_never_duplicate_dose_stock_or_work(): void
    {
        $uuid = (string) Str::uuid();
        $this->record(['client_request_uuid' => $uuid, 'witness_override_id' => $this->override->id])->assertOk();
        $this->record(['client_request_uuid' => $uuid, 'witness_override_id' => $this->override->id])->assertOk();
        $dose = ClientMedicationAdministration::query()->sole();
        DB::transaction(function () use ($dose): void {
            $client = Client::query()->lockForUpdate()->findOrFail($this->client->id);
            $medicine = ClientMedication::query()->lockForUpdate()->findOrFail($this->medication->id);
            $medicine->setRelation('client', $client);
            $override = ControlledWitnessOverride::query()->lockForUpdate()->findOrFail($this->override->id);
            app(ControlledDoseOverrideService::class)->recordApplied($override, $dose, $this->recorder, $medicine);
        });
        $this->assertSame('4.00', $this->stock->fresh()->on_hand);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame(1, ControlledWorkflowEvent::query()->where('action', 'recorded_under_override')->count());
        $this->assertSame(1, MedicationFollowup::query()->where('type', 'override')->count());
    }

    public function test_waiting_declined_and_expired_grants_cannot_record_a_dose(): void
    {
        foreach (['waiting', 'declined', 'expired'] as $state) {
            $this->override->update(['status' => $state === 'expired' ? 'approved' : $state,
                'expires_at' => $state === 'expired' ? now() : now()->addHour()]);
            $this->requirements()->assertOk()->assertJsonPath('witness_override', null);
            $this->record(['witness_override_id' => $this->override->id])->assertStatus(422)
                ->assertJsonValidationErrors('witness_override_id');
        }
        $this->assertNoDose();
    }

    public function test_future_window_or_action_time_outside_the_window_is_rejected(): void
    {
        $this->override->update(['starts_at' => now()->addMinute()]);
        $this->record(['witness_override_id' => $this->override->id])->assertStatus(422);
        $this->override->update(['starts_at' => now()->subMinutes(10)]);
        $this->record(['witness_override_id' => $this->override->id, 'administered_at' => now()->subMinutes(11)->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('witness_override_id');
        $expiresAt = now()->addMinute();
        $this->override->update(['expires_at' => $expiresAt]);
        Carbon::setTestNow($expiresAt->copy()->addSecond());
        $this->record(['witness_override_id' => $this->override->id, 'administered_at' => now()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('witness_override_id');
        $this->assertNoDose();
    }

    public function test_dose_time_before_independent_approval_is_not_authorised_retroactively(): void
    {
        $this->override->update(['starts_at' => now()->subMinutes(45)]);
        $this->record(['witness_override_id' => $this->override->id, 'administered_at' => now()->subMinutes(20)->toIso8601String()])->assertStatus(422);
        $this->assertNoDose();
    }

    public function test_explicit_order_witness_requirement_wins_even_over_an_approved_grant(): void
    {
        // This order was approved with its witness requirement, not edited after approval.
        $this->medication = $this->approvedOrder(witnessRequired: true);
        $this->stock->update(['client_medication_id' => $this->medication->id]);
        $this->override->update(['client_medication_id' => $this->medication->id, 'medicine_ids' => [$this->medication->id]]);
        $this->assertSame(RecordingContract::SECOND_WITNESS, app(RecordingContractEnforcer::class)
            ->secondPersonKind(['status' => 'given'], $this->medication, [], false, true));
        $this->requirements()->assertOk()->assertJsonPath('witness_override', null)->assertJsonPath('second_person.kind', 'witness');
        $this->record(['witness_override_id' => $this->override->id])->assertStatus(422);
        $this->record()->assertStatus(422)->assertJsonPath('error_field', 'witnessed_by');
        $this->assertNoDose();
        $this->record($this->confirmation())->assertOk();
        $dose = ClientMedicationAdministration::query()->sole();
        $this->assertNull($dose->witness_override_id);
        $this->assertSame(RecordingContract::SECOND_VERIFIED, $dose->second_person_status);
    }

    public function test_another_requester_foreign_house_or_uncovered_medicine_cannot_use_a_grant(): void
    {
        $cases = [
            ['requested_by' => $this->witness->id],
            ['site_id' => Site::factory()->create()->id],
            ['medicine_ids' => [$this->medication->id + 10000]],
            ['decided_by' => $this->recorder->id],
        ];
        foreach ($cases as $fields) {
            $this->override->update(['requested_by' => $this->recorder->id, 'site_id' => $this->site->id,
                'medicine_ids' => [$this->medication->id], 'decided_by' => $this->manager->id, ...$fields]);
            $this->record(['witness_override_id' => $this->override->id])->assertStatus(422)->assertJsonValidationErrors('witness_override_id');
        }
        $this->record(['witness_override_id' => $this->override->id + 10000])->assertStatus(422);
        $this->assertNoDose();
    }

    public function test_offline_capture_is_denied_by_the_domain_guard_even_without_transport_validation(): void
    {
        $caught = false;
        try {
            app(EnhancedMarService::class)->recordAdministration($this->client, $this->medication,
                ['status' => 'given', 'scheduled_for' => '2026-04-30T09:30:00+12:00', 'scope_authorized' => true,
                    'amount_mode' => 'as_ordered', 'quantity_administered' => 1, 'cd_balance' => 4,
                    'witness_override_id' => $this->override->id, 'queued_offline' => true,
                    'captured_offline_at' => now()->toIso8601String()], $this->recorder->id);
        } catch (ValidationException $exception) {
            $caught = true;
            $this->assertArrayHasKey('witness_override_id', $exception->errors());
        }
        $this->assertTrue($caught);
        $this->assertNoDose();
    }

    public function test_restricted_recorder_rule_and_smaller_amount_still_require_confirmation(): void
    {
        app(MedicationSafetyPolicySettings::class)->save([MedicationSafetyPolicySettings::RESTRICTED_COMPETENCY => 'cosigner']);
        MedicationCompetencyAssessment::query()->where('user_id', $this->recorder->id)->update(['restricted' => true]);
        $this->requirements()->assertOk()->assertJsonPath('second_person.kind', 'cosigner');
        $this->record()->assertStatus(422)->assertJsonPath('error_field', 'witnessed_by');
        MedicationCompetencyAssessment::query()->where('user_id', $this->recorder->id)->update(['restricted' => false]);
        $rule = MedicationAdminRule::query()->create(['site_id' => null, 'match_type' => 'medicine_name',
            'match_value' => $this->medication->name, 'requires_countersign' => true, 'required_observations' => [], 'active' => true]);
        $this->record()->assertStatus(422)->assertJsonPath('error_field', 'witnessed_by');
        Shift::query()->where('user_id', $this->witness->id)->update(['status' => 'cancelled']);
        $this->record(['second_person_unavailable' => true])->assertStatus(422)->assertJsonPath('error_field', 'witnessed_by');
        $rule->update(['active' => false]);
        $this->record(['amount_mode' => 'less', 'quantity_given' => 0.5, 'amount_reason' => 'part_taken'])
            ->assertStatus(422)->assertJsonPath('error_field', 'witnessed_by');
        $this->assertNoDose();
    }

    public function test_expired_competency_or_inactive_employment_cannot_be_replaced_by_an_override(): void
    {
        MedicationCompetencyAssessment::query()->where('user_id', $this->recorder->id)->update(['expiry_date' => now()->subDay()->toDateString()]);
        $this->record(['witness_override_id' => $this->override->id])->assertStatus(422);
        MedicationCompetencyAssessment::query()->where('user_id', $this->recorder->id)->update(['expiry_date' => now()->addYear()->toDateString()]);
        HrEmployeeProfile::query()->where('user_id', $this->recorder->id)->update(['is_active' => false]);
        $response = $this->record(['witness_override_id' => $this->override->id]);
        $this->assertContains($response->status(), [403, 404, 422]);
        $this->assertNoDose();
    }

    public function test_waste_cannot_be_recorded_without_a_real_witness_under_an_override(): void
    {
        $this->record(['quantity_administered' => 2, 'cd_balance' => 3])->assertStatus(422);
        $this->assertNoDose();
        $this->record([...$this->confirmation(), 'quantity_administered' => 2, 'cd_balance' => 3])->assertOk();
        $this->assertSame('3.00', $this->stock->fresh()->on_hand);
        $this->assertSame(2, ClientControlledDrugEntry::query()->count());
        $this->assertSame($this->witness->id, (int) ClientControlledDrugEntry::query()->where('entry_type', 'disposal')->sole()->witnessed_by);
        $this->assertNull(ClientMedicationAdministration::query()->sole()->witness_override_id);
    }

    public function test_active_dose_override_never_bypasses_witnessed_register_counts(): void
    {
        $this->actingAs($this->recorder->fresh())->postJson('/emar/controlled/product/actions/count', [
            'client_medication_id' => $this->medication->id, 'client_request_uuid' => (string) Str::uuid(),
            'expected_balance' => 5, 'expected_entry_id' => null, 'actual_balance' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('witnessed_by');
        $this->assertNoDose();
    }

    public function test_a_real_witness_does_not_create_override_provenance_or_pending_work(): void
    {
        $this->record($this->confirmation())->assertOk();
        $dose = ClientMedicationAdministration::query()->sole();
        $this->assertSame($this->witness->id, (int) $dose->witnessed_by);
        $this->assertSame(RecordingContract::SECOND_VERIFIED, $dose->second_person_status);
        $this->assertNull($dose->witness_override_id);
        $this->assertSame(0, MedicationFollowup::query()->where('type', 'override')->count());
        $this->assertSame(0, ControlledWorkflowEvent::query()->where('action', 'recorded_under_override')->count());
    }

    public function test_override_dose_signoff_requires_a_post_dose_participant_count_and_closes_source_once(): void
    {
        $lead = $this->staff('Participating house lead');
        $lead->update(['role' => 'team_lead']);
        $lead->roles()->syncWithoutDetaching([Role::query()->where('name', 'team_lead')->sole()->id]);
        $manage = Permission::query()->where('key', 'medications.controlled.manage')->sole();
        $lead->permissionOverrides()->syncWithoutDetaching([$manage->id => ['allowed' => true]]);
        Cache::flush();
        $count = function (User $actor): int {
            $this->actingAs($actor->fresh())->postJson('/emar/controlled/product/actions/count', [
                'client_medication_id' => $this->medication->id, 'client_request_uuid' => (string) Str::uuid(),
                'expected_entry_id' => ClientControlledDrugEntry::query()->where('client_medication_id', $this->medication->id)->max('id'),
                'expected_balance' => $this->stock->fresh()->on_hand, 'actual_balance' => $this->stock->fresh()->on_hand,
                ...$this->confirmation(),
            ])->assertOk();

            return (int) ClientControlledDrugEntry::query()->where('client_medication_id', $this->medication->id)->max('id');
        };
        // The clock stays frozen: entry identity must reject a count earlier in the same second.
        $beforeDoseCount = $count($lead);
        $this->record()->assertOk();
        $dose = ClientMedicationAdministration::query()->sole();
        $doseEntry = ClientControlledDrugEntry::query()->where('entry_type', 'administered')->sole();
        $this->assertLessThan((int) $doseEntry->id, $beforeDoseCount);
        $this->assertTrue(ClientControlledDrugEntry::query()->findOrFail($beforeDoseCount)->recorded_at->equalTo($dose->administered_at));
        $work = MedicationFollowup::query()->where('source_key', 'witness-override:dose-'.$dose->id)->sole();
        $initialRevision = $work->revision;
        $signoffInput = ['client_medication_id' => $this->medication->id, 'expected_entry_id' => null,
            'target_id' => $this->override->id, 'administration_id' => $dose->id, 'notes' => 'Checked the witnessed cupboard count.'];
        $this->actingAs($lead->fresh())->postJson('/emar/controlled/product/actions/override_signoff', [
            ...$signoffInput, 'client_request_uuid' => (string) Str::uuid(), 'counted_entry_id' => $beforeDoseCount,
        ])->assertStatus(422)->assertJsonValidationErrors('counted_entry_id');
        $nonparticipantCount = $count($this->recorder);
        $this->actingAs($lead->fresh())->postJson('/emar/controlled/product/actions/override_signoff', [
            ...$signoffInput, 'client_request_uuid' => (string) Str::uuid(), 'counted_entry_id' => $nonparticipantCount,
        ])->assertStatus(422)->assertJsonValidationErrors('counted_entry_id');
        $this->assertNull($work->fresh()->completed_at);
        $this->assertSame($initialRevision, $work->fresh()->revision);
        $matchingCount = $count($lead);
        $uuid = (string) Str::uuid();
        $input = [...$signoffInput, 'client_request_uuid' => $uuid, 'counted_entry_id' => $matchingCount];
        $this->actingAs($lead->fresh())->postJson('/emar/controlled/product/actions/override_signoff', $input)->assertOk();
        $work->refresh();
        $this->assertNotNull($work->completed_at);
        $this->assertSame('done', $work->state);
        $this->assertSame($lead->id, (int) $work->completed_by);
        $this->assertSame($initialRevision + 1, $work->revision);
        $completion = $work->events()->where('action', 'source_completed')->sole();
        $this->assertSame('witnessed_count_signed_off', $completion->data['outcome']);
        $this->assertSame($matchingCount, $completion->data['counted_entry_id']);
        $sourceAudit = MedicationEvent::query()->where('kind', 'followup.source_completed')
            ->where('subject_type', 'medication_followup')->where('subject_id', (string) $work->id)->sole();
        $signoffAudit = MedicationEvent::query()->where('kind', 'controlled.override_signoff')
            ->where('subject_id', (string) $this->medication->id)->sole();
        $this->assertSame($sourceAudit->sequence + 1, $signoffAudit->sequence);
        $this->assertSame($sourceAudit->hash, $signoffAudit->previous_hash);
        $eventCount = MedicationEvent::query()->count();
        $this->actingAs($lead->fresh())->postJson('/emar/controlled/product/actions/override_signoff', $input)->assertOk();
        $this->assertSame($initialRevision + 1, $work->fresh()->revision);
        $this->assertSame(1, $work->events()->where('action', 'source_completed')->count());
        $this->assertSame(1, ControlledWorkflowEvent::query()->where('subject_type', 'override_dose')
            ->where('subject_id', $dose->id)->where('action', 'signed_off')->count());
        $this->assertSame($eventCount, MedicationEvent::query()->count());
        $this->assertSame('4.00', $this->stock->fresh()->on_hand);
    }

    private function approvedOrder(bool $witnessRequired = false): ClientMedication
    {
        $clock = Carbon::getTestNow();
        Carbon::setTestNow(now('Pacific/Auckland')->startOfDay()->utc());
        try {
            return ClientMedication::query()->create([
                'client_id' => $this->client->id, 'name' => 'Override test medicine', 'dosage' => '1 tablet',
                'frequency' => 'Daily', 'dose_times' => ['09:30'], 'dose_amount' => 1, 'dose_unit' => 'tablet',
                'is_prn' => false, 'controlled_drug' => true, 'high_risk' => false, 'witness_required' => $witnessRequired,
                'approval_status' => 'verified', 'active' => true, 'state' => 'active',
            ]);
        } finally {
            Carbon::setTestNow($clock);
        }
    }

    private function staff(string $name): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'name' => $name, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', 'support_worker')->sole()->id]);
        $permissions = ['medications.view', 'medications.administer.record', 'medications.controlled.view',
            'medications.controlled.record', 'medications.controlled.witness'];
        $user->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', $permissions)->get()
            ->mapWithKeys(fn (Permission $permission): array => [$permission->id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'start_date' => now()->subMonth(), 'end_date' => null, 'is_active' => true]);
        MedicationCompetencyAssessment::query()->create(['user_id' => $user->id, 'assessor_id' => User::factory()->create()->id,
            'assessment_type' => 'annual', 'status' => 'passed', 'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(), 'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(), 'can_administer_unsupervised' => true,
            'can_witness_controlled' => true, 'controlled_drugs' => true, 'restricted' => false, 'not_seen_areas' => []]);
        Shift::factory()->create(['user_id' => $user->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30), 'actual_ends_at' => null, 'status' => 'in_progress']);
        Cache::flush();

        return $user;
    }

    private function confirmation(): array
    {
        return ['witnessed_by' => $this->witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN];
    }

    private function record(array $attributes = [])
    {
        return $this->actingAs($this->recorder->fresh())->postJson('/meds/today/record', [
            'client_medication_id' => $this->medication->id, 'scheduled_for' => '2026-04-30T09:30:00+12:00',
            'status' => 'given', 'amount_mode' => 'as_ordered', 'quantity_administered' => 1, 'cd_balance' => 4, ...$attributes,
        ]);
    }

    private function requirements()
    {
        return $this->actingAs($this->recorder->fresh())->getJson('/meds/today/doses/requirements?'.http_build_query([
            'client_medication_id' => $this->medication->id, 'scheduled_for' => '2026-04-30T09:30:00+12:00',
        ]));
    }

    private function assertNoDose(): void
    {
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame('5.00', $this->stock->fresh()->on_hand);
        $this->assertSame(0, MedicationFollowup::query()->where('type', 'override')->count());
    }
}
