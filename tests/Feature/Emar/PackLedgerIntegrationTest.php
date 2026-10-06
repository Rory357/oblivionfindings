<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ControlledDrugLossReport;
use App\Models\FleetMedicationTransitLog;
use App\Models\FleetResidentTransport;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDestruction;
use App\Models\MedicationStockCountRecord;
use App\Models\MedicationStockLot;
use App\Models\MedicationStockMovement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Controlled\ControlledRegisterService;
use App\Services\Medication\MedicationOrderLifecycleService;
use App\Services\Medication\Stock\MedicationStockService;
use App\Services\MedicationScanVerificationService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PackLedgerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private ClientMedication $medication;

    private ClientMedicationStock $stock;

    private User $recorder;

    private User $witness;

    private User $lead;

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
        $this->recorder = $this->staff('support_worker');
        $this->witness = $this->staff('support_worker');
        $this->lead = $this->staff('team_lead');
        $this->medication = ClientMedication::create([
            'client_id' => $this->client->id, 'name' => 'Synthetic exact pack medicine', 'dosage' => '1 tablet',
            'frequency' => 'Daily', 'dose_times' => ['09:30'], 'dose_amount' => 1, 'dose_unit' => 'tablet',
            'active' => true, 'state' => 'active', 'approval_status' => 'verified',
            'controlled_drug' => true, 'nz_controlled_class' => 'B', 'controlled_class_source' => 'Synthetic reviewed test class',
        ]);
        $this->stock = ClientMedicationStock::create(['client_medication_id' => $this->medication->id, 'on_hand' => '10.00', 'unit' => 'tablet']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_controlled_opening_requires_current_witness_and_known_unit_before_replay(): void
    {
        $input = $this->opening();
        $this->actingAs($this->recorder)->postJson('/emar/stock/packs/commands', [...$input, 'witness_credential' => 'wrong-pin'])->assertUnprocessable();
        $this->assertNull($this->stock->fresh()->lots_started_at);
        $this->assertDatabaseCount('medication_stock_lots', 0);
        $this->postJson('/emar/stock/packs/commands', $input)->assertOk();
        $lot = $this->lot();
        $this->assertSame('recorded_balance', $lot->source);
        $this->assertNull($lot->received_by);
        $this->assertSame('10.00', $lot->quantity_remaining);
        $this->assertFalse($lot->batch_not_printed);
        $this->assertFalse($lot->expiry_not_printed);
        $before = $this->snapshot();
        $this->postJson('/emar/stock/packs/commands', [...$input, 'witness_credential' => 'wrong-pin'])->assertUnprocessable();
        $before = $this->assertOnlyWitnessFailureRecorded($before);
        $this->postJson('/emar/stock/packs/commands', $input)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_unknown_controlled_opening_unit_does_not_create_pack_or_receipt(): void
    {
        $this->stock->update(['unit' => '']);
        $before = $this->snapshot();
        $this->actingAs($this->recorder)->postJson('/emar/stock/packs/commands', $this->opening())
            ->assertUnprocessable()->assertJsonValidationErrors('unit');
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('medication_stock_lots', 0);
    }

    public function test_multi_pack_controlled_receipt_links_one_register_entry_and_rechecks_witness_on_replay(): void
    {
        $this->open();
        $input = $this->receipt();
        $response = $this->actingAs($this->recorder)->postJson('/emar/stock/packs/commands', $input)->assertOk();
        $entry = ClientControlledDrugEntry::findOrFail($response->json('entry_id'));
        $lots = MedicationStockLot::where('controlled_entry_id', $entry->id)->get();
        $this->assertCount(2, $lots);
        $this->assertSame(['PACK-A', 'PACK-B'], $lots->pluck('batch_number')->sort()->values()->all());
        $this->assertSame('5.00', $entry->quantity);
        $this->assertSame('15.00', $this->stock->fresh()->on_hand);
        $this->assertSame('15.00', $this->physical());
        $this->assertSame(2, MedicationStockMovement::where('controlled_entry_id', $entry->id)->count());
        $before = $this->snapshot();
        $this->postJson('/emar/stock/packs/commands', [...$input, 'witness_credential' => 'wrong-pin'])->assertUnprocessable();
        $before = $this->assertOnlyWitnessFailureRecorded($before);
        $this->postJson('/emar/stock/packs/commands', $input)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame($before, $this->snapshot());
        $this->postJson('/emar/stock/packs/commands', [...$input, 'source_reference' => 'Different synthetic delivery'])->assertUnprocessable()->assertJsonValidationErrors('client_request_uuid');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_bad_second_controlled_pack_rolls_back_register_first_pack_audit_and_receipt(): void
    {
        $this->open();
        $input = $this->receipt();
        $input['packs'][1]['expiry_month'] = '03/2026';
        $before = $this->snapshot();
        $this->actingAs($this->recorder)->postJson('/emar/stock/packs/commands', $input)
            ->assertUnprocessable()->assertJsonValidationErrors('packs.1.expiry_month');
        $this->assertSame($before, $this->snapshot());
        $input['packs'][1]['expiry_month'] = '08/2026';
        $this->postJson('/emar/stock/packs/commands', $input)->assertOk();
        $this->assertSame('15.00', $this->physical());
    }

    public function test_live_controlled_dose_and_waste_use_actual_pack_and_one_canonical_clinical_record(): void
    {
        $lot = $this->open();
        $input = $this->dose($lot);
        $this->actingAs($this->recorder)->postJson('/meds/today/record', $input)->assertOk();
        $admin = ClientMedicationAdministration::sole();
        $entries = ClientControlledDrugEntry::where('client_medication_administration_id', $admin->id)->get();
        $this->assertSame(['administered', 'disposal'], $entries->pluck('entry_type')->sort()->values()->all());
        $moves = MedicationStockMovement::where('administration_id', $admin->id)->get();
        $this->assertSame(['controlled_given', 'controlled_waste'], $moves->pluck('kind')->sort()->values()->all());
        $this->assertSame($entries->modelKeys(), $moves->pluck('controlled_entry_id')->sort()->values()->all());
        $this->assertSame([$lot->id], $moves->pluck('medication_stock_lot_id')->unique()->all());
        $this->assertSame('8.00', $this->physical());
        $this->assertSame('8.00', $this->stock->fresh()->on_hand);
        $before = $this->snapshot();
        $this->postJson('/meds/today/record', $input)->assertOk();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_unusable_actual_controlled_pack_cannot_create_clinical_or_register_evidence(): void
    {
        $this->stock->update(['expiry_date' => '2026-04-29']);
        $lot = $this->open();
        $this->assertSame('10.00', $this->stock->fresh()->on_hand);
        $this->assertSame('0.00', $this->stock->fresh()->usableQuantity());
        $before = $this->snapshot();
        $this->actingAs($this->recorder)->postJson('/meds/today/record', $this->dose($lot))->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
    }

    public function test_stale_pack_revision_rejects_entire_controlled_command(): void
    {
        $lot = $this->open();
        $line = $this->line($lot, 1);
        $this->action('movement', ['movement_type' => 'going_out', 'quantity' => 1, 'actual_balance' => 9, 'pack_lines' => [$line]])->assertOk();
        $before = $this->snapshot();
        $this->action('movement', ['movement_type' => 'going_out', 'quantity' => 1, 'actual_balance' => 8, 'pack_lines' => [$line]])
            ->assertUnprocessable()->assertJsonValidationErrors('pack_lines');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_controlled_zero_return_closes_actual_outward_without_inventing_house_stock(): void
    {
        $lot = $this->open();
        $this->action('movement', ['movement_type' => 'going_out', 'quantity' => 2, 'actual_balance' => 8, 'pack_lines' => [$this->line($lot, 2)]])->assertOk();
        $out = MedicationStockMovement::where('kind', 'controlled_transfer_out')->sole();
        $this->assertSame($out->id, app(MedicationStockService::class)->packOptions($this->medication)['outward'][0]['id']);
        $this->action('movement', ['movement_type' => 'coming_back', 'quantity' => 0, 'actual_balance' => 8,
            'pack_lines' => [[...$this->line($lot->fresh(), 0), 'return_of_id' => $out->id, 'used_away' => 2]]])->assertOk();
        $this->assertSame('8.00', $this->physical());
        $this->assertSame('8.00', $this->stock->fresh()->on_hand);
        $this->assertSame('0.00', $out->returns()->sole()->quantity);
        $this->assertSame([], app(MedicationStockService::class)->packOptions($this->medication)['outward']);
        $before = $this->snapshot();
        $this->action('movement', ['movement_type' => 'coming_back', 'quantity' => 0, 'actual_balance' => 8,
            'pack_lines' => [[...$this->line($lot->fresh(), 0), 'return_of_id' => $out->id, 'used_away' => 2]]])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_counted_controlled_shortfall_loss_keeps_exact_count_and_does_not_deduct_twice(): void
    {
        $lot = $this->open();
        $count = $this->action('count', ['actual_balance' => 8, 'recount_balance' => 8, 'notes' => 'Synthetic count confirmed shortfall',
            'immediate_action_taken' => 'Synthetic stock secured', 'pack_lines' => [$this->line($lot, 8)]])->assertOk();
        $record = MedicationStockCountRecord::sole();
        $this->assertSame($count->json('entry_id'), $record->controlled_entry_id);
        $this->assertSame($this->witness->id, $record->witnessed_by);
        $this->assertSame(1, $record->lines[0]['settled_revision']);
        $this->action('resolve', ['target_id' => $count->json('discrepancy_id'), 'outcome' => 'loss', 'notes' => 'Synthetic independent loss confirmed',
            'immediate_action_taken' => 'Synthetic manager notified'], $this->lead)->assertOk();
        $loss = ControlledDrugLossReport::sole();
        $this->assertSame('2.00', $loss->quantity_lost);
        $this->assertSame('8.00', $this->stock->fresh()->on_hand);
        $this->assertSame('8.00', $this->physical());
        $move = MedicationStockMovement::where('controlled_entry_id', $loss->register_entry_id)->sole();
        $this->assertSame($record->id, $move->stock_count_record_id);
        $this->assertSame($move->balance_before, $move->balance_after);
    }

    public function test_controlled_recount_reconciles_every_pack_with_new_count_evidence(): void
    {
        $lot = $this->open();
        $result = $this->action('count', ['actual_balance' => 8, 'recount_balance' => 8, 'notes' => 'Synthetic initial count',
            'immediate_action_taken' => 'Synthetic packs secured', 'pack_lines' => [$this->line($lot, 8)]])->assertOk();
        $this->action('resolve', ['target_id' => $result->json('discrepancy_id'), 'outcome' => 'recount', 'actual_balance' => 10,
            'notes' => 'Synthetic independent recount', 'pack_lines' => [$this->line($lot->fresh(), 10)]], $this->lead)->assertOk();
        $this->assertDatabaseCount('medication_stock_count_records', 2);
        $this->assertSame('10.00', $this->physical());
        $this->assertSame('10.00', $this->stock->fresh()->on_hand);
    }

    public function test_void_and_correction_retain_original_exact_allocation_with_current_revision_guard(): void
    {
        $lot = $this->open();
        $out = $this->action('movement', ['movement_type' => 'going_out', 'quantity' => 2, 'actual_balance' => 8, 'pack_lines' => [$this->line($lot, 2)]])->assertOk();
        $original = MedicationStockMovement::where('controlled_entry_id', $out->json('entry_id'))->sole();
        $this->action('void', ['target_id' => $out->json('entry_id'), 'notes' => 'Synthetic wrong outward quantity',
            'correction_quantity' => 1, 'correction_direction' => 'out', 'correction_pack_lines' => [$this->line($lot->fresh(), 1)]], $this->lead)->assertOk();
        $this->assertSame('9.00', $this->physical());
        $this->assertSame('9.00', $this->stock->fresh()->on_hand);
        $this->assertSame('2.00', $original->fresh()->quantity);
        $this->assertNotNull($original->reversal()->first());
        $this->assertDatabaseCount('client_controlled_drug_entries', 3);
        $before = $this->snapshot();
        $this->action('void', ['target_id' => $out->json('entry_id'), 'notes' => 'Duplicate synthetic void'], $this->lead)->assertConflict();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_controlled_destruction_retains_pack_and_register_links_for_expired_physical_supply(): void
    {
        $this->stock->update(['expiry_date' => '2026-04-29']);
        $lot = $this->open();
        $result = $this->action('destruction', ['method' => 'pharmacy_return', 'reason' => 'expired', 'quantity' => 2,
            'notes' => 'Synthetic physical return', 'pack_lines' => [$this->line($lot, 2)]], $this->lead)->assertOk();
        $destruction = MedicationDestruction::findOrFail($result->json('target_id'));
        $move = MedicationStockMovement::where('destruction_id', $destruction->id)->sole();
        $this->assertSame($destruction->register_entry_id, $move->controlled_entry_id);
        $this->assertSame($lot->id, $move->medication_stock_lot_id);
        $this->assertSame('8.00', $this->physical());
        $this->assertSame('8.00', $this->stock->fresh()->on_hand);
        $this->assertSame('0.00', $this->stock->fresh()->usableQuantity());
    }

    public function test_started_controlled_supply_disposal_does_not_require_administration_verification(): void
    {
        $lot = $this->open();
        $this->medication->update(['form' => 'tablet']);
        $pending = $this->medication->fresh();
        $this->assertSame('pending_verification', $pending->approval_status);
        $this->assertFalse($pending->isActive());
        $orderBefore = $pending->getRawOriginal();
        $this->actingAs($this->lead)->getJson('/emar/controlled/product')->assertOk()->assertJsonPath('medicines.0.can_destroy', true);
        $this->action('destruction', ['method' => 'pharmacy_return', 'reason' => 'expired', 'quantity' => 2,
            'pack_lines' => [$this->line($lot, 2)]], $this->lead)->assertOk();
        $this->assertSame('8.00', $lot->fresh()->quantity_remaining);
        $this->assertSame('8.00', $this->stock->fresh()->on_hand);
        $this->assertSame($orderBefore, $pending->fresh()->getRawOriginal());
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_destructions', 1);
    }

    public function test_ceased_controlled_supply_can_be_destroyed_with_exact_packs_and_replayed_at_zero(): void
    {
        $lot = $this->open();
        $stopped = $this->cease();
        $orderBefore = $stopped->fresh()->getRawOriginal();
        $this->actingAs($this->lead->fresh())->getJson('/emar/controlled/product')->assertOk()->assertJsonPath('medicines.0.can_destroy', true);
        $request = ['client_medication_id' => $this->medication->id, 'client_request_uuid' => (string) Str::uuid(),
            'expected_balance' => 10, 'expected_entry_id' => null, 'method' => 'pharmacy_return', 'reason' => 'ceased',
            'quantity' => 10, 'notes' => 'Synthetic retained ceased supply returned to pharmacy',
            'witnessed_by' => $this->witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            'pack_lines' => [$this->line($lot, 10)]];
        $response = $this->postJson('/emar/controlled/product/actions/destruction', $request)->assertOk();
        $destruction = MedicationDestruction::findOrFail($response->json('target_id'));
        $movement = MedicationStockMovement::where('destruction_id', $destruction->id)->sole();
        $this->assertSame($lot->id, $movement->medication_stock_lot_id);
        $this->assertSame($destruction->register_entry_id, $movement->controlled_entry_id);
        $this->assertSame('10.00', $movement->quantity);
        $this->assertSame('0.00', $this->physical());
        $this->assertSame('0.00', $this->stock->fresh()->on_hand);
        $this->assertSame($orderBefore, $stopped->fresh()->getRawOriginal());
        $before = $this->snapshot();
        $this->postJson('/emar/controlled/product/actions/destruction', $request)->assertOk();
        $this->assertSame($before, $this->snapshot());
        $this->getJson('/emar/controlled/product')->assertOk()->assertJsonPath('medicines.0.can_destroy', false);
        $this->action('destruction', ['method' => 'pharmacy_return', 'reason' => 'ceased', 'quantity' => 1,
            'pack_lines' => [$this->line($lot->fresh(), 1)]], $this->lead)->assertNotFound();
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('ceasedDestructionDenials')]
    public function test_ceased_pack_destruction_keeps_current_site_witness_and_role_denials(string $denial, int $status): void
    {
        $lot = $this->open();
        $stopped = $this->cease();
        if ($denial === 'site') {
            $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
            $this->lead->hrEmployeeProfile->update(['primary_site_id' => $otherSite->id, 'secondary_site_ids' => []]);
            foreach (['clinical.accessAllSites', 'sites.viewAll'] as $key) {
                $permission = Permission::where('key', $key)->sole();
                $this->lead->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
            }
            Cache::flush();
            $this->assertFalse($this->lead->fresh()->canDo('clinical.accessAllSites'));
            $this->assertFalse($this->lead->fresh()->canDo('sites.viewAll'));
        } elseif ($denial === 'role') {
            $permission = Permission::where('key', 'medications.controlled.record')->sole();
            $this->lead->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
            Cache::flush();
            $this->assertFalse($this->lead->fresh()->canDo('medications.controlled.record'));
        }
        $before = $this->snapshot();
        $orderBefore = $stopped->fresh()->getRawOriginal();
        $this->action('destruction', ['method' => 'pharmacy_return', 'reason' => 'ceased', 'quantity' => 2,
            'witness_credential' => $denial === 'witness' ? 'wrong-pin' : UserFactory::TEST_WITNESS_PIN,
            'pack_lines' => [$this->line($lot, 2)]], $this->lead)->assertStatus($status);
        if ($denial === 'witness') {
            $this->assertOnlyWitnessFailureRecorded($before);
        } else {
            $this->assertSame($before, $this->snapshot());
        }
        $this->assertSame($orderBefore, $stopped->fresh()->getRawOriginal());
        $this->assertDatabaseCount('medication_destructions', 0);
    }

    public function test_ceased_retained_pack_disposal_does_not_authorize_receipt_or_administration(): void
    {
        $lot = $this->open();
        $this->cease();
        $before = $this->snapshot();
        $this->actingAs($this->recorder->fresh())->postJson('/emar/stock/packs/commands', $this->receipt())->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->postJson('/meds/today/record', $this->dose($lot))->assertNotFound();
        $this->assertSame($before, $this->snapshot());
    }

    public static function ceasedDestructionDenials(): array
    {
        return ['wrong approved Site' => ['site', 404], 'invalid current witness' => ['witness', 422], 'revoked recording right' => ['role', 403]];
    }

    public function test_controlled_journey_pack_dose_waste_and_return_never_deduct_house_twice(): void
    {
        $lot = $this->open();
        $log = $this->transit($lot, 6);
        $this->assertSame('4.00', $this->physical());
        $admin = $this->administration();
        DB::transaction(fn () => app(MedicationStockService::class)->transitDose($admin, $this->recorder, 2, $log, $this->witness, 1,
            [[...$this->line($lot->fresh(), 2), 'quantity_wasted' => 1]]));
        $this->assertSame('4.00', $this->physical());
        $this->assertSame('4.00', $this->stock->fresh()->on_hand);
        $entries = ClientControlledDrugEntry::where('client_medication_administration_id', $admin->id)->get();
        $this->assertSame(['transit'], $entries->pluck('stock_balance_scope')->unique()->all());
        $this->assertSame(['administered', 'disposal'], $entries->pluck('entry_type')->sort()->values()->all());
        DB::transaction(fn () => app(MedicationStockService::class)->returnTransit($log, $this->recorder, ['return_lines' => [['lot_id' => $lot->id, 'quantity' => 4, 'missing' => 0]]], $this->witness));
        $this->assertSame('8.00', $this->physical());
        $this->assertSame('8.00', $this->stock->fresh()->on_hand);
        $this->assertTrue(app(MedicationStockService::class)->transitReconciled($log));
        $this->assertSame('complete', $log->fresh()->stock_reconciliation_status);
        $this->assertSame('2.00', DB::table('medication_stock_transit_allocations')->where('transit_log_id', $log->id)->sole()->quantity_used);
        $this->assertSame(1, MedicationStockMovement::where('kind', 'transit_return')->where('return_of_id', MedicationStockMovement::where('kind', 'transit_out')->sole()->id)->count());
    }

    public function test_controlled_journey_shortfall_retains_loss_evidence_without_second_house_deduction(): void
    {
        $lot = $this->open();
        $log = $this->transit($lot, 6);
        DB::transaction(fn () => app(MedicationStockService::class)->returnTransit($log, $this->recorder, [
            'return_lines' => [['lot_id' => $lot->id, 'quantity' => 4, 'missing' => 2]],
            'reconciliation_reason' => 'Synthetic missing journey units', 'immediate_action_taken' => 'Synthetic bags secured and manager told',
        ], $this->witness));
        $this->assertSame('8.00', $this->physical());
        $this->assertSame('8.00', $this->stock->fresh()->on_hand);
        $this->assertFalse(app(MedicationStockService::class)->transitReconciled($log));
        $this->assertSame('shortfall', $log->fresh()->stock_reconciliation_status);
        $loss = ControlledDrugLossReport::findOrFail($log->fresh()->stock_loss_report_id);
        $this->assertSame('2.00', $loss->quantity_lost);
        $entry = ClientControlledDrugEntry::findOrFail($loss->register_entry_id);
        $this->assertSame('transit', $entry->stock_balance_scope);
        $this->assertSame($log->id, $entry->transit_log_id);
        $move = MedicationStockMovement::where('kind', 'transit_loss')->sole();
        $this->assertSame($entry->id, $move->controlled_entry_id);
        $this->assertSame($move->balance_before, $move->balance_after);
    }

    public function test_ordinary_given_and_explicit_waste_have_separate_retained_pack_movements(): void
    {
        $this->ordinaryOrder();
        $lot = $this->open(false);
        $admin = $this->administration(false);
        DB::transaction(fn () => app(MedicationStockService::class)->ordinaryDose($admin, $this->recorder, 1, 1, 'Synthetic damaged remainder'));
        $moves = MedicationStockMovement::where('administration_id', $admin->id)->get();
        $this->assertSame(['given', 'waste'], $moves->pluck('kind')->sort()->values()->all());
        $this->assertSame(['1.00', '1.00'], $moves->pluck('quantity')->all());
        $this->assertSame('8.00', $lot->fresh()->quantity_remaining);
        $this->assertSame('8.00', $this->stock->fresh()->availableQuantity());
    }

    #[DataProvider('clinicalStockTypes')]
    public function test_canonical_journey_endpoints_consume_exact_custody_and_return_the_remainder(bool $controlled): void
    {
        if (! $controlled) {
            $this->ordinaryOrder();
        }
        $lot = $this->open($controlled);
        $transport = FleetResidentTransport::create(['asset_id' => Asset::factory()->vehicle()->forSite($this->site)->create()->id,
            'site_id' => $this->site->id, 'resident_id' => $this->client->id, 'resident_name' => $this->client->full_name,
            'driver_user_id' => $this->recorder->id, 'transport_type' => 'medical', 'status' => 'in_progress', 'departed_at' => now()->subMinute()]);
        $code = app(MedicationScanVerificationService::class)->internalCode($this->client, $this->medication);
        $scan = ['scan_code' => $code, 'scan_source' => 'manual', 'scan_verified' => true];
        $witness = ['witnessed_by_user_id' => $this->witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN];
        $this->actingAs($this->recorder)->postJson('/fleet-assets/transports/'.$transport->id.'/pack-medication', [
            'client_id' => $this->client->id, 'medication_id' => $this->medication->id, 'attestation_state' => 'accepted',
            'medication_name' => $this->medication->name, 'is_controlled_drug' => $this->medication->controlled_drug,
            'pack_lines' => [$this->line($lot, 6)], ...$scan, ...$witness,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $log = FleetMedicationTransitLog::sole();
        $this->assertSame('4.00', $this->stock->fresh()->on_hand);
        $this->postJson('/fleet-assets/medication-transit/'.$log->id.'/administer', [
            'quantity_administered' => 2, 'quantity_unit' => 'tablet', 'quantity_given' => 1, 'quantity_wasted' => 1,
            'waste_reason' => 'Synthetic witnessed journey remainder', 'amount_mode' => 'as_ordered',
            'pack_lines' => [[...$this->line($lot->fresh(), 2), 'quantity_wasted' => 1]], ...$scan, ...$witness,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($log->fresh()->medication_administration_id);
        $this->assertSame('4.00', $this->stock->fresh()->on_hand);
        $this->get('/fleet-assets/transports/medications')->assertInertia(fn ($page) => $page
            ->where('stats.awaiting_return', 1)->where('stats.controlled_drugs_out', $controlled ? 1 : 0));
        $this->postJson('/fleet-assets/medication-transit/'.$log->id.'/return', [
            'return_lines' => [['lot_id' => $lot->id, 'quantity' => 4, 'missing' => 0]], ...$scan, ...$witness,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('8.00', $this->stock->fresh()->on_hand);
        $this->assertSame('8.00', $this->physical());
        $this->assertSame('complete', $log->fresh()->stock_reconciliation_status);
        $this->assertNotNull($log->fresh()->returned_to_house_at);
        $this->get('/fleet-assets/transports/medications')->assertInertia(fn ($page) => $page
            ->where('stats.awaiting_return', 0)->where('stats.controlled_drugs_out', 0));
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_declared_controlled_transit_shortfall_allows_completion_while_loss_stays_open(): void
    {
        $lot = $this->open();
        $transport = FleetResidentTransport::create(['asset_id' => Asset::factory()->vehicle()->forSite($this->site)->create()->id,
            'site_id' => $this->site->id, 'resident_id' => $this->client->id, 'resident_name' => $this->client->full_name,
            'driver_user_id' => $this->recorder->id, 'transport_type' => 'medical', 'status' => 'in_progress', 'departed_at' => now()->subMinute()]);
        $scan = ['scan_code' => app(MedicationScanVerificationService::class)->internalCode($this->client, $this->medication),
            'scan_source' => 'manual', 'scan_verified' => true];
        $witness = ['witnessed_by_user_id' => $this->witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN];
        $this->actingAs($this->recorder->fresh())->postJson('/fleet-assets/transports/'.$transport->id.'/pack-medication', [
            'client_id' => $this->client->id, 'medication_id' => $this->medication->id, 'attestation_state' => 'accepted',
            'medication_name' => $this->medication->name, 'is_controlled_drug' => $this->medication->controlled_drug,
            'pack_lines' => [$this->line($lot, 6)], ...$scan, ...$witness,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $log = FleetMedicationTransitLog::sole();
        // Before a declared return, actual custody still blocks completion.
        $this->get('/fleet-assets/transports/'.$transport->id)->assertInertia(fn ($page) => $page
            ->where('completion_blockers.0.type', 'unresolved_medications')->where('completion_blockers.0.count', 1));
        $this->postJson('/fleet-assets/medication-transit/'.$log->id.'/return', [
            'return_lines' => [['lot_id' => $lot->id, 'quantity' => 4, 'missing' => 2]],
            'reconciliation_reason' => 'Synthetic two units missing from retained custody',
            'immediate_action_taken' => 'Synthetic house lead informed and physical packs secured', ...$scan, ...$witness,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('shortfall', $log->fresh()->stock_reconciliation_status);
        $loss = ControlledDrugLossReport::findOrFail($log->fresh()->stock_loss_report_id);
        $lossBefore = $loss->getRawOriginal();
        $this->assertSame('reported', $loss->investigation_status);
        $this->assertSame('8.00', $this->physical());
        $this->assertSame('8.00', $this->stock->fresh()->on_hand);
        $this->get('/fleet-assets/transports/'.$transport->id)->assertInertia(fn ($page) => $page->where('completion_blockers', []));
        $this->get('/fleet-assets/transports/medications?status=packed')->assertInertia(fn ($page) => $page
            ->where('stats.awaiting_return', 1)->where('logs.data.0.stock_reconciliation_status', 'shortfall'));
        $this->post('/fleet-assets/transports/'.$transport->id.'/complete', ['client_request_uuid' => (string) Str::uuid()])
            ->assertRedirect('/fleet-assets/transports/'.$transport->id)->assertSessionHas('success')->assertSessionMissing('error');
        $this->assertSame('completed', $transport->fresh()->status);
        $this->assertSame($lossBefore, $loss->fresh()->getRawOriginal());
        $this->assertSame('8.00', $this->physical());
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_another_orders_pack_is_concealed_without_any_controlled_effect(): void
    {
        $lot = $this->open();
        $foreign = $this->medication->replicate()->forceFill(['name' => 'Synthetic unrelated order']);
        $foreign->save();
        $foreignStock = ClientMedicationStock::create(['client_medication_id' => $foreign->id, 'on_hand' => 5, 'unit' => 'tablet']);
        DB::transaction(fn () => app(MedicationStockService::class)->startLots($foreignStock, $this->recorder, (string) Str::uuid()));
        $foreignLot = MedicationStockLot::where('client_medication_stock_id', $foreignStock->id)->sole();
        $before = $this->snapshot();
        $this->action('movement', ['movement_type' => 'going_out', 'quantity' => 1, 'actual_balance' => 9,
            'pack_lines' => [$this->line($foreignLot, 1)]])->assertNotFound();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('10.00', $lot->fresh()->quantity_remaining);
        $this->assertSame('5.00', $foreignLot->fresh()->quantity_remaining);
    }

    public static function clinicalStockTypes(): array
    {
        return ['ordinary' => [false], 'controlled' => [true]];
    }

    private function ordinaryOrder(): void
    {
        // Construct a verified ordinary fixture; changing a live CD order would
        // correctly invalidate its clinical verification.
        $this->medication = ClientMedication::create([
            'client_id' => $this->client->id, 'name' => 'Synthetic ordinary exact pack medicine', 'dosage' => '1 tablet',
            'frequency' => 'Daily', 'dose_times' => ['09:30'], 'dose_amount' => 1, 'dose_unit' => 'tablet',
            'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'controlled_drug' => false,
        ]);
        $this->stock->update(['client_medication_id' => $this->medication->id]);
        $this->stock->unsetRelation('medication');
        $this->assertTrue($this->medication->isAdministrable());
    }

    private function cease(): ClientMedication
    {
        $permission = Permission::where('key', 'medications.orders.manage')->sole();
        $this->lead->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        Cache::flush();
        $this->actingAs($this->lead->fresh());
        $stopped = app(MedicationOrderLifecycleService::class)->discontinue(
            $this->lead->fresh(), $this->medication, 'Synthetic canonical cessation with retained packs', $this->client->id,
        );
        $this->assertSame('ceased', $stopped->state);
        $this->assertFalse($stopped->active);
        $this->assertNull($stopped->deleted_at);
        $this->assertNull($stopped->superseded_by);

        return $stopped;
    }

    private function opening(bool $controlled = true): array
    {
        return ['action' => 'initialise', 'client_medication_id' => $this->medication->id, 'request_uuid' => (string) Str::uuid(), 'confirm_balance' => true,
            ...($controlled ? ['witnessed_by' => $this->witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN] : [])];
    }

    private function open(bool $controlled = true): MedicationStockLot
    {
        $this->actingAs($this->recorder->fresh())->postJson('/emar/stock/packs/commands', $this->opening($controlled))->assertOk();

        return $this->lot();
    }

    private function lot(): MedicationStockLot
    {
        return MedicationStockLot::where('client_medication_stock_id', $this->stock->id)->sole();
    }

    private function line(MedicationStockLot $lot, int|float|string $quantity): array
    {
        return ['lot_id' => $lot->id, 'revision' => $lot->revision, 'quantity' => $quantity];
    }

    private function receipt(): array
    {
        return ['action' => 'receive', 'client_medication_id' => $this->medication->id, 'request_uuid' => (string) Str::uuid(), 'label_checked' => true,
            'source' => 'pharmacy', 'source_reference' => 'Synthetic exact delivery', 'witnessed_by' => $this->witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            'packs' => array_map(fn ($batch, $quantity) => ['quantity' => $quantity, 'batch_number' => $batch, 'batch_not_printed' => false,
                'expiry_month' => '08/2026', 'expiry_not_printed' => false], ['PACK-A', 'PACK-B'], [2, 3])];
    }

    private function dose(MedicationStockLot $lot): array
    {
        return ['client_medication_id' => $this->medication->id, 'client_request_uuid' => (string) Str::uuid(),
            'scheduled_for' => '2026-04-30T09:30:00+12:00', 'status' => 'given', 'amount_mode' => 'as_ordered',
            'quantity_given' => 1, 'quantity_administered' => 2, 'quantity_wasted' => 1, 'waste_reason' => 'Synthetic witnessed remainder',
            'cd_balance' => 8, 'witnessed_by' => $this->witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            'pack_lines' => [[...$this->line($lot, 2), 'quantity_wasted' => 1]]];
    }

    private function action(string $action, array $fields, ?User $actor = null)
    {
        $input = ['client_medication_id' => $this->medication->id, 'client_request_uuid' => (string) Str::uuid(),
            'expected_balance' => $this->stock->fresh()->on_hand, 'expected_entry_id' => ClientControlledDrugEntry::where('client_medication_id', $this->medication->id)->latest('id')->value('id'),
            'witnessed_by' => $this->witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN, ...$fields];

        return $this->actingAs(($actor ?? $this->recorder)->fresh())->postJson('/emar/controlled/product/actions/'.$action, $input);
    }

    private function physical(): string
    {
        return DB::transaction(fn () => app(MedicationStockService::class)->physicalQuantity($this->stock));
    }

    /** A rejected credential retains only its security audit; no clinical or stock effects survive. */
    private function assertOnlyWitnessFailureRecorded(array $before): array
    {
        $expected = [...$before, 'audits' => $before['audits'] + 1];
        $after = $this->snapshot();
        $this->assertSame($expected, $after);
        $audit = AuditLog::latest('id')->firstOrFail();
        $this->assertSame('medications.witness_pin.failed', $audit->action);
        $this->assertSame($this->witness->id, (int) $audit->meta['witness_user_id']);

        return $after;
    }

    private function snapshot(): array
    {
        return ['stock' => $this->stock->fresh()->getRawOriginal(),
            'lots' => MedicationStockLot::orderBy('id')->get()->map->getRawOriginal()->all(),
            'movements' => MedicationStockMovement::count(), 'entries' => ClientControlledDrugEntry::count(), 'doses' => ClientMedicationAdministration::count(),
            'audits' => AuditLog::count(), 'receipts' => DB::table('medication_idempotency_results')->count(),
            'controlled_requests' => DB::table('controlled_product_requests')->count(),
            'destructions' => MedicationDestruction::count(), 'events' => DB::table('medication_events')->count()];
    }

    private function transit(MedicationStockLot $lot, int $quantity): FleetMedicationTransitLog
    {
        $transport = FleetResidentTransport::create(['asset_id' => Asset::factory()->vehicle()->forSite($this->site)->create()->id,
            'site_id' => $this->site->id, 'resident_id' => $this->client->id, 'resident_name' => $this->client->full_name,
            'driver_user_id' => $this->recorder->id, 'transport_type' => 'medical', 'status' => 'in_progress', 'departed_at' => now()->subMinute()]);
        $log = FleetMedicationTransitLog::create(['transport_id' => $transport->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'medication_id' => $this->medication->id, 'medication_name' => $this->medication->name, 'is_controlled_drug' => $this->medication->controlled_drug,
            'packed_by_user_id' => $this->recorder->id, 'packed_at' => now(), 'packed_witnessed_by_user_id' => $this->witness->id]);
        DB::transaction(fn () => app(MedicationStockService::class)->packTransit($log, $this->recorder, ['pack_lines' => [$this->line($lot, $quantity)]], $this->witness));

        return $log;
    }

    private function administration(bool $controlled = true): ClientMedicationAdministration
    {
        return ClientMedicationAdministration::create(['client_id' => $this->client->id, 'client_medication_id' => $this->medication->id,
            'service_context_id' => $this->client->service_context_id, 'administered_by' => $this->recorder->id,
            'witnessed_by' => $controlled ? $this->witness->id : null, 'administered_at' => now(), 'status' => 'given', 'dose_given' => '1 tablet']);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::where('name', $role)->sole()->id]);
        $keys = ['fleet.viewAny', 'fleet.medication.manage', 'medications.view', 'medications.stock.update', 'medications.administer.record', 'medications.controlled.view',
            'medications.controlled.record', 'medications.controlled.witness', ControlledRegisterService::MANAGE];
        $permissions = Permission::whereIn('key', $keys)->get();
        $this->assertCount(count($keys), $permissions);
        $user->permissionOverrides()->syncWithoutDetaching($permissions->mapWithKeys(fn ($p) => [$p->id => ['allowed' => $p->key !== ControlledRegisterService::MANAGE || $role === 'team_lead']])->all());
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
