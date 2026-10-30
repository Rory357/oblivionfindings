<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlledDrugLossReport;
use App\Models\ControlledWitnessOverride;
use App\Models\MedicationDestruction;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlledProductPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    private Site $site;

    private Client $person;

    private Client $other;

    private ClientMedication $medicine;

    private ClientMedication $otherMedicine;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 12:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->person = Client::factory()->create(['site_id' => $this->site->id]);
        $this->other = Client::factory()->create(['site_id' => $this->site->id]);
        $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->reader->id, 'primary_site_id' => $this->site->id,
            'is_active' => true, 'start_date' => '2026-01-01',
        ]);
        $grants = ['medications.view', 'medications.controlled.view', 'clients.viewAny'];
        $permissions = Permission::query()->whereIn('key', [...$grants, ...MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS])->get();
        $this->assertCount(count($grants) + count(MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS), $permissions);
        $this->reader->permissionOverrides()->sync($permissions->mapWithKeys(fn ($p) => [
            $p->id => ['allowed' => in_array($p->key, $grants, true)],
        ])->all());
        $this->reader = $this->reader->fresh();
        $this->medicine = $this->medicine($this->person);
        $this->otherMedicine = $this->medicine($this->other);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_same_site_mismatches_do_not_change_history_latest_entry_or_last_count(): void
    {
        $valid = $this->entry();
        $count = $this->entry(['entry_type' => 'balance_check']);
        $forged = $this->entry(['client_id' => $this->other->id, 'entry_type' => 'balance_check', 'notes' => 'forged-count-sentinel']);

        $response = $this->product()->assertJsonCount(2, 'entries')->assertDontSee('forged-count-sentinel');
        $this->assertEqualsCanonicalizing([$valid->id, $count->id], $response->json('entries.*.id'));
        $medicine = collect($response->json('medicines'))->firstWhere('id', $this->medicine->id);
        $this->assertSame($count->id, $medicine['entry_version']);
        $this->assertSame($count->id, $medicine['count']['last_entry_id']);
        $this->assertNull($medicine['balance']);
        $this->assertDatabaseHas('client_controlled_drug_entries', ['id' => $forged->id, 'client_id' => $this->other->id]);
    }

    public function test_reversals_require_the_same_canonical_person_and_medicine_as_the_original(): void
    {
        $originals = [$this->entry(), $this->entry(), $this->entry()];
        $foreign = Client::factory()->create(['site_id' => Site::factory()->create(['is_active' => true])->id]);
        $foreignMedicine = $this->medicine($foreign);
        $this->entry([
            'client_id' => $foreign->id, 'client_medication_id' => $foreignMedicine->id,
            'reverses_entry_id' => $originals[0]->id, 'reason' => 'foreign-reversal-sentinel',
        ]);
        $this->entry([
            'client_id' => $this->other->id, 'client_medication_id' => $this->otherMedicine->id,
            'reverses_entry_id' => $originals[1]->id, 'reason' => 'wrong-medicine-reversal-sentinel',
        ]);
        $this->entry(['reverses_entry_id' => $originals[2]->id, 'reason' => 'Valid same-owner reversal']);

        $response = $this->product()->assertDontSee('foreign-reversal-sentinel')->assertDontSee('wrong-medicine-reversal-sentinel');
        $rows = collect($response->json('entries'))->keyBy('id');
        foreach (array_slice($originals, 0, 2) as $original) {
            $this->assertNull($rows[$original->id]['voided_at']);
            $this->assertNull($rows[$original->id]['void_reason']);
        }
        $this->assertSame('Valid same-owner reversal', $rows[$originals[2]->id]['void_reason']);
        $this->assertNotNull($rows[$originals[2]->id]['voided_at']);
    }

    public function test_outstanding_records_and_totals_exclude_same_site_owner_mismatches(): void
    {
        $valid = [];
        foreach ([$this->person, $this->other] as $person) {
            $good = $person->is($this->person);
            $sentinel = $good ? 'Valid owned record' : 'mismatched-outstanding-sentinel';
            $valid['discrepancies'][] = ClientControlledDrugDiscrepancy::create([
                'client_id' => $person->id, 'client_medication_id' => $this->medicine->id,
                'status' => 'open', 'notes' => $sentinel, 'reported_at' => now(),
            ])->id;
            $valid['losses'][] = ControlledDrugLossReport::create([
                'client_id' => $person->id, 'client_medication_id' => $this->medicine->id,
                'medication_name' => 'Synthetic controlled medicine', 'quantity_lost' => 1,
                'circumstances' => $sentinel, 'discovered_by' => $this->reader->id,
                'discovered_at' => now(), 'investigation_status' => 'reported',
            ])->id;
            $valid['destructions'][] = MedicationDestruction::create([
                'client_id' => $person->id, 'client_medication_id' => $this->medicine->id, 'site_id' => $this->site->id,
                'medication_name' => 'Synthetic controlled medicine', 'quantity' => 1, 'reason' => 'expired',
                'disposal_method' => 'pharmacy_return', 'is_controlled_drug' => true,
                'destroyed_by' => $this->reader->id, 'witness_1_id' => $this->reader->id,
                'destroyed_at' => now(), 'notes' => $sentinel,
            ])->id;
        }

        $response = $this->product()->assertDontSee('mismatched-outstanding-sentinel');
        foreach ($valid as $key => $ids) {
            $response->assertJsonCount(1, $key)->assertJsonPath($key.'.0.id', $ids[0]);
        }
        foreach (['open_discrepancies', 'open_losses', 'awaiting_receipt'] as $key) {
            $response->assertJsonPath('totals.'.$key, 1);
        }
        foreach (['total_open_discrepancies', 'total_open_losses', 'total_awaiting_receipts'] as $key) {
            $response->assertJsonPath('meters.'.$key, 1);
        }
    }

    public function test_override_doses_and_followup_totals_require_canonical_owner_pairs(): void
    {
        $withValid = $this->override();
        $onlyMismatched = $this->override();
        $valid = $this->dose($withValid, $this->person);
        $this->dose($withValid, $this->other);
        $this->dose($onlyMismatched, $this->other);

        $response = $this->product();
        $overrides = collect($response->json('overrides'))->keyBy('id');
        $this->assertSame([$valid->id], array_column($overrides[$withValid->id]['doses'], 'administration_id'));
        $this->assertSame([], $overrides[$onlyMismatched->id]['doses']);
        $response->assertJsonPath('meters.total_pending_followups', 1)->assertJsonPath('meters.total_overdue_followups', 1);
    }

    public function test_override_doses_require_membership_while_multi_medicine_overrides_keep_each_listed_medicine(): void
    {
        $anchorOnly = $this->override();
        $multi = $this->override(['medicine_ids' => [$this->medicine->id, $this->otherMedicine->id]]);
        $onlyUnlisted = $this->override();
        $anchorDose = $this->dose($anchorOnly, $this->person);
        $this->dose($anchorOnly, $this->other, $this->otherMedicine);
        $multiAnchor = $this->dose($multi, $this->person);
        $multiOther = $this->dose($multi, $this->other, $this->otherMedicine);
        $this->dose($onlyUnlisted, $this->other, $this->otherMedicine);

        $response = $this->product();
        $overrides = collect($response->json('overrides'))->keyBy('id');
        $this->assertSame([$anchorDose->id], array_column($overrides[$anchorOnly->id]['doses'], 'administration_id'));
        $this->assertEqualsCanonicalizing(
            [$multiAnchor->id, $multiOther->id],
            array_column($overrides[$multi->id]['doses'], 'administration_id'),
        );
        $this->assertSame([], $overrides[$onlyUnlisted->id]['doses']);
        $response->assertJsonPath('meters.total_pending_followups', 2)->assertJsonPath('meters.total_overdue_followups', 2);
    }

    public function test_failed_product_validation_does_not_flash_second_witness_secrets_at_any_depth(): void
    {
        $this->actingAs($this->reader)->from('/emar/controlled')->post(route('emar.controlled.product.action', ['action' => 'count']), [
            'client_medication_id' => 'not-an-integer', 'expected_entry_id' => null,
            'second_witness_credential' => '731946', 'notes' => 'Retain safe notes',
            'nested' => [['second_witness_credential' => '946731', 'label' => 'Retain safe label']],
        ])->assertSessionHasErrors('client_medication_id');

        $old = session()->getOldInput();
        $this->assertSame('Retain safe notes', $old['notes'] ?? null);
        $this->assertSame('Retain safe label', $old['nested'][0]['label'] ?? null);
        $this->assertArrayNotHasKey('second_witness_credential', $old);
        $this->assertArrayNotHasKey('second_witness_credential', $old['nested'][0]);
        $session = json_encode(session()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('731946', $session);
        $this->assertStringNotContainsString('946731', $session);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
    }

    private function product()
    {
        return $this->actingAs($this->reader)->getJson(route('emar.controlled.product', ['site_id' => $this->site->id]))->assertOk();
    }

    private function medicine(Client $person): ClientMedication
    {
        return ClientMedication::factory()->create([
            'client_id' => $person->id, 'name' => 'Synthetic controlled medicine '.$person->id,
            'controlled_drug' => true, 'active' => true, 'state' => 'active', 'is_prn' => true,
            'approval_status' => 'verified', 'frequency' => 'PRN', 'end_date' => null,
        ]);
    }

    private function entry(array $overrides = []): ClientControlledDrugEntry
    {
        return ClientControlledDrugEntry::create(array_replace([
            'client_id' => $this->person->id, 'client_medication_id' => $this->medicine->id,
            'entry_type' => 'receipt', 'quantity' => 1, 'unit' => 'tablets', 'on_hand_before' => 1,
            'on_hand_after' => 2, 'recorded_by' => $this->reader->id, 'witnessed_by' => $this->reader->id,
            'recorded_at' => now(),
        ], $overrides));
    }

    private function override(array $overrides = []): ControlledWitnessOverride
    {
        return ControlledWitnessOverride::create(array_replace([
            'site_id' => $this->site->id, 'client_medication_id' => $this->medicine->id,
            'medicine_ids' => [$this->medicine->id], 'requested_by' => $this->reader->id,
            'reason' => 'Synthetic existing override', 'status' => 'approved', 'followup_due_at' => now()->subHour(),
        ], $overrides));
    }

    private function dose(ControlledWitnessOverride $override, Client $person, ?ClientMedication $medicine = null): ClientMedicationAdministration
    {
        return ClientMedicationAdministration::create([
            'client_id' => $person->id, 'client_medication_id' => ($medicine ?? $this->medicine)->id,
            'witness_override_id' => $override->id, 'administered_by' => $this->reader->id,
            'administered_at' => now(), 'status' => 'given',
        ]);
    }
}
