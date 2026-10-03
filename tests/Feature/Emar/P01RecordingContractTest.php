<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationAdminRule;
use App\Models\MedicationAllergy;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDoseSlot;
use App\Models\MedicationError;
use App\Models\MedicationOnCallRule;
use App\Models\MedicationRefusalFollowup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\ClientControlledDrugEntry;
use App\Services\Medication\MedicationSafetyPolicySettings;
use App\Services\Medication\Recording\DoseRecordingRequirements;
use App\Services\Medication\Recording\RecordingContract;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * eMAR P01 C1 — the one recording contract on the server: what was given
 * (as ordered / less / more), the second person and "Not confirmed by a
 * second person" (Q2), re-offer after a refusal (NF-11, Q8), the refusal
 * follow-up, NF-06 (a block never stops a refusal), NF-18 (controlled stock
 * is never assumed), the dialog's JSON answers, and the requirements the
 * dialog reads before anything is saved.
 */
class P01RecordingContractTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Client $client;

    private ServiceContext $serviceContext;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->site = Site::factory()->create(['is_active' => true, 'name' => 'Kōwhai House']);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'P01 contract',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
            'preferred_name' => 'Aroha',
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);

        $this->worker = $this->staffAt($this->site, 'Priya Shah');
        $this->onShift($this->worker, $this->client);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_refusal_saves_while_a_safety_block_stops_given(): void
    {
        $order = $this->order(['09:30'], ['name' => 'Amoxicillin 500mg']);
        MedicationAllergy::query()->create([
            'client_id' => $this->client->id,
            'allergen' => 'Amoxicillin',
            'recorded_by' => $this->worker->id,
            'severity' => 'severe',
        ]);

        $this->record($order, '09:30', ['status' => 'given'])
            ->assertSessionHasErrors('client_medication_id');
        $this->assertDatabaseCount('client_medication_administrations', 0);

        $this->record($order, '09:30', ['status' => 'refused', 'reason_code' => 'refused'])
            ->assertSessionHas('success');
        $this->assertSame('refused', ClientMedicationAdministration::query()->sole()->status);
    }

    public function test_outside_the_window_given_takes_the_approved_late_reason_and_a_refusal_needs_none(): void
    {
        $given = $this->order(['08:00'], ['name' => 'Losartan 50mg']);
        $refused = $this->order(['08:00'], ['name' => 'Vitamin D 1000iu']);

        $this->record($given, '08:00', ['status' => 'given', 'late_reason' => 'not_a_reason'])
            ->assertSessionHasErrors('late_reason');
        $this->record($given, '08:00', ['status' => 'given', 'late_reason' => 'out_or_asleep'])
            ->assertSessionHas('success');
        $this->assertSame(
            'out_or_asleep',
            ClientMedicationAdministration::query()->where('client_medication_id', $given->id)->sole()->late_reason,
        );

        $this->record($refused, '08:00', ['status' => 'refused', 'reason_code' => 'refused'])
            ->assertSessionHas('success');
        $this->assertNull(ClientMedicationAdministration::query()->where('client_medication_id', $refused->id)->sole()->late_reason);
    }

    public function test_controlled_stock_is_never_assumed_to_be_one_unit(): void
    {
        $this->grant($this->worker, ['medications.controlled.record', 'medications.controlled.view']);
        $witness = $this->witnessOnShift();

        $unknown = $this->order(['09:30'], ['name' => 'Oxycodone 5mg', 'controlled_drug' => true]);
        ClientMedicationStock::query()->create(['client_medication_id' => $unknown->id, 'on_hand' => 10, 'unit' => 'tablets']);

        $this->record($unknown, '09:30', [
            'status' => 'given',
            'witnessed_by' => $witness->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
        ])->assertSessionHasErrors('quantity_administered');
        $this->assertDatabaseCount('client_medication_administrations', 0);

        $ordered = $this->order(['09:30'], [
            'name' => 'Clonazepam 0.5mg',
            'controlled_drug' => true,
            'dose_amount' => 2,
            'dose_unit' => 'tablet',
        ]);
        $stock = ClientMedicationStock::query()->create(['client_medication_id' => $ordered->id, 'on_hand' => 10, 'unit' => 'tablets']);

        $this->record($ordered, '09:30', [
            'status' => 'given',
            'amount_mode' => 'as_ordered',
            'witnessed_by' => $witness->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
        ])->assertSessionHas('success');

        $this->assertSame(8.0, (float) $stock->refresh()->on_hand);
        $administration = ClientMedicationAdministration::query()->where('client_medication_id', $ordered->id)->sole();
        $this->assertSame('2.00', (string) $administration->quantity_given);
        $this->assertSame(RecordingContract::SECOND_WITNESS, $administration->second_person_kind);
        $this->assertSame(RecordingContract::SECOND_VERIFIED, $administration->second_person_status);
        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_medication_id' => $ordered->id,
            'quantity' => '2.00',
        ]);
    }

    public function test_a_refusal_opens_a_follow_up_and_a_reoffer_closes_it(): void
    {
        $order = $this->order(['09:30'], ['name' => 'Sertraline 50mg']);

        $this->record($order, '09:30', [
            'status' => 'refused',
            'reason_code' => 'refused',
            'notes' => 'Said she felt fine today.',
            'follow_up_due_at' => '2026-04-30T11:00',
        ], json: true)->assertOk()->assertJsonPath('sync.status', 'processed');

        $refusal = ClientMedicationAdministration::query()->sole();
        $followUp = MedicationRefusalFollowup::query()->sole();
        $this->assertSame($refusal->id, (int) $followUp->client_medication_administration_id);
        $this->assertSame($this->worker->id, (int) $followUp->owner_id);
        $this->assertSame('personal_choice', $followUp->reason_category);
        $this->assertSame(
            Carbon::parse('2026-04-30 11:00', 'Pacific/Auckland')->utc()->format('Y-m-d H:i'),
            Carbon::parse((string) $followUp->getRawOriginal('follow_up_due_at'), 'UTC')->format('Y-m-d H:i'),
        );

        Carbon::setTestNow(Carbon::parse('2026-04-30 10:15:00', 'Pacific/Auckland')->utc());
        $this->record($order, '09:30', [
            'status' => 'given',
            'reoffer_of_id' => $refusal->id,
            'administered_at' => now()->toIso8601String(),
        ], json: true)
            ->assertOk()
            ->assertJsonPath('sync.status', 'processed')
            ->assertJsonPath('administration.reoffer_of_id', $refusal->id);

        $reoffer = ClientMedicationAdministration::query()->latest('id')->first();
        $this->assertSame('given', $reoffer->status);
        $this->assertSame($refusal->id, (int) $reoffer->reoffer_of_id);
        $followUp->refresh();
        $this->assertNotNull($followUp->follow_up_completed_at);
        $this->assertSame('Given after re-offer at 10:15 am.', $followUp->follow_up_outcome);
        $this->assertSame('given', MedicationDoseSlot::query()
            ->where('client_medication_id', $order->id)
            ->where('due_at', Carbon::parse('2026-04-30 09:30', 'Pacific/Auckland')->utc()->format('Y-m-d H:i:s'))
            ->whereNull('superseded_at')
            ->sole()
            ->outcome);

        // The dose now has an outcome: another re-offer is a duplicate that
        // says who recorded it.
        $this->record($order, '09:30', [
            'status' => 'given',
            'reoffer_of_id' => $refusal->id,
        ], json: true)
            ->assertOk()
            ->assertJsonPath('sync.status', 'duplicate')
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('duplicate_of.by', 'Priya Shah')
            ->assertJsonPath('duplicate_of.status', 'given');
        $this->assertDatabaseCount('client_medication_administrations', 2);
    }

    public function test_a_reoffer_needs_an_open_follow_up_from_the_same_day(): void
    {
        $order = $this->order(['09:30'], ['name' => 'Metformin 500mg']);
        $this->record($order, '09:30', ['status' => 'refused', 'reason_code' => 'refused'])->assertSessionHas('success');
        $refusal = ClientMedicationAdministration::query()->sole();

        $this->record($order, '09:30', ['status' => 'given', 'reoffer_of_id' => $refusal->id])
            ->assertSessionHasErrors('reoffer_of_id');

        $fresh = $this->order(['09:30'], ['name' => 'Atorvastatin 20mg']);
        $this->record($fresh, '09:30', ['status' => 'given', 'reoffer_of_id' => $refusal->id])
            ->assertSessionHasErrors('reoffer_of_id');
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_less_than_ordered_needs_a_reason_and_a_second_person_unless_nobody_can_confirm(): void
    {
        $order = $this->order(['09:30'], ['name' => 'Paracetamol 500mg', 'dose_amount' => 2, 'dose_unit' => 'tablet']);
        $less = ['status' => 'given', 'amount_mode' => 'less', 'quantity_given' => 1, 'amount_reason' => 'part_taken'];

        $this->record($order, '09:30', [...$less, 'amount_reason' => null])->assertSessionHasErrors('amount_reason');
        $this->record($order, '09:30', [...$less, 'quantity_given' => 2])->assertSessionHasErrors('quantity_given');
        $this->record($order, '09:30', $less)->assertSessionHasErrors('witnessed_by');
        $this->assertDatabaseCount('client_medication_administrations', 0);

        // Nobody else on shift can confirm: recorded "Not confirmed by a
        // second person", flagged for the house lead (Q2).
        $this->record($order, '09:30', [...$less, 'second_person_unavailable' => true])->assertSessionHas('success');

        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame('less', $administration->amount_mode);
        $this->assertSame('part_taken', $administration->amount_reason);
        $this->assertSame('1.00', (string) $administration->quantity_given);
        $this->assertSame(RecordingContract::SECOND_AMOUNT, $administration->second_person_kind);
        $this->assertSame(RecordingContract::SECOND_NOT_CONFIRMED, $administration->second_person_status);
        $this->assertTrue((bool) $administration->review_required);
        $this->assertSame(RecordingContract::REVIEW_PARTIAL_DOSE_NOT_CONFIRMED, $administration->review_reason_key);
        $this->assertNull($administration->witnessed_by);
    }

    public function test_not_confirmed_is_refused_when_a_colleague_on_shift_can_confirm(): void
    {
        $witness = $this->witnessOnShift();
        $order = $this->order(['09:30'], ['name' => 'Ibuprofen 200mg', 'dose_amount' => 2, 'dose_unit' => 'tablet']);
        $less = ['status' => 'given', 'amount_mode' => 'less', 'quantity_given' => 1, 'amount_reason' => 'dropped_or_spilled'];

        $this->record($order, '09:30', [...$less, 'second_person_unavailable' => true])
            ->assertSessionHasErrors('witnessed_by');
        $this->assertStringContainsString('A colleague on shift can confirm', session('errors')->first('witnessed_by'));

        $this->record($order, '09:30', [
            ...$less,
            'witnessed_by' => $witness->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
        ])->assertSessionHas('success');

        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame($witness->id, (int) $administration->witnessed_by);
        $this->assertSame(RecordingContract::SECOND_AMOUNT, $administration->second_person_kind);
        $this->assertSame(RecordingContract::SECOND_VERIFIED, $administration->second_person_status);
        $this->assertFalse((bool) $administration->review_required);
    }

    public function test_a_rule_second_person_may_go_unconfirmed_but_a_witness_never_can(): void
    {
        MedicationAdminRule::query()->create([
            'site_id' => null,
            'match_type' => 'medicine_name',
            'match_value' => 'Insulin',
            'requires_countersign' => true,
            'required_observations' => ['blood_glucose'],
            'active' => true,
        ]);
        $insulin = $this->order(['09:30'], ['name' => 'Insulin glargine']);

        $this->record($insulin, '09:30', [
            'status' => 'given',
            'blood_glucose_level' => 6.2,
            'second_person_unavailable' => true,
        ])->assertSessionHas('success');

        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame(RecordingContract::SECOND_RULE, $administration->second_person_kind);
        $this->assertSame(RecordingContract::SECOND_NOT_CONFIRMED, $administration->second_person_status);
        $this->assertSame(RecordingContract::REVIEW_SECOND_PERSON_NOT_CONFIRMED, $administration->review_reason_key);

        $this->grant($this->worker, ['medications.controlled.record', 'medications.controlled.view']);
        $controlled = $this->order(['09:30'], [
            'name' => 'Morphine 10mg',
            'controlled_drug' => true,
            'dose_amount' => 1,
            'dose_unit' => 'tablet',
        ]);
        ClientMedicationStock::query()->create(['client_medication_id' => $controlled->id, 'on_hand' => 5, 'unit' => 'tablet']);

        $this->record($controlled, '09:30', ['status' => 'given', 'second_person_unavailable' => true])
            ->assertSessionHasErrors('witnessed_by');
        $this->assertStringContainsString('always needs a witness', session('errors')->first('witnessed_by'));
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_more_than_ordered_raises_one_error_and_one_incident(): void
    {
        $order = $this->order(['09:30'], ['name' => 'Levetiracetam 500mg', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $more = [
            'status' => 'given',
            'amount_mode' => 'more',
            'quantity_given' => 2,
            'more_severity' => 'moderate',
            'more_immediate_action' => 'Rang the on-call contact at 9:35 am.',
            'notes' => 'Picked up the wrong pack.',
            'client_request_uuid' => 'f3b0e9a2-74c1-4c55-9f0e-6f1b4b2d9a11',
        ];

        $this->record($order, '09:30', [...$more, 'more_severity' => null], json: true)
            ->assertStatus(422)
            ->assertJsonPath('error_field', 'more_severity');

        $first = $this->record($order, '09:30', $more, json: true)
            ->assertOk()
            ->assertJsonPath('sync.status', 'processed');

        $error = MedicationError::query()->sole();
        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame($administration->id, (int) $error->client_medication_administration_id);
        $this->assertSame('wrong_dose', $error->error_type);
        $this->assertSame('moderate', $error->severity);
        $this->assertNotNull($error->client_incident_id);
        $this->assertSame(1, ClientIncident::query()->count());
        $first->assertJsonPath('medication_error.id', $error->id);

        // The same request again: the same record, error and incident.
        $this->record($order, '09:30', $more, json: true)
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('medication_error.id', $error->id);
        $this->assertSame(1, MedicationError::query()->count());
        $this->assertSame(1, ClientIncident::query()->count());
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_the_dialog_gets_json_answers_for_rejected_and_already_recorded(): void
    {
        $blocked = $this->order(['09:30'], ['name' => 'Amoxicillin 250mg']);
        MedicationAllergy::query()->create([
            'client_id' => $this->client->id,
            'allergen' => 'Amoxicillin',
            'recorded_by' => $this->worker->id,
            'severity' => 'life_threatening',
        ]);
        $this->record($blocked, '09:30', ['status' => 'given'], json: true)
            ->assertStatus(422)
            ->assertJsonPath('sync.status', 'rejected')
            ->assertJsonPath('error_field', 'client_medication_id');

        $order = $this->order(['09:30'], ['name' => 'Omeprazole 20mg']);
        $daniel = $this->staffAt($this->site, 'Daniel Ahn');
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $order->id,
            'administered_by' => $daniel->id,
            'scheduled_for' => Carbon::parse('2026-04-30 09:30', 'Pacific/Auckland')->utc(),
            'administered_at' => Carbon::parse('2026-04-30 09:10', 'Pacific/Auckland')->utc(),
            'status' => 'given',
        ]);

        $this->record($order, '09:30', ['status' => 'given'], json: true)
            ->assertOk()
            ->assertJsonPath('sync.status', 'duplicate')
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('duplicate_of.by', 'Daniel Ahn')
            ->assertJsonPath('sync.message', 'Already recorded — nothing new was saved.');
    }

    public function test_an_as_needed_dose_keeps_its_effect_check_time(): void
    {
        $order = $this->order([], [
            'name' => 'Paracetamol 500mg (as needed)',
            'is_prn' => true,
            'max_per_day' => 4,
            'prn_reason' => 'Pain',
        ]);

        $this->actingAs($this->worker)
            ->postJson('/meds/today/prn', [
                'client_medication_id' => $order->id,
                'reason' => 'Pain',
                'administered_at' => now()->toIso8601String(),
                'effect_check_due_at' => '2026-04-30T10:30',
            ])
            ->assertOk()
            ->assertJsonPath('sync.status', 'processed');

        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame(
            Carbon::parse('2026-04-30 10:30', 'Pacific/Auckland')->utc()->format('Y-m-d H:i'),
            Carbon::parse((string) $administration->getRawOriginal('effect_check_due_at'), 'UTC')->format('Y-m-d H:i'),
        );

        $this->actingAs($this->worker)
            ->postJson('/meds/today/prn', [
                'client_medication_id' => $order->id,
                'reason' => 'Pain',
                'administered_at' => now()->toIso8601String(),
                'effect_check_due_at' => '2026-04-30T08:00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_field', 'effect_check_due_at');
    }

    public function test_requirements_for_a_simple_dose_in_reach(): void
    {
        $order = $this->order(['09:30'], ['name' => 'Losartan 50mg', 'dose_amount' => 1, 'dose_unit' => 'tablet']);

        $this->requirements($order, '09:30')
            ->assertOk()
            ->assertJsonPath('kind', 'scheduled')
            ->assertJsonPath('block_all', null)
            ->assertJsonPath('block_given', null)
            ->assertJsonPath('competency.state', 'current')
            ->assertJsonPath('second_person.kind', null)
            ->assertJsonPath('due.state', 'due')
            ->assertJsonPath('person.preferred_name', 'Aroha')
            ->assertJsonPath('person.house', 'Kōwhai House')
            ->assertJsonPath('allergy.status', 'none_recorded')
            ->assertJsonPath('order.dose_amount', 1)
            ->assertJsonPath('support', 'administer')
            ->assertJsonPath('not_simple', []);
    }

    public function test_requirements_explain_a_controlled_dose_with_no_witness_and_an_allergy_match(): void
    {
        $this->grant($this->worker, ['medications.controlled.record', 'medications.controlled.view']);
        $controlled = $this->order(['09:30'], ['name' => 'Clonazepam 0.5mg', 'controlled_drug' => true]);

        $this->requirements($controlled, '09:30')
            ->assertOk()
            ->assertJsonPath('block_given.key', DoseRecordingRequirements::BLOCK_NO_WITNESS)
            ->assertJsonPath('second_person.kind', RecordingContract::SECOND_WITNESS)
            ->assertJsonPath('second_person.anyone_available', false)
            // A witness never goes unconfirmed, even with nobody on shift.
            ->assertJsonPath('second_person.may_go_unconfirmed', false)
            ->assertJsonPath('second_person.candidates', []);

        $this->witnessOnShift('Mere Kahu');
        $this->requirements($controlled, '09:30')
            ->assertJsonPath('block_given', null)
            ->assertJsonPath('second_person.anyone_available', true)
            ->assertJsonPath('second_person.candidates.0.name', 'Mere Kahu')
            ->assertJsonPath('second_person.candidates.0.can_confirm', true)
            ->assertJsonPath('second_person.may_go_unconfirmed', false);
        // A colleague is named and whether they can confirm — never why not.
        $this->assertSame(['id', 'name', 'can_confirm'], array_keys($this->requirements($controlled, '09:30')->json('second_person.candidates.0')));

        $amoxicillin = $this->order(['09:30'], ['name' => 'Amoxicillin 500mg']);
        MedicationAllergy::query()->create([
            'client_id' => $this->client->id,
            'allergen' => 'Amoxicillin',
            'recorded_by' => $this->worker->id,
            'severity' => 'mild',
        ]);
        $response = $this->requirements($amoxicillin, '09:30')
            ->assertOk()
            ->assertJsonPath('allergy.status', 'recorded')
            ->assertJsonPath('allergy.match.allergen', 'Amoxicillin')
            ->assertJsonPath('block_given', null);
        $this->assertContains('allergy_match', $response->json('not_simple'));
    }

    public function test_requirements_say_why_nothing_can_be_recorded_and_nothing_else(): void
    {
        $order = $this->order(['09:30'], ['name' => 'Losartan 50mg']);

        // Aroha's worker, rostered here but not clocked in.
        $daniel = $this->staffAt($this->site, 'Daniel Ahn');
        $this->client->supportWorkers()->attach($daniel->id);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $daniel->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => null,
            'status' => 'scheduled',
        ]);
        $blocked = $this->requirements($order, '09:30', $daniel)
            ->assertOk()
            ->assertJsonPath('block_all.key', DoseRecordingRequirements::BLOCK_NOT_CLOCKED_IN)
            ->assertJsonPath('block_all.facts', ['house' => 'Kōwhai House']);
        // P0-1: the reason and the house — nothing about the person, their
        // allergies, the order, a covert plan or colleagues.
        $this->assertSame(['kind', 'block_all', 'checked_at'], array_keys($blocked->json()));

        // Aroha's worker, clocked in at the house on a shift for someone else.
        $mele = Client::factory()->create([
            'first_name' => 'Mele',
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);
        $tama = $this->staffAt($this->site, 'Tama Rewi');
        $this->client->supportWorkers()->attach($tama->id);
        $this->onShift($tama, $mele);
        $blocked = $this->requirements($order, '09:30', $tama)
            ->assertOk()
            ->assertJsonPath('block_all.key', DoseRecordingRequirements::BLOCK_NOT_ON_SHIFT)
            ->assertJsonPath('block_all.facts', ['house' => 'Kōwhai House']);
        $this->assertSame(['kind', 'block_all', 'checked_at'], array_keys($blocked->json()));

        // An order waiting for its check: nothing can be recorded (P1-2).
        $unchecked = $this->order(['09:30'], ['name' => 'Warfarin 3mg', 'approval_status' => 'pending_verification']);
        $blocked = $this->requirements($unchecked, '09:30')
            ->assertOk()
            ->assertJsonPath('block_all.key', DoseRecordingRequirements::BLOCK_AWAITING_VERIFICATION);
        $this->assertSame(['kind', 'block_all', 'checked_at'], array_keys($blocked->json()));

        // Someone at a house outside your access, with no shift of yours: not found.
        $rimu = Site::factory()->create(['is_active' => true, 'name' => 'Rimu House']);
        $ben = Client::factory()->create(['first_name' => 'Ben', 'site_id' => $rimu->id, 'status' => 'active']);
        $bens = ClientMedication::query()->create([
            'client_id' => $ben->id,
            'name' => 'Aspirin 100mg',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => ['09:30'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ]);
        $this->requirements($bens, '09:30')->assertNotFound();

        // A time the order doesn't owe: not found.
        $this->requirements($order, '10:15')->assertNotFound();
    }

    public function test_requirements_for_an_as_needed_order_at_its_limit_and_a_refusal_to_reoffer(): void
    {
        $prn = $this->order([], [
            'name' => 'Lorazepam 1mg (as needed)',
            'is_prn' => true,
            'max_per_day' => 1,
            'prn_reason' => 'Agitation',
        ]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $prn->id,
            'administered_by' => $this->worker->id,
            'administered_at' => now()->subHours(2),
            'status' => 'given',
        ]);
        $this->actingAs($this->worker)
            ->getJson('/meds/today/prn/'.$prn->id.'/requirements')
            ->assertOk()
            ->assertJsonPath('kind', 'prn')
            ->assertJsonPath('block_all.key', DoseRecordingRequirements::BLOCK_PRN_LIMIT)
            ->assertJsonPath('block_all.facts.house', 'Kōwhai House')
            ->assertJsonPath('block_all.facts.count_24h', 1)
            ->assertJsonPath('block_all.facts.max_24h', 1)
            ->assertJsonMissingPath('prn')
            ->assertJsonMissingPath('person');

        $order = $this->order(['09:30'], ['name' => 'Sertraline 50mg']);
        $this->record($order, '09:30', [
            'status' => 'refused',
            'reason_code' => 'refused',
            'follow_up_due_at' => '2026-04-30T12:00',
        ])->assertSessionHas('success');
        $refusal = ClientMedicationAdministration::query()->where('client_medication_id', $order->id)->sole();

        $this->requirements($order, '09:30')
            ->assertOk()
            ->assertJsonPath('reoffer.refusal_id', $refusal->id);
    }

    public function test_board_rows_carry_the_same_keys_as_the_dialog(): void
    {
        $this->grant($this->worker, ['medications.controlled.record', 'medications.controlled.view']);
        $simple = $this->order(['09:30'], ['name' => 'Losartan 50mg', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $controlled = $this->order(['09:30'], ['name' => 'Clonazepam 0.5mg', 'controlled_drug' => true]);
        $due = Carbon::parse('2026-04-30 09:30', 'Pacific/Auckland');

        $rows = app(DoseRecordingRequirements::class)->forBoard($this->worker, [
            ['order' => $simple->fresh(), 'due_at' => $due],
            ['order' => $controlled->fresh(), 'due_at' => $due],
        ]);

        $simpleRow = $rows[DoseRecordingRequirements::boardKey($simple->id, $due)];
        $this->assertNull($simpleRow['block_all']);
        $this->assertNull($simpleRow['block_given']);
        $this->assertSame([], $simpleRow['not_simple']);
        $this->assertSame('due', $simpleRow['window']);

        $controlledRow = $rows[DoseRecordingRequirements::boardKey($controlled->id, $due)];
        $this->assertSame(DoseRecordingRequirements::BLOCK_NO_WITNESS, $controlledRow['block_given']);
        $this->assertContains('witness', $controlledRow['not_simple']);
    }

    public function test_requirements_are_not_found_past_the_person_gate(): void
    {
        $order = $this->order(['09:30'], ['name' => 'Losartan 50mg']);
        $due = Carbon::parse('2026-04-30 09:30', 'Pacific/Auckland');

        // Same house, clocked in for someone else, not one of Aroha's workers.
        $mele = Client::factory()->create([
            'first_name' => 'Mele',
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);
        $mere = $this->staffAt($this->site, 'Mere Kahu');
        $this->onShift($mere, $mele);
        $this->requirements($order, '09:30', $mere)->assertNotFound();
        $this->assertSame([], app(DoseRecordingRequirements::class)->forBoard($mere, [
            ['order' => $order->fresh(), 'due_at' => $due],
        ]));

        // A controlled order without controlled-medicine access: not found,
        // even to the worker on Aroha's shift, and left off the board.
        $controlled = $this->order(['09:30'], ['name' => 'Oxycodone 5mg', 'controlled_drug' => true]);
        $this->deny($this->worker, ['medications.controlled.view']);
        $this->requirements($controlled, '09:30')->assertNotFound();
        $rows = app(DoseRecordingRequirements::class)->forBoard($this->worker, [
            ['order' => $order->fresh(), 'due_at' => $due],
            ['order' => $controlled->fresh(), 'due_at' => $due],
        ]);
        $this->assertSame([DoseRecordingRequirements::boardKey($order->id, $due)], array_keys($rows));
    }

    public function test_nothing_is_offered_on_a_controlled_medicine_you_may_not_record(): void
    {
        $this->witnessOnShift();
        $controlled = $this->order(['09:30'], ['name' => 'Oxycodone 5mg', 'controlled_drug' => true]);
        $this->deny($this->worker, ['medications.controlled.record']);

        // The record path refuses every outcome on a controlled medicine
        // without this key, so the dialog offers none.
        $blocked = $this->requirements($controlled, '09:30')
            ->assertOk()
            ->assertJsonPath('block_all.key', DoseRecordingRequirements::BLOCK_CONTROLLED_NOT_ALLOWED);
        $this->assertSame(['kind', 'block_all', 'checked_at'], array_keys($blocked->json()));

        $response = $this->record($controlled, '09:30', ['status' => 'refused', 'reason_code' => 'refused'], json: true);
        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_a_restricted_workers_cosigner_never_goes_unconfirmed(): void
    {
        app(MedicationSafetyPolicySettings::class)->save([MedicationSafetyPolicySettings::RESTRICTED_COMPETENCY => 'cosigner']);
        MedicationCompetencyAssessment::query()->where('user_id', $this->worker->id)->update(['restricted' => true]);
        $order = $this->order(['09:30'], ['name' => 'Losartan 50mg']);

        $this->requirements($order, '09:30')
            ->assertOk()
            ->assertJsonPath('second_person.kind', RecordingContract::SECOND_COSIGNER)
            ->assertJsonPath('second_person.anyone_available', false)
            ->assertJsonPath('second_person.may_go_unconfirmed', false);

        $this->record($order, '09:30', ['status' => 'given', 'second_person_unavailable' => true])
            ->assertSessionHasErrors('witnessed_by');
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_a_reoffer_is_refused_on_another_day_by_the_server_clock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-29 08:00', 'Pacific/Auckland')->utc());
        $order = $this->order(['09:30'], ['name' => 'Sertraline 50mg']);
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30', 'Pacific/Auckland')->utc());

        $refusal = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $order->id,
            'administered_by' => $this->worker->id,
            'scheduled_for' => Carbon::parse('2026-04-29 09:30', 'Pacific/Auckland')->utc(),
            'administered_at' => Carbon::parse('2026-04-29 09:40', 'Pacific/Auckland')->utc(),
            'status' => 'refused',
            'reason_code' => 'refused',
        ]);
        MedicationRefusalFollowup::query()->create([
            'client_id' => $this->client->id,
            'client_medication_administration_id' => $refusal->id,
            'reason_category' => 'personal_choice',
            'follow_up_action' => RecordingContract::REFUSAL_FOLLOW_UP_ACTION,
            'follow_up_due_at' => Carbon::parse('2026-04-29 11:00', 'Pacific/Auckland')->utc(),
            'created_by' => $this->worker->id,
            'owner_id' => $this->worker->id,
        ]);

        $this->record($order, '2026-04-29 09:30', [
            'status' => 'given',
            'reoffer_of_id' => $refusal->id,
            'late_reason' => 'out_or_asleep',
        ])->assertSessionHasErrors('reoffer_of_id');
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_a_reoffer_is_refused_once_the_follow_up_is_closed(): void
    {
        $order = $this->order(['09:30'], ['name' => 'Sertraline 50mg']);
        $this->record($order, '09:30', [
            'status' => 'refused',
            'reason_code' => 'refused',
            'follow_up_due_at' => '2026-04-30T11:00',
        ])->assertSessionHas('success');
        $refusal = ClientMedicationAdministration::query()->sole();
        MedicationRefusalFollowup::query()->sole()->forceFill([
            'follow_up_completed_at' => now(),
            'follow_up_completed_by' => $this->worker->id,
            'follow_up_outcome' => 'Spoke with her GP.',
        ])->save();

        $this->requirements($order, '09:30')->assertOk()->assertJsonPath('reoffer', null);
        $this->record($order, '09:30', ['status' => 'given', 'reoffer_of_id' => $refusal->id])
            ->assertSessionHasErrors('reoffer_of_id');
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_a_reoffer_of_a_given_dose_is_a_duplicate_and_a_second_reoffer_is_refused(): void
    {
        $given = $this->order(['09:30'], ['name' => 'Metformin 500mg']);
        $this->record($given, '09:30', ['status' => 'given'])->assertSessionHas('success');
        $first = ClientMedicationAdministration::query()->sole();
        $this->record($given, '09:30', ['status' => 'given', 'reoffer_of_id' => $first->id], json: true)
            ->assertOk()
            ->assertJsonPath('sync.status', 'duplicate');
        $this->assertDatabaseCount('client_medication_administrations', 1);

        $order = $this->order(['09:30'], ['name' => 'Sertraline 50mg']);
        $this->record($order, '09:30', [
            'status' => 'refused',
            'reason_code' => 'refused',
            'follow_up_due_at' => '2026-04-30T11:00',
        ])->assertSessionHas('success');
        $refusal = ClientMedicationAdministration::query()->where('client_medication_id', $order->id)->sole();

        Carbon::setTestNow(Carbon::parse('2026-04-30 10:15:00', 'Pacific/Auckland')->utc());
        $this->record($order, '09:30', [
            'status' => 'refused',
            'reason_code' => 'refused',
            'reoffer_of_id' => $refusal->id,
        ])->assertSessionHas('success');
        $this->assertSame('Refused again at 10:15 am.', MedicationRefusalFollowup::query()->sole()->follow_up_outcome);

        // The follow-up is closed: a second re-offer of the same refusal isn't.
        $this->record($order, '09:30', ['status' => 'given', 'reoffer_of_id' => $refusal->id])
            ->assertSessionHasErrors('reoffer_of_id');
        $this->assertSame(2, ClientMedicationAdministration::query()->where('client_medication_id', $order->id)->count());
    }

    public function test_less_on_a_controlled_medicine_records_what_was_taken_and_the_witnessed_waste(): void
    {
        $witness = $this->witnessOnShift();
        $order = $this->order(['09:30'], [
            'name' => 'Clonazepam 0.5mg',
            'controlled_drug' => true,
            'dose_amount' => 2,
            'dose_unit' => 'tablet',
        ]);
        $stock = ClientMedicationStock::query()->create(['client_medication_id' => $order->id, 'on_hand' => 10, 'unit' => 'tablets']);
        $less = [
            'status' => 'given',
            'amount_mode' => 'less',
            'quantity_given' => 1,
            'amount_reason' => 'part_taken',
            'witnessed_by' => $witness->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
        ];

        // How many were taken from the stock is always entered…
        $this->record($order, '09:30', $less)->assertSessionHasErrors('quantity_administered');
        // …and can't be less than what was given.
        $this->record($order, '09:30', [...$less, 'quantity_administered' => 0.5])
            ->assertSessionHasErrors('quantity_administered');
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);

        $this->record($order, '09:30', [...$less, 'quantity_administered' => 2])->assertSessionHas('success');

        // Everything taken comes off: the register is never above the count.
        $this->assertSame(8.0, (float) $stock->refresh()->on_hand);
        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame('1.00', (string) $administration->quantity_given);
        $this->assertSame('1 tablet', $administration->dose_given);
        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_medication_id' => $order->id,
            'entry_type' => 'administered',
            'quantity' => '1.00',
            'on_hand_before' => '10.00',
            'on_hand_after' => '9.00',
        ]);
        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_medication_id' => $order->id,
            'entry_type' => 'disposal',
            'quantity' => '1.00',
            'on_hand_before' => '9.00',
            'on_hand_after' => '8.00',
            'witnessed_by' => $witness->id,
        ]);
    }

    public function test_more_than_ordered_on_a_controlled_medicine_reconciles_taken_against_given(): void
    {
        $witness = $this->witnessOnShift();
        $order = $this->order(['09:30'], [
            'name' => 'Clonazepam 0.5mg',
            'controlled_drug' => true,
            'dose_amount' => 1,
            'dose_unit' => 'tablet',
        ]);
        $stock = ClientMedicationStock::query()->create(['client_medication_id' => $order->id, 'on_hand' => 10, 'unit' => 'tablet']);
        $more = [
            'status' => 'given',
            'amount_mode' => 'more',
            'quantity_given' => 2,
            'more_severity' => 'minor',
            'more_immediate_action' => 'Told the house lead straight away.',
            'notes' => 'Two tablets came out of the pack together.',
            'witnessed_by' => $witness->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
        ];

        $this->record($order, '09:30', $more)->assertSessionHasErrors('quantity_administered');
        $this->record($order, '09:30', [...$more, 'quantity_administered' => 1])->assertSessionHasErrors('quantity_administered');
        $this->assertDatabaseCount('client_medication_administrations', 0);

        $this->record($order, '09:30', [...$more, 'quantity_administered' => 2])->assertSessionHas('success');
        $this->assertSame(8.0, (float) $stock->refresh()->on_hand);
        $this->assertSame('2 tablet', ClientMedicationAdministration::query()->sole()->dose_given);
        $this->assertSame(0, ClientControlledDrugEntry::query()->where('entry_type', 'disposal')->count());
    }

    public function test_less_on_a_controlled_medicine_counted_in_other_units_is_refused(): void
    {
        $witness = $this->witnessOnShift();
        $order = $this->order(['09:30'], [
            'name' => 'Oxycodone liquid',
            'controlled_drug' => true,
            'dose_amount' => 5,
            'dose_unit' => 'mg',
        ]);
        $stock = ClientMedicationStock::query()->create(['client_medication_id' => $order->id, 'on_hand' => 100, 'unit' => 'mL']);

        $this->record($order, '09:30', [
            'status' => 'given',
            'amount_mode' => 'less',
            'quantity_given' => 2.5,
            'amount_reason' => 'dropped_or_spilled',
            'quantity_administered' => 5,
            'witnessed_by' => $witness->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
        ])->assertSessionHasErrors('amount_mode');
        $this->assertStringContainsString('Controlled drugs › Loss', session('errors')->first('amount_mode'));
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame(100.0, (float) $stock->refresh()->on_hand);
    }

    public function test_a_refusal_still_needs_the_scope_and_the_shift(): void
    {
        $order = $this->order(['09:30'], ['name' => 'Amoxicillin 500mg']);
        MedicationAllergy::query()->create([
            'client_id' => $this->client->id,
            'allergen' => 'Amoxicillin',
            'recorded_by' => $this->worker->id,
            'severity' => 'severe',
        ]);

        // One of Aroha's workers, not clocked in.
        $daniel = $this->staffAt($this->site, 'Daniel Ahn');
        $this->client->supportWorkers()->attach($daniel->id);
        $response = $this->record($order, '09:30', ['status' => 'refused', 'reason_code' => 'refused'], json: true, as: $daniel);
        $this->assertContains($response->status(), [403, 404, 422]);

        // Clocked in at the house for someone else.
        $mele = Client::factory()->create([
            'first_name' => 'Mele',
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);
        $mere = $this->staffAt($this->site, 'Mere Kahu');
        $this->onShift($mere, $mele);
        $response = $this->record($order, '09:30', ['status' => 'refused', 'reason_code' => 'refused'], json: true, as: $mere);
        $this->assertContains($response->status(), [403, 404, 422]);

        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_the_mobile_api_records_the_same_contract_and_a_queued_reoffer_is_not_a_conflict(): void
    {
        $order = $this->order(['09:30'], ['name' => 'Paracetamol 500mg', 'dose_amount' => 2, 'dose_unit' => 'tablet']);
        $url = "/api/medications/clients/{$this->client->id}/medications/{$order->id}/administrations";
        $scheduledFor = Carbon::parse('2026-04-30 09:30', 'Pacific/Auckland')->toIso8601String();

        $this->actingAs($this->worker, 'sanctum')
            ->postJson($url, [
                'status' => 'given',
                'scheduled_for' => $scheduledFor,
                'amount_mode' => 'less',
                'quantity_given' => 1,
                'amount_reason' => 'part_taken',
                'second_person_unavailable' => true,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertSuccessful();
        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame('1.00', (string) $administration->quantity_given);
        $this->assertSame('1 tablet', $administration->dose_given);
        $this->assertSame(RecordingContract::SECOND_NOT_CONFIRMED, $administration->second_person_status);
        $this->assertSame(RecordingContract::REVIEW_PARTIAL_DOSE_NOT_CONFIRMED, $administration->review_reason_key);

        $refused = $this->order(['09:30'], ['name' => 'Sertraline 50mg']);
        $refusedUrl = "/api/medications/clients/{$this->client->id}/medications/{$refused->id}/administrations";
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($refusedUrl, [
                'status' => 'refused',
                'reason_code' => 'refused',
                'scheduled_for' => $scheduledFor,
                'follow_up_due_at' => '2026-04-30T11:00',
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertSuccessful();
        $refusal = ClientMedicationAdministration::query()->where('client_medication_id', $refused->id)->sole();

        // Captured offline, sent later: a re-offer, not "already recorded".
        Carbon::setTestNow(Carbon::parse('2026-04-30 10:20:00', 'Pacific/Auckland')->utc());
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($refusedUrl, [
                'status' => 'given',
                'scheduled_for' => $scheduledFor,
                'reoffer_of_id' => $refusal->id,
                'administered_at' => now()->subMinutes(5)->toIso8601String(),
                'captured_offline_at' => now()->subMinutes(5)->toIso8601String(),
                'origin_device_id' => 'p01-phone',
                'queued_offline' => true,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertSuccessful();
        $reoffer = ClientMedicationAdministration::query()->where('client_medication_id', $refused->id)->latest('id')->first();
        $this->assertSame('given', $reoffer->status);
        $this->assertSame($refusal->id, (int) $reoffer->reoffer_of_id);
        $this->assertNotNull(MedicationRefusalFollowup::query()->sole()->follow_up_completed_at);
    }

    public function test_requirements_name_the_houses_on_call_contact(): void
    {
        $order = $this->order(['09:30'], ['name' => 'Losartan 50mg']);

        $this->requirements($order, '09:30')
            ->assertOk()
            ->assertJsonPath('on_call.configured', false)
            ->assertJsonPath('on_call.name', null);

        $rangi = $this->staffAt($this->site, 'Rangi Parata');
        HrEmployeeProfile::query()->where('user_id', $rangi->id)->update(['work_phone' => '021 555 0142']);
        MedicationOnCallRule::query()->create([
            'site_id' => $this->site->id,
            'mode' => MedicationOnCallRule::FIXED,
            'team_lead' => false,
            'backup_user_id' => $rangi->id,
        ]);

        $this->requirements($order, '09:30')
            ->assertOk()
            ->assertJsonPath('on_call.configured', true)
            ->assertJsonPath('on_call.name', 'Rangi Parata')
            ->assertJsonPath('on_call.phone', '021 555 0142');
    }

    public function test_a_controlled_as_needed_dose_checks_the_balance_left(): void
    {
        $witness = $this->witnessOnShift();
        $order = $this->order([], [
            'name' => 'Oxycodone 5mg (as needed)',
            'is_prn' => true,
            'prn_reason' => 'Pain',
            'max_per_day' => 4,
            'controlled_drug' => true,
            'dose_amount' => 1,
            'dose_unit' => 'tablet',
        ]);
        $stock = ClientMedicationStock::query()->create(['client_medication_id' => $order->id, 'on_hand' => 10, 'unit' => 'tablets']);
        $prn = fn (array $extra) => $this->actingAs($this->worker)->postJson('/meds/today/prn', [
            'client_medication_id' => $order->id,
            'reason' => 'Pain',
            'administered_at' => now()->toIso8601String(),
            'quantity_administered' => 1,
            'witnessed_by' => $witness->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            ...$extra,
        ]);

        // A balance that doesn't match the register is refused; nothing moves.
        $prn(['cd_balance' => 7])->assertStatus(422)->assertJsonPath('error_field', 'cd_balance');
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);

        $prn(['cd_balance' => 9])->assertOk();
        $this->assertSame(9.0, (float) $stock->refresh()->on_hand);
    }

    // ─── helpers ─────────────────────────────────────────────

    /**
     * @param  string  $time  "HH:MM" today (30 April), or "YYYY-MM-DD HH:MM"
     * @param  array<string, mixed>  $fields
     */
    private function record(ClientMedication $order, string $time, array $fields, bool $json = false, ?User $as = null)
    {
        $payload = array_filter([
            'client_medication_id' => $order->id,
            'scheduled_for' => Carbon::parse(strlen($time) > 5 ? $time : '2026-04-30 '.$time, 'Pacific/Auckland')->toIso8601String(),
            ...$fields,
        ], fn ($value) => $value !== null);

        $request = $this->actingAs($as ?? $this->worker)->from('/meds/today');

        return $json
            ? $request->postJson('/meds/today/record', $payload)
            : $request->post('/meds/today/record', $payload);
    }

    private function requirements(ClientMedication $order, string $time, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->worker)->getJson('/meds/today/doses/requirements?'.http_build_query([
            'client_medication_id' => $order->id,
            'scheduled_for' => Carbon::parse('2026-04-30 '.$time, 'Pacific/Auckland')->toIso8601String(),
        ]));
    }

    /** @param  array<string, mixed>  $overrides */
    private function order(array $doseTimes, array $overrides = []): ClientMedication
    {
        $now = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::now('Pacific/Auckland')->startOfDay()->utc());
        $order = ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Morning tablets',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => $doseTimes,
            'is_prn' => false,
            'controlled_drug' => false,
            'nz_controlled_class' => ($overrides['controlled_drug'] ?? false) ? 'B' : null,
            'controlled_class_source' => ($overrides['controlled_drug'] ?? false) ? 'Synthetic reviewed test configuration' : null,
            'approval_status' => 'verified',
            'active' => true,
            'state' => 'active',
        ], $overrides));
        Carbon::setTestNow($now);

        return $order;
    }

    private function staffAt(Site $site, string $name): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'name' => $name]);
        $role = Role::query()->where('name', 'support_worker')->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
        $this->grant($user, ['medications.administer.record']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $assessor = User::factory()->create(['role' => 'manager', 'approved_at' => now()]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $user->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
            'controlled_drugs' => true,
            'can_witness_controlled' => true,
        ]);

        return $user;
    }

    private function onShift(User $user, Client $client): void
    {
        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $user->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);
    }

    private function witnessOnShift(string $name = 'Jordan Tipene'): User
    {
        $witness = $this->staffAt($this->site, $name);
        $this->grant($witness, ['medications.controlled.witness']);
        $this->onShift($witness, $this->client);

        return $witness;
    }

    /** @param  list<string>  $keys */
    private function deny(User $user, array $keys): void
    {
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $keys)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
                ->all(),
        );
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        Cache::flush();
    }

    /** @param  list<string>  $keys */
    private function grant(User $user, array $keys): void
    {
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $keys)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }
}
