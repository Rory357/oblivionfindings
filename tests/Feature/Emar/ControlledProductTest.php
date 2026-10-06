<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ControlledDrugLossReport;
use App\Models\ControlledWitnessOverride;
use App\Models\ControlledWitnessRequest;
use App\Models\ControlledWorkflowEvent;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationDestruction;
use App\Models\MedicationEvent;
use App\Models\MedicationSettingChange;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Alerts\MedicationAlertSources;
use App\Services\Medication\Controlled\ControlledCountStatus;
use App\Services\Medication\Controlled\ControlledPolicy;
use App\Services\Medication\Controlled\ControlledRegisterService;
use App\Services\Medication\MedicationOrderLifecycleService;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** P07 register workflows use isolated synthetic staff, medicines and balances. */
class ControlledProductTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private ServiceContext $context;

    private Client $client;

    private ClientMedication $medication;

    private ClientMedicationStock $stock;

    private User $recorder;

    private User $witness;

    private User $lead;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active',
        ]);
        $this->recorder = $this->staff('support_worker', 'Synthetic counter');
        $this->witness = $this->staff('support_worker', 'Synthetic witness');
        $this->lead = $this->staff('team_lead', 'Synthetic house lead');
        $this->manager = $this->staff('provider_manager', 'Synthetic provider manager');
        $this->medication = $this->medicine($this->client);
        $this->stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $this->medication->id, 'on_hand' => 10, 'unit' => 'tablet',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_legacy_missing_register_quantities_remain_unknown_while_zero_and_fractional_values_are_preserved(): void
    {
        $base = ['client_id' => $this->client->id, 'client_medication_id' => $this->medication->id,
            'entry_type' => 'balance_check', 'recorded_at' => now(), 'recorded_by' => $this->recorder->id];
        $unknown = ClientControlledDrugEntry::query()->create([...$base,
            'quantity' => null, 'on_hand_before' => null, 'on_hand_after' => null]);
        $known = ClientControlledDrugEntry::query()->create([...$base,
            'quantity' => '0.00', 'on_hand_before' => '0.00', 'on_hand_after' => '1.25']);
        $discrepancy = ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $this->client->id, 'client_medication_id' => $this->medication->id,
            'on_hand_before' => null, 'on_hand_after' => '0.00', 'status' => 'open',
            'reported_at' => now(), 'reported_by' => $this->recorder->id,
        ]);
        $payload = $this->actingAs($this->recorder->fresh())->getJson('/emar/controlled/product')->assertOk()->json();
        $entries = collect($payload['entries'])->keyBy('id');
        foreach (['quantity', 'on_hand_before', 'on_hand_after'] as $field) {
            $this->assertNull($entries[$unknown->id][$field]);
        }
        $this->assertEquals(0, $entries[$known->id]['quantity']);
        $this->assertEquals(0, $entries[$known->id]['on_hand_before']);
        $this->assertEquals(1.25, $entries[$known->id]['on_hand_after']);
        $row = collect($payload['discrepancies'])->firstWhere('id', $discrepancy->id);
        $this->assertNull($row['expected_balance']);
        $this->assertEquals(0, $row['actual_balance']);
        $this->assertNull($unknown->fresh()->quantity);
        $this->assertNull($unknown->fresh()->on_hand_after);
        $this->assertSame('10.00', $this->stock->fresh()->on_hand);
    }

    public function test_matching_witnessed_count_stamps_current_count_without_a_discrepancy(): void
    {
        $result = $this->perform('count', ['actual_balance' => 10]);
        $entry = ClientControlledDrugEntry::query()->sole();
        $this->assertSame($entry->id, $result['counted_entry_id']);
        $this->assertSame('balance_check', $entry->entry_type);
        $this->assertSame('10.00', $entry->first_count);
        $this->assertNull($entry->recount);
        $this->assertSame('10.00', $this->stock->refresh()->on_hand);
        $this->assertTrue($this->stock->last_counted_at->equalTo(now()));
        $this->assertDatabaseCount('client_controlled_drug_discrepancies', 0);
    }

    public function test_mismatched_first_count_requires_recount_before_any_evidence_or_balance_write(): void
    {
        $this->invalid('recount_balance', fn () => $this->perform('count', ['actual_balance' => 8]));
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('client_controlled_drug_discrepancies', 0);
        $this->assertSame('10.00', $this->stock->refresh()->on_hand);
    }

    public function test_matching_recount_preserves_first_count_without_creating_discrepancy(): void
    {
        $this->perform('count', ['actual_balance' => 8, 'recount_balance' => 10]);
        $entry = ClientControlledDrugEntry::query()->sole();
        $this->assertSame('8.00', $entry->first_count);
        $this->assertSame('10.00', $entry->recount);
        $this->assertSame('10.00', $this->stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_discrepancies', 0);
    }

    public function test_persistent_recount_difference_creates_one_linked_incident_and_house_lead_owner(): void
    {
        $result = $this->discrepancy();
        $entry = ClientControlledDrugEntry::query()->sole();
        $row = ClientControlledDrugDiscrepancy::query()->sole();
        $this->assertSame($row->id, $result['discrepancy_id']);
        $this->assertSame($entry->id, (int) $row->count_entry_id);
        $this->assertSame($this->lead->id, (int) $row->owner_id);
        $this->assertSame('-2.00', $row->difference);
        $this->assertSame('8.00', $this->stock->refresh()->on_hand);
        $this->assertNotNull($row->incident_id);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseHas('medication_dashboard_alerts', ['alert_type' => 'controlled_discrepancy', 'client_medication_id' => $this->medication->id]);
    }

    public function test_counter_and_original_witness_cannot_resolve_even_with_manage_permission(): void
    {
        $id = $this->discrepancy()['discrepancy_id'];
        foreach ([$this->recorder, $this->witness] as $participant) {
            $this->permissions($participant, [ControlledRegisterService::MANAGE => true]);
            $this->invalid('target_id', fn () => $this->perform('resolve', [
                'target_id' => $id, 'outcome' => 'recount', 'actual_balance' => 10,
                'witnessed_by' => $this->lead->id, 'notes' => 'Independent recount required.',
            ], $participant));
        }
        $this->assertSame('open', ClientControlledDrugDiscrepancy::query()->sole()->status);
        $this->assertSame('8.00', $this->stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
    }

    public function test_independent_recount_accounts_for_subsequent_movements_and_preserves_incident_status(): void
    {
        $id = $this->discrepancy()['discrepancy_id'];
        $this->perform('movement', ['movement_type' => 'going_out', 'quantity' => 1, 'actual_balance' => 7]);
        $incident = ClientControlledDrugDiscrepancy::query()->sole()->incident;
        $status = $incident->status;
        $this->perform('resolve', ['target_id' => $id, 'outcome' => 'recount', 'actual_balance' => 9, 'notes' => 'Original counting error independently reconciled.'], $this->lead);
        $row = ClientControlledDrugDiscrepancy::query()->sole();
        $this->assertSame('closed', $row->status);
        $this->assertSame('recount', $row->resolution_outcome);
        $this->assertSame($this->lead->id, (int) $row->resolved_by);
        $this->assertSame('9.00', $this->stock->refresh()->on_hand);
        $this->assertSame('reconciliation', ClientControlledDrugEntry::query()->latest('id')->firstOrFail()->entry_type);
        $this->assertSame($status, $incident->refresh()->status);
        $this->assertDatabaseHas('controlled_workflow_events', ['subject_type' => 'discrepancy', 'subject_id' => $id, 'action' => 'resolve']);
    }

    public function test_escalation_remains_open_for_review_and_does_not_require_or_write_stock_witness(): void
    {
        $id = $this->discrepancy()['discrepancy_id'];
        $this->perform('resolve', ['target_id' => $id, 'outcome' => 'escalate', 'witnessed_by' => null,
            'witness_credential' => null, 'notes' => 'Sent to the provider manager for independent investigation.'], $this->lead);
        $row = ClientControlledDrugDiscrepancy::query()->sole();
        $this->assertSame('under_review', $row->status);
        $this->assertNull($row->resolved_at);
        $this->assertNull($row->resolved_by);
        $this->assertSame('8.00', $this->stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
    }

    public function test_discrepancy_to_loss_does_not_debit_the_shortfall_twice(): void
    {
        $id = $this->discrepancy()['discrepancy_id'];
        $this->perform('movement', ['movement_type' => 'going_out', 'quantity' => 1, 'actual_balance' => 7]);
        $this->perform('resolve', ['target_id' => $id, 'outcome' => 'loss', 'notes' => 'Confirmed missing tablets.',
            'immediate_action_taken' => 'Secured stock and informed the provider manager.', 'suspected_theft' => true], $this->lead);
        $loss = ControlledDrugLossReport::query()->sole();
        $this->assertSame('2.00', $loss->quantity_lost);
        $this->assertTrue($loss->suspected_theft);
        $lossEntry = ClientControlledDrugEntry::query()->where('entry_type', 'loss')->sole();
        $this->assertSame($lossEntry->id, (int) $loss->register_entry_id);
        $this->assertSame('discrepancy', $lossEntry->source_type);
        $this->assertSame($id, (int) $lossEntry->source_id);
        $this->assertSame('7.00', $lossEntry->on_hand_before);
        $this->assertSame('7.00', $lossEntry->on_hand_after);
        $this->assertSame($this->witness->id, (int) $lossEntry->witnessed_by);
        $this->assertSame('7.00', $this->stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 3);
    }

    public function test_direct_loss_is_witnessed_once_and_investigation_updates_preserve_the_balance(): void
    {
        $input = $this->input(['quantity' => 2, 'notes' => 'Missing stock found during an unscheduled check.',
            'immediate_action_taken' => 'Secured stock and called the provider manager.', 'suspected_theft' => true]);
        $service = app(ControlledRegisterService::class);
        $this->actingAs($this->recorder);
        $result = $service->perform($this->recorder, 'loss_report', $input);
        $replayed = $service->perform($this->recorder->fresh(), 'loss_report', $input);
        // JSON object key order is not evidence; keep strict values and all keys.
        ksort($result);
        ksort($replayed);
        $this->assertSame($result, $replayed);
        $loss = ControlledDrugLossReport::query()->sole();
        $this->assertSame($result['entry_id'], (int) $loss->register_entry_id);
        $this->assertSame('8.00', $this->stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->perform('loss_note', ['target_id' => $loss->id, 'notes' => 'First investigation note.'], $this->lead);
        $this->invalid('police_reference', fn () => $this->perform('loss_close', ['target_id' => $loss->id, 'notes' => 'Attempted closure before notification.'], $this->manager));
        $this->perform('loss_notify', ['target_id' => $loss->id, 'authority' => 'police', 'reference' => 'SYNTHETIC-123',
            'notified_at' => '2026-04-30T09:15+12:00', 'notes' => 'Police notified for this synthetic test.'], $this->lead);
        $this->denied(403, fn () => $this->perform('loss_close', ['target_id' => $loss->id, 'notes' => 'House lead cannot close.'], $this->lead));
        $this->perform('loss_close', ['target_id' => $loss->id, 'notes' => 'Independent provider investigation concluded.'], $this->manager);
        $this->assertSame('resolved', $loss->refresh()->investigation_status);
        $this->assertSame('8.00', $this->stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame(3, ControlledWorkflowEvent::query()->where('subject_type', 'loss')->where('subject_id', $loss->id)->whereIn('action', ['loss_note', 'loss_notify', 'loss_close'])->count());
        $this->assertFalse(MedicationDashboardAlert::query()->where('alert_type', 'controlled_loss')->where('client_medication_id', $this->medication->id)->whereNull('resolved_at')->exists());
    }

    public function test_void_keeps_original_and_creates_one_reversal_with_durable_replay(): void
    {
        $entryId = $this->perform('movement', ['movement_type' => 'going_out', 'quantity' => 2, 'actual_balance' => 8])['entry_id'];
        $original = ClientControlledDrugEntry::query()->findOrFail($entryId)->getRawOriginal();
        $input = $this->input(['target_id' => $entryId, 'notes' => 'The movement was recorded twice in error.']);
        $this->actingAs($this->lead);
        $service = app(ControlledRegisterService::class);
        $result = $service->perform($this->lead, 'void', $input);
        $this->assertSame($result, $service->perform($this->lead->fresh(), 'void', $input));
        $this->assertSame($original, ClientControlledDrugEntry::query()->findOrFail($entryId)->getRawOriginal());
        $this->assertSame(1, ClientControlledDrugEntry::query()->where('reverses_entry_id', $entryId)->count());
        $this->assertSame('10.00', $this->stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 2);
        $this->denied(409, fn () => $this->perform('void', ['target_id' => $entryId, 'notes' => 'Try the same target with a different request.'], $this->lead));
        $this->assertDatabaseCount('client_controlled_drug_entries', 2);
    }

    public function test_void_with_correction_appends_both_entries_without_overwriting_original(): void
    {
        $entryId = $this->perform('movement', ['movement_type' => 'going_out', 'quantity' => 2, 'actual_balance' => 8])['entry_id'];
        $this->perform('void', ['target_id' => $entryId, 'notes' => 'Quantity was one, not two.',
            'correction_quantity' => 1, 'correction_direction' => 'out'], $this->lead);
        $this->assertSame('9.00', $this->stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 3);
        $this->assertSame('2.00', ClientControlledDrugEntry::query()->findOrFail($entryId)->quantity);
        $correction = ClientControlledDrugEntry::query()->where('entry_type', 'correction')->sole();
        $this->assertSame('entry_correction', $correction->source_type);
        $this->assertSame($entryId, (int) $correction->source_id);
    }

    public function test_snapshot_conflict_and_uuid_reuse_with_changed_details_never_add_stock_evidence(): void
    {
        $stale = $this->input(['actual_balance' => 10]);
        $movement = $this->input(['movement_type' => 'going_out', 'quantity' => 1, 'actual_balance' => 9]);
        $this->actingAs($this->recorder);
        $service = app(ControlledRegisterService::class);
        $service->perform($this->recorder, 'movement', $movement);
        $this->denied(409, fn () => $service->perform($this->recorder->fresh(), 'count', $stale));
        $this->denied(409, fn () => $service->perform($this->recorder->fresh(), 'movement', [...$movement, 'quantity' => 2]));
        $this->assertSame('9.00', $this->stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame(1, DB::table('controlled_product_requests')->count());
    }

    public function test_pharmacy_return_receipt_is_linked_once_without_another_stock_debit(): void
    {
        $result = $this->perform('destruction', ['method' => 'pharmacy_return', 'reason' => 'expired', 'quantity' => 2], $this->lead);
        $row = MedicationDestruction::query()->sole();
        $this->assertSame($result['entry_id'], (int) $row->register_entry_id);
        $this->assertSame('8.00', $this->stock->refresh()->on_hand);
        $this->perform('destruction_receipt', ['target_id' => $row->id, 'pharmacist_name' => 'Synthetic pharmacist',
            'pharmacist_registration' => 'SYNTHETIC-PHARM-123', 'received_at' => '2026-04-30T09:30+12:00'], $this->lead);
        $this->assertSame('Synthetic pharmacist', $row->refresh()->pharmacist_name);
        $this->assertTrue($row->pharmacy_received_at->equalTo(Carbon::parse('2026-04-30T09:30+12:00')));
        $this->assertSame('8.00', $this->stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->denied(409, fn () => $this->perform('destruction_receipt', ['target_id' => $row->id,
            'pharmacist_name' => 'Second receipt', 'pharmacist_registration' => 'SYNTHETIC-SECOND'], $this->lead));
    }

    public function test_void_pharmacy_return_keeps_destruction_and_stock_as_an_administrative_annotation(): void
    {
        $result = $this->perform('destruction', ['method' => 'pharmacy_return', 'reason' => 'expired', 'quantity' => 2], $this->lead);
        $row = MedicationDestruction::query()->sole();
        $input = $this->input(['target_id' => $row->id, 'notes' => 'Synthetic package remained at the house; return was recorded in error.']);
        $this->actingAs($this->lead);
        $service = app(ControlledRegisterService::class);
        $response = $service->perform($this->lead, 'destruction_void', $input);
        $retry = $service->perform($this->lead->fresh(), 'destruction_void', $input);
        ksort($response);
        ksort($retry);
        $this->assertSame($response, $retry);
        $this->assertNotNull($row->refresh()->voided_at);
        $this->assertSame($result['entry_id'], (int) $row->register_entry_id);
        $this->assertSame(0, ClientControlledDrugEntry::query()->where('reverses_entry_id', $result['entry_id'])->count());
        $this->assertSame('8.00', $this->stock->refresh()->on_hand);
        $this->assertDatabaseCount('medication_destructions', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
    }

    public function test_generic_void_cannot_restore_a_pharmacy_return_before_or_after_administrative_void(): void
    {
        $result = $this->perform('destruction', ['method' => 'pharmacy_return', 'reason' => 'expired', 'quantity' => 2], $this->lead);
        $destruction = MedicationDestruction::query()->sole();

        $this->assertProtectedEntryCannotBeVoided($result['entry_id'], '8.00');
        $this->assertNull($destruction->refresh()->voided_at);
        $this->perform('destruction_void', ['target_id' => $destruction->id,
            'notes' => 'The return documentation needs review; physical stock remains unresolved.'], $this->lead);
        $this->assertProtectedEntryCannotBeVoided($result['entry_id'], '8.00');
        $this->assertNotNull($destruction->refresh()->voided_at);
        $this->assertSame($result['entry_id'], (int) $destruction->register_entry_id);
    }

    public function test_generic_void_cannot_restore_a_recorded_controlled_dose_or_its_waste(): void
    {
        ClientMedication::query()->whereKey($this->medication->id)->update(['dose_amount' => 1, 'dose_unit' => 'tablet']);
        $this->actingAs($this->recorder->fresh())->postJson('/meds/today/record', [
            'client_medication_id' => $this->medication->id, 'scheduled_for' => '2026-04-30T09:30:00+12:00',
            'status' => 'given', 'amount_mode' => 'as_ordered', 'quantity_administered' => 2, 'cd_balance' => 8,
            'witnessed_by' => $this->witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN,
        ])->assertOk();
        $dose = ClientMedicationAdministration::query()->sole();
        $entries = ClientControlledDrugEntry::query()->where('client_medication_administration_id', $dose->id)->get();
        $this->assertSame(['administered', 'disposal'], $entries->pluck('entry_type')->sort()->values()->all());

        foreach ($entries as $entry) {
            $this->assertProtectedEntryCannotBeVoided($entry->id, '8.00');
        }
        $this->assertSame('given', $dose->refresh()->status);
        $this->assertNull($dose->deleted_at);
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_legacy_administration_and_disposal_without_links_still_require_governed_reconciliation(): void
    {
        $this->stock->update(['on_hand' => 8]);
        foreach (['administered', 'disposal'] as $offset => $type) {
            $entry = ClientControlledDrugEntry::query()->create([
                'client_id' => $this->client->id, 'client_medication_id' => $this->medication->id,
                'entry_type' => $type, 'quantity' => 1, 'on_hand_before' => 10 - $offset,
                'on_hand_after' => 9 - $offset, 'unit' => 'tablet', 'recorded_at' => now(),
                'recorded_by' => $this->recorder->id, 'witnessed_by' => $this->witness->id,
            ]);
            $this->assertProtectedEntryCannotBeVoided($entry->id, '8.00');
        }
    }

    public function test_destruction_link_protects_an_entry_even_when_its_legacy_type_is_a_movement(): void
    {
        $entryId = $this->perform('movement', ['movement_type' => 'going_out', 'quantity' => 2, 'actual_balance' => 8])['entry_id'];
        MedicationDestruction::query()->create([
            'client_id' => $this->client->id, 'client_medication_id' => $this->medication->id, 'site_id' => $this->site->id,
            'medication_name' => $this->medication->name, 'quantity' => 2, 'unit' => 'tablet',
            'reason' => 'expired', 'disposal_method' => 'pharmacy_return', 'is_controlled_drug' => true,
            'destroyed_by' => $this->lead->id, 'witness_1_id' => $this->witness->id, 'destroyed_at' => now(),
            'register_entry_id' => $entryId, 'voided_at' => now(), 'voided_by' => $this->lead->id,
            'void_reason' => 'Legacy documentation under review.',
        ]);
        // Soft deletion is fixture state, not a supported way to remove physical-use evidence.
        MedicationDestruction::query()->withTrashed()->sole()->delete();

        $this->assertProtectedEntryCannotBeVoided($entryId, '8.00');
    }

    public function test_administration_link_protects_an_entry_even_when_its_legacy_type_is_a_movement(): void
    {
        $dose = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id, 'client_medication_id' => $this->medication->id,
            'administered_by' => $this->recorder->id, 'administered_at' => now(), 'status' => 'given',
        ]);
        $entry = ClientControlledDrugEntry::query()->create([
            'client_id' => $this->client->id, 'client_medication_id' => $this->medication->id,
            'client_medication_administration_id' => $dose->id, 'entry_type' => 'transfer_out',
            'quantity' => 1, 'on_hand_before' => 10, 'on_hand_after' => 9, 'unit' => 'tablet',
            'recorded_at' => now(), 'recorded_by' => $this->recorder->id, 'witnessed_by' => $this->witness->id,
        ]);
        $this->stock->update(['on_hand' => 9]);

        $this->assertProtectedEntryCannotBeVoided($entry->id, '9.00');
        $this->assertSame('given', $dose->refresh()->status);
    }

    private function assertProtectedEntryCannotBeVoided(int $entryId, string $balance): void
    {
        $entriesBefore = ClientControlledDrugEntry::query()->count();
        $eventsBefore = MedicationEvent::query()->count();
        $requestsBefore = DB::table('controlled_product_requests')->count();
        $original = ClientControlledDrugEntry::query()->findOrFail($entryId)->getRawOriginal();
        $payload = $this->actingAs($this->lead->fresh())->getJson('/emar/controlled/product')->assertOk();
        $this->assertFalse(collect($payload->json('entries'))->firstWhere('id', $entryId)['can_void']);
        $input = ['target_id' => $entryId, 'notes' => 'Attempt a register-only reversal of physical-use evidence.',
            'correction_quantity' => 1, 'correction_direction' => 'in'];

        $this->actionRequest('void', $input, $this->lead)->assertUnprocessable()->assertJsonValidationErrors('target_id');
        $this->invalid('target_id', fn () => $this->perform('void', $input, $this->lead));

        $this->assertSame($balance, $this->stock->refresh()->on_hand);
        $this->assertSame($original, ClientControlledDrugEntry::query()->findOrFail($entryId)->getRawOriginal());
        $this->assertSame(0, ClientControlledDrugEntry::query()->where('reverses_entry_id', $entryId)->count());
        $this->assertSame($entriesBefore, ClientControlledDrugEntry::query()->count());
        $this->assertSame($eventsBefore, MedicationEvent::query()->count());
        $this->assertSame($requestsBefore, DB::table('controlled_product_requests')->count());
    }

    public function test_support_worker_cannot_manage_and_manage_deny_wins_for_a_provider_manager(): void
    {
        $entryId = $this->perform('movement', ['quantity' => 1, 'actual_balance' => 9, 'movement_type' => 'going_out'])['entry_id'];
        $this->denied(403, fn () => $this->perform('void', ['target_id' => $entryId, 'notes' => 'Unauthorised support worker void.']));
        $this->permissions($this->manager, [ControlledRegisterService::MANAGE => false]);
        $this->denied(403, fn () => $this->perform('void', ['target_id' => $entryId, 'notes' => 'Explicitly denied manager void.'], $this->manager));
        $this->assertSame('9.00', $this->stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
    }

    public function test_forged_medication_witness_and_register_target_ids_are_denied_without_a_write(): void
    {
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
        $foreignMedicine = $this->medicine($foreignClient);
        $foreignStock = ClientMedicationStock::query()->create(['client_medication_id' => $foreignMedicine->id, 'on_hand' => 20, 'unit' => 'tablet']);
        $this->denied(404, fn () => $this->perform('count', ['client_medication_id' => $foreignMedicine->id,
            'expected_balance' => 20, 'expected_entry_id' => null, 'actual_balance' => 20]));
        $foreignWitness = $this->staff('support_worker', 'Synthetic foreign witness', $foreignSite, $foreignClient);
        $this->denied(404, fn () => $this->perform('count', ['actual_balance' => 10, 'witnessed_by' => $foreignWitness->id]));
        $foreignEntry = ClientControlledDrugEntry::query()->create([
            'client_id' => $foreignClient->id, 'client_medication_id' => $foreignMedicine->id, 'entry_type' => 'transfer_out',
            'quantity' => 1, 'unit' => 'tablet', 'on_hand_before' => 21, 'on_hand_after' => 20,
            'recorded_by' => $foreignWitness->id, 'witnessed_by' => $foreignWitness->id, 'recorded_at' => now(),
        ]);
        try {
            $this->perform('void', ['target_id' => $foreignEntry->id, 'notes' => 'Forged foreign entry.'], $this->lead);
            $this->fail('A foreign entry must remain concealed.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(ClientControlledDrugEntry::class, $exception->getModel());
        }
        $this->assertSame('10.00', $this->stock->refresh()->on_hand);
        $this->assertSame('20.00', $foreignStock->refresh()->on_hand);
        $this->assertDatabaseCount('controlled_workflow_events', 0);
        $this->assertDatabaseCount('controlled_product_requests', 0);
    }

    public function test_current_site_staff_without_the_person_read_gate_cannot_access_register_actions(): void
    {
        $otherClient = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active']);
        $stranger = $this->staff('support_worker', 'Synthetic unassigned worker', $this->site, $otherClient);
        $this->permissions($stranger, [
            'clients.viewAny' => false, 'medications.stock.update' => false, 'medications.audit.view' => false,
            'medications.reports.export' => false, 'reports.viewAny' => false, 'medications.breakglass' => false,
        ]);
        $this->denied(404, fn () => $this->perform('count', ['actual_balance' => 10], $stranger));
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('controlled_product_requests', 0);
    }

    public function test_http_count_recount_and_movement_return_confirmed_json_and_update_register(): void
    {
        $response = $this->actionRequest('count', ['actual_balance' => 10])->assertOk()->assertJsonStructure(['message', 'entry_id', 'counted_entry_id']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $count = $this->actionRequest('count', ['actual_balance' => 8, 'recount_balance' => 8,
            'notes' => 'Synthetic two-count shortfall.', 'immediate_action_taken' => 'Secured cupboard and told house lead.'])
            ->assertOk()->assertJsonStructure(['discrepancy_id']);
        $this->assertNotNull($count->json('discrepancy_id'));
        $this->actionRequest('movement', ['movement_type' => 'going_out', 'quantity' => 1, 'actual_balance' => 7, 'notes' => 'Synthetic witnessed movement.'])
            ->assertOk()->assertJsonStructure(['entry_id']);
        $this->assertSame('7.00', $this->stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 3);
    }

    public function test_http_resolution_and_void_correction_keep_original_evidence(): void
    {
        $id = $this->discrepancy()['discrepancy_id'];
        $this->actionRequest('resolve', ['target_id' => $id, 'outcome' => 'recount', 'actual_balance' => 10,
            'notes' => 'Count error independently established and reconciled.'], $this->lead)->assertOk();
        $this->assertSame('closed', ClientControlledDrugDiscrepancy::query()->findOrFail($id)->status);
        $movementId = $this->actionRequest('movement', ['movement_type' => 'going_out', 'quantity' => 2, 'actual_balance' => 8])
            ->assertOk()->json('entry_id');
        $this->actionRequest('void', ['target_id' => $movementId, 'notes' => 'Only one tablet left; original entry wrong.',
            'correction_quantity' => 1, 'correction_direction' => 'out'], $this->lead)->assertOk();
        $this->assertSame('9.00', $this->stock->refresh()->on_hand);
        $this->assertSame('2.00', ClientControlledDrugEntry::query()->findOrFail($movementId)->quantity);
        $this->assertSame(1, ClientControlledDrugEntry::query()->where('reverses_entry_id', $movementId)->count());
    }

    public function test_http_loss_report_note_notification_and_manager_closure_complete_one_investigation(): void
    {
        $lossId = $this->actionRequest('loss_report', ['quantity' => 2, 'notes' => 'Synthetic loss circumstances.',
            'immediate_action_taken' => 'Secured the cupboard.', 'discovered_at' => '2026-04-30T09:15+12:00', 'suspected_theft' => true])
            ->assertOk()->assertJsonStructure(['target_id', 'entry_id'])->json('target_id');
        $loss = ControlledDrugLossReport::query()->findOrFail($lossId);
        $this->assertSame('Synthetic loss circumstances.', $loss->circumstances);
        $this->assertTrue($loss->discovered_at->equalTo(Carbon::parse('2026-04-30T09:15+12:00')));
        $this->actionRequest('loss_note', ['target_id' => $lossId, 'notes' => 'Synthetic investigation note.'], $this->lead)->assertOk();
        $this->actionRequest('loss_notify', ['target_id' => $lossId, 'authority' => 'police', 'reference' => 'SYNTHETIC-EVENT-456',
            'notified_at' => '2026-04-30T09:25+12:00', 'notes' => 'Police notification recorded.'], $this->lead)->assertOk();
        $this->actionRequest('loss_close', ['target_id' => $lossId, 'notes' => 'Theft finding: synthetic police investigation recorded; follow-up complete.'], $this->manager)->assertOk();
        $this->assertSame('resolved', $loss->refresh()->investigation_status);
        $this->assertSame('SYNTHETIC-EVENT-456', $loss->police_reference);
        $this->assertSame('8.00', $this->stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
    }

    public function test_http_destruction_photo_receipt_and_void_have_private_readable_evidence(): void
    {
        Storage::fake('local');
        $photo = UploadedFile::fake()->image('synthetic-return.png', 32, 32);
        $this->actingAs($this->lead->fresh());
        $input = $this->form(['method' => 'pharmacy_return', 'reason' => 'expired', 'quantity' => 2, 'notes' => 'Synthetic returns package.', 'photo' => $photo]);
        $record = $this->post('/emar/controlled/product/actions/destruction', $input, ['Accept' => 'application/json'])->assertOk();
        $destructionId = $record->json('target_id');
        $row = MedicationDestruction::query()->findOrFail($destructionId);
        Storage::disk('local')->assertExists($row->photo_path);
        $photoResponse = $this->get('/emar/controlled/product/destructions/'.$destructionId.'/photo')->assertOk();
        $this->assertStringContainsString('private', $photoResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $photoResponse->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $photoResponse->headers->get('X-Content-Type-Options'));
        $this->get('/emar/controlled/product/destructions/'.$destructionId.'/photo?download=1')->assertOk();
        $this->actionRequest('destruction_receipt', ['target_id' => $destructionId, 'pharmacist_name' => 'Synthetic pharmacist',
            'pharmacist_registration' => 'SYNTHETIC-REGISTRATION', 'received_at' => '2026-04-30T09:30+12:00'], $this->lead)->assertOk();
        $this->assertNotNull($row->refresh()->pharmacy_received_at);
        $this->actionRequest('destruction_void', ['target_id' => $destructionId, 'notes' => 'Wrong synthetic medicine was selected; actual package is separately recorded.'], $this->lead)->assertOk();
        $this->assertNotNull($row->refresh()->voided_at);
        $this->assertDatabaseCount('medication_destructions', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame('8.00', $this->stock->fresh()->on_hand);
        Storage::disk('local')->assertExists($row->photo_path);
    }

    public function test_http_class_review_records_reviewed_source_without_changing_legacy_schedule(): void
    {
        $this->medication->forceFill(['cd_schedule' => 3])->save();
        $this->actionRequest('class_review', ['nz_class' => 'C', 'source' => 'Synthetic reviewed classification source',
            'notes' => 'This test source is isolated synthetic evidence.'], $this->lead)->assertOk();
        $medicine = $this->medication->refresh();
        $this->assertSame('C', $medicine->nz_controlled_class);
        $this->assertSame(3, $medicine->cd_schedule);
        $this->assertSame($this->lead->id, (int) $medicine->controlled_class_reviewed_by);
        $this->assertSame('Synthetic reviewed classification source', $medicine->controlled_class_source);
        $this->assertNotNull($medicine->controlled_class_reviewed_at);
    }

    public function test_http_witness_request_answer_and_cancel_keep_the_same_canonical_medicine(): void
    {
        $id = $this->actionRequest('witness_request', ['witness_id' => $this->witness->id, 'purpose' => 'count',
            'notes' => 'Please come to the cupboard for the synthetic count.'])->assertOk()->json('target_id');
        $this->actionRequest('witness_answer', ['target_id' => $id, 'response' => 'cant_come',
            'notes' => 'Supporting another resident now.'], $this->witness)->assertOk();
        $row = ControlledWitnessRequest::query()->findOrFail($id);
        $this->assertSame('cant_come', $row->response);
        $this->assertSame('Supporting another resident now.', $row->reason);
        $this->actionRequest('witness_cancel', ['target_id' => $id])->assertOk();
        $this->assertNotNull($row->refresh()->closed_at);
        $this->assertSame($this->medication->id, (int) $row->client_medication_id);
    }

    public function test_http_override_request_and_independent_decision_use_explicit_coverage(): void
    {
        $this->permissions($this->manager, ['medications.controlled.override' => true]);
        $id = $this->actionRequest('override_request', ['medicine_ids' => [$this->medication->id],
            'notes' => 'No eligible witness available; synthetic independent decision requested.'])->assertOk()->json('target_id');
        $this->actionRequest('override_decide', ['target_id' => $id, 'medicine_ids' => [$this->medication->id],
            'decision' => 'declined', 'notes' => 'An eligible colleague is available; arrange witnessed administration.'], $this->manager)->assertOk();
        $row = ControlledWitnessOverride::query()->findOrFail($id);
        $this->assertSame('declined', $row->status);
        $this->assertSame($this->manager->id, (int) $row->decided_by);
        $this->assertSame([$this->medication->id], array_map('intval', $row->medicine_ids));
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
    }

    public function test_http_pages_products_and_actions_share_controlled_person_and_site_concealment(): void
    {
        $this->actingAs($this->recorder->fresh())->get('/emar/controlled')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('emar/ControlledRegister')
            ->where('product.current_user_id', $this->recorder->id)->has('product.medicines', 1));
        $this->getJson('/emar/controlled/product')->assertOk()->assertJsonPath('current_user_id', $this->recorder->id);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
        $foreignMedicine = $this->medicine($foreignClient);
        $this->getJson('/emar/controlled/product?site_id='.$foreignSite->id)->assertNotFound();
        $this->getJson('/emar/controlled/product?client_medication_id='.$foreignMedicine->id)->assertNotFound();
        $this->actionRequest('count', ['client_medication_id' => $foreignMedicine->id, 'actual_balance' => 10])->assertNotFound();
        $this->actionRequest('count', ['actual_balance' => 10, 'queued_offline' => true])->assertStatus(422);
        $this->permissions($this->recorder, ['medications.controlled.view' => false]);
        $this->actingAs($this->recorder->fresh())->get('/emar/controlled')->assertForbidden();
        $this->getJson('/emar/controlled/product')->assertForbidden();
        $this->actionRequest('count', ['actual_balance' => 10])->assertForbidden();
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
    }

    public function test_http_multi_medicine_override_conceals_unreadable_person_coverage_and_doses(): void
    {
        $otherClient = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active']);
        $otherMedicine = $this->medicine($otherClient);
        $otherStaff = $this->staff('support_worker', 'Synthetic other resident worker', $this->site, $otherClient);
        $this->permissions($this->recorder, [
            'clients.viewAny' => false, 'medications.stock.update' => false, 'medications.audit.view' => false,
            'medications.reports.export' => false, 'reports.viewAny' => false, 'medications.breakglass' => false,
        ]);
        $override = ControlledWitnessOverride::query()->create([
            'site_id' => $this->site->id, 'client_medication_id' => $this->medication->id,
            'medicine_ids' => [$this->medication->id, $otherMedicine->id], 'requested_by' => $this->recorder->id,
            'reason' => 'Synthetic independently approved multi-medicine coverage.', 'status' => 'approved',
            'decided_by' => $this->manager->id, 'decided_at' => now()->subMinute(),
            'starts_at' => now()->subMinute(), 'expires_at' => now()->addHour(),
        ]);
        ClientMedicationAdministration::withoutEvents(fn () => ClientMedicationAdministration::query()->forceCreate([
            'client_id' => $otherClient->id, 'client_medication_id' => $otherMedicine->id,
            'administered_by' => $otherStaff->id, 'administered_at' => now(), 'status' => 'given',
            'witness_override_id' => $override->id, 'dose_given' => '1 tablet',
        ]));
        $this->actingAs($this->recorder->fresh())->getJson('/emar/controlled/product')
            ->assertOk()->assertJsonCount(1, 'medicines')->assertJsonCount(1, 'overrides')
            ->assertJsonPath('overrides.0.medicine_ids', [$this->medication->id])->assertJsonCount(0, 'overrides.0.doses');
    }

    public function test_http_theft_discovered_later_requires_police_evidence_before_closure(): void
    {
        $id = $this->actionRequest('loss_report', ['quantity' => 1, 'notes' => 'Initially unexplained shortfall.',
            'immediate_action_taken' => 'Secured cupboard.', 'suspected_theft' => false])->assertOk()->json('target_id');
        $this->actionRequest('loss_close', ['target_id' => $id, 'notes' => 'Investigation established suspected theft.',
            'resolution_outcome' => 'theft', 'suspected_theft' => true, 'notifications_checked' => true], $this->manager)
            ->assertUnprocessable()->assertJsonValidationErrors('police_reference');
        $this->assertSame('reported', ControlledDrugLossReport::findOrFail($id)->investigation_status);
        $this->actionRequest('loss_notify', ['target_id' => $id, 'authority' => 'police', 'reference' => 'SYNTHETIC-LATER-THEFT',
            'notified_at' => '2026-04-30T09:30', 'notes' => 'Police notified after investigation.'], $this->lead)->assertOk();
        $this->actionRequest('loss_close', ['target_id' => $id, 'notes' => 'Police evidence and notifications checked.',
            'resolution_outcome' => 'theft', 'suspected_theft' => true, 'notifications_checked' => true], $this->manager)->assertOk();
        $this->assertTrue(ControlledDrugLossReport::findOrFail($id)->suspected_theft);
    }

    public function test_outstanding_work_remains_reachable_beyond_completed_history_limit(): void
    {
        $d = $this->discrepancy()['discrepancy_id'];
        $lossId = $this->perform('loss_report', ['quantity' => 1, 'notes' => 'Older open loss.', 'immediate_action_taken' => 'Secured cupboard.'])['target_id'];
        $destructionId = $this->perform('destruction', ['quantity' => 1, 'reason' => 'expired', 'method' => 'pharmacy_return'])['target_id'];
        $template = ClientControlledDrugDiscrepancy::findOrFail($d)->getAttributes();
        unset($template['id']);
        $template['status'] = 'closed';
        $closed = [];
        for ($i = 0; $i < 501; $i++) {
            $closed[] = $template;
        }
        DB::table('client_controlled_drug_discrepancies')->insert($closed);
        $lossTemplate = ControlledDrugLossReport::findOrFail($lossId)->getAttributes();
        unset($lossTemplate['id']);
        $lossTemplate['investigation_status'] = 'resolved';
        $closedLosses = [];
        for ($i = 0; $i < 501; $i++) {
            $closedLosses[] = [...$lossTemplate, 'reference_number' => 'CDL-SYNTHETIC-CLOSED-'.$i];
        }
        DB::table('controlled_drug_loss_reports')->insert($closedLosses);
        $destructionTemplate = MedicationDestruction::findOrFail($destructionId)->getAttributes();
        unset($destructionTemplate['id']);
        $destructionTemplate['pharmacy_received_at'] = now()->toDateTimeString();
        DB::table('medication_destructions')->insert(array_fill(0, 501, $destructionTemplate));
        $payload = $this->actingAs($this->manager->fresh())->getJson('/emar/controlled/product')->assertOk()->json();
        $this->assertCount(501, $payload['discrepancies']);
        $this->assertContains($d, array_column($payload['discrepancies'], 'id'));
        $this->assertContains($lossId, array_column($payload['losses'], 'id'));
        $this->assertContains($destructionId, array_column($payload['destructions'], 'id'));
        $this->assertSame(1, $payload['meters']['total_open_discrepancies']);
        $this->assertSame(1, $payload['meters']['total_open_losses']);
        $this->assertSame(1, $payload['meters']['total_awaiting_receipts']);
    }

    public function test_controlled_settings_cadence_is_deliberately_saved_from_not_configured(): void
    {
        $this->assertNull(app(ControlledPolicy::class)->cadence());
        $this->actingAs($this->manager->fresh())->put('/emar/settings/changes', ['view' => 'rules', 'changes' => [
            ['group' => 'controlled_counts', 'key' => 'cadence', 'from' => '', 'value' => 'shift'],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('shift', app(ControlledPolicy::class)->cadence());
        $this->assertDatabaseHas('medication_setting_changes', ['setting_group' => 'controlled_counts', 'setting_key' => 'cadence', 'before_text' => 'Not configured']);
        $this->permissions($this->manager, ['medications.controlled.view' => false]);
        $this->actingAs($this->manager->fresh())->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [
            ['group' => 'controlled_counts', 'key' => 'cadence', 'from' => 'shift', 'value' => 'day'],
        ]])->assertForbidden();
    }

    public function test_weekly_settings_publish_one_complete_anchor_and_start_at_the_next_boundary(): void
    {
        $anchor = '{"day":4,"time":"09:00"}';
        $this->actingAs($this->manager->fresh())->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [
            ['group' => 'controlled_counts', 'key' => 'cadence', 'from' => '', 'value' => 'week'],
            ['group' => 'controlled_counts', 'key' => 'weekly_anchor', 'from' => 'off', 'value' => '{"time":"09:00","day":4}'],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($anchor, AppSetting::where('key', ControlledPolicy::COUNT_WEEKLY_ANCHOR)->sole()->value);
        $history = MedicationSettingChange::where('setting_group', 'controlled_counts')->where('setting_key', 'weekly_anchor')->sole();
        $this->assertSame('off', $history->before_value);
        $this->assertSame($anchor, $history->after_value);
        $this->assertSame('Thursday at 9:00 am (Pacific/Auckland)', $history->after_text);
        $this->assertFalse($history->loosens);
        $this->assertDatabaseCount('medication_setting_changes', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'medications.controlled_count_policy.updated']);
        $payload = $this->getJson('/emar/controlled/product')->assertOk();
        $payload->assertJsonPath('cadence.configured', true)
            ->assertJsonPath('medicines.0.count.cadence', 'week')
            ->assertJsonPath('medicines.0.count.state', 'next')
            ->assertJsonPath('medicines.0.count.due_at', '2026-05-07T08:30:00+12:00')
            ->assertJsonPath('medicines.0.count.next_at', '2026-05-07T09:00:00+12:00');
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame('10.00', $this->stock->fresh()->on_hand);
    }

    public function test_any_weekly_count_group_draft_requires_an_anchor_without_writing_partial_policy(): void
    {
        AppSetting::updateOrCreate(['key' => ControlledPolicy::COUNT_CADENCE], ['value' => 'week']);
        $before = AppSetting::where('key', ControlledPolicy::COUNT_CADENCE)->sole()->getRawOriginal();
        $payload = $this->actingAs($this->manager->fresh())->getJson('/emar/controlled/product')->assertOk();
        $payload->assertJsonPath('cadence.configured', false)->assertJsonPath('medicines.0.count.state', 'not_configured')
            ->assertJsonPath('medicines.0.count.due_at', null)->assertJsonPath('medicines.0.count.overdue_at', null);
        $auditBefore = AuditLog::count();
        $this->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [
            ['group' => 'controlled_counts', 'key' => 'overdue_minutes', 'from' => '60', 'value' => '90'],
        ], 'confirm_loosening' => true])->assertUnprocessable()->assertJsonValidationErrors('controlled_counts.weekly_anchor');
        $this->assertSame($before, AppSetting::where('key', ControlledPolicy::COUNT_CADENCE)->sole()->getRawOriginal());
        $this->assertDatabaseMissing('app_settings', ['key' => ControlledPolicy::COUNT_OVERDUE_MINUTES]);
        $this->assertDatabaseMissing('app_settings', ['key' => ControlledPolicy::COUNT_WEEKLY_ANCHOR]);
        $this->assertDatabaseCount('medication_setting_changes', 0);
        $this->assertDatabaseCount('medication_events', 0);
        $this->assertDatabaseCount('audit_logs', $auditBefore);
    }

    public function test_weekly_publication_rejects_nz_ambiguous_hour_and_partial_values_before_any_effect(): void
    {
        $auditBefore = AuditLog::count();
        foreach (['{"day":7,"time":"02:00"}', '{"day":7,"time":"02:59"}', '{"day":null,"time":""}', '{"day":1}'] as $value) {
            $this->actingAs($this->manager->fresh())->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [
                ['group' => 'controlled_counts', 'key' => 'cadence', 'from' => '', 'value' => 'week'],
                ['group' => 'controlled_counts', 'key' => 'weekly_anchor', 'from' => 'off', 'value' => $value],
            ]])->assertUnprocessable()->assertJsonValidationErrors('changes.1.value');
        }
        $this->assertDatabaseMissing('app_settings', ['key' => ControlledPolicy::COUNT_CADENCE]);
        $this->assertDatabaseMissing('app_settings', ['key' => ControlledPolicy::COUNT_WEEKLY_ANCHOR]);
        $this->assertDatabaseCount('medication_setting_changes', 0);
        $this->assertDatabaseCount('medication_events', 0);
        $this->assertDatabaseCount('audit_logs', $auditBefore);
    }

    public function test_weekly_anchor_keeps_exact_settings_controlled_authority_and_compare_and_swap(): void
    {
        $change = ['group' => 'controlled_counts', 'key' => 'weekly_anchor', 'from' => 'off', 'value' => '{"day":1,"time":"09:00"}'];
        foreach (['medications.controlled.view', 'medications.settings.manage'] as $capability) {
            $this->permissions($this->manager, [$capability => false]);
            $this->actingAs($this->manager->fresh())->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [$change]])->assertForbidden();
            $this->permissions($this->manager, [$capability => true]);
        }
        $this->actingAs($this->recorder->fresh())->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [$change]])->assertForbidden();
        $this->actingAs($this->manager->fresh())->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [$change]])->assertRedirect()->assertSessionHasNoErrors();
        $this->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [[...$change, 'value' => '{"day":2,"time":"09:00"}']]])->assertUnprocessable();
        $this->assertDatabaseCount('medication_setting_changes', 1);
        $this->assertSame($change['value'], AppSetting::where('key', ControlledPolicy::COUNT_WEEKLY_ANCHOR)->sole()->value);
    }

    public function test_configured_weekly_anchor_change_and_history_restore_require_loosening_confirmation(): void
    {
        $first = '{"day":1,"time":"09:00"}';
        $second = '{"day":2,"time":"10:00"}';
        AppSetting::updateOrCreate(['key' => ControlledPolicy::COUNT_WEEKLY_ANCHOR], ['value' => $first]);
        $change = ['group' => 'controlled_counts', 'key' => 'weekly_anchor', 'from' => $first, 'value' => $second];
        $this->actingAs($this->manager->fresh())->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [$change]])->assertUnprocessable()->assertJsonValidationErrors('confirm_loosening');
        $this->assertDatabaseCount('medication_setting_changes', 0);
        $this->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [$change], 'confirm_loosening' => true])->assertRedirect()->assertSessionHasNoErrors();
        $history = MedicationSettingChange::where('setting_key', 'weekly_anchor')->sole();
        $this->assertTrue($history->loosens);
        $this->assertSame($first, $history->before_value);
        $restore = [...$change, 'from' => $second, 'value' => $history->before_value];
        $this->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [$restore]])->assertUnprocessable()->assertJsonValidationErrors('confirm_loosening');
        $this->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [$restore], 'confirm_loosening' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($first, AppSetting::where('key', ControlledPolicy::COUNT_WEEKLY_ANCHOR)->sole()->value);
        $this->assertDatabaseCount('medication_setting_changes', 2);
    }

    public function test_weekly_timing_cannot_clear_an_unfinished_due_count_but_completion_allows_reviewed_transition(): void
    {
        $anchor = '{"day":4,"time":"09:00"}';
        $this->seedWeeklyPolicy($anchor);
        $change = ['group' => 'controlled_counts', 'key' => 'weekly_anchor', 'from' => $anchor, 'value' => '{"day":4,"time":"11:00"}'];
        $this->actingAs($this->manager->fresh())->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [$change], 'confirm_loosening' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('controlled_counts.weekly_anchor');
        $this->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [
            ['group' => 'controlled_counts', 'key' => 'cadence', 'from' => 'week', 'value' => 'shift'],
        ], 'confirm_loosening' => true])->assertUnprocessable()->assertJsonValidationErrors('controlled_counts.weekly_anchor');
        $this->assertSame($anchor, AppSetting::where('key', ControlledPolicy::COUNT_WEEKLY_ANCHOR)->sole()->value);
        $this->assertDatabaseCount('medication_setting_changes', 0);
        $this->perform('count', ['actual_balance' => 10]);
        $this->actingAs($this->manager->fresh())->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [$change], 'confirm_loosening' => true])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['day' => 4, 'time' => '11:00'], app(ControlledPolicy::class)->weeklyAnchor());
        $this->assertSame('upcoming', app(ControlledPolicy::class)->countStatus($this->medication->fresh(), now(), ClientControlledDrugEntry::sole()->recorded_at)['status']);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame('10.00', $this->stock->fresh()->on_hand);
    }

    public function test_weekly_publication_retries_busy_current_medicine_and_stock_locks_without_waiting_or_writing(): void
    {
        $anchor = '{"day":4,"time":"09:00"}';
        $this->seedWeeklyPolicy($anchor);
        $auditBefore = AuditLog::count();
        $lockTarget = '';
        $inject = true;
        DB::connection()->beforeExecuting(function (string $query, array $bindings, $connection) use (&$lockTarget, &$inject): void {
            if ($inject && str_contains($query, $lockTarget) && str_contains($query, 'for share nowait')) {
                $cause = new \PDOException('Statement aborted because locks could not be acquired immediately and NOWAIT is set.');
                $cause->errorInfo = ['HY000', 3572, $cause->getMessage()];
                throw new QueryException($connection->getName(), $query, $bindings, $cause);
            }
        });
        try {
            foreach (['client_medications', 'client_medication_stocks'] as $table) {
                $lockTarget = $table;
                $response = $this->actingAs($this->manager->fresh())->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [
                    ['group' => 'controlled_counts', 'key' => 'weekly_anchor', 'from' => $anchor, 'value' => '{"day":4,"time":"11:00"}'],
                ], 'confirm_loosening' => true])->assertUnprocessable()->assertJsonValidationErrors('controlled_counts.weekly_anchor');
                $this->assertStringContainsString('Counts are being recorded now', $response->json('errors')['controlled_counts.weekly_anchor'][0], $table);
                $this->assertSame($anchor, AppSetting::where('key', ControlledPolicy::COUNT_WEEKLY_ANCHOR)->sole()->value);
                $this->assertDatabaseCount('medication_setting_changes', 0);
                $this->assertDatabaseCount('audit_logs', $auditBefore);
                $this->assertDatabaseCount('client_controlled_drug_entries', 0);
                $this->assertDatabaseCount('controlled_product_requests', 0);
                $this->assertSame('10.00', $this->stock->fresh()->on_hand);
            }
        } finally {
            $inject = false;
        }
    }

    public function test_weekly_publication_uses_locked_current_policy_even_when_implicit_policy_state_is_stale(): void
    {
        $anchor = '{"day":4,"time":"09:00"}';
        $this->seedWeeklyPolicy($anchor);
        $stale = new class extends ControlledPolicy
        {
            protected function organisationValue(string $key): mixed
            {
                return null;
            }
        };
        $this->app->instance(ControlledPolicy::class, $stale);
        $this->app->forgetInstance(ControlledCountStatus::class);
        $this->assertNull(app(ControlledPolicy::class)->cadence(), 'Simulate an older implicit settings snapshot.');
        $auditBefore = AuditLog::count();
        $response = $this->actingAs($this->manager->fresh())->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [
            ['group' => 'controlled_counts', 'key' => 'weekly_anchor', 'from' => $anchor, 'value' => '{"day":4,"time":"11:00"}'],
        ], 'confirm_loosening' => true])->assertUnprocessable()->assertJsonValidationErrors('controlled_counts.weekly_anchor');
        $this->assertStringNotContainsString($this->medication->name, json_encode($response->json('errors')));
        $this->assertStringNotContainsString($this->client->first_name, json_encode($response->json('errors')));
        $this->assertSame($anchor, AppSetting::where('key', ControlledPolicy::COUNT_WEEKLY_ANCHOR)->sole()->value);
        $this->assertDatabaseCount('medication_setting_changes', 0);
        $this->assertDatabaseCount('audit_logs', $auditBefore);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame('10.00', $this->stock->fresh()->on_hand);
    }

    public function test_weekly_count_alerts_use_actual_witnessed_evidence_for_retained_physical_stock(): void
    {
        $this->seedWeeklyPolicy('{"day":4,"time":"08:00"}');
        DB::table('client_medications')->where('id', $this->medication->id)->update([
            'state' => 'ceased', 'active' => false, 'deleted_at' => now(),
            'ceased_at' => now(), 'ceased_by' => $this->manager->id, 'ceased_reason' => 'Synthetic retained stock',
        ]);
        $this->artisan('emar:escalate-overdue-cd-checks')->assertExitCode(0);
        $this->assertDatabaseHas('medication_dashboard_alerts', ['client_medication_id' => $this->medication->id, 'alert_type' => 'controlled_overdue_check', 'status' => 'active']);
        $this->assertDatabaseHas('medication_alerts', ['type' => 'cdCheck', 'status' => 'open']);
        $this->actingAs($this->manager->fresh())->getJson('/emar/controlled/product')->assertOk()
            ->assertJsonPath('medicines.0.count.title', 'Weekly count overdue')->assertJsonPath('medicines.0.count.cadence', 'week');
        $this->perform('count', ['actual_balance' => 10]);
        $this->artisan('emar:escalate-overdue-cd-checks')->assertExitCode(0);
        $this->assertDatabaseMissing('medication_dashboard_alerts', ['alert_type' => 'controlled_overdue_check', 'status' => 'active']);
        $this->assertDatabaseMissing('medication_alerts', ['type' => 'cdCheck', 'status' => 'open']);
        $this->assertSame('complete', app(ControlledPolicy::class)->countStatus($this->medication->fresh(), now(), ClientControlledDrugEntry::sole()->recorded_at)['status']);
        $this->assertSame('10.00', $this->stock->fresh()->on_hand);
    }

    public function test_weekly_settings_audit_failure_rolls_back_the_whole_policy_and_history(): void
    {
        $auditBefore = AuditLog::count();
        MedicationEvent::creating(function (MedicationEvent $event): void {
            if ($event->kind === 'settings.changed') {
                throw new RuntimeException('Injected weekly publication audit failure.');
            }
        });
        try {
            $this->withoutExceptionHandling()->actingAs($this->manager->fresh())->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [
                ['group' => 'controlled_counts', 'key' => 'cadence', 'from' => '', 'value' => 'week'],
                ['group' => 'controlled_counts', 'key' => 'weekly_anchor', 'from' => 'off', 'value' => '{"day":4,"time":"09:00"}'],
            ]]);
            $this->fail('A publication audit failure must roll back the complete weekly policy.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected weekly publication audit failure.', $exception->getMessage());
        }
        $this->assertDatabaseMissing('app_settings', ['key' => ControlledPolicy::COUNT_CADENCE]);
        $this->assertDatabaseMissing('app_settings', ['key' => ControlledPolicy::COUNT_WEEKLY_ANCHOR]);
        $this->assertDatabaseCount('medication_setting_changes', 0);
        $this->assertDatabaseCount('audit_logs', $auditBefore);
        $this->assertDatabaseCount('medication_events', 0);
    }

    public function test_configured_count_scheduler_checks_deadlines_every_five_minutes_without_overlap(): void
    {
        $this->artisan('schedule:list')->assertExitCode(0);
        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains($event->command ?? '', 'emar:escalate-overdue-cd-checks'));
        $this->assertCount(1, $events);
        $event = $events->sole();
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertSame('Pacific/Auckland', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    private function seedWeeklyPolicy(string $anchor): void
    {
        foreach ([ControlledPolicy::COUNT_CADENCE => 'week', ControlledPolicy::COUNT_WEEKLY_ANCHOR => $anchor] as $key => $value) {
            AppSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        DB::table('app_settings')->whereIn('key', [ControlledPolicy::COUNT_CADENCE, ControlledPolicy::COUNT_WEEKLY_ANCHOR])->update(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        DB::table('client_medications')->where('id', $this->medication->id)->update(['created_at' => now()->subDay()]);
    }

    public function test_class_review_of_ceased_order_changes_only_register_classification(): void
    {
        $this->medication->forceFill(['state' => 'ceased', 'active' => false, 'ceased_at' => now(), 'ceased_by' => $this->manager->id, 'ceased_reason' => 'Synthetic cessation.'])->save();
        $original = $this->medication->fresh()->only(['name', 'dosage', 'active', 'state', 'ceased_reason', 'ceased_at', 'cd_schedule', 'witness_required']);
        $this->actionRequest('class_review', ['nz_class' => 'C', 'source' => 'Reviewed synthetic NZ source.', 'notes' => 'Register review.'], $this->manager)->assertOk();
        $this->assertEquals($original, $this->medication->fresh()->only(array_keys($original)));
        $this->assertSame('C', $this->medication->fresh()->nz_controlled_class);
        $this->expectException(\LogicException::class);
        $this->medication->fresh()->update(['dosage' => '2 tablets']);
    }

    public function test_replay_rechecks_current_witness_pin_permission_and_recorder_presence_without_repeating_stock(): void
    {
        $this->client->supportWorkers()->syncWithoutDetaching([$this->recorder->id]);
        $input = $this->input(['movement_type' => 'going_out', 'quantity' => 1, 'actual_balance' => 9]);
        $service = app(ControlledRegisterService::class);
        $first = $service->perform($this->recorder->fresh(), 'movement', $input);
        $retry = [...$input, 'quantity' => '1.00', 'expected_balance' => 10, 'actual_balance' => '9.0'];
        $replayed = $service->perform($this->recorder->fresh(), 'movement', $retry);
        ksort($first);
        ksort($replayed);
        $this->assertSame($first, $replayed);
        $this->assertTrue($service->lastRequestWasReplay());
        $this->invalid('witness_credential', fn () => $service->perform($this->recorder->fresh(), 'movement', [...$retry, 'witness_credential' => '000000']));
        $this->permissions($this->witness, ['medications.controlled.witness' => false]);
        $this->denied(404, fn () => $service->perform($this->recorder->fresh(), 'movement', $retry));
        $this->permissions($this->witness, ['medications.controlled.witness' => true]);
        Shift::query()->where('user_id', $this->recorder->id)->update(['status' => 'cancelled']);
        $this->invalid('presence', fn () => $service->perform($this->recorder->fresh(), 'movement', $retry));
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame('9.00', $this->stock->fresh()->on_hand);
        $this->assertSame(1, DB::table('controlled_product_requests')->count());
    }

    public function test_denaturing_retry_rechecks_the_second_witness_and_keeps_canonical_snapshots(): void
    {
        AppSetting::query()->updateOrCreate(['key' => ControlledPolicy::ONSITE_DESTRUCTION], ['value' => 'on']);
        $this->medication->forceFill(['form' => 'tablet', 'dosage' => '5 mg'])->save();
        $input = $this->input(['quantity' => 2, 'reason' => 'expired', 'method' => 'denaturing',
            'second_witness_id' => $this->manager->id, 'second_witness_credential' => UserFactory::TEST_WITNESS_PIN,
            'medication_name' => 'Forged label', 'form' => 'Forged form', 'strength' => 'Forged strength']);
        $service = app(ControlledRegisterService::class);
        $first = $service->perform($this->lead->fresh(), 'destruction', $input);
        $row = MedicationDestruction::query()->sole();
        $this->assertSame($this->medication->name, $row->medication_name);
        $this->assertSame('tablet', $row->form);
        $this->assertSame('5 mg', $row->strength);
        $this->permissions($this->manager, ['medications.controlled.witness' => false]);
        $this->denied(404, fn () => $service->perform($this->lead->fresh(), 'destruction', $input));
        $this->assertSame($row->id, $first['target_id']);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame('8.00', $this->stock->fresh()->on_hand);
        $payload = $this->actingAs($this->lead->fresh())->getJson('/emar/controlled/product')->assertOk();
        $payload->assertJsonPath('destructions.0.form', 'tablet')->assertJsonPath('destructions.0.strength', '5 mg');
    }

    public function test_disposal_void_after_later_movement_keeps_physical_stock_and_original_entry(): void
    {
        $result = $this->perform('destruction', ['method' => 'pharmacy_return', 'reason' => 'expired', 'quantity' => 2], $this->lead);
        $original = ClientControlledDrugEntry::findOrFail($result['entry_id'])->getRawOriginal();
        $this->perform('movement', ['movement_type' => 'going_out', 'quantity' => 1, 'actual_balance' => 7]);
        $this->perform('destruction_void', ['target_id' => $result['target_id'], 'expected_balance' => 10,
            'expected_entry_id' => null, 'witnessed_by' => null, 'witness_credential' => null,
            'notes' => 'Administrative duplicate; physical stock requires separate reconciliation.'], $this->lead);
        $this->assertSame('7.00', $this->stock->fresh()->on_hand);
        $this->assertSame($original, ClientControlledDrugEntry::findOrFail($result['entry_id'])->getRawOriginal());
        $this->assertDatabaseCount('client_controlled_drug_entries', 2);
        $audit = AuditLog::where('action', 'medications.destruction.void')->latest('id')->firstOrFail();
        $this->assertFalse($audit->meta['stock_effect_reversed']);
        $this->assertTrue($audit->meta['requires_governed_stock_reconciliation']);
    }

    public function test_count_policy_covers_retained_stock_and_ignores_empty_historical_orders(): void
    {
        AppSetting::query()->updateOrCreate(['key' => ControlledPolicy::COUNT_CADENCE], ['value' => 'shift']);
        DB::table('client_medications')->where('id', $this->medication->id)->update(['created_at' => now()->subDay()]);
        $stockMedicine = $this->medicine($this->client);
        $emptyMedicine = $this->medicine($this->client);
        foreach ([$stockMedicine, $emptyMedicine] as $medicine) {
            DB::table('client_medications')->where('id', $medicine->id)->update([
                'state' => 'ceased', 'active' => false, 'created_at' => now()->subDay(),
                'ceased_at' => now()->subMinutes(5), 'ceased_by' => $this->manager->id, 'ceased_reason' => 'Synthetic cessation',
                'deleted_at' => now(),
            ]);
        }
        ClientMedicationStock::create(['client_medication_id' => $stockMedicine->id, 'on_hand' => 2, 'unit' => 'tablet']);
        ClientMedicationStock::create(['client_medication_id' => $emptyMedicine->id, 'on_hand' => 0, 'unit' => 'tablet']);
        $status = app(ControlledCountStatus::class);
        $this->assertEqualsCanonicalizing([$this->medication->id, $stockMedicine->id], $status->overdueMedicines($this->site->id)->pluck('id')->all());
        $payload = $this->actingAs($this->manager->fresh())->getJson('/emar/controlled/product')->assertOk()->json();
        $rows = collect($payload['medicines'])->keyBy('id');
        $this->assertSame('overdue', $rows[$stockMedicine->id]['count']['state']);
        $this->assertSame('No stock count required', $rows[$emptyMedicine->id]['count']['title']);
        $this->assertSame(2, $payload['meters']['total_overdue_counts']);
        $this->assertFalse($rows[$stockMedicine->id]['can_record']);
        $this->assertTrue($rows[$stockMedicine->id]['can_count']);
        $this->assertFalse($rows[$emptyMedicine->id]['can_count']);
        $this->assertSame('not_applicable', $rows[$emptyMedicine->id]['count']['state']);
        $this->perform('count', ['client_medication_id' => $stockMedicine->id, 'expected_entry_id' => null, 'expected_balance' => 2, 'actual_balance' => 2]);
        $this->assertSame([$this->medication->id], $status->overdueMedicines($this->site->id)->pluck('id')->all());
        $this->denied(404, fn () => $this->perform('count', ['client_medication_id' => $emptyMedicine->id,
            'expected_entry_id' => null, 'expected_balance' => 0, 'actual_balance' => 0]));
    }

    public function test_historical_count_to_zero_replays_its_receipt_without_enabling_a_new_zero_stock_count(): void
    {
        DB::table('client_medications')->where('id', $this->medication->id)->update([
            'state' => 'ceased', 'active' => false, 'deleted_at' => now(),
            'ceased_at' => now(), 'ceased_by' => $this->manager->id, 'ceased_reason' => 'Synthetic historical stock',
        ]);
        $actor = $this->recorder->fresh();
        $this->actingAs($actor);
        $input = $this->input([
            'actual_balance' => 0, 'recount_balance' => 0,
            'notes' => 'Synthetic retained stock was missing on two independent counts.',
            'immediate_action_taken' => 'Secured the cupboard and informed the house lead.',
        ]);
        $service = app(ControlledRegisterService::class);
        $injectFailure = true;
        ClientIncident::creating(function () use (&$injectFailure): void {
            if ($injectFailure) {
                throw new RuntimeException('Injected historical count incident failure.');
            }
        });
        try {
            $service->perform($actor, 'count', $input);
            $this->fail('The incident failure must roll back the complete historical count.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected historical count incident failure.', $exception->getMessage());
        } finally {
            $injectFailure = false;
        }
        $this->assertSame('10.00', $this->stock->fresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('client_controlled_drug_discrepancies', 0);
        $this->assertDatabaseCount('controlled_product_requests', 0);
        $this->assertDatabaseCount('client_incidents', 0);

        $result = $service->perform($actor->fresh(), 'count', $input);
        $original = ClientControlledDrugEntry::query()->sole()->getRawOriginal();
        $this->assertSame('0.00', $this->stock->fresh()->on_hand);
        $discrepancy = ClientControlledDrugDiscrepancy::query()->sole();
        $this->assertSame($this->medication->id, $discrepancy->medication->id);
        $this->assertTrue($discrepancy->medication->trashed());
        $incident = $discrepancy->incident;
        $this->assertNotNull($incident);
        $this->assertSame($this->client->id, (int) $incident->client_id);
        $this->assertSame($this->medication->id, $incident->metadata['medication_id']);
        $this->assertTrue($incident->metadata['controlled_drug']);
        $this->assertArrayNotHasKey('medication_name', $incident->metadata);
        $this->assertStringNotContainsString($this->medication->name, $incident->title);

        $this->assertEquals($result, $service->perform($actor->fresh(), 'count', $input));
        $this->assertTrue($service->lastRequestWasReplay());
        $this->denied(404, fn () => $service->perform($actor->fresh(), 'count', [
            ...$input, 'client_request_uuid' => (string) Str::uuid(),
            'expected_balance' => 0, 'expected_entry_id' => $result['counted_entry_id'],
        ]));

        $this->assertSame('0.00', $this->stock->fresh()->on_hand);
        $this->assertSame($original, ClientControlledDrugEntry::query()->sole()->getRawOriginal());
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertDatabaseCount('client_controlled_drug_discrepancies', 1);
        $this->assertDatabaseCount('controlled_product_requests', 1);
        $this->assertDatabaseCount('client_incidents', 1);
    }

    public function test_canonical_ceased_zero_stock_denies_new_counts_without_effects(): void
    {
        $this->permissions($this->manager, ['medications.orders.manage' => true]);
        $this->actingAs($this->manager->fresh());
        $stopped = app(MedicationOrderLifecycleService::class)->discontinue(
            $this->manager->fresh(), $this->medication, 'Synthetic canonical cessation', $this->client->id,
        );
        $this->assertSame('ceased', $stopped->state);
        $this->assertFalse($stopped->active);
        $this->assertNull($stopped->deleted_at);
        $this->assertNull($stopped->superseded_by);
        $this->stock->update(['on_hand' => 0]);
        $medicineBefore = $stopped->fresh()->getRawOriginal();
        $stockBefore = $this->stock->fresh()->getRawOriginal();
        $evidenceCounts = [];
        foreach (['client_controlled_drug_entries', 'client_controlled_drug_discrepancies',
            'controlled_product_requests', 'client_incidents', 'medication_events', 'audit_logs'] as $table) {
            $evidenceCounts[$table] = DB::table($table)->count();
        }
        $this->actingAs($this->recorder->fresh())->getJson('/emar/controlled/product')->assertOk()
            ->assertJsonPath('medicines.0.can_count', false)
            ->assertJsonPath('medicines.0.count.state', 'not_applicable');

        foreach ([0, 5] as $attemptedBalance) {
            $this->actionRequest('count', [
                'actual_balance' => $attemptedBalance, 'recount_balance' => $attemptedBalance,
                'notes' => 'Synthetic attempt to count an empty stopped order.',
                'immediate_action_taken' => 'Synthetic no-effects regression.',
            ])->assertNotFound();

            $this->assertSame($stockBefore, $this->stock->fresh()->getRawOriginal());
            $this->assertSame($medicineBefore, $stopped->fresh()->getRawOriginal());
            foreach ($evidenceCounts as $table => $count) {
                $this->assertDatabaseCount($table, $count);
            }
        }
    }

    public function test_canonical_ceased_positive_stock_count_to_zero_keeps_exact_replay(): void
    {
        $this->permissions($this->manager, ['medications.orders.manage' => true]);
        $this->actingAs($this->manager->fresh());
        $stopped = app(MedicationOrderLifecycleService::class)->discontinue(
            $this->manager->fresh(), $this->medication, 'Synthetic canonical cessation', $this->client->id,
        );
        $this->assertNull($stopped->deleted_at);
        $this->assertNull($stopped->superseded_by);
        $medicineBefore = $stopped->fresh()->getRawOriginal();
        $request = $this->input([
            'actual_balance' => 0, 'recount_balance' => 0,
            'notes' => 'Synthetic retained stock was missing on both counts.',
            'immediate_action_taken' => 'Secured the cupboard and informed the house lead.',
        ]);
        $this->actingAs($this->recorder->fresh());
        $service = app(ControlledRegisterService::class);
        $result = $service->perform($this->recorder->fresh(), 'count', $request);
        $entryBefore = ClientControlledDrugEntry::query()->sole()->getRawOriginal();
        $stockBeforeReplay = $this->stock->fresh()->getRawOriginal();
        $this->assertSame('0.00', $this->stock->fresh()->on_hand);
        $this->assertSame($medicineBefore, $stopped->fresh()->getRawOriginal());
        $eventCount = MedicationEvent::query()->count();
        $auditCount = AuditLog::query()->count();

        $this->assertEquals($result, $service->perform($this->recorder->fresh(), 'count', $request));
        $this->assertTrue($service->lastRequestWasReplay());
        $this->assertSame($stockBeforeReplay, $this->stock->fresh()->getRawOriginal());
        $this->assertSame($entryBefore, ClientControlledDrugEntry::query()->sole()->getRawOriginal());
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertDatabaseCount('client_controlled_drug_discrepancies', 1);
        $this->assertDatabaseCount('controlled_product_requests', 1);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('medication_events', $eventCount);
        $this->assertDatabaseCount('audit_logs', $auditCount);
    }

    public function test_active_zero_stock_still_accepts_a_witnessed_zero_count(): void
    {
        $this->stock->update(['on_hand' => 0]);
        $this->assertTrue($this->medication->fresh()->isActive());

        $result = $this->actionRequest('count', ['actual_balance' => 0])->assertOk()->json();
        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'id' => $result['counted_entry_id'], 'entry_type' => 'balance_check',
            'client_medication_id' => $this->medication->id, 'on_hand_before' => 0, 'on_hand_after' => 0,
        ]);
        $this->assertSame('0.00', $this->stock->fresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertDatabaseCount('client_controlled_drug_discrepancies', 0);
        $this->assertDatabaseCount('controlled_product_requests', 1);
        $this->assertDatabaseCount('client_incidents', 0);
    }

    public function test_count_alerts_reconcile_by_configured_policy_and_latest_actual_witnessed_time(): void
    {
        $status = app(ControlledCountStatus::class);
        $this->assertCount(0, $status->overdueMedicines());
        AppSetting::query()->updateOrCreate(['key' => ControlledPolicy::COUNT_CADENCE], ['value' => 'shift']);
        DB::table('client_medications')->where('id', $this->medication->id)->update(['created_at' => now()->subDay()]);
        $this->assertCount(1, $status->refreshDashboardAlerts($this->site->id));
        $this->assertDatabaseHas('medication_dashboard_alerts', ['alert_type' => 'controlled_overdue_check', 'status' => 'active']);
        $base = ['client_id' => $this->client->id, 'client_medication_id' => $this->medication->id,
            'entry_type' => 'balance_check', 'quantity' => 0, 'on_hand_before' => 10, 'on_hand_after' => 10,
            'recorded_by' => $this->recorder->id, 'witnessed_by' => $this->witness->id];
        $latest = ClientControlledDrugEntry::create([...$base, 'recorded_at' => now()->subMinutes(10)]);
        ClientControlledDrugEntry::create([...$base, 'recorded_at' => now()->subHours(3)]);
        ClientControlledDrugEntry::create([...$base, 'recorded_at' => now()->addHour()]);
        ClientControlledDrugEntry::create([...$base, 'recorded_at' => now(), 'witnessed_by' => null]);
        $this->assertSame($latest->id, $status->latestWitnessedCounts([$this->medication->id])->get($this->medication->id)->id);
        app(MedicationAlertSources::class)->controlledCheckRecorded($this->site->id);
        $this->assertDatabaseMissing('medication_dashboard_alerts', ['alert_type' => 'controlled_overdue_check', 'status' => 'active']);
        $payload = $this->actingAs($this->manager->fresh())->getJson('/emar/controlled/product')->assertOk();
        $payload->assertJsonPath('medicines.0.count.state', 'counted')->assertJsonPath('medicines.0.count.last_entry_id', $latest->id);
        AppSetting::where('key', ControlledPolicy::COUNT_CADENCE)->sole()->update(['value' => '']);
        $this->assertCount(0, $status->dueMedicines());
    }

    /** Fields sent by the dialogs after their action-specific canonical mapping. */
    private function form(array $fields): array
    {
        return $this->input([
            'target_id' => null, 'timezone' => 'Pacific/Auckland', 'direction' => 'out', 'method' => 'pharmacy_return',
            'discovered_at' => '2026-04-30T09:30', 'received_at' => '2026-04-30T09:30', 'starts_at' => '2026-04-30T09:30',
            'expires_at' => '', 'outcome' => '', 'decision' => '', 'response' => '', 'nz_class' => '', ...$fields,
        ]);
    }

    private function actionRequest(string $action, array $fields = [], ?User $actor = null)
    {
        if ($action === 'loss_close') {
            $fields = ['resolution_outcome' => 'unexplained', 'notifications_checked' => true, ...$fields];
        }

        return $this->actingAs(($actor ?? $this->recorder)->fresh())
            ->postJson('/emar/controlled/product/actions/'.$action, $this->form($fields));
    }

    private function perform(string $action, array $fields = [], ?User $actor = null): array
    {
        if ($action === 'loss_close') {
            $fields = ['resolution_outcome' => 'unexplained', 'notifications_checked' => true, ...$fields];
        }
        $actor = ($actor ?? $this->recorder)->fresh();
        $this->actingAs($actor);

        return app(ControlledRegisterService::class)->perform($actor, $action, $this->input($fields));
    }

    private function input(array $fields): array
    {
        return [
            'client_medication_id' => $this->medication->id, 'client_request_uuid' => (string) Str::uuid(),
            'expected_balance' => $this->stock->refresh()->on_hand,
            'expected_entry_id' => ClientControlledDrugEntry::query()->where('client_medication_id', $this->medication->id)->latest('id')->value('id'),
            'witnessed_by' => $this->witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN, ...$fields,
        ];
    }

    private function discrepancy(): array
    {
        return $this->perform('count', ['actual_balance' => 8, 'recount_balance' => 8, 'notes' => 'Synthetic stock shortfall confirmed twice.',
            'immediate_action_taken' => 'Secured stock and told the house lead.']);
    }

    private function denied(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a concealed or unauthorised controlled action.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function invalid(string $field, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a controlled workflow validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function medicine(Client $client): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Synthetic controlled medicine', 'dosage' => '1 tablet', 'frequency' => 'Daily',
            'dose_times' => ['09:30'], 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
            'controlled_drug' => true, 'nz_controlled_class' => 'B', 'controlled_class_source' => 'Synthetic reviewed test configuration',
        ]);
    }

    private function staff(string $roleName, string $name, ?Site $site = null, ?Client $client = null): User
    {
        $site ??= $this->site;
        $client ??= $this->client;
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now(), 'name' => $name]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->sole()->id]);
        $this->permissions($user, [
            'medications.view' => true, 'medications.administer.record' => true,
            'medications.controlled.view' => true, 'medications.controlled.record' => true,
            'medications.controlled.witness' => true, ControlledRegisterService::MANAGE => in_array($roleName, ['team_lead', 'provider_manager'], true),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subYear(), 'end_date' => null, 'is_active' => true,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $user->id, 'assessor_id' => User::factory()->create()->id, 'assessment_type' => 'annual',
            'status' => 'passed', 'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true, 'can_witness_controlled' => true, 'controlled_drugs' => true,
            'restricted' => false, 'not_seen_areas' => [],
        ]);
        Shift::factory()->create([
            'user_id' => $user->id, 'client_id' => $client->id, 'site_id' => $site->id,
            'service_context_id' => $client->service_context_id, 'starts_at' => now()->subHour()->utc(), 'ends_at' => now()->addHours(3)->utc(),
            'actual_starts_at' => now()->subMinutes(30)->utc(), 'actual_ends_at' => null, 'status' => 'in_progress',
        ]);

        return $user;
    }

    private function permissions(User $user, array $permissions): void
    {
        $user->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', array_keys($permissions))->get()
            ->mapWithKeys(fn (Permission $permission): array => [$permission->id => ['allowed' => $permissions[$permission->key]]])->all());
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        Cache::flush();
    }
}
