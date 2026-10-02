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
use App\Models\MedicationRefusalFollowup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Recording\DoseRecordingRequirements;
use App\Services\Medication\Recording\RecordingContract;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
            ->assertJsonPath('second_person.candidates', []);

        $this->witnessOnShift('Mere Kahu');
        $this->requirements($controlled, '09:30')
            ->assertJsonPath('block_given', null)
            ->assertJsonPath('second_person.anyone_available', true)
            ->assertJsonPath('second_person.candidates.0.name', 'Mere Kahu')
            ->assertJsonPath('second_person.candidates.0.eligible', true);

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

    public function test_requirements_say_why_nothing_can_be_recorded(): void
    {
        $order = $this->order(['09:30'], ['name' => 'Losartan 50mg']);

        // Rostered here but not clocked in.
        $daniel = $this->staffAt($this->site, 'Daniel Ahn');
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
        $this->requirements($order, '09:30', $daniel)
            ->assertOk()
            ->assertJsonPath('block_all.key', DoseRecordingRequirements::BLOCK_NOT_CLOCKED_IN);

        // Clocked in at the house, on a shift for someone else.
        $mele = Client::factory()->create([
            'first_name' => 'Mele',
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);
        $mere = $this->staffAt($this->site, 'Mere Kahu');
        $this->onShift($mere, $mele);
        $this->requirements($order, '09:30', $mere)
            ->assertOk()
            ->assertJsonPath('block_all.key', DoseRecordingRequirements::BLOCK_NOT_ON_SHIFT);

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
            ->assertJsonPath('prn.count_24h', 1)
            ->assertJsonPath('prn.max_24h', 1)
            ->assertJsonPath('prn.reasons.0', 'Agitation');

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

    // ─── helpers ─────────────────────────────────────────────

    /** @param  array<string, mixed>  $fields */
    private function record(ClientMedication $order, string $time, array $fields, bool $json = false, ?User $as = null)
    {
        $payload = array_filter([
            'client_medication_id' => $order->id,
            'scheduled_for' => Carbon::parse('2026-04-30 '.$time, 'Pacific/Auckland')->toIso8601String(),
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
