<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BreakGlassPolicy;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationAdminRule;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDoseSlot;
use App\Models\MedicationDowntime;
use App\Models\MedicationEvent;
use App\Models\MedicationPaperEntry;
use App\Models\MedicationPaperStockEvidence;
use App\Models\MedicationStockCountRecord;
use App\Models\MedicationStockLot;
use App\Models\MedicationStockMovement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Downtime\DowntimeEvents;
use App\Services\Medication\Downtime\DowntimeService;
use App\Services\Medication\Downtime\PaperDoseFacts;
use App\Services\Medication\Downtime\PaperEntryService;
use App\Services\Medication\EmergencyAccess\EmergencyAccessService;
use App\Services\Medication\Recording\LiveRecordingContext;
use App\Services\Medication\Stock\MedicationStockService;
use App\Support\Medication\MedicationStockQuantity;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MedicationHistoricalRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $giver;

    private User $reviewer;

    private ClientMedication $order;

    private ClientMedicationStock $stock;

    private MedicationStockLot $lot;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 00:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->giver = $this->staff('team_lead');
        $this->reviewer = $this->staff('clinical_lead');
        $this->order = ClientMedication::factory()->create(['client_id' => $this->client->id, 'name' => 'Recovery medicine', 'dosage' => '10 mg', 'dose_amount' => 10, 'dose_unit' => 'mg',
            'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'verified_at' => now(), 'start_date' => '2026-10-01', 'end_date' => null,
            'dose_times' => ['09:00'], 'route' => 'oral', 'frequency' => 'Once daily', 'is_prn' => false, 'controlled_drug' => false, 'witness_required' => false]);
        $this->stock = ClientMedicationStock::forceCreate(['client_medication_id' => $this->order->id, 'on_hand' => 100, 'unit' => 'mg', 'lots_started_at' => now()->subDay()]);
        $this->lot = MedicationStockLot::create(['client_medication_stock_id' => $this->stock->id, 'batch_number' => 'PAPER-A', 'expiry_date' => '2027-09-30',
            'quantity_received' => 100, 'quantity_remaining' => 100, 'source' => 'delivery', 'received_by' => $this->reviewer->id, 'received_at' => now()->subDay(), 'revision' => 0, 'state' => 'open']);
        Carbon::setTestNow(Carbon::parse('2026-10-03 12:00', 'Pacific/Auckland')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function recoverableKinds(): array
    {
        return ['scheduled' => ['scheduled'], 'as-needed' => ['prn'], 'rule with reading' => ['rule'], 'ordinary witness-required' => ['witness'],
            'less and waste' => ['less'], 'controlled' => ['controlled'], 'controlled less and waste' => ['controlled_less']];
    }

    #[DataProvider('recoverableKinds')]
    public function test_exact_paper_facts_post_once_through_shared_clinical_and_pack_writer(string $kind): void
    {
        $this->assertSame('10.0000', $this->order->fresh()->dose_amount);
        $controlled = str_starts_with($kind, 'controlled');
        $less = in_array($kind, ['less', 'controlled_less'], true);
        ClientMedication::withoutEvents(fn () => $this->order->update(['controlled_drug' => $controlled, 'witness_required' => $kind === 'witness', 'is_prn' => $kind === 'prn']));
        if ($kind === 'rule') {
            MedicationAdminRule::create(['match_type' => 'medicine_name', 'match_value' => 'Recovery', 'requires_countersign' => true, 'required_observations' => ['pulse'], 'active' => true]);
        }
        $this->cover($this->giver);
        $witness = ($controlled || $less || in_array($kind, ['rule', 'witness'], true)) ? $this->staff('support_worker') : null;
        if ($witness) {
            $this->cover($witness);
            if (! $controlled) {
                $this->deny($witness, 'medications.controlled.witness');
            }
        }
        $downtime = $this->downtime();
        $entry = $this->capture($downtime, ['witness_id' => $witness?->id, 'observations' => $kind === 'rule' ? ['pulse_bpm' => 72] : [],
            'clinical_facts' => ['quantity_given' => $less ? 8 : 10, 'amount_mode' => $less ? 'less' : 'as_ordered', 'amount_reason' => $less ? 'part_taken' : null,
                'prn_reason' => $kind === 'prn' ? 'Signed headache indication' : null, 'effect_check_due_at' => $kind === 'prn' ? '2026-10-03T10:00' : null],
            'stock_evidence' => $this->physical($less ? 2 : 0)]);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
        if ($witness) {
            $this->actingAs($witness)->post($this->url($entry).'/confirm', ['kind' => 'witness', 'accountable_confirmation' => true, 'witness_pin' => UserFactory::TEST_WITNESS_PIN])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->settle($entry);
        $before = $entry->getRawOriginal();
        $preview = $this->actingAs($this->giver)->getJson($this->url($entry).'/reconciliation')->assertOk()->assertJsonPath('can_reconcile', true)->json();
        $this->actingAs($this->giver)->post($this->url($entry).'/reconcile', ['preview_token' => $preview['preview_token'], 'accountable_confirmation' => true])->assertRedirect()->assertSessionHasNoErrors();
        $admin = ClientMedicationAdministration::sole();
        $this->assertSame($entry->request_uuid, $admin->client_request_uuid);
        $this->assertSame($this->giver->id, $admin->administered_by);
        $this->assertSame('given', $admin->status);
        $this->assertSame($less ? '8.00' : '10.00', $admin->quantity_given);
        $this->assertTrue($admin->administered_at->equalTo($entry->given_at));
        $this->assertSame('90.00', $this->lot->fresh()->quantity_remaining);
        $this->assertSame('90.00', $this->stock->fresh()->on_hand);
        $moves = MedicationStockMovement::where('administration_id', $admin->id)->get();
        $this->assertCount($less ? 2 : 1, $moves);
        $this->assertSame('10.00', $moves->reduce(fn ($sum, $move) => MedicationStockQuantity::add($sum, $move->quantity), '0.00'));
        if ($controlled) {
            $this->assertDatabaseCount('client_controlled_drug_entries', $less ? 2 : 1);
            $this->assertNotNull($moves->first()->controlled_entry_id);
        } else {
            $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        }
        if ($witness) {
            $this->assertSame($witness->id, $admin->witnessed_by);
            $this->assertSame('historical_paper_own_pin', $admin->witness_method);
            $this->assertSame('verified', $admin->second_person_status);
        }
        $counts = [MedicationStockMovement::count(), MedicationEvent::count(), DB::table('medication_idempotency_results')->count()];
        $this->actingAs($this->giver)->post($this->url($entry).'/reconcile', ['preview_token' => $preview['preview_token'], 'accountable_confirmation' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_paper_postings', 1);
        $this->assertSame($counts, [MedicationStockMovement::count(), MedicationEvent::count(), DB::table('medication_idempotency_results')->count()]);
        $this->assertSame($before, $entry->fresh()->getRawOriginal());
    }

    public function test_fractional_clinical_amount_and_different_physical_unit_post_exactly_without_conversion(): void
    {
        $this->cover($this->giver);
        ClientMedication::withoutEvents(fn () => $this->order->update(['dose_amount' => '0.25', 'dosage' => '0.25 mg']));
        $this->stock->forceFill(['unit' => 'tablets'])->saveFromPackLedger();
        $this->assertSame('0.2500', $this->order->fresh()->dose_amount);
        $physical = ['stock_id' => $this->stock->id, 'unit' => 'tablets', 'quantity_removed' => 1, 'quantity_wasted' => 0,
            'lines' => [['lot_id' => $this->lot->id, 'quantity' => 1, 'quantity_wasted' => 0]]];
        $entry = $this->capture($this->downtime(), [
            'dose_on_paper' => '0.25 mg', 'clinical_facts' => ['quantity_given' => '0.25', 'amount_mode' => 'as_ordered'],
            'stock_evidence' => $physical,
        ]);
        $this->assertSame('0.25', $entry->clinical_facts['quantity_given']);
        $this->assertSame('1.00', $entry->stock_evidence['quantity_removed']);
        $this->assertSame('tablets', $entry->stock_evidence['unit']);
        $factsBefore = $entry->getRawOriginal();
        $this->settle($entry, closingQuantity: 99);
        $this->postPaper($entry);
        $admin = ClientMedicationAdministration::sole();
        $this->assertSame('0.25', $admin->quantity_given);
        $this->assertSame('0.25 mg', $admin->dose_given);
        $this->assertSame($entry->request_uuid, $admin->client_request_uuid);
        $move = MedicationStockMovement::where('administration_id', $admin->id)->sole();
        $this->assertSame('1.00', $move->quantity);
        $this->assertSame($this->lot->id, $move->medication_stock_lot_id);
        $this->assertSame('99.00', $this->stock->fresh()->on_hand);
        $this->assertSame('99.00', $this->lot->fresh()->quantity_remaining);
        $counts = [MedicationEvent::count(), DB::table('medication_idempotency_results')->count()];
        $this->postPaper($entry);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_paper_postings', 1);
        $this->assertDatabaseCount('medication_stock_movements', 1);
        $this->assertSame($counts, [MedicationEvent::count(), DB::table('medication_idempotency_results')->count()]);
        $this->assertSame($factsBefore, $entry->fresh()->getRawOriginal());
        $this->assertSame('99.00', $this->stock->fresh()->on_hand);
    }

    public function test_clinical_amount_beyond_canonical_precision_is_rejected_without_rounding(): void
    {
        $this->cover($this->giver);
        ClientMedication::withoutEvents(fn () => $this->order->update(['dose_amount' => '0.125', 'dosage' => '0.125 mg']));
        $this->stock->forceFill(['unit' => 'tablets'])->saveFromPackLedger();
        $downtime = $this->downtime();
        $orderBefore = $this->order->fresh()->getRawOriginal();
        $stockBefore = $this->stock->fresh()->getRawOriginal();
        $lotBefore = $this->lot->fresh()->getRawOriginal();
        $this->assertSame('0.1250', $this->order->fresh()->dose_amount);
        $this->actingAs($this->giver)->postJson('/emar/downtime/'.$downtime->id.'/paper/preview', [
            'client_medication_id' => $this->order->id, 'downtime_dose_id' => $downtime->doses()->firstOrFail()->id,
            'given_at' => '2026-10-03T09:10', 'given_by' => $this->giver->id, 'outcome' => 'given', 'dose_on_paper' => '0.125 mg',
            'clinical_facts' => ['quantity_given' => '0.125', 'amount_mode' => 'as_ordered'],
            'stock_evidence' => ['stock_id' => $this->stock->id, 'unit' => 'tablets', 'quantity_removed' => 1, 'quantity_wasted' => 0,
                'lines' => [['lot_id' => $this->lot->id, 'quantity' => 1, 'quantity_wasted' => 0]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('clinical_facts.quantity_given');
        $this->assertSame($orderBefore, $this->order->fresh()->getRawOriginal());
        $this->assertSame($stockBefore, $this->stock->fresh()->getRawOriginal());
        $this->assertSame($lotBefore, $this->lot->fresh()->getRawOriginal());
        $this->assertDatabaseCount('medication_paper_entries', 0);
        $this->assertDatabaseCount('medication_paper_stock_evidence', 0);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
    }

    public function test_covering_reviewed_count_settles_once_without_second_deduction(): void
    {
        $this->cover($this->giver);
        $entry = $this->capture($this->downtime());
        $count = DB::transaction(function () use ($entry) {
            $service = app(MedicationStockService::class);
            $count = $service->count($this->stock, $this->reviewer, ['request_uuid' => (string) Str::uuid(), 'reason' => 'Paper removals included in physical closing count',
                'lines' => [['lot_id' => $this->lot->id, 'revision' => 0, 'quantity' => 90]], 'coverage_start' => '2026-10-03T08:30:00+13:00', 'coverage_end' => '2026-10-03T10:30:00+13:00', 'covered_paper_entry_ids' => [$entry->id]]);
            $service->reviewCount($this->stock, $count, $this->reviewer, 'Checked signed paper against actual packs', (string) Str::uuid());

            return $count->fresh();
        });
        $this->settle($entry, 'covered_by_count', $count->id);
        $before = $this->lot->fresh()->getRawOriginal();
        $this->postPaper($entry);
        $this->assertSame($before, $this->lot->fresh()->getRawOriginal());
        $this->assertSame('90.00', $this->stock->fresh()->on_hand);
        $move = MedicationStockMovement::whereNotNull('administration_id')->sole();
        $this->assertSame('90.00', $move->balance_before);
        $this->assertSame('90.00', $move->balance_after);
        $this->assertSame($count->id, $move->stock_count_record_id);
    }

    public function test_controlled_covering_witnessed_count_posts_zero_delta_history_without_reducing_stock_twice(): void
    {
        ClientMedication::withoutEvents(fn () => $this->order->update(['controlled_drug' => true]));
        $this->cover($this->giver);
        $paperWitness = $this->staff('support_worker');
        $this->cover($paperWitness);
        $entry = $this->capture($this->downtime(), ['witness_id' => $paperWitness->id]);
        $this->actingAs($paperWitness)->post($this->url($entry).'/confirm', ['kind' => 'witness', 'accountable_confirmation' => true, 'witness_pin' => UserFactory::TEST_WITNESS_PIN])
            ->assertRedirect()->assertSessionHasNoErrors();
        $countWitness = $this->staff('support_worker');
        foreach ([$this->reviewer, $countWitness] as $present) {
            Shift::factory()->create(['user_id' => $present->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
                'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(2), 'actual_starts_at' => now()->subHour(), 'status' => 'in_progress']);
        }
        $response = $this->actingAs($this->reviewer)->postJson('/emar/controlled/product/actions/count', [
            'client_medication_id' => $this->order->id, 'client_request_uuid' => (string) Str::uuid(), 'expected_entry_id' => null,
            'expected_balance' => 100, 'actual_balance' => 90, 'recount_balance' => 90,
            'witnessed_by' => $countWitness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            'notes' => 'Exact signed paper removal included in physical count', 'immediate_action_taken' => 'Both staff checked retained paper and exact pack',
            'pack_lines' => [['lot_id' => $this->lot->id, 'revision' => 0, 'quantity' => 90]],
            'coverage_start' => '2026-10-03T08:30:00+13:00', 'coverage_end' => '2026-10-03T10:30:00+13:00', 'covered_paper_entry_ids' => [$entry->id],
        ])->assertOk();
        $count = MedicationStockCountRecord::where('controlled_entry_id', $response->json('entry_id'))->sole();
        $this->assertSame($countWitness->id, $count->witnessed_by);
        $this->assertSame([$entry->id], $count->covered_paper_entry_ids);
        $this->settle($entry, 'covered_by_count', $count->id);
        $before = $this->lot->fresh()->getRawOriginal();
        $this->postPaper($entry);
        $this->assertSame($before, $this->lot->fresh()->getRawOriginal());
        $this->assertSame('90.00', $this->stock->fresh()->on_hand);
        $admin = ClientMedicationAdministration::sole();
        $register = ClientControlledDrugEntry::where('client_medication_administration_id', $admin->id)->sole();
        $this->assertSame('counted_history', $register->stock_balance_scope);
        $this->assertSame($count->id, $register->stock_count_record_id);
        $this->assertSame('90.00', $register->on_hand_before);
        $this->assertSame('90.00', $register->on_hand_after);
        $move = MedicationStockMovement::where('administration_id', $admin->id)->sole();
        $this->assertSame($register->id, $move->controlled_entry_id);
        $this->assertSame($count->id, $move->stock_count_record_id);
        $this->assertSame('90.00', $move->balance_before);
        $this->assertSame('90.00', $move->balance_after);
        $preview = app(PaperEntryService::class)->reconciliationPreview($this->giver, $entry->downtime, $entry);
        $result = app(PaperEntryService::class)->reconcile($this->giver, $entry->downtime, $entry, $preview['preview_token']);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['duplicate']);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 2);
        $this->assertDatabaseCount('medication_stock_movements', 2);
    }

    public function test_unrelated_count_and_stale_closing_quantity_cannot_authorize_a_paper_removal(): void
    {
        $this->cover($this->giver);
        $entry = $this->capture($this->downtime());
        $count = MedicationStockCountRecord::create(['client_medication_stock_id' => $this->stock->id, 'request_uuid' => (string) Str::uuid(), 'state' => 'counted',
            'counted_by' => $this->reviewer->id, 'counted_at' => now(), 'lines' => [['lot_id' => $this->lot->id, 'revision' => 0, 'expected' => '100.00', 'counted' => '100.00']]]);
        $this->actingAs($this->reviewer)->postJson($this->url($entry).'/stock-evidence', ['request_uuid' => (string) Str::uuid(), 'settlement' => 'covered_by_count', 'closing_count_id' => $count->id, 'accountable_confirmation' => true,
            'lines' => [['lot_id' => $this->lot->id, 'revision' => 0, 'closing_quantity' => 100]]])->assertUnprocessable();
        $this->actingAs($this->reviewer)->postJson($this->url($entry).'/stock-evidence', ['request_uuid' => (string) Str::uuid(), 'settlement' => 'deduct_now', 'accountable_confirmation' => true,
            'lines' => [['lot_id' => $this->lot->id, 'revision' => 0, 'closing_quantity' => 99]]])->assertUnprocessable();
        $this->assertDatabaseCount('medication_paper_stock_evidence', 0);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame('100.00', $this->lot->fresh()->quantity_remaining);
    }

    public function test_settlement_has_current_authority_and_immutable_actual_pack_binding(): void
    {
        $this->cover($this->giver);
        $entry = $this->capture($this->downtime());
        $worker = $this->staff('support_worker');
        $this->actingAs($worker)->postJson($this->url($entry).'/stock-evidence', ['request_uuid' => (string) Str::uuid(), 'settlement' => 'deduct_now', 'accountable_confirmation' => true,
            'lines' => [['lot_id' => $this->lot->id, 'revision' => 0, 'closing_quantity' => 90]]])->assertNotFound();
        $this->settle($entry);
        $row = MedicationPaperStockEvidence::sole();
        $this->actingAs($this->reviewer)->postJson($this->url($entry).'/stock-evidence', ['request_uuid' => $row->request_uuid, 'settlement' => 'deduct_now', 'accountable_confirmation' => true,
            'lines' => [['lot_id' => $this->lot->id, 'revision' => 0, 'closing_quantity' => 80]]])->assertConflict();
        $this->assertSame($row->fingerprint, $row->fresh()->fingerprint);
        $preview = app(PaperEntryService::class)->reconciliationPreview($this->giver, $entry->downtime, $entry);
        $this->deny($this->reviewer, 'medications.stock.update');
        $changed = app(PaperEntryService::class)->reconciliationPreview($this->giver, $entry->downtime, $entry);
        $this->assertFalse($changed['can_reconcile']);
        $this->assertStringContainsString('stock reviewer no longer', $changed['unavailable']);
        $this->actingAs($this->giver)->postJson($this->url($entry).'/reconcile', ['preview_token' => $preview['preview_token'], 'accountable_confirmation' => true])->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame('100.00', $this->lot->fresh()->quantity_remaining);
    }

    public function test_final_event_failure_rolls_back_dose_pack_slot_register_receipt_and_posting(): void
    {
        $this->cover($this->giver);
        $entry = $this->capture($this->downtime());
        $this->settle($entry);
        $preview = app(PaperEntryService::class)->reconciliationPreview($this->giver, $entry->downtime, $entry);
        $events = Mockery::mock(DowntimeEvents::class);
        $events->shouldReceive('record')->once()->withArgs(fn ($d, $action) => $action === 'paper_reconciled')->andThrow(new \RuntimeException('final chain unavailable'));
        $this->app->instance(DowntimeEvents::class, $events);
        try {
            app(PaperEntryService::class)->reconcile($this->giver, $entry->downtime, $entry, $preview['preview_token']);
            $this->fail('Final event failure must roll back every domain effect.');
        } catch (\RuntimeException $e) {
            $this->assertSame('final chain unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
        $this->assertDatabaseCount('medication_paper_postings', 0);
        $this->assertSame(0, DB::table('medication_idempotency_results')->where('scope', 'administration.record')->count());
        $this->assertSame('100.00', $this->lot->fresh()->quantity_remaining);
        $target = $entry->downtime->doses()->firstOrFail();
        $this->assertNull(MedicationDoseSlot::findOrFail($target->dose_slot_id)->outcome_administration_id);
    }

    public function test_expired_server_grant_needs_separate_reviewer_and_is_never_revived(): void
    {
        $grant = $this->expiredGrant();
        $entry = $this->capture($this->downtime());
        $this->settle($entry);
        $before = $grant->fresh()->getRawOriginal();
        $preview = app(PaperEntryService::class)->reconciliationPreview($this->giver, $entry->downtime, $entry);
        $this->assertFalse($preview['can_reconcile']);
        $this->assertStringContainsString('expired access', $preview['unavailable']);
        $this->actingAs($this->giver)->postJson($this->url($entry).'/recovery-authorization', ['reason' => 'Self review is not independent', 'accountable_confirmation' => true])->assertForbidden();
        $this->actingAs($this->reviewer)->post($this->url($entry).'/recovery-authorization', ['reason' => 'Checked giver confirmation and original server grant coverage', 'accountable_confirmation' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->postPaper($entry);
        $this->assertSame($before, $grant->fresh()->getRawOriginal());
        $this->assertFalse($grant->fresh()->isRunning());
        $this->assertDatabaseCount('medication_paper_recovery_authorizations', 1);
        $this->assertSame($this->reviewer->id, MedicationEvent::where('kind', 'downtime.historical_authority_reviewed')->sole()->actor_id);
        $this->deny($this->reviewer, 'medications.administer.record');
        $latest = app(PaperEntryService::class)->reconciliationPreview($this->giver, $entry->downtime, $entry);
        $this->assertFalse($latest['can_reconcile']);
        $result = app(PaperEntryService::class)->reconcile($this->giver, $entry->downtime, $entry, $latest['preview_token']);
        $this->assertFalse($result['success']);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertSame('90.00', $this->lot->fresh()->quantity_remaining);
    }

    public function test_reviewer_cannot_infer_historical_authority_or_use_a_grant_for_another_person(): void
    {
        $entry = $this->capture($this->downtime());
        $this->actingAs($this->reviewer)->postJson($this->url($entry).'/recovery-authorization', ['reason' => 'There is no original grant', 'accountable_confirmation' => true])->assertUnprocessable();
        $this->assertDatabaseCount('medication_paper_recovery_authorizations', 0);
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_worker_gets_only_own_scoped_paper_pack_choices_without_stock_management_authority(): void
    {
        $worker = $this->staff('support_worker');
        $this->deny($worker, 'medications.stock.update');
        $this->assertFalse($worker->canDo('medications.stock.update'));
        $this->assertTrue($worker->canDo('medications.view'));
        $entry = $this->capture($this->downtime(), ['given_by' => $worker->id]);
        $response = $this->actingAs($worker)->getJson($this->url($entry).'/reconciliation')->assertOk();
        $response->assertJsonPath('stock_evidence.stock_id', $this->stock->id)->assertJsonPath('stock_evidence.unit', 'mg')
            ->assertJsonPath('stock_evidence.lots.0.id', $this->lot->id)->assertJsonPath('stock_evidence.can_record_settlement', false);
        $stockBefore = $this->stock->fresh()->getRawOriginal();
        $lotBefore = $this->lot->fresh()->getRawOriginal();
        $this->actingAs($worker)->postJson($this->url($entry).'/stock-evidence', [
            'request_uuid' => (string) Str::uuid(), 'settlement' => 'deduct_now', 'accountable_confirmation' => true,
            'lines' => [['lot_id' => $this->lot->id, 'revision' => 0, 'closing_quantity' => 90]],
        ])->assertForbidden();
        $this->assertSame($stockBefore, $this->stock->fresh()->getRawOriginal());
        $this->assertSame($lotBefore, $this->lot->fresh()->getRawOriginal());
        $this->assertDatabaseCount('medication_paper_stock_evidence', 0);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
        $other = $this->staff('support_worker');
        $this->actingAs($other)->getJson($this->url($entry).'/reconciliation')->assertNotFound();
    }

    public function test_historical_prn_interval_uses_actual_dose_time_and_has_no_partial_stock_effects(): void
    {
        ClientMedication::withoutEvents(fn () => $this->order->update(['is_prn' => true, 'min_hours_between_doses' => 2, 'max_per_day' => 4]));
        $this->cover($this->giver);
        ClientMedicationAdministration::create(['client_id' => $this->client->id, 'client_medication_id' => $this->order->id, 'administered_by' => $this->giver->id,
            'administered_at' => Carbon::parse('2026-10-03 08:50', 'Pacific/Auckland')->utc(), 'status' => 'given']);
        $entry = $this->capture($this->downtime(), ['clinical_facts' => ['quantity_given' => 10, 'amount_mode' => 'as_ordered', 'prn_reason' => 'Signed pain indication']]);
        $this->settle($entry);
        $preview = app(PaperEntryService::class)->reconciliationPreview($this->giver, $entry->downtime, $entry);
        $result = app(PaperEntryService::class)->reconcile($this->giver, $entry->downtime, $entry, $preview['preview_token']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('between doses', $result['error']);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_paper_postings', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
        $this->assertSame('100.00', $this->lot->fresh()->quantity_remaining);
    }

    public function test_stock_movement_requires_a_fresh_append_only_review_before_post_and_exact_replay(): void
    {
        $this->cover($this->giver);
        $entry = $this->capture($this->downtime());
        $this->settle($entry);
        $first = MedicationPaperStockEvidence::sole();
        $firstRaw = $first->getRawOriginal();
        $preview = app(PaperEntryService::class)->reconciliationPreview($this->giver, $entry->downtime, $entry);
        DB::transaction(fn () => app(MedicationStockService::class)->move($this->stock->fresh(), $this->reviewer, [
            'lot_id' => $this->lot->id, 'quantity' => 10, 'kind' => 'damaged', 'request_uuid' => (string) Str::uuid(), 'reason' => 'Separate pack damage after initial review',
        ]));
        $held = $this->actingAs($this->reviewer)->getJson($this->url($entry).'/reconciliation')->assertOk()
            ->assertJsonPath('stock_evidence.can_record_settlement', true)->assertJsonCount(1, 'stock_settlement_history')->json();
        $this->assertFalse($held['can_reconcile']);
        $giverHeld = app(PaperEntryService::class)->reconciliationPreview($this->giver, $entry->downtime, $entry);
        $this->assertFalse($giverHeld['can_reconcile']);
        $this->assertStringContainsString('revision', $giverHeld['unavailable']);
        $this->actingAs($this->giver)->postJson($this->url($entry).'/reconcile', ['preview_token' => $preview['preview_token'], 'accountable_confirmation' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->settle($entry, closingQuantity: 80);
        $latest = MedicationPaperStockEvidence::latest('id')->firstOrFail();
        $this->assertNotSame($first->id, $latest->id);
        $this->assertSame($firstRaw, $first->fresh()->getRawOriginal());
        $body = ['request_uuid' => $latest->request_uuid, 'settlement' => 'deduct_now', 'accountable_confirmation' => true,
            'lines' => [['lot_id' => $this->lot->id, 'revision' => 1, 'closing_quantity' => 80]]];
        $this->actingAs($this->reviewer)->post($this->url($entry).'/stock-evidence', $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('medication_paper_stock_evidence', 2);
        $this->postPaper($entry);
        $moves = MedicationStockMovement::whereNotNull('administration_id')->get();
        $this->assertCount(1, $moves);
        $this->assertSame($latest->id, $moves->sole()->evidence['settlement_id']);
        $this->assertSame($latest->fingerprint, $moves->sole()->evidence['settlement_fingerprint']);
        $this->assertSame('80.00', $this->stock->fresh()->on_hand);
        $posted = app(PaperEntryService::class)->reconciliationPreview($this->giver, $entry->downtime, $entry);
        $result = app(PaperEntryService::class)->reconcile($this->giver, $entry->downtime, $entry, $posted['preview_token']);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['duplicate']);
        $this->actingAs($this->reviewer)->post($this->url($entry).'/stock-evidence', $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('medication_paper_stock_evidence', 2);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_stock_movements', 2);
        $this->assertSame('80.00', $this->lot->fresh()->quantity_remaining);
    }

    public function test_valid_at_dose_but_expired_current_giver_competency_keeps_recovery_on_hold(): void
    {
        $this->cover($this->giver);
        MedicationCompetencyAssessment::where('user_id', $this->giver->id)->update(['expiry_date' => '2026-10-03']);
        $entry = $this->capture($this->downtime());
        $this->settle($entry);
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00', 'Pacific/Auckland')->utc());
        $preview = app(PaperEntryService::class)->reconciliationPreview($this->giver, $entry->downtime, $entry);
        $this->assertFalse($preview['can_reconcile']);
        $this->assertStringContainsString('Current medication competency', $preview['unavailable']);
        $result = app(PaperEntryService::class)->reconcile($this->giver, $entry->downtime, $entry, $preview['preview_token']);
        $this->assertFalse($result['success']);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
        $this->assertSame('100.00', $this->lot->fresh()->quantity_remaining);
    }

    public static function incompleteActualFacts(): array
    {
        return ['blank dialog' => ['blank'], 'partly known paper' => ['partial'], 'unknown overall waste' => ['overall_waste'], 'unknown pack waste' => ['pack_waste']];
    }

    #[DataProvider('incompleteActualFacts')]
    public function test_incomplete_actual_paper_is_retained_without_assuming_unknown_waste_or_posting(string $kind): void
    {
        $this->cover($this->giver);
        $clinical = ['quantity_given' => 10, 'amount_mode' => 'as_ordered'];
        $physical = $this->physical();
        if ($kind === 'blank') {
            $clinical = ['quantity_given' => '', 'amount_mode' => '', 'effect_check_due_at' => ''];
            $physical = ['stock_id' => '', 'unit' => '', 'quantity_removed' => '', 'quantity_wasted' => '', 'waste_reason' => '', 'lines' => []];
        } elseif ($kind === 'partial') {
            $clinical = ['quantity_given' => 10, 'amount_mode' => null];
            $physical = ['stock_id' => $this->stock->id, 'unit' => 'mg', 'quantity_removed' => 10, 'quantity_wasted' => null,
                'lines' => [['lot_id' => $this->lot->id, 'quantity' => 10, 'quantity_wasted' => null]]];
        } elseif ($kind === 'overall_waste') {
            unset($physical['quantity_wasted']);
        } else {
            unset($physical['lines'][0]['quantity_wasted']);
        }
        $entry = $this->capture($this->downtime(), ['clinical_facts' => $clinical, 'stock_evidence' => $physical]);
        $raw = $entry->getRawOriginal();
        if ($kind === 'blank') {
            $this->assertNull($entry->clinical_facts['quantity_given']);
            $this->assertNull($entry->stock_evidence['quantity_wasted']);
        } elseif ($kind === 'overall_waste') {
            $this->assertArrayNotHasKey('quantity_wasted', $entry->stock_evidence);
        } elseif ($kind === 'pack_waste') {
            $this->assertArrayNotHasKey('quantity_wasted', $entry->stock_evidence['lines'][0]);
        } else {
            $this->assertSame(10, $entry->clinical_facts['quantity_given']);
            $this->assertNull($entry->stock_evidence['quantity_wasted']);
        }
        $preview = $this->actingAs($this->giver)->getJson($this->url($entry).'/reconciliation')->assertOk()->assertJsonPath('can_reconcile', false)->json();
        $missing = array_column($preview['missing_actual_fields'], 'key');
        $this->assertContains($kind === 'pack_waste' ? 'stock_evidence.lines.0.quantity_wasted' : 'stock_evidence.quantity_wasted', $missing);
        $this->assertStringContainsString('Unknown waste is never zero', $preview['unavailable']);
        $this->actingAs($this->reviewer)->postJson($this->url($entry).'/stock-evidence', ['request_uuid' => (string) Str::uuid(), 'settlement' => 'deduct_now', 'accountable_confirmation' => true,
            'lines' => [['lot_id' => $this->lot->id, 'revision' => 0, 'closing_quantity' => 90]]])->assertUnprocessable();
        $result = app(PaperEntryService::class)->reconcile($this->giver, $entry->downtime, $entry, $preview['preview_token']);
        $this->assertFalse($result['success']);
        $this->assertSame($raw, $entry->fresh()->getRawOriginal());
        $this->assertDatabaseCount('medication_paper_stock_evidence', 0);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
        $this->assertSame('100.00', $this->stock->fresh()->on_hand);
        $this->assertSame('100.00', $this->lot->fresh()->quantity_remaining);
    }

    public function test_unit_alias_cannot_disguise_a_mismatched_clinical_and_physical_actual_amount(): void
    {
        $this->cover($this->giver);
        ClientMedication::withoutEvents(fn () => $this->order->update(['dose_amount' => 1, 'dose_unit' => 'tablet', 'dosage' => '1 tablet']));
        $this->stock->forceFill(['unit' => 'tablets'])->saveFromPackLedger();
        $downtime = $this->downtime();
        $this->actingAs($this->giver)->postJson('/emar/downtime/'.$downtime->id.'/paper/preview', [
            'client_medication_id' => $this->order->id, 'downtime_dose_id' => $downtime->doses()->firstOrFail()->id,
            'given_at' => '2026-10-03T09:10', 'given_by' => $this->giver->id, 'outcome' => 'given', 'dose_on_paper' => '1 tablet',
            'clinical_facts' => ['quantity_given' => 1, 'amount_mode' => 'as_ordered'],
            'stock_evidence' => [...$this->physical(), 'unit' => 'tablets'],
        ])->assertUnprocessable()->assertJsonValidationErrors('stock_evidence.quantity_removed');
        $this->assertDatabaseCount('medication_paper_entries', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
        $this->assertSame('100.00', $this->lot->fresh()->quantity_remaining);
    }

    public static function effectTimes(): array
    {
        return ['NZ local summer' => ['2026-10-03T10:00', '2026-10-02T21:00:00+00:00'],
            'explicit daylight offset' => ['2026-09-27T03:30:00+13:00', '2026-09-26T14:30:00+00:00'],
            'explicit standard offset' => ['2026-09-27T01:30:00+12:00', '2026-09-26T13:30:00+00:00']];
    }

    #[DataProvider('effectTimes')]
    public function test_retained_effect_check_time_preserves_the_new_zealand_instant(string $input, string $expected): void
    {
        $retained = PaperDoseFacts::retain($this->order, ['effect_check_due_at' => $input], []);
        $this->assertSame($expected, $retained['clinical']['effect_check_due_at']);
        $this->assertFalse(PaperDoseFacts::complete($retained['clinical'], $retained['physical']));
    }

    public static function liveActualTimes(): array
    {
        return ['minute precision at review' => ['2026-10-03T09:00:00+13:00'], 'several minutes after opening' => ['2026-10-03T09:04:00+13:00']];
    }

    #[DataProvider('liveActualTimes')]
    public function test_connected_multi_minute_form_uses_bounded_server_review_and_replays_after_token_expiry(string $actual): void
    {
        $this->liveShift();
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:00:45', 'Pacific/Auckland')->utc());
        $requirements = $this->liveRequirements();
        $this->assertSame(['lots_started' => true, 'offline_given_allowed' => false], $requirements['stock_tracking']);
        $this->assertNotEmpty($requirements['live_recording_context']);
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:05:10', 'Pacific/Auckland')->utc());
        $body = $this->liveBody($requirements['live_recording_context'], $actual);
        $this->actingAs($this->giver)->postJson('/meds/today/record', $body)->assertOk()->assertJsonPath('success', true);
        $admin = ClientMedicationAdministration::sole();
        $this->assertTrue($admin->administered_at->equalTo(Carbon::parse($actual)));
        $this->assertSame('90.00', $this->lot->fresh()->quantity_remaining);
        $before = [MedicationStockMovement::count(), MedicationEvent::count(), DB::table('medication_idempotency_results')->count()];
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:40', 'Pacific/Auckland')->utc());
        $this->actingAs($this->giver)->postJson('/meds/today/record', $body)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($before, [MedicationStockMovement::count(), MedicationEvent::count(), DB::table('medication_idempotency_results')->count()]);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertSame('90.00', $this->stock->fresh()->on_hand);
    }

    public static function invalidLiveReviews(): array
    {
        return ['tampered token' => ['tamper', 'live_recording_context'], 'expired token' => ['expired', 'live_recording_context'],
            'another worker' => ['actor', 'live_recording_context'], 'another order' => ['order', 'live_recording_context'],
            'changed order' => ['changed_order', 'live_recording_context'], 'changed stock unit' => ['stock', 'live_recording_context'],
            'earlier actual paper time' => ['earlier', 'administered_at'], 'missing review for earlier dose' => ['missing', 'live_recording_context'],
            'queued despite connected token' => ['offline', 'administered_at']];
    }

    #[DataProvider('invalidLiveReviews')]
    public function test_unproved_live_review_cannot_infer_past_pack_use(string $kind, string $field): void
    {
        $this->liveShift();
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:00:45', 'Pacific/Auckland')->utc());
        $token = $this->liveRequirements()['live_recording_context'];
        if ($kind === 'tamper') {
            $token .= 'x';
        }
        if ($kind === 'actor') {
            $token = LiveRecordingContext::issue($this->reviewer, $this->order, now());
        }
        if ($kind === 'order') {
            $other = $this->order->replicate();
            $other->name = 'Another medicine';
            $other->save();
            $token = LiveRecordingContext::issue($this->giver, $other, now());
        }
        if ($kind === 'changed_order') {
            ClientMedication::withoutEvents(fn () => $this->order->update(['instructions' => 'A materially changed instruction']));
        }
        if ($kind === 'stock') {
            $this->stock->forceFill(['unit' => 'mL'])->saveFromPackLedger();
        }
        Carbon::setTestNow(Carbon::parse($kind === 'expired' ? '2026-10-03 09:30:45' : '2026-10-03 09:05:10', 'Pacific/Auckland')->utc());
        $body = $this->liveBody($token, $kind === 'earlier' ? '2026-10-03T08:59:00+13:00' : '2026-10-03T09:00:00+13:00');
        if ($kind === 'missing') {
            unset($body['live_recording_context']);
        }
        if ($kind === 'offline') {
            $body += ['queued_offline' => true, 'captured_offline_at' => '2026-10-03T09:00:00+13:00', 'origin_device_id' => 'recovery-acceptance-device'];
        }
        $this->actingAs($this->giver)->postJson('/meds/today/record', $body)->assertUnprocessable()->assertJsonPath('error_field', $field);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
        $this->assertSame('100.00', $this->lot->fresh()->quantity_remaining);
        $this->assertSame('100.00', $this->stock->fresh()->on_hand);
        $this->assertSame(0, DB::table('medication_idempotency_results')->where('scope', 'administration.record')->count());
    }

    private function liveShift(): void
    {
        $this->cover($this->giver)->update(['status' => 'in_progress', 'actual_ends_at' => null]);
    }

    private function liveRequirements(): array
    {
        return $this->actingAs($this->giver)->getJson('/meds/today/doses/requirements?'.http_build_query([
            'client_medication_id' => $this->order->id, 'scheduled_for' => '2026-10-03T09:00:00+13:00',
        ]))->assertOk()->json();
    }

    private function liveBody(string $token, string $actual): array
    {
        return ['client_medication_id' => $this->order->id, 'scheduled_for' => '2026-10-03T09:00:00+13:00',
            'status' => 'given', 'amount_mode' => 'as_ordered', 'quantity_given' => 10, 'quantity_administered' => 10,
            'quantity_wasted' => 0, 'administered_at' => $actual, 'client_request_uuid' => (string) Str::uuid(), 'live_recording_context' => $token];
    }

    public static function closedGrantBoundaries(): array
    {
        return ['expired at actual instant' => ['expires_at', '2026-10-03T10:00:00+13:00'],
            'ended at actual instant' => ['ended_at', '2026-10-03T09:10:00+13:00'],
            'deleted at actual instant' => ['deleted_at', '2026-10-03T09:10:00+13:00']];
    }

    #[DataProvider('closedGrantBoundaries')]
    public function test_recovery_review_cannot_claim_authority_at_the_exact_server_grant_closing_instant(string $field, string $actual): void
    {
        $grant = $this->expiredGrant();
        if ($field !== 'expires_at') {
            $grant->forceFill([$field => Carbon::parse($actual)->utc()])->save();
        }
        $before = ClientBreakGlassAccess::withTrashed()->findOrFail($grant->id)->getRawOriginal();
        $entry = $this->capture($this->downtime(), ['given_at' => $actual]);
        $this->actingAs($this->reviewer)->postJson($this->url($entry).'/recovery-authorization', [
            'reason' => 'Independent review must prove the actual instant, not revive closed access', 'accountable_confirmation' => true,
        ])->assertUnprocessable();
        $this->assertSame($before, ClientBreakGlassAccess::withTrashed()->findOrFail($grant->id)->getRawOriginal());
        $this->assertDatabaseCount('medication_paper_recovery_authorizations', 0);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
        $this->assertSame('100.00', $this->lot->fresh()->quantity_remaining);
    }

    public static function malformedGrantDurations(): array
    {
        return ['below canonical minimum' => ['short'], 'above retained policy maximum' => ['long']];
    }

    #[DataProvider('malformedGrantDurations')]
    public function test_malformed_retained_grant_duration_cannot_prove_historical_authority(string $kind): void
    {
        $grant = $this->expiredGrant();
        $minutes = $kind === 'short' ? 4 : (int) $grant->effectivePolicy()['max_minutes'] + 1;
        $grant->forceFill(['expires_at' => $grant->created_at->copy()->addMinutes($minutes)])->save();
        $actual = $kind === 'short' ? '2026-10-03T09:02:00+13:00' : '2026-10-03T09:10:00+13:00';
        $entry = $this->capture($this->downtime(), ['given_at' => $actual]);
        $before = $grant->fresh()->getRawOriginal();
        $this->actingAs($this->reviewer)->postJson($this->url($entry).'/recovery-authorization', [
            'reason' => 'A retained row with an invalid canonical duration cannot establish authority', 'accountable_confirmation' => true,
        ])->assertUnprocessable();
        $this->assertSame($before, $grant->fresh()->getRawOriginal());
        $this->assertDatabaseCount('medication_paper_recovery_authorizations', 0);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
        $this->assertSame('100.00', $this->stock->fresh()->on_hand);
        $this->assertSame('100.00', $this->lot->fresh()->quantity_remaining);
    }

    public function test_later_shorter_application_policy_does_not_rewrite_a_legitimate_retained_grant(): void
    {
        $grant = $this->expiredGrant();
        $retainedPolicy = $grant->policy_snapshot;
        $this->assertGreaterThanOrEqual(60, $retainedPolicy['max_minutes']);
        BreakGlassPolicy::updateApplicationPolicy(['default_minutes' => 30, 'max_minutes' => 30]);
        $this->assertSame(30, BreakGlassPolicy::current()->snapshot()['max_minutes']);
        $this->assertSame($retainedPolicy, $grant->fresh()->effectivePolicy());
        $entry = $this->capture($this->downtime());
        $this->settle($entry);
        $before = $grant->fresh()->getRawOriginal();
        $this->actingAs($this->reviewer)->post($this->url($entry).'/recovery-authorization', [
            'reason' => 'Original retained policy permitted the genuine server-held sixty-minute grant', 'accountable_confirmation' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->postPaper($entry);
        $this->assertSame($before, $grant->fresh()->getRawOriginal());
        $this->assertFalse($grant->fresh()->isRunning());
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertSame('90.00', $this->stock->fresh()->on_hand);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2026-01-01']);
        $keys = ['medications.view', 'medications.administer.record', 'clients.viewAny', 'medications.controlled.view', 'medications.controlled.record', 'medications.controlled.witness', 'medications.stock.update', 'medications.breakglass'];
        $user->permissionOverrides()->sync(Permission::whereIn('key', $keys)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        MedicationCompetencyAssessment::create(['user_id' => $user->id, 'assessor_id' => User::factory()->create(['approved_at' => now()])->id,
            'assessment_type' => 'annual', 'status' => 'passed', 'assessment_date' => '2026-09-01', 'expiry_date' => '2027-09-01', 'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(), 'can_administer_unsupervised' => true, 'controlled_drugs' => true, 'can_witness_controlled' => true]);

        return $user->fresh();
    }

    private function cover(User $user): Shift
    {
        return Shift::factory()->create(['user_id' => $user->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'starts_at' => Carbon::parse('2026-10-03 08:00', 'Pacific/Auckland')->utc(), 'ends_at' => Carbon::parse('2026-10-03 11:00', 'Pacific/Auckland')->utc(),
            'actual_starts_at' => Carbon::parse('2026-10-03 08:00', 'Pacific/Auckland')->utc(), 'actual_ends_at' => Carbon::parse('2026-10-03 11:00', 'Pacific/Auckland')->utc(), 'status' => 'completed']);
    }

    private function downtime(): MedicationDowntime
    {
        return app(DowntimeService::class)->declare($this->giver, ['site_id' => $this->site->id, 'started_at' => '2026-10-03T08:30', 'ended_at' => '2026-10-03T10:30', 'description' => 'Signed recovery evidence', 'request_uuid' => (string) Str::uuid()]);
    }

    private function physical(int $waste = 0): array
    {
        return ['stock_id' => $this->stock->id, 'unit' => 'mg', 'quantity_removed' => 10, 'quantity_wasted' => $waste, 'waste_reason' => $waste ? 'Signed paper waste' : null,
            'lines' => [['lot_id' => $this->lot->id, 'quantity' => 10, 'quantity_wasted' => $waste]]];
    }

    private function capture(MedicationDowntime $downtime, array $extra = []): MedicationPaperEntry
    {
        $data = array_merge(['client_medication_id' => $this->order->id, 'downtime_dose_id' => $this->order->is_prn ? null : $downtime->doses()->firstOrFail()->id,
            'given_at' => '2026-10-03T09:10', 'given_by' => $this->giver->id, 'witness_id' => null, 'outcome' => 'given', 'dose_on_paper' => '10 mg', 'notes' => 'Actual signed paper',
            'observations' => [], 'clinical_facts' => ['quantity_given' => 10, 'amount_mode' => 'as_ordered'], 'stock_evidence' => $this->physical()], $extra);
        $preview = $this->actingAs($this->giver)->postJson('/emar/downtime/'.$downtime->id.'/paper/preview', $data)->assertOk()->assertJsonPath('can_submit', true)->json();
        $this->actingAs($this->giver)->post('/emar/downtime/'.$downtime->id.'/paper', $data + ['preview_token' => $preview['preview_token'], 'request_uuid' => (string) Str::uuid(), 'accountable_confirmation' => true])->assertRedirect()->assertSessionHasNoErrors();

        return MedicationPaperEntry::latest('id')->firstOrFail();
    }

    private function settle(MedicationPaperEntry $entry, string $mode = 'deduct_now', ?int $countId = null, int $closingQuantity = 90): void
    {
        $this->actingAs($this->reviewer)->post($this->url($entry).'/stock-evidence', ['request_uuid' => (string) Str::uuid(), 'settlement' => $mode, 'closing_count_id' => $countId, 'accountable_confirmation' => true,
            'lines' => [['lot_id' => $this->lot->id, 'revision' => $this->lot->fresh()->revision, 'closing_quantity' => $closingQuantity]]])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function postPaper(MedicationPaperEntry $entry): void
    {
        $preview = $this->actingAs($this->giver)->getJson($this->url($entry).'/reconciliation')->assertOk()->assertJsonPath('can_reconcile', true)->json();
        $this->actingAs($this->giver)->post($this->url($entry).'/reconcile', ['preview_token' => $preview['preview_token'], 'accountable_confirmation' => true])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function expiredGrant(): ClientBreakGlassAccess
    {
        $current = Carbon::now();
        try {
            Carbon::setTestNow(Carbon::parse('2026-10-03 09:00', 'Pacific/Auckland')->utc());

            return app(EmergencyAccessService::class)->start($this->giver, $this->client, ['reason' => 'Historical urgent clinical support', 'reason_category' => 'urgent_support', 'minutes' => 60,
                'authorization_mode' => 'self', 'acknowledged_min_necessary' => true, 'acknowledged_incident_report' => true]);
        } finally {
            Carbon::setTestNow($current);
        }
    }

    private function deny(User $user, string $key): void
    {
        $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => false]]);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }

    private function url(MedicationPaperEntry $entry): string
    {
        return '/emar/downtime/'.$entry->downtime_id.'/paper/'.$entry->id;
    }
}
