<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationEvent;
use App\Models\MedicationFollowup;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationStockCountRecord;
use App\Models\MedicationStockLot;
use App\Models\MedicationStockMovement;
use App\Models\MedicationStockPhoto;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Stock\MedicationStockService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockPacksWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(?string $expiryDate = null, bool $startPacks = true): array
    {
        config(['medications.stock_lots_enabled' => true]);
        $this->seed(RbacSeeder::class);
        $actor = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $actor->roles()->syncWithoutDetaching([\App\Models\Role::where('name', 'admin')->firstOrFail()->id]);
        $permissions = \App\Models\Permission::whereIn('key', ['medications.view', 'medications.stock.update'])
            ->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all();
        $actor->permissionOverrides()->syncWithoutDetaching($permissions);
        $site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subMonth(), 'end_date' => null, 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $med = ClientMedication::query()->forceCreate(['client_id' => $client->id, 'name' => 'Synthetic test medicine', 'dosage' => '1 tablet',
            'frequency' => 'PRN', 'is_prn' => true, 'controlled_drug' => false, 'active' => true, 'state' => 'active', 'approval_status' => 'verified']);
        $stock = ClientMedicationStock::create(['client_medication_id' => $med->id, 'on_hand' => '10.00', 'unit' => 'tablets', 'expiry_date' => $expiryDate, 'last_counted_at' => now()->subDays(3)]);
        if ($startPacks) {
            DB::transaction(function () use ($stock, $actor) {
                app(MedicationStockService::class)->startLots($stock, $actor, (string) Str::uuid());
            });
        }
        return compact('actor', 'site', 'client', 'med', 'stock');
    }

    private function receipt(int $medicationId): array
    {
        return ['action' => 'receive', 'client_medication_id' => $medicationId, 'request_uuid' => (string) Str::uuid(),
            'quantity' => '4.25', 'source' => 'pharmacy', 'source_reference' => 'Synthetic delivery reference',
            'label_checked' => true, 'batch_number' => 'TEST-01', 'batch_not_printed' => false,
            'expiry_month' => now('Pacific/Auckland')->addMonths(3)->format('m/Y'), 'expiry_not_printed' => false];
    }

    private function delivery(int $medicationId, array $quantities): array
    {
        $base = $this->receipt($medicationId);
        $fields = ['quantity', 'batch_number', 'batch_not_printed', 'expiry_month', 'expiry_not_printed', 'short_expiry_reason'];
        $label = \Illuminate\Support\Arr::only($base, $fields);
        return [...\Illuminate\Support\Arr::except($base, $fields),
            'packs' => array_map(fn ($quantity, $index) => [...$label, 'quantity' => $quantity, 'batch_number' => 'SYNTHETIC-'.$index],
                $quantities, array_keys($quantities))];
    }

    public function test_two_batch_delivery_uses_dispensed_quantity_closes_once_and_preserves_each_label(): void
    {
        extract($this->fixture());
        $lastCounted = $stock->last_counted_at->toIso8601String();
        $order = MedicationPharmacyOrder::create(['client_id' => $client->id, 'client_medication_id' => $med->id,
            'pharmacy_name' => 'Synthetic Pharmacy', 'quantity_ordered' => 10, 'quantity_dispensed' => '6.00',
            'quantity_received' => 0, 'status' => 'dispensed', 'ordered_by' => $actor->id]);
        $data = [...$this->delivery($med->id, ['2.25', '3.75']), 'pharmacy_order_id' => $order->id];
        $first = $this->actingAs($actor)->postJson('/emar/stock/packs/commands', $data)->assertOk();
        $this->postJson('/emar/stock/packs/commands', $data)->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('lot_ids', $first->json('lot_ids'));
        $this->assertCount(2, $first->json('lot_ids'));
        $this->assertSame('16.00', $stock->fresh()->on_hand);
        $this->assertSame($lastCounted, $stock->fresh()->last_counted_at->toIso8601String());
        $this->assertSame('6.00', $order->fresh()->quantity_received);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(['SYNTHETIC-0', 'SYNTHETIC-1'], MedicationStockLot::where('pharmacy_order_id', $order->id)->orderBy('id')->pluck('batch_number')->all());
        $this->assertSame(2, MedicationStockMovement::where('kind', 'received')->count());
        $this->assertSame(1, MedicationEvent::where('kind', 'stock.receive')->count());
    }

    public function test_short_delivery_requires_explicit_outcome_and_reason_before_atomic_close_short(): void
    {
        extract($this->fixture());
        $order = MedicationPharmacyOrder::create(['client_id' => $client->id, 'client_medication_id' => $med->id,
            'pharmacy_name' => 'Synthetic Pharmacy', 'quantity_ordered' => 10, 'quantity_dispensed' => '10.00',
            'quantity_received' => 0, 'status' => 'dispensed', 'ordered_by' => $actor->id]);
        $data = [...$this->delivery($med->id, ['2.00', '3.00']), 'pharmacy_order_id' => $order->id];
        $this->actingAs($actor)->postJson('/emar/stock/packs/commands', $data)->assertUnprocessable()->assertJsonValidationErrors('delivery_outcome');
        $this->postJson('/emar/stock/packs/commands', [...$data, 'delivery_outcome' => 'closed_short'])
            ->assertUnprocessable()->assertJsonValidationErrors('delivery_outcome');
        $this->assertSame('10.00', $stock->fresh()->on_hand);
        $this->assertSame('dispensed', $order->fresh()->status);
        $this->assertSame(0, MedicationStockLot::where('pharmacy_order_id', $order->id)->count());
        $data = [...$data, 'delivery_outcome' => 'closed_short', 'closure_reason' => 'Synthetic pharmacy confirmed no more coming'];
        $this->postJson('/emar/stock/packs/commands', $data)->assertOk();
        $this->postJson('/emar/stock/packs/commands', $data)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame('closed_short', $order->fresh()->status);
        $this->assertSame($data['closure_reason'], $order->fresh()->closure_reason);
        $this->assertSame('5.00', $order->fresh()->quantity_received);
        $this->assertSame('15.00', $stock->fresh()->on_hand);
    }

    public function test_invalid_second_batch_rolls_back_all_packs_pharmacy_totals_and_replay_receipt(): void
    {
        extract($this->fixture());
        $order = MedicationPharmacyOrder::create(['client_id' => $client->id, 'client_medication_id' => $med->id,
            'pharmacy_name' => 'Synthetic Pharmacy', 'quantity_ordered' => 6, 'quantity_dispensed' => '6.00',
            'quantity_received' => 0, 'status' => 'dispensed', 'ordered_by' => $actor->id]);
        $data = [...$this->delivery($med->id, ['2.25', '3.75']), 'pharmacy_order_id' => $order->id];
        $data['packs'][1]['expiry_month'] = now('Pacific/Auckland')->subMonths(2)->format('m/Y');
        $this->actingAs($actor)->postJson('/emar/stock/packs/commands', $data)->assertUnprocessable()->assertJsonValidationErrors('packs.1.expiry_month');
        $this->assertSame('10.00', $stock->fresh()->on_hand);
        $this->assertSame('0.00', $order->fresh()->quantity_received);
        $this->assertSame('dispensed', $order->fresh()->status);
        $this->assertSame(0, MedicationStockLot::where('pharmacy_order_id', $order->id)->count());
        $this->assertSame(0, MedicationStockMovement::where('kind', 'received')->count());
        $this->assertSame(0, MedicationEvent::where('kind', 'stock.receive')->count());
        $data['packs'][1]['expiry_month'] = now('Pacific/Auckland')->addMonths(3)->format('m/Y');
        $this->postJson('/emar/stock/packs/commands', $data)->assertOk();
        $this->assertSame('16.00', $stock->fresh()->on_hand);
    }

    public function test_receipt_on_last_printed_day_requires_short_expiry_reason_then_remains_usable(): void
    {
        $clock = \Carbon\CarbonImmutable::parse('2026-10-31 09:00:00', 'Pacific/Auckland')->utc();
        \Carbon\Carbon::setTestNow($clock);
        \Carbon\CarbonImmutable::setTestNow($clock);
        try {
            extract($this->fixture());
            $data = [...$this->receipt($med->id), 'expiry_month' => '10/2026'];
            $this->actingAs($actor)->postJson('/emar/stock/packs/commands', $data)->assertUnprocessable()->assertJsonValidationErrors('short_expiry_reason');
            $data['short_expiry_reason'] = 'Synthetic acceptance reason on the printed expiry day';
            $this->postJson('/emar/stock/packs/commands', $data)->assertOk();
            $this->assertSame('14.25', $stock->fresh()->availableQuantity());
            $nextDay = $clock->addDay();
            \Carbon\Carbon::setTestNow($nextDay); \Carbon\CarbonImmutable::setTestNow($nextDay);
            $this->assertSame('10.00', $stock->fresh()->availableQuantity());
            $this->assertSame('14.25', $stock->fresh()->on_hand);
        } finally {
            \Carbon\Carbon::setTestNow(); \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_receipt_rejects_scalar_and_pack_quantities_together_without_writing(): void
    {
        extract($this->fixture());
        $data = $this->delivery($med->id, ['2.00']);
        $data['quantity'] = '3.00';
        $this->actingAs($actor)->postJson('/emar/stock/packs/commands', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame('10.00', $stock->fresh()->on_hand);
        $this->assertSame(0, MedicationStockMovement::where('kind', 'received')->count());
    }

    public function test_nested_pack_cannot_override_operation_identity_or_stock_unit(): void
    {
        extract($this->fixture());
        $data = $this->delivery($med->id, ['2.00']);
        $data['packs'][0]['request_uuid'] = (string) Str::uuid();
        $this->actingAs($actor)->postJson('/emar/stock/packs/commands', $data)->assertUnprocessable()->assertJsonValidationErrors('packs.0');
        $this->assertSame('10.00', $stock->fresh()->on_hand);
        $this->assertSame(0, MedicationStockMovement::where('kind', 'received')->count());
    }

    public function test_empty_pack_count_requires_physical_confirmation_and_rejects_a_new_receipt_race(): void
    {
        extract($this->fixture(null, false));
        $stock->update(['on_hand' => '0.00']);
        DB::transaction(fn () => app(MedicationStockService::class)->startLots($stock, $actor, (string) Str::uuid()));
        $lastCounted = $stock->last_counted_at->toIso8601String();
        $data = ['action' => 'count', 'client_medication_id' => $med->id, 'request_uuid' => (string) Str::uuid(), 'lines' => []];
        $this->actingAs($actor)->postJson('/emar/stock/packs/commands', $data)->assertUnprocessable()->assertJsonValidationErrors('confirm_empty');
        $this->assertSame(0, MedicationStockCountRecord::count());
        $this->postJson('/emar/stock/packs/commands', [...$data, 'confirm_empty' => true])->assertOk();
        $this->assertSame([], MedicationStockCountRecord::sole()->lines);
        $this->assertNotSame($lastCounted, $stock->fresh()->last_counted_at->toIso8601String());
        $this->postJson('/emar/stock/packs/commands', $this->receipt($med->id))->assertOk();
        $this->postJson('/emar/stock/packs/commands', [...$data, 'request_uuid' => (string) Str::uuid(), 'confirm_empty' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertSame(1, MedicationStockCountRecord::count());
        $this->assertSame('4.25', $stock->fresh()->on_hand);
    }

    public function test_followup_count_link_returns_exact_scoped_record_and_denies_a_mismatched_medicine_filter(): void
    {
        extract($this->fixture());
        $lot = MedicationStockLot::where('client_medication_stock_id', $stock->id)->sole();
        $countId = $this->actingAs($actor)->postJson('/emar/stock/packs/commands', [
            'action' => 'count', 'client_medication_id' => $med->id, 'request_uuid' => (string) Str::uuid(),
            'lines' => [['lot_id' => $lot->id, 'revision' => $lot->revision, 'quantity' => '9.00']], 'reason' => 'Synthetic discrepancy',
        ])->assertOk()->json('count_id');
        $this->get('/emar/stock/packs?view=counts&count_id='.$countId, $this->inertiaPartialHeaders('emar/stock/StockHub', 'focused_count,metrics,items'))->assertOk()
            ->assertJsonPath('props.focused_count.id', $countId)->assertJsonPath('props.focused_count.medication_id', $med->id);
        $other = $med->replicate()->forceFill(['name' => 'Synthetic different medicine']);
        $other->save();
        $this->get('/emar/stock/packs?view=counts&count_id='.$countId.'&medication_id='.$other->id, $this->inertiaPartialHeaders('emar/stock/StockHub', 'focused_count,metrics,items'))->assertNotFound();
    }

    public function test_started_pack_stock_rejects_legacy_receipt_adjustment_and_profile_aliases_without_changes(): void
    {
        extract($this->fixture());
        $lastCounted = $stock->last_counted_at->toIso8601String();
        $lot = MedicationStockLot::where('client_medication_stock_id', $stock->id)->sole();
        $this->actingAs($actor)->postJson('/emar/stock/receive', [
            'client_medication_id' => $med->id, 'quantity' => '2.00',
            'scan_verified' => true, 'scan_code' => app(\App\Services\MedicationScanVerificationService::class)->internalCode($client, $med),
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity')
            ->assertJsonPath('errors.stock_workflow_url.0', $stock->packWorkflowUrl());
        $this->postJson('/emar/stock/adjust', [
            'client_medication_id' => $med->id, 'new_quantity' => '99.00', 'reason' => 'Synthetic scalar attempt',
        ])->assertUnprocessable()->assertJsonValidationErrors('new_quantity');
        foreach (['/clients/', '/operations/clients/'] as $prefix) {
            $this->putJson($prefix.$client->id.'/medical/medications/'.$med->id.'/stock', [
                'on_hand' => '99.00', 'unit' => 'tablets', 'reason' => 'Synthetic profile scalar attempt',
            ])->assertUnprocessable()->assertJsonValidationErrors('on_hand');
        }
        $this->assertSame('10.00', $stock->fresh()->on_hand);
        $this->assertSame($lastCounted, $stock->fresh()->last_counted_at->toIso8601String());
        $this->assertSame('10.00', $lot->fresh()->quantity_remaining);
        $this->assertSame(1, MedicationStockMovement::where('medication_stock_lot_id', $lot->id)->count());
    }

    public function test_started_pack_pharmacy_advance_does_not_change_status_or_create_receipt(): void
    {
        extract($this->fixture());
        $order = MedicationPharmacyOrder::create(['client_id' => $client->id, 'client_medication_id' => $med->id,
            'pharmacy_name' => 'Synthetic Pharmacy', 'quantity_ordered' => 10, 'quantity_received' => 0,
            'status' => 'dispensed', 'ordered_by' => $actor->id]);
        $this->actingAs($actor)->postJson('/emar/stock/pharmacy-orders/'.$order->id.'/advance', [
            'expected_status' => 'dispensed', 'quantity_received' => '10.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('dispensed', $order->fresh()->status);
        $this->assertSame('0.00', $order->fresh()->quantity_received);
        $this->assertSame('10.00', $stock->fresh()->on_hand);
        $this->assertSame(0, MedicationStockLot::where('pharmacy_order_id', $order->id)->count());
    }

    public function test_started_pack_scheduled_count_rejects_scalar_completion_before_marking_count_done(): void
    {
        extract($this->fixture());
        $count = \App\Models\MedicationScheduledStockCount::create(['client_id' => $client->id,
            'client_medication_id' => $med->id, 'scheduled_date' => now()->toDateString(),
            'expected_quantity' => '10.00', 'status' => 'pending']);
        $this->actingAs($actor, 'sanctum')->postJson('/api/medications/clients/'.$client->id.'/scheduled-counts/'.$count->id.'/complete', [
            'actual_quantity' => '99.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('actual_quantity');
        $this->assertSame('pending', $count->fresh()->status);
        $this->assertNull($count->fresh()->completed_at);
        $this->assertSame('10.00', $stock->fresh()->on_hand);
    }

    public function test_started_pack_metadata_preserves_label_evidence_but_allows_reorder_and_storage_settings(): void
    {
        extract($this->fixture('2027-04-30'));
        $this->actingAs($actor)->patch('/emar/stock/'.$stock->id, [
            'reorder_level' => 3, 'supplier_name' => 'Synthetic updated supplier', 'storage_condition' => 'fridge',
        ])->assertRedirect();
        $this->assertSame(3, $stock->fresh()->reorder_level);
        $this->assertSame('fridge', $stock->fresh()->storage_condition);
        $this->patchJson('/emar/stock/'.$stock->id, ['batch_number' => 'REPLACEMENT', 'expiry_date' => '2028-04-30'])
            ->assertUnprocessable()->assertJsonValidationErrors('batch_number');
        $this->assertNull($stock->fresh()->batch_number);
        $this->assertSame('2027-04-30', $stock->fresh()->expiry_date->toDateString());
        $this->assertSame('2027-04-30', MedicationStockLot::where('client_medication_stock_id', $stock->id)->sole()->expiry_date->toDateString());
    }

    public function test_legacy_unstarted_receipt_remains_available_with_pack_release_off(): void
    {
        extract($this->fixture(null, false));
        config(['medications.stock_lots_enabled' => false]);
        $this->actingAs($actor)->postJson('/emar/stock/receive', [
            'client_medication_id' => $med->id, 'quantity' => '2.25', 'client_request_uuid' => (string) Str::uuid(),
            'scan_verified' => true, 'scan_code' => app(\App\Services\MedicationScanVerificationService::class)->internalCode($client, $med),
        ])->assertOk();
        $this->assertSame('12.25', $stock->fresh()->on_hand);
        $this->assertNull($stock->fresh()->lots_started_at);
        $this->assertSame(0, MedicationStockLot::count());
    }

    public function test_model_rejects_direct_scalar_save_but_current_reader_never_rewrites_evidence(): void
    {
        extract($this->fixture());
        $stock->on_hand = '99.00';
        try {
            $stock->save();
            $this->fail('A direct scalar save must not bypass pack evidence.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock_workflow_url', $e->errors());
        }
        $this->assertSame('10.00', $stock->fresh()->on_hand);
        $this->assertSame('10.00', $stock->fresh()->availableQuantity());
        $controlled = $med->replicate()->forceFill(['name' => 'Synthetic controlled physical balance', 'controlled_drug' => true]);
        $controlled->save();
        $cdStock = ClientMedicationStock::create(['client_medication_id' => $controlled->id, 'on_hand' => '7.50', 'unit' => 'tablets']);
        $cdStock->on_hand = '7.00';
        $cdStock->save();
        $this->assertSame('7.00', $cdStock->fresh()->availableQuantity());
    }

    public function test_partial_receipt_is_atomic_replay_safe_and_does_not_claim_a_count(): void
    {
        extract($this->fixture());
        $countedAt = $stock->last_counted_at;
        $order = MedicationPharmacyOrder::create(['client_id' => $client->id, 'client_medication_id' => $med->id,
            'pharmacy_name' => 'Synthetic Pharmacy', 'quantity_ordered' => 10, 'quantity_received' => 0, 'status' => 'dispensed', 'ordered_by' => $actor->id]);
        $data = [...$this->receipt($med->id), 'pharmacy_order_id' => $order->id, 'delivery_outcome' => 'still_to_come'];
        $first = $this->actingAs($actor)->postJson('/emar/stock/packs/commands', $data)->assertOk()->assertJson(['success' => true]);
        $this->postJson('/emar/stock/packs/commands', $data)->assertOk()->assertJson(['success' => true, 'duplicate' => true]);
        $this->assertSame('14.25', $stock->fresh()->on_hand);
        $this->assertSame($countedAt->toIso8601String(), $stock->fresh()->last_counted_at->toIso8601String());
        $this->assertSame('part_received', $order->fresh()->status);
        $this->assertSame('4.25', $order->fresh()->quantity_received);
        $this->assertSame(1, MedicationStockLot::where('pharmacy_order_id', $order->id)->count());
        $this->assertSame(1, MedicationStockMovement::where('kind', 'received')->count());
        $this->assertSame(1, MedicationEvent::where('kind', 'stock.receive')->count());
        $first->assertJsonPath('lot_id', MedicationStockLot::where('pharmacy_order_id', $order->id)->value('id'));
    }

    public function test_expired_receipt_is_rejected_without_stock_order_or_movement_changes(): void
    {
        extract($this->fixture());
        $data = [...$this->receipt($med->id), 'expiry_month' => now('Pacific/Auckland')->subMonths(2)->format('m/Y')];
        $this->actingAs($actor)->postJson('/emar/stock/packs/commands', $data)->assertUnprocessable()->assertJsonValidationErrors('expiry_month');
        $this->assertSame('10.00', $stock->fresh()->on_hand);
        $this->assertSame(1, MedicationStockLot::count());
        $this->assertSame(0, MedicationStockMovement::where('kind', 'received')->count());
    }

    public function test_count_difference_waits_for_review_and_stale_revision_requires_a_recount(): void
    {
        extract($this->fixture());
        $lot = MedicationStockLot::firstOrFail();
        $data = ['action' => 'count', 'client_medication_id' => $med->id, 'request_uuid' => (string) Str::uuid(),
            'lines' => [['lot_id' => $lot->id, 'revision' => $lot->revision, 'quantity' => '9.00']], 'reason' => 'Synthetic count difference'];
        $countId = $this->actingAs($actor)->postJson('/emar/stock/packs/commands', $data)->assertOk()->json('count_id');
        $this->assertSame('10.00', $stock->fresh()->on_hand);
        $this->assertSame('needs_review', MedicationStockCountRecord::findOrFail($countId)->state);
        $this->assertSame(1, MedicationFollowup::where('source_key', 'stock-discrepancy:'.$countId)->count());
        DB::transaction(fn () => app(MedicationStockService::class)->move($stock, $actor, [
            'lot_id' => $lot->id, 'kind' => 'damaged', 'quantity' => '1.00', 'reason' => 'Synthetic damage', 'request_uuid' => (string) Str::uuid(),
        ]));
        $this->postJson('/emar/stock/packs/commands', ['action' => 'count_review', 'client_medication_id' => $med->id,
            'request_uuid' => (string) Str::uuid(), 'count_id' => $countId, 'reason' => 'Synthetic review'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('9.00', $stock->fresh()->on_hand);
    }

    public function test_lead_signoff_completes_the_same_discrepancy_workflow_once(): void
    {
        extract($this->fixture());
        $lot = MedicationStockLot::firstOrFail();
        $data = ['action' => 'count', 'client_medication_id' => $med->id, 'request_uuid' => (string) Str::uuid(),
            'lines' => [['lot_id' => $lot->id, 'revision' => $lot->revision, 'quantity' => '9.00']],
            'reason' => 'Synthetic discrepancy'];
        $countId = $this->actingAs($actor)->postJson('/emar/stock/packs/commands', $data)->assertOk()->json('count_id');
        $this->postJson('/emar/stock/packs/commands', $data)->assertOk()->assertJson(['duplicate' => true]);
        $followup = MedicationFollowup::where('source_key', 'stock-discrepancy:'.$countId)->sole();
        $review = ['action' => 'count_review', 'client_medication_id' => $med->id, 'count_id' => $countId,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Synthetic checked reconciliation'];
        $this->postJson('/emar/stock/packs/commands', $review)->assertOk();
        $this->postJson('/emar/stock/packs/commands', $review)->assertOk()->assertJson(['duplicate' => true]);
        $this->assertSame('9.00', $stock->fresh()->on_hand);
        $this->assertSame('done', $followup->fresh()->state);
        $this->assertNotNull($followup->fresh()->completed_at);
        $this->assertSame(1, MedicationFollowup::where('source_key', 'stock-discrepancy:'.$countId)->count());
        $this->assertSame(1, MedicationStockMovement::where('kind', 'count_correction')->count());
        $this->assertSame(1, MedicationEvent::where('kind', 'stock.count_review')->count());
    }

    public function test_dose_recorded_after_count_opening_rejects_stale_count_without_creating_review_work(): void
    {
        extract($this->fixture());
        $lot = MedicationStockLot::firstOrFail();
        $openedRevision = $lot->revision;
        $countedAt = $stock->last_counted_at;
        $administration = ClientMedicationAdministration::create([
            'client_id' => $client->id, 'client_medication_id' => $med->id,
            'administered_by' => $actor->id, 'administered_at' => now(), 'status' => 'given',
            'dose_given' => '1 tablet',
        ]);
        DB::transaction(fn () => app(MedicationStockService::class)->ordinaryDose($administration, $actor, '1.00'));

        $this->actingAs($actor)->postJson('/emar/stock/packs/commands', [
            'action' => 'count', 'client_medication_id' => $med->id, 'request_uuid' => (string) Str::uuid(),
            'lines' => [['lot_id' => $lot->id, 'revision' => $openedRevision, 'quantity' => '10.00']],
            'reason' => 'Synthetic stale count',
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');

        $this->assertSame('9.00', $stock->fresh()->on_hand);
        $this->assertSame('9.00', $lot->fresh()->quantity_remaining);
        $this->assertSame(0, MedicationStockCountRecord::count());
        $this->assertSame(0, MedicationFollowup::where('type', 'stock_discrepancy')->count());
        $this->assertSame(0, MedicationEvent::where('kind', 'stock.count')->count());
        $this->assertSame($countedAt->toIso8601String(), $stock->fresh()->last_counted_at->toIso8601String());

        $this->postJson('/emar/stock/packs/commands', [
            'action' => 'count', 'client_medication_id' => $med->id, 'request_uuid' => (string) Str::uuid(),
            'lines' => [['lot_id' => $lot->id, 'revision' => $lot->fresh()->revision, 'quantity' => '9.00']],
        ])->assertOk();
        $this->assertSame('counted', MedicationStockCountRecord::sole()->state);
        $this->assertSame('9.00', $stock->fresh()->on_hand);
    }

    public function test_receipt_after_a_difference_count_requires_recount_before_review(): void
    {
        extract($this->fixture());
        $lot = MedicationStockLot::firstOrFail();
        $countId = $this->actingAs($actor)->postJson('/emar/stock/packs/commands', [
            'action' => 'count', 'client_medication_id' => $med->id, 'request_uuid' => (string) Str::uuid(),
            'lines' => [['lot_id' => $lot->id, 'revision' => $lot->revision, 'quantity' => '9.00']],
            'reason' => 'Synthetic difference',
        ])->assertOk()->json('count_id');
        $this->postJson('/emar/stock/packs/commands', $this->receipt($med->id))->assertOk();
        $this->postJson('/emar/stock/packs/commands', [
            'action' => 'count_review', 'client_medication_id' => $med->id, 'count_id' => $countId,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Synthetic review',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('14.25', $stock->fresh()->on_hand);
        $this->assertSame('needs_review', MedicationStockCountRecord::findOrFail($countId)->state);
    }

    public function test_closed_pharmacy_record_cannot_change_or_receive_stock(): void
    {
        extract($this->fixture());
        $order = MedicationPharmacyOrder::create(['client_id' => $client->id, 'client_medication_id' => $med->id, 'pharmacy_name' => 'Synthetic Pharmacy',
            'quantity_ordered' => 10, 'quantity_received' => 10, 'status' => 'delivered', 'ordered_by' => $actor->id]);
        $this->actingAs($actor)->postJson('/emar/stock/packs/commands', ['action' => 'order_update', 'client_medication_id' => $med->id,
            'request_uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'next' => 'cancelled', 'reason' => 'Synthetic cancellation'])
            ->assertUnprocessable()->assertJsonValidationErrors('next');
        $this->postJson('/emar/stock/packs/commands', [...$this->receipt($med->id), 'pharmacy_order_id' => $order->id])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame('10.00', $stock->fresh()->on_hand);
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_cross_person_order_and_pack_ids_are_denied(): void
    {
        extract($this->fixture());
        $other = Client::factory()->create(['site_id' => $site->id]);
        $order = MedicationPharmacyOrder::create(['client_id' => $other->id, 'client_medication_id' => $med->id,
            'pharmacy_name' => 'Synthetic bad ownership', 'quantity_ordered' => 10, 'status' => 'dispensed', 'ordered_by' => $actor->id]);
        $this->actingAs($actor)->postJson('/emar/stock/packs/commands', [...$this->receipt($med->id), 'pharmacy_order_id' => $order->id])->assertNotFound();
        $this->assertSame('10.00', $stock->fresh()->on_hand);
    }

    public function test_simultaneous_dose_and_movement_wait_for_stock_mutex_and_preserve_both_changes(): void
    {
        $this->beforeApplicationDestroyed(\Tests\Support\CommittedFixtureCleanup::capture()->restore(...));
        extract($this->fixture());
        $administration = ClientMedicationAdministration::create([
            'client_id' => $client->id, 'client_medication_id' => $med->id, 'administered_by' => $actor->id,
            'administered_at' => now(), 'status' => 'given', 'dose_given' => '1 tablet',
        ]);
        $lot = MedicationStockLot::firstOrFail();
        $initialRevision = $lot->revision;
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $token = (string) Str::uuid();
        $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'p06-go-'.$token;
        $ready = array_map(fn ($kind) => sys_get_temp_dir().DIRECTORY_SEPARATOR.'p06-ready-'.$kind.'-'.$token, ['dose', 'movement']);
        $attempted = array_map(fn ($kind) => sys_get_temp_dir().DIRECTORY_SEPARATOR.'p06-attempt-'.$kind.'-'.$token, ['dose', 'movement']);
        $processes = [];
        $connection->commit();
        try {
            $connection->beginTransaction();
            ClientMedicationStock::whereKey($stock->id)->lockForUpdate()->firstOrFail();
            foreach (['dose', 'movement'] as $index => $kind) {
                $processes[] = $this->stockWorker($kind, $stock->id, $lot->id, $administration->id, $actor->id, $ready[$index], $attempted[$index], $go);
            }
            $this->waitForWorkerFiles($ready);
            file_put_contents($go, 'go');
            $this->waitForWorkerFiles($attempted);
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'A stock writer did not wait for the shared stock mutex.');
            }
            $connection->commit();
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()));
                $this->assertSame(['success' => true], json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR));
            }
            $this->assertSame('8.00', $stock->fresh()->on_hand);
            $this->assertSame('8.00', $lot->fresh()->quantity_remaining);
            $this->assertSame($initialRevision + 2, $lot->fresh()->revision);
            $this->assertSame(1, MedicationStockMovement::where('kind', 'given')->count());
            $this->assertSame(1, MedicationStockMovement::where('kind', 'damaged')->count());
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([$go, ...$ready, ...$attempted] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $connection->beginTransaction();
        }
    }

    private function stockWorker(string $kind, int $stockId, int $lotId, int $administrationId, int $actorId, string $ready, string $attempted, string $go): \Symfony\Component\Process\Process
    {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$stock = App\Models\ClientMedicationStock::findOrFail((int) $argv[3]);
$actor = App\Models\User::findOrFail((int) $argv[6]);
$administration = App\Models\ClientMedicationAdministration::findOrFail((int) $argv[5]);
file_put_contents($argv[7], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[9])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the stock race gate.');
    }
    usleep(10_000);
}
file_put_contents($argv[8], 'attempting');
Illuminate\Support\Facades\DB::transaction(function () use ($app, $argv, $stock, $actor, $administration) {
    $service = $app->make(App\Services\Medication\Stock\MedicationStockService::class);
    if ($argv[2] === 'dose') {
        $service->ordinaryDose($administration, $actor, '1.00');
    } else {
        $service->move($stock, $actor, [
            'lot_id' => (int) $argv[4], 'kind' => 'damaged', 'quantity' => '1.00',
            'reason' => 'Synthetic concurrency damage', 'request_uuid' => (string) Illuminate\Support\Str::uuid(),
        ]);
    }
}, 5);
echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
PHP;
        $process = new \Symfony\Component\Process\Process([
            PHP_BINARY, '-r', $worker, base_path(), $kind, (string) $stockId, (string) $lotId,
            (string) $administrationId, (string) $actorId, $ready, $attempted, $go,
        ], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
        ]);
        $process->setTimeout(30);
        $process->start();
        return $process;
    }

    private function waitForWorkerFiles(array $paths): void
    {
        $deadline = microtime(true) + 15;
        while (collect($paths)->contains(fn ($path) => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Stock concurrency workers did not reach the gate.');
            }
            usleep(10_000);
        }
    }

    public function test_expiry_crossing_without_a_write_keeps_stock_row_meter_and_filter_consistent(): void
    {
        $clock = \Carbon\CarbonImmutable::parse('2026-10-03 23:59:00', 'Pacific/Auckland')->utc();
        \Carbon\Carbon::setTestNow($clock);
        \Carbon\CarbonImmutable::setTestNow($clock);
        try {
            extract($this->fixture('2026-10-03'));
            $controlled = $med->replicate()->forceFill(['name' => 'Synthetic controlled medicine', 'controlled_drug' => true]);
            $controlled->save();
            $controlledStock = ClientMedicationStock::create(['client_medication_id' => $controlled->id, 'on_hand' => '10.00',
                'unit' => 'tablets', 'expiry_date' => '2026-10-03']);
            DB::transaction(fn () => app(MedicationStockService::class)->startLots($controlledStock, $actor, (string) Str::uuid()));
            $unknown = $med->replicate()->forceFill(['name' => 'Synthetic unknown balance']);
            $unknown->save();
            ClientMedicationStock::create(['client_medication_id' => $unknown->id, 'on_hand' => null, 'unit' => 'tablets']);
            $lot = MedicationStockLot::where('client_medication_stock_id', $stock->id)->sole();
            $revision = $lot->revision;
            $countedAt = $stock->last_counted_at;

            $this->actingAs($actor)->getJson('/emar/stock/packs/medicine/'.$med->id)->assertOk()->assertJsonPath('on_hand', 10);
            $this->get('/emar/stock/packs', $this->inertiaPartialHeaders('emar/stock/StockHub', 'focused_count,metrics,items'))->assertOk()->assertJsonPath('props.metrics.out', 0);

            $nextDay = $clock->addMinutes(2);
            \Carbon\Carbon::setTestNow($nextDay);
            \Carbon\CarbonImmutable::setTestNow($nextDay);
            $this->getJson('/emar/stock/packs/medicine/'.$med->id)->assertOk()->assertJsonPath('on_hand', 0);
            $this->getJson('/emar/stock/packs/medicine/'.$controlled->id)->assertOk()->assertJsonPath('on_hand', 10);
            $this->getJson('/emar/stock/packs/medicine/'.$unknown->id)->assertOk()->assertJsonPath('on_hand', null);
            $this->get('/emar/stock/packs?show=out', $this->inertiaPartialHeaders('emar/stock/StockHub', 'focused_count,metrics,items'))->assertOk()
                ->assertJsonPath('props.metrics.out', 1)->assertJsonPath('props.items.total', 1)
                ->assertJsonPath('props.items.data.0.id', $med->id)->assertJsonPath('props.items.data.0.on_hand', 0);
            $this->assertSame('10.00', $stock->fresh()->on_hand);
            $this->assertSame('10.00', $lot->fresh()->quantity_remaining);
            $this->assertSame($revision, $lot->fresh()->revision);
            $this->assertSame($countedAt->toIso8601String(), $stock->fresh()->last_counted_at->toIso8601String());
        } finally {
            \Carbon\Carbon::setTestNow();
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_audit_failure_rolls_back_count_correction_workflow_and_receipt_then_retry_succeeds(): void
    {
        extract($this->fixture());
        $lot = MedicationStockLot::firstOrFail();
        $countId = $this->actingAs($actor)->postJson('/emar/stock/packs/commands', [
            'action' => 'count', 'client_medication_id' => $med->id, 'request_uuid' => (string) Str::uuid(),
            'lines' => [['lot_id' => $lot->id, 'revision' => $lot->revision, 'quantity' => '9.00']], 'reason' => 'Synthetic discrepancy',
        ])->assertOk()->json('count_id');
        $review = ['action' => 'count_review', 'client_medication_id' => $med->id, 'count_id' => $countId,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Synthetic verified count'];
        $this->app->instance(\App\Services\Medication\Audit\MedicationEventRecorder::class, new class {
            public function appendMany(array $events): never
            {
                throw new \RuntimeException('Synthetic audit storage failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/emar/stock/packs/commands', $review);
            $this->fail('The count should not commit without its audit batch.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic audit storage failure', $error->getMessage());
        }
        $this->assertSame('10.00', $stock->fresh()->on_hand);
        $this->assertSame('10.00', $lot->fresh()->quantity_remaining);
        $this->assertSame('needs_review', MedicationStockCountRecord::findOrFail($countId)->state);
        $this->assertNull(MedicationFollowup::where('source_key', 'stock-discrepancy:'.$countId)->sole()->completed_at);
        $this->assertSame(0, MedicationStockMovement::where('kind', 'count_correction')->count());
        $this->app->forgetInstance(\App\Services\Medication\Audit\MedicationEventRecorder::class);
        $this->postJson('/emar/stock/packs/commands', $review)->assertOk();
        $this->assertSame('9.00', $stock->fresh()->on_hand);
    }

    public function test_photo_audit_failure_removes_only_its_new_file_and_rolls_back_its_row(): void
    {
        extract($this->fixture());
        Storage::fake('private');
        $lot = MedicationStockLot::firstOrFail();
        $this->app->instance(\App\Services\Medication\Audit\MedicationEventRecorder::class, new class {
            public function append(\App\Services\Medication\Audit\MedicationEventData $event): never
            {
                throw new \RuntimeException('Synthetic photo audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($actor)->postJson('/emar/stock/packs/'.$lot->id.'/photos', [
                'photo' => UploadedFile::fake()->image('synthetic-failed-photo.png'), 'request_uuid' => (string) Str::uuid(),
            ]);
            $this->fail('The photo should not commit without its audit.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic photo audit failure', $error->getMessage());
        }
        $this->assertSame(0, MedicationStockPhoto::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_photo_bytes_are_private_and_upload_retries_do_not_create_history_duplicates(): void
    {
        extract($this->fixture());
        Storage::fake('private');
        $lot = MedicationStockLot::firstOrFail();
        $upload = UploadedFile::fake()->image('synthetic-pack.png');
        $uuid = (string) Str::uuid();
        $this->actingAs($actor)->postJson('/emar/stock/packs/'.$lot->id.'/photos', ['photo' => $upload, 'request_uuid' => $uuid])->assertOk();
        $photo = MedicationStockPhoto::firstOrFail();
        Storage::disk('private')->assertExists($photo->path);
        $this->get('/emar/stock/packs/photos/'.$photo->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/emar/stock/packs/photos/'.$photo->id.'/download')->assertOk();
        $this->postJson('/emar/stock/packs/'.$lot->id.'/photos', ['photo' => $upload, 'request_uuid' => $uuid])->assertOk()->assertJson(['duplicate' => true]);
        $this->assertSame(1, MedicationStockPhoto::count());
    }
}

