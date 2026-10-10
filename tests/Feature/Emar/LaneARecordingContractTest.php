<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationStockLot;
use App\Models\MedicationStockMovement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Controlled\ControlledRegisterService;
use App\Services\Medication\Stock\MedicationStockService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * eMAR audit Lane A1 (EA-003, EA-009, EA-058): the recording dialog's own
 * payloads for as-needed, controlled and withheld doses must save.
 *
 *  - EA-003: an as-needed dose forwards the whole recording contract (pack
 *    lines, the connected review, waste) exactly as a scheduled dose does.
 *  - EA-009: a pack-tracked controlled dose whose dose unit differs from the
 *    stock unit is signed with its per-pack waste, taken in stock units.
 *  - EA-058: "Withheld › Other (say what happened)" saves with the note.
 */
class LaneARecordingContractTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private ClientMedication $medication;

    private ClientMedicationStock $stock;

    private User $recorder;

    private User $witness;

    protected function setUp(): void
    {
        parent::setUp();
        $clock = CarbonImmutable::parse('2026-04-30 09:30', 'Pacific/Auckland')->utc();
        Carbon::setTestNow($clock);
        CarbonImmutable::setTestNow($clock);
        config(['medications.stock_lots_enabled' => true]);
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $this->recorder = $this->staff();
        $this->witness = $this->staff();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** EA-003 case 1: the dialog's controlled as-needed payload with an actual pack. */
    public function test_a_controlled_as_needed_dose_with_started_packs_saves_its_actual_pack(): void
    {
        $this->order([
            'name' => 'Synthetic oxycodone 5mg', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet',
            'frequency' => 'As needed', 'dose_times' => [], 'is_prn' => true, 'prn_reason' => 'Breakthrough pain',
            'controlled_drug' => true, 'nz_controlled_class' => 'B', 'controlled_class_source' => 'Synthetic reviewed test class',
        ], 'tablet', '10.00');
        $lot = $this->open(true);
        $review = $this->asNeededRequirements()->json('live_recording_context');
        $this->assertIsString($review);

        $this->actingAs($this->recorder)->postJson('/meds/today/prn', [
            'client_medication_id' => $this->medication->id,
            'client_request_uuid' => (string) Str::uuid(),
            'live_recording_context' => $review,
            'administered_at' => '2026-04-30T09:30:00',
            'reason' => 'Breakthrough pain',
            'effect_check_due_at' => '2026-04-30T10:30:00',
            'amount_mode' => 'as_ordered',
            'witnessed_by' => $this->witness->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            'cd_balance' => 9,
            // The dialog's shape: per-pack waste, and the waste total it adds up.
            'pack_lines' => [['lot_id' => $lot->id, 'revision' => $lot->revision, 'quantity' => '1', 'quantity_wasted' => '0']],
            'quantity_wasted' => 0,
        ])->assertOk()->assertJsonPath('sync.status', 'processed');

        $admin = ClientMedicationAdministration::sole();
        $this->assertSame('given', $admin->status);
        $this->assertSame('9.00', $this->stock->fresh()->on_hand);
        $this->assertSame('9.00', $lot->fresh()->quantity_remaining);
        $this->assertSame([$lot->id], MedicationStockMovement::where('administration_id', $admin->id)->pluck('medication_stock_lot_id')->unique()->values()->all());
        $this->assertSame(['administered'], ClientControlledDrugEntry::where('client_medication_administration_id', $admin->id)->pluck('entry_type')->all());
    }

    /**
     * Walk finding F3 (Main, 10 Oct): the dialog records whole minutes, so a
     * pack started at 09:30:13 can be used by a dose given at 09:30. A dose
     * in an earlier minute than the pack started is still refused.
     */
    public function test_a_pack_started_earlier_in_the_same_minute_can_be_used(): void
    {
        $this->order([
            'name' => 'Synthetic oxycodone 5mg', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet',
            'frequency' => 'As needed', 'dose_times' => [], 'is_prn' => true, 'prn_reason' => 'Breakthrough pain',
            'controlled_drug' => true, 'nz_controlled_class' => 'B', 'controlled_class_source' => 'Synthetic reviewed test class',
        ], 'tablet', '10.00');
        $started = CarbonImmutable::parse('2026-04-30 09:30:13', 'Pacific/Auckland')->utc();
        Carbon::setTestNow($started);
        CarbonImmutable::setTestNow($started);
        $lot = $this->open(true);
        $review = $this->asNeededRequirements()->json('live_recording_context');
        $dose = fn (string $at): array => [
            'client_medication_id' => $this->medication->id,
            'client_request_uuid' => (string) Str::uuid(),
            'live_recording_context' => $review,
            'administered_at' => $at,
            'reason' => 'Breakthrough pain',
            'effect_check_due_at' => '2026-04-30T10:30:00',
            'amount_mode' => 'as_ordered',
            'witnessed_by' => $this->witness->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            'cd_balance' => 9,
            'pack_lines' => [['lot_id' => $lot->id, 'revision' => $lot->revision, 'quantity' => '1', 'quantity_wasted' => '0']],
            'quantity_wasted' => 0,
        ];

        // Before the fix: "Enter the actual selected pack…" (the walk's 422).
        $this->actingAs($this->recorder)->postJson('/meds/today/prn', $dose('2026-04-30T09:30:00'))
            ->assertOk()->assertJsonPath('sync.status', 'processed');
        $this->assertSame('9.00', $lot->fresh()->quantity_remaining);
        $this->assertSame(1, MedicationStockMovement::where('medication_stock_lot_id', $lot->id)->where('kind', 'controlled_given')->count());

        // The boundary itself: the pack's minute counts, an earlier minute
        // never does. (Through the dialog, an earlier dose time is refused
        // first by its connected review.)
        $at = fn (string $nz) => CarbonImmutable::parse($nz, 'Pacific/Auckland');
        $this->assertTrue(MedicationStockService::packStartedBy($at('2026-04-30 09:30:13'), $at('2026-04-30 09:30:00')));
        $this->assertTrue(MedicationStockService::packStartedBy($at('2026-04-30 09:30:13'), $at('2026-04-30 09:31:00')));
        $this->assertFalse(MedicationStockService::packStartedBy($at('2026-04-30 09:30:13'), $at('2026-04-30 09:29:59')));
        $this->assertFalse(MedicationStockService::packStartedBy($at('2026-04-30 09:30:00'), $at('2026-04-30 09:29:00')));
    }

    /** EA-003 case 2: a back-dated pack-tracked as-needed dose keeps its connected review. */
    public function test_a_back_dated_pack_tracked_as_needed_dose_keeps_its_connected_review(): void
    {
        $this->order([
            'name' => 'Synthetic paracetamol 500mg', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet',
            'frequency' => 'As needed', 'dose_times' => [], 'is_prn' => true, 'prn_reason' => 'Pain', 'controlled_drug' => false,
        ], 'tablet', '20.00');
        $this->open(false);
        // The dialog opens at 09:30; the dose is given at 09:32 and saved at 09:37.
        $review = $this->asNeededRequirements()->json('live_recording_context');
        $this->assertIsString($review);
        $this->travelTo(now()->addMinutes(7));

        $this->actingAs($this->recorder)->postJson('/meds/today/prn', [
            'client_medication_id' => $this->medication->id,
            'client_request_uuid' => (string) Str::uuid(),
            'live_recording_context' => $review,
            'administered_at' => '2026-04-30T09:32:00',
            'reason' => 'Pain',
            'effect_check_due_at' => '2026-04-30T10:32:00',
            'amount_mode' => 'as_ordered',
        ])->assertOk()->assertJsonPath('sync.status', 'processed');

        $admin = ClientMedicationAdministration::sole();
        $this->assertSame('2026-04-29 21:32:00', $admin->getRawOriginal('administered_at'));
        $this->assertSame('19.00', $this->stock->fresh()->on_hand);
    }

    /** EA-009: methadone 25 mg from mL stock, pack-tracked, as the dialog sends it. */
    public function test_a_controlled_dose_counted_in_another_stock_unit_is_signed_with_its_pack_waste(): void
    {
        $this->order([
            'name' => 'Synthetic methadone oral liquid', 'dosage' => '25 mg', 'dose_amount' => 25, 'dose_unit' => 'mg',
            'frequency' => 'Daily', 'dose_times' => ['09:30'], 'controlled_drug' => true, 'nz_controlled_class' => 'B',
            'controlled_class_source' => 'Synthetic reviewed test class',
        ], 'mL', '100.00');
        $lot = $this->open(true);

        // The amount taken in the stock unit and each pack's waste; no total.
        $payload = [
            'client_medication_id' => $this->medication->id,
            'scheduled_for' => '2026-04-30T09:30:00+12:00',
            'status' => 'given',
            'administered_at' => '2026-04-30T09:30:00',
            'amount_mode' => 'as_ordered',
            'quantity_administered' => 5,
            'cd_balance' => 95,
            'witnessed_by' => $this->witness->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            'pack_lines' => [['lot_id' => $lot->id, 'revision' => $lot->revision, 'quantity' => '5', 'quantity_wasted' => '0']],
        ];

        // A caller that leaves the total out has it worked out from the packs.
        $this->actingAs($this->recorder)->postJson('/meds/today/record', [...$payload, 'client_request_uuid' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('sync.status', 'processed');

        $admin = ClientMedicationAdministration::sole();
        $this->assertSame('given', $admin->status);
        $this->assertSame('95.00', $this->stock->fresh()->on_hand);
        $this->assertSame('95.00', $lot->fresh()->quantity_remaining);
        $this->assertSame(['administered'], ClientControlledDrugEntry::where('client_medication_administration_id', $admin->id)->pluck('entry_type')->all());
    }

    /** EA-009: per-pack waste, in stock units, is recorded as the dose's witnessed waste. */
    public function test_pack_waste_in_another_stock_unit_is_the_doses_witnessed_waste(): void
    {
        $this->order([
            'name' => 'Synthetic methadone oral liquid', 'dosage' => '25 mg', 'dose_amount' => 25, 'dose_unit' => 'mg',
            'frequency' => 'Daily', 'dose_times' => ['09:30'], 'controlled_drug' => true, 'nz_controlled_class' => 'B',
            'controlled_class_source' => 'Synthetic reviewed test class',
        ], 'mL', '100.00');
        $lot = $this->open(true);

        $base = [
            'client_medication_id' => $this->medication->id,
            'scheduled_for' => '2026-04-30T09:30:00+12:00',
            'status' => 'given',
            'administered_at' => '2026-04-30T09:30:00',
            'amount_mode' => 'as_ordered',
            'quantity_administered' => 6,
            'cd_balance' => 94,
            'witnessed_by' => $this->witness->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            'pack_lines' => [['lot_id' => $lot->id, 'revision' => $lot->revision, 'quantity' => '6', 'quantity_wasted' => '1']],
        ];

        // Waste needs its reason, as every witnessed waste does.
        $this->actingAs($this->recorder)->postJson('/meds/today/record', [...$base, 'client_request_uuid' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonPath('error_field', 'waste_reason');
        $this->assertDatabaseCount('client_medication_administrations', 0);

        $this->postJson('/meds/today/record', [...$base, 'client_request_uuid' => (string) Str::uuid(), 'waste_reason' => 'Spilled while drawing up'])
            ->assertOk();
        $admin = ClientMedicationAdministration::sole();
        $this->assertSame('94.00', $this->stock->fresh()->on_hand);
        $this->assertSame(['administered', 'disposal'], ClientControlledDrugEntry::where('client_medication_administration_id', $admin->id)->pluck('entry_type')->sort()->values()->all());
    }

    /** EA-058: the dialog sends the note; "Other" needs no second field. */
    public function test_withheld_other_saves_with_the_workers_note_as_its_reason(): void
    {
        $this->order([
            'name' => 'Synthetic losartan 50mg', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet',
            'frequency' => 'Daily', 'dose_times' => ['09:30'], 'controlled_drug' => false,
        ], 'tablet', '20.00');

        $payload = [
            'client_medication_id' => $this->medication->id,
            'scheduled_for' => '2026-04-30T09:30:00+12:00',
            'status' => 'withheld',
            'reason_code' => 'other',
            'administered_at' => '2026-04-30T09:30:00',
        ];

        // Other with no note is still refused.
        $this->actingAs($this->recorder)->postJson('/meds/today/record', [...$payload, 'client_request_uuid' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonPath('error_field', 'reason');
        $this->assertDatabaseCount('client_medication_administrations', 0);

        $this->postJson('/meds/today/record', [
            ...$payload,
            'client_request_uuid' => (string) Str::uuid(),
            'notes' => 'GP phoned to hold it until the blood test result is back',
        ])->assertOk()->assertJsonPath('sync.status', 'processed');

        $admin = ClientMedicationAdministration::sole();
        $this->assertSame('withheld', $admin->status);
        $this->assertSame('other', $admin->reason_code);
        $this->assertSame('GP phoned to hold it until the blood test result is back', $admin->reason);
    }

    private function order(array $attributes, string $stockUnit, string $onHand): void
    {
        $this->medication = ClientMedication::create([
            'client_id' => $this->client->id, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
            ...$attributes,
        ]);
        $this->stock = ClientMedicationStock::create(['client_medication_id' => $this->medication->id, 'on_hand' => $onHand, 'unit' => $stockUnit]);
    }

    private function open(bool $controlled): MedicationStockLot
    {
        $this->actingAs($this->recorder->fresh())->postJson('/emar/stock/packs/commands', [
            'action' => 'initialise', 'client_medication_id' => $this->medication->id, 'request_uuid' => (string) Str::uuid(), 'confirm_balance' => true,
            ...($controlled ? ['witnessed_by' => $this->witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN] : []),
        ])->assertOk();

        return MedicationStockLot::where('client_medication_stock_id', $this->stock->id)->sole();
    }

    private function asNeededRequirements()
    {
        return $this->actingAs($this->recorder->fresh())
            ->getJson('/meds/today/prn/'.$this->medication->id.'/requirements')
            ->assertOk();
    }

    private function staff(): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::where('name', 'support_worker')->sole()->id]);
        $keys = ['medications.view', 'medications.stock.update', 'medications.administer.record', 'medications.controlled.view',
            'medications.controlled.record', 'medications.controlled.witness', ControlledRegisterService::MANAGE];
        $permissions = Permission::whereIn('key', $keys)->get();
        $this->assertCount(count($keys), $permissions);
        $user->permissionOverrides()->syncWithoutDetaching($permissions->mapWithKeys(fn ($p) => [$p->id => ['allowed' => $p->key !== ControlledRegisterService::MANAGE]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subYear(), 'end_date' => null, 'is_active' => true]);
        MedicationCompetencyAssessment::create(['user_id' => $user->id, 'assessor_id' => User::factory()->create()->id, 'assessment_type' => 'annual', 'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true, 'can_witness_controlled' => true, 'controlled_drugs' => true, 'restricted' => false, 'not_seen_areas' => []]);
        Shift::factory()->create(['user_id' => $user->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id, 'starts_at' => now()->subHour()->utc(), 'ends_at' => now()->addHours(3)->utc(),
            'actual_starts_at' => now()->subMinutes(30)->utc(), 'actual_ends_at' => null, 'status' => 'in_progress']);
        Cache::flush();

        return $user->fresh();
    }
}
