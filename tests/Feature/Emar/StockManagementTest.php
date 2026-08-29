<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationIdempotencyResult;
use App\Models\MedicationPharmacyOrder;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Support\Medication\MedicationStockQuantity;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * The redesigned Stock Management page resolves the active site's brand colour,
 * exposes a controlled-drug reconciliation feed, and supports cold-chain
 * (storage condition) on the stock row.
 */
class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $medicationOverrides */
    private function seedStock(bool $controlled = false, array $medicationOverrides = []): array
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $permissions = [
            'medications.view',
            'medications.stock.update',
        ];
        if ($controlled) {
            $permissions[] = 'medications.controlled.view';
            $permissions[] = 'medications.controlled.record';
        }
        $this->grantPermissions($user, $permissions);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $med = ClientMedication::query()->forceCreate(array_merge([
            'client_id' => $client->id, 'name' => $controlled ? 'Morphine sulfate' : 'Paracetamol', 'dosage' => '10mg', 'frequency' => 'PRN',
            'controlled_drug' => $controlled, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ], $medicationOverrides));
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $med->id, 'on_hand' => 12, 'unit' => 'tablets', 'reorder_level' => 5,
        ]);

        return compact('user', 'site', 'client', 'med', 'stock');
    }

    public function test_page_serves_brand_colour_and_controlled_register(): void
    {
        ['user' => $user, 'site' => $site, 'client' => $client, 'med' => $med] = $this->seedStock(true);
        MedicationPharmacyOrder::create([
            'client_id' => $client->id,
            'client_medication_id' => $med->id,
            'pharmacy_name' => 'Local Pharmacy',
            'quantity_ordered' => 1,
            'status' => 'dispensed',
            'ordered_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get('/emar/stock?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/StockManagement')
                ->where('site_brand_colour', '#5E35B1')
                ->has('stockItems', 1)
                ->where('stockItems.0.on_hand', 12)
                ->has('controlledRegister', 1)
                ->where('controlledRegister.0.register_balance', 12) // falls back to on-hand when no balance check
                ->has('pharmacyOrders', 1)
                ->where('pharmacyOrders.0.medication_id', $med->id)
                ->where('pharmacyOrders.0.controlled', true)
                ->has('sites')
            );
    }

    public function test_med_cd_scope_stock_register_ignores_noncanonical_balance_checks_and_discrepancies(): void
    {
        ['user' => $user, 'site' => $site, 'client' => $client, 'med' => $med] = $this->seedStock(true);
        $legitimateAt = now()->subDays(8)->startOfMinute();
        $noncanonicalAt = now()->subDay()->startOfMinute();
        $otherClient = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $otherWitness = User::factory()->create(['name' => 'Noncanonical Witness']);

        ClientControlledDrugEntry::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $med->id,
            'entry_type' => 'balance_check',
            'unit' => 'tablets',
            'on_hand_before' => '11.00',
            'on_hand_after' => '11.00',
            'recorded_at' => $legitimateAt,
            'recorded_by' => $user->id,
            'witnessed_by' => $user->id,
        ]);
        ClientControlledDrugEntry::query()->create([
            'client_id' => $otherClient->id,
            'client_medication_id' => $med->id,
            'entry_type' => 'balance_check',
            'unit' => 'tablets',
            'on_hand_before' => '99.00',
            'on_hand_after' => '99.00',
            'recorded_at' => $noncanonicalAt,
            'recorded_by' => $otherWitness->id,
            'witnessed_by' => $otherWitness->id,
        ]);
        ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $otherClient->id,
            'client_medication_id' => $med->id,
            'on_hand_before' => '12.00',
            'on_hand_after' => '99.00',
            'difference' => '87.00',
            'reason' => 'Noncanonical mismatch',
            'reported_at' => $noncanonicalAt,
            'reported_by' => $otherWitness->id,
            'witnessed_by' => $otherWitness->id,
            'status' => 'open',
        ]);

        $this->actingAs($user)
            ->get('/emar/stock?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('controlledRegister', 1)
                ->where('controlledRegister.0.register_balance', 11)
                ->where('controlledRegister.0.last_check_at', $legitimateAt->toIso8601String())
                ->where('controlledRegister.0.last_check_witness', $user->name)
                ->where('controlledRegister.0.discrepancy', null)
            );
    }

    public function test_update_stock_item_sets_storage_condition(): void
    {
        ['user' => $user, 'stock' => $stock] = $this->seedStock();

        $this->actingAs($user)
            ->from('/emar/stock')
            ->patch('/emar/stock/'.$stock->id, ['storage_condition' => 'fridge', 'reorder_level' => 8])
            ->assertSessionHasNoErrors();

        $stock->refresh();
        $this->assertSame('fridge', $stock->storage_condition);
        $this->assertTrue($stock->requiresColdChain());
        $this->assertSame(8, (int) $stock->reorder_level);
    }

    public function test_adjust_stock_updates_on_hand_with_reason(): void
    {
        ['user' => $user, 'med' => $med, 'stock' => $stock] = $this->seedStock();

        $this->actingAs($user)
            ->from('/emar/stock')
            ->post('/emar/stock/adjust', ['client_medication_id' => $med->id, 'new_quantity' => 9.5, 'reason' => 'Physical stock count'])
            ->assertSessionHasNoErrors();

        $stock->refresh();
        $this->assertSame(9.5, (float) $stock->on_hand);
        $stock->reorder_level = 10;
        $this->assertTrue($stock->isLowStock());
        $stock->reorder_level = 9;
        $this->assertFalse($stock->isLowStock());
    }

    public function test_receive_stock_preserves_half_units_and_excess_scale_writes_nothing(): void
    {
        ['user' => $user, 'med' => $med, 'stock' => $stock] = $this->seedStock();
        $med->forceFill(['barcode' => 'MED-HALF-001'])->save();
        $med->forceFill([
            'approval_status' => 'verified',
            'verified_by' => $user->id,
            'verified_at' => now(),
        ])->save();

        $this->actingAs($user)
            ->from('/emar/stock')
            ->post('/emar/stock/receive', ['client_medication_id' => $med->id, 'quantity' => 0.015])
            ->assertSessionHasErrors('quantity');
        $this->actingAs($user)
            ->from('/emar/stock')
            ->post('/emar/stock/adjust', [
                'client_medication_id' => $med->id,
                'new_quantity' => 11.999,
                'reason' => 'Invalid precision probe',
            ])
            ->assertSessionHasErrors('new_quantity');

        $this->assertSame(12.0, (float) $stock->refresh()->on_hand);

        $this->actingAs($user)
            ->from('/emar/stock')
            ->post('/emar/stock/receive', [
                'client_medication_id' => $med->id,
                'quantity' => 0.5,
                'scan_code' => 'MED-HALF-001',
                'scan_source' => 'scanner',
                'scan_verified' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(12.5, (float) $stock->refresh()->on_hand);
    }

    public function test_stock_receipt_rejects_incomplete_or_contradictory_offline_provenance(): void
    {
        ['user' => $user, 'med' => $medication, 'stock' => $stock] = $this->seedStock();
        $uuid = '13bc9e74-831d-42ec-a0cf-65df638a1109';
        $capturedAt = now()->subMinutes(5)->toIso8601String();
        $nonRfcCapturedAt = now()->subMinutes(5)->format('Y-m-d H:i:s');
        $base = [
            'client_medication_id' => $medication->id,
            'quantity' => 1,
        ];
        $invalid = [
            [[...$base, 'queued_offline' => true], 'client_request_uuid'],
            [[
                ...$base,
                'client_request_uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
                'queued_offline' => false,
            ], 'client_request_uuid'],
            [[
                ...$base,
                'client_request_uuid' => '2ed6657d-e927-568b-95e1-2665a8aea6a2',
                'queued_offline' => false,
            ], 'client_request_uuid'],
            [[
                ...$base,
                'client_request_uuid' => $uuid,
                'captured_offline_at' => $nonRfcCapturedAt,
                'origin_device_id' => 'stock-device',
                'queued_offline' => true,
            ], 'captured_offline_at'],
            [[
                ...$base,
                'client_request_uuid' => $uuid,
                'captured_offline_at' => $capturedAt,
                'queued_offline' => true,
            ], 'origin_device_id'],
            [[
                ...$base,
                'client_request_uuid' => $uuid,
                'captured_offline_at' => $capturedAt,
                'origin_device_id' => 'stock-device',
                'queued_offline' => false,
            ], 'captured_offline_at'],
        ];

        foreach ($invalid as [$payload, $field]) {
            $this->actingAs($user)
                ->postJson('/emar/stock/receive', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame(12.0, (float) $stock->refresh()->on_hand);
    }

    public function test_offline_stock_receipt_audit_and_replay_binding_are_durable_and_actor_bound(): void
    {
        ['user' => $user, 'site' => $site, 'med' => $medication, 'stock' => $stock] = $this->seedStock(
            medicationOverrides: ['barcode' => 'MED-OFFLINE-RECEIPT-001'],
        );
        $requestUuid = '71f2fe08-9148-4960-9c7b-76fa5c194f1d';
        $capturedAt = now()->subMinutes(5)->toIso8601String();
        $payload = [
            'client_medication_id' => $medication->id,
            'quantity' => 1,
            'client_request_uuid' => $requestUuid,
            'captured_offline_at' => $capturedAt,
            'origin_device_id' => 'stock-receipt-device',
            'queued_offline' => true,
            'scan_code' => 'MED-OFFLINE-RECEIPT-001',
            'scan_source' => 'scanner',
            'scan_verified' => true,
        ];

        $this->actingAs($user)
            ->postJson('/emar/stock/receive', $payload)
            ->assertOk()
            ->assertJsonPath('sync.status', 'synced')
            ->assertJsonPath('sync.duplicate', false);

        $audit = AuditLog::query()->where('action', 'medications.stock.receive')->sole();
        $this->assertSame($requestUuid, $audit->meta['client_request_uuid'] ?? null);
        $this->assertSame($capturedAt, $audit->meta['captured_offline_at'] ?? null);
        $this->assertSame('stock-receipt-device', $audit->meta['origin_device_id'] ?? null);
        $this->assertTrue($audit->meta['queued_offline'] ?? false);
        $this->assertDatabaseHas('medication_idempotency_results', [
            'scope' => 'emar-stock-receive',
            'request_uuid' => $requestUuid,
            'expires_at' => null,
        ]);
        $this->assertSame(13.0, (float) $stock->refresh()->on_hand);

        $secondActor = $this->makeRoleUser('admin');
        $this->grantPermissions($secondActor, ['medications.stock.update']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $secondActor->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
        $this->actingAs($secondActor)
            ->postJson('/emar/stock/receive', $payload)
            ->assertConflict()
            ->assertJsonPath('sync.status', 'conflict');

        $this->travel(8)->days();
        $this->assertSame(0, (new MedicationIdempotencyResult)->prunable()->delete());
        $this->actingAs($user)
            ->postJson('/emar/stock/receive', $payload)
            ->assertOk()
            ->assertJsonPath('sync.status', 'duplicate')
            ->assertJsonPath('sync.duplicate', true);

        $this->assertSame(13.0, (float) $stock->refresh()->on_hand);
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.stock.receive')->count());
        $this->assertDatabaseCount('medication_idempotency_results', 1);
    }

    public function test_receive_stock_rejects_derived_decimal_12_2_overflow_without_stock_or_audit_write(): void
    {
        ['user' => $user, 'med' => $med, 'stock' => $stock] = $this->seedStock();
        $med->forceFill(['barcode' => 'MED-MAX-001'])->save();
        $med->forceFill([
            'approval_status' => 'verified',
            'verified_by' => $user->id,
            'verified_at' => now(),
        ])->save();
        $stock->forceFill(['on_hand' => MedicationStockQuantity::DECIMAL_12_2_MAX])->save();
        $auditCount = AuditLog::query()
            ->where('action', 'medications.stock.receive')
            ->count();

        $this->actingAs($user)
            ->from('/emar/stock')
            ->post('/emar/stock/receive', [
                'client_medication_id' => $med->id,
                'quantity' => '0.25',
                'scan_code' => 'MED-MAX-001',
                'scan_source' => 'scanner',
                'scan_verified' => true,
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(
            MedicationStockQuantity::DECIMAL_12_2_MAX,
            (string) $stock->refresh()->on_hand,
        );
        $this->assertSame(
            $auditCount,
            AuditLog::query()->where('action', 'medications.stock.receive')->count(),
        );
    }

    public function test_stock_only_receipt_adjustment_and_pharmacy_delivery_reject_controlled_medication_without_effects(): void
    {
        ['user' => $user, 'client' => $client, 'med' => $med, 'stock' => $stock] = $this->seedStock(true);
        $order = MedicationPharmacyOrder::create([
            'client_id' => $client->id,
            'client_medication_id' => $med->id,
            'pharmacy_name' => 'Local Pharmacy',
            'quantity_ordered' => 5,
            'status' => 'dispensed',
            'ordered_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->post('/emar/stock/adjust', [
                'client_medication_id' => $med->id,
                'new_quantity' => 9.5,
                'reason' => 'Direct controlled count probe',
            ])
            ->assertSessionHasErrors('client_medication_id');
        $this->actingAs($user)
            ->post('/emar/stock/receive', [
                'client_medication_id' => $med->id,
                'quantity' => 0.5,
            ])
            ->assertSessionHasErrors('client_medication_id');
        $this->actingAs($user)
            ->post(route('emar.pharmacy_orders.advance', $order), [
                'quantity_received' => 0.5,
            ])
            ->assertSessionHasErrors('client_medication_id');

        $this->assertSame(12.0, (float) $stock->refresh()->on_hand);
        $this->assertSame('dispensed', $order->refresh()->status);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.stock.adjust']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.stock.receive']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.stock.pharmacy_delivery']);
    }

    public function test_controlled_pharmacy_order_mutations_require_view_and_record_authority_before_validation(): void
    {
        ['user' => $user, 'client' => $client, 'med' => $med] = $this->seedStock(true);
        $order = MedicationPharmacyOrder::create([
            'client_id' => $client->id,
            'client_medication_id' => $med->id,
            'pharmacy_name' => 'Local Pharmacy',
            'quantity_ordered' => 5,
            'status' => 'draft',
            'ordered_by' => $user->id,
        ]);

        foreach ([
            [false, true],
            [true, false],
        ] as [$canView, $canRecord]) {
            $canView
                ? $this->grantPermissions($user, ['medications.controlled.view'])
                : $this->denyPermissions($user, ['medications.controlled.view']);
            $canRecord
                ? $this->grantPermissions($user, ['medications.controlled.record'])
                : $this->denyPermissions($user, ['medications.controlled.record']);
            $user->unsetRelation('permissionOverrides')->unsetRelation('roles');

            $this->actingAs($user)
                ->post(route('emar.pharmacy_orders.store'), [
                    'client_id' => $client->id,
                    'client_medication_id' => $med->id,
                ])
                ->assertNotFound();
            $this->actingAs($user)
                ->put(route('emar.pharmacy_orders.update', $order), [
                    'pharmacy_email' => 'not-an-email',
                ])
                ->assertNotFound();
            $this->actingAs($user)
                ->post(route('emar.pharmacy_orders.advance', $order))
                ->assertNotFound();
        }

        $this->assertDatabaseCount('medication_pharmacy_orders', 1);
        $order->refresh();
        $this->assertSame('draft', $order->status);
        $this->assertNull($order->order_notes);

        $this->grantPermissions($user, [
            'medications.controlled.view',
            'medications.controlled.record',
        ]);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');

        $this->actingAs($user)
            ->post(route('emar.pharmacy_orders.store'), [
                'client_id' => $client->id,
                'client_medication_id' => $med->id,
                'pharmacy_name' => 'Second Pharmacy',
                'quantity_ordered' => 2,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->put(route('emar.pharmacy_orders.update', $order), [
                'order_notes' => 'Both controlled permissions supplied.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->post(route('emar.pharmacy_orders.advance', $order))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('medication_pharmacy_orders', 2);
        $order->refresh();
        $this->assertSame('submitted', $order->status);
        $this->assertSame('Both controlled permissions supplied.', $order->order_notes);
    }

    public function test_controlled_pharmacy_delivery_is_atomic_fractional_and_replay_safe(): void
    {
        $context = $this->controlledDeliveryContext();

        $this->actingAs($context['user'])
            ->from('/emar/stock')
            ->post(
                route('emar.pharmacy_orders.controlled_delivery', $context['order']),
                $context['payload'],
            )
            ->assertSessionHasNoErrors();

        $entry = ClientControlledDrugEntry::query()->sole();
        $this->assertSame($context['order']->id, $entry->pharmacy_order_id);
        $this->assertSame('receipt', $entry->entry_type);
        $this->assertSame('12.00', (string) $entry->on_hand_before);
        $this->assertSame('12.50', (string) $entry->on_hand_after);
        $this->assertSame('0.50', (string) $entry->quantity);
        $this->assertSame('12.50', (string) $context['stock']->refresh()->on_hand);
        $this->assertSame('delivered', $context['order']->refresh()->status);
        $this->assertSame('0.50', (string) $context['order']->quantity_received);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'medications.controlled.pharmacy_delivery.receive',
            'auditable_id' => $entry->id,
        ]);

        $this->actingAs($context['user'])
            ->from('/emar/stock')
            ->post(
                route('emar.pharmacy_orders.controlled_delivery', $context['order']),
                $context['payload'],
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame('12.50', (string) $context['stock']->refresh()->on_hand);

        $mismatchedReplay = $context['payload'];
        $mismatchedReplay['quantity_received'] = '1.00';
        $mismatchedReplay['on_hand_after'] = '13.00';
        $this->actingAs($context['user'])
            ->from('/emar/stock')
            ->post(
                route('emar.pharmacy_orders.controlled_delivery', $context['order']),
                $mismatchedReplay,
            )
            ->assertSessionHasErrors('client_request_uuid');

        $duplicatePayload = $context['payload'];
        $duplicatePayload['client_request_uuid'] = 'ae7bd7ab-14bb-4803-8b83-4ec3f5a66392';
        $this->actingAs($context['user'])
            ->from('/emar/stock')
            ->post(
                route('emar.pharmacy_orders.controlled_delivery', $context['order']),
                $duplicatePayload,
            )
            ->assertSessionHasErrors('status');

        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame('12.50', (string) $context['stock']->refresh()->on_hand);
    }

    public function test_controlled_pharmacy_delivery_retains_its_replay_binding_indefinitely(): void
    {
        $context = $this->controlledDeliveryContext();

        $this->actingAs($context['user'])
            ->post(
                route('emar.pharmacy_orders.controlled_delivery', $context['order']),
                $context['payload'],
            )
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $binding = MedicationIdempotencyResult::query()
            ->where(
                'scope',
                'emar-controlled-pharmacy-delivery',
            )
            ->where('request_uuid', $context['payload']['client_request_uuid'])
            ->sole();

        $this->assertNull($binding->expires_at);
    }

    public function test_controlled_pharmacy_delivery_replay_rechecks_current_witness_authority(): void
    {
        $context = $this->controlledDeliveryContext();

        $this->actingAs($context['user'])
            ->post(
                route('emar.pharmacy_orders.controlled_delivery', $context['order']),
                $context['payload'],
            )
            ->assertSessionHasNoErrors();

        $this->denyPermissions($context['witness'], ['medications.controlled.witness']);

        $this->actingAs($context['user'])
            ->post(
                route('emar.pharmacy_orders.controlled_delivery', $context['order']),
                $context['payload'],
            )
            ->assertNotFound();

        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame('12.50', (string) $context['stock']->refresh()->on_hand);
    }

    public function test_controlled_pharmacy_delivery_rejects_forged_medication_and_incomplete_or_stale_balance_without_effects(): void
    {
        $context = $this->controlledDeliveryContext();
        $otherMedication = ClientMedication::create([
            'client_id' => $context['client']->id,
            'name' => 'Other controlled medication',
            'dosage' => '5mg',
            'frequency' => 'PRN',
            'controlled_drug' => true,
            'is_prn' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $forged = $context['payload'];
        $forged['client_medication_id'] = $otherMedication->id;
        $this->actingAs($context['user'])
            ->post(
                route('emar.pharmacy_orders.controlled_delivery', $context['order']),
                $forged,
            )
            ->assertNotFound();

        $stale = $context['payload'];
        $stale['on_hand_before'] = '11.50';
        $stale['on_hand_after'] = '12.00';
        $this->actingAs($context['user'])
            ->from('/emar/stock')
            ->post(
                route('emar.pharmacy_orders.controlled_delivery', $context['order']),
                $stale,
            )
            ->assertSessionHasErrors('on_hand_before');

        $incomplete = $context['payload'];
        unset($incomplete['on_hand_after']);
        $this->actingAs($context['user'])
            ->from('/emar/stock')
            ->post(
                route('emar.pharmacy_orders.controlled_delivery', $context['order']),
                $incomplete,
            )
            ->assertSessionHasErrors('on_hand_after');

        $this->assertSame('dispensed', $context['order']->refresh()->status);
        $this->assertSame('12.00', (string) $context['stock']->refresh()->on_hand);
        $this->assertDatabaseMissing('client_controlled_drug_entries', [
            'pharmacy_order_id' => $context['order']->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.controlled.pharmacy_delivery.receive',
        ]);
    }

    public function test_controlled_pharmacy_delivery_conceals_foreign_direct_objects_without_effects(): void
    {
        $context = $this->controlledDeliveryContext();
        $localSite = $context['site'];
        $actor = $this->makeRoleUser('support_worker');
        $this->grantPermissions($actor, ['medications.controlled.record']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $localSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth(),
            'end_date' => null,
        ]);
        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
        $foreignMedication = ClientMedication::create([
            'client_id' => $foreignClient->id,
            'name' => 'Foreign controlled medication',
            'dosage' => '10mg',
            'frequency' => 'PRN',
            'controlled_drug' => true,
            'is_prn' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $foreignStock = ClientMedicationStock::create([
            'client_medication_id' => $foreignMedication->id,
            'on_hand' => 4.5,
            'unit' => 'tablets',
        ]);
        $foreignOrder = MedicationPharmacyOrder::create([
            'client_id' => $foreignClient->id,
            'client_medication_id' => $foreignMedication->id,
            'pharmacy_name' => 'Foreign Pharmacy',
            'quantity_ordered' => 1,
            'status' => 'dispensed',
            'ordered_by' => $actor->id,
        ]);

        $this->actingAs($actor)
            ->post(
                route('emar.pharmacy_orders.controlled_delivery', $foreignOrder),
                [],
            )
            ->assertNotFound();

        $payload = $context['payload'];
        $payload['client_medication_id'] = $foreignMedication->id;
        $payload['on_hand_before'] = '4.50';
        $payload['on_hand_after'] = '5.00';
        $this->actingAs($actor)
            ->post(
                route('emar.pharmacy_orders.controlled_delivery', $foreignOrder),
                $payload,
            )
            ->assertNotFound();

        $this->assertSame('dispensed', $foreignOrder->refresh()->status);
        $this->assertSame('4.50', (string) $foreignStock->refresh()->on_hand);
        $this->assertDatabaseMissing('client_controlled_drug_entries', [
            'pharmacy_order_id' => $foreignOrder->id,
        ]);
    }

    public function test_controlled_pharmacy_delivery_audit_failure_rolls_back_order_stock_register_and_replay_result(): void
    {
        $context = $this->controlledDeliveryContext();

        $this->assertAuditFailureRollsBack(
            'medications.controlled.pharmacy_delivery.receive',
            fn () => $this->actingAs($context['user'])->post(
                route('emar.pharmacy_orders.controlled_delivery', $context['order']),
                $context['payload'],
            ),
        );

        $this->assertSame('dispensed', $context['order']->refresh()->status);
        $this->assertNull($context['order']->quantity_received);
        $this->assertSame('12.00', (string) $context['stock']->refresh()->on_hand);
        $this->assertDatabaseMissing('client_controlled_drug_entries', [
            'pharmacy_order_id' => $context['order']->id,
        ]);
        $this->assertDatabaseMissing('medication_idempotency_results', [
            'scope' => 'emar-controlled-pharmacy-delivery',
            'request_uuid' => $context['payload']['client_request_uuid'],
        ]);
    }

    public function test_strict_audit_failures_roll_back_stock_adjustment_and_pharmacy_delivery(): void
    {
        ['user' => $user, 'client' => $client, 'med' => $med, 'stock' => $stock] = $this->seedStock();

        $this->assertAuditFailureRollsBack(
            'medications.stock.adjust',
            fn () => $this->actingAs($user)->post('/emar/stock/adjust', [
                'client_medication_id' => $med->id,
                'new_quantity' => 9.5,
                'reason' => 'Audited physical count',
            ]),
        );
        $this->assertSame(12.0, (float) $stock->refresh()->on_hand);

        $order = MedicationPharmacyOrder::create([
            'client_id' => $client->id,
            'client_medication_id' => $med->id,
            'pharmacy_name' => 'Local Pharmacy',
            'quantity_ordered' => 5,
            'status' => 'dispensed',
            'ordered_by' => $user->id,
        ]);
        $this->assertAuditFailureRollsBack(
            'medications.stock.pharmacy_delivery',
            fn () => $this->actingAs($user)->post(
                route('emar.pharmacy_orders.advance', $order),
                ['quantity_received' => 1],
            ),
        );

        $this->assertSame(12.0, (float) $stock->refresh()->on_hand);
        $this->assertSame('dispensed', $order->refresh()->status);
    }

    public function test_client_filter_scopes_stock_to_one_client(): void
    {
        ['user' => $user, 'site' => $site, 'client' => $client] = $this->seedStock();

        // A second client + stock that must be filtered out by ?client_id=.
        $other = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $otherMed = ClientMedication::query()->create([
            'client_id' => $other->id, 'name' => 'Aspirin', 'dosage' => '75mg', 'frequency' => 'daily',
            'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        ClientMedicationStock::query()->create(['client_medication_id' => $otherMed->id, 'on_hand' => 5, 'unit' => 'tablets']);

        $this->actingAs($user)
            ->get('/emar/stock?client_id='.$client->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/StockManagement')
                ->where('client_id', $client->id)
                ->has('stockItems', 1)
                ->where('stockItems.0.client_id', $client->id)
            );
    }

    public function test_stock_item_carries_detail_modal_payload(): void
    {
        ['user' => $user, 'med' => $med, 'client' => $client] = $this->seedStock();

        // Generate a fractional movement on the audit-log ledger (12 → 9.5 = -2.5).
        $this->actingAs($user)
            ->from('/emar/stock')
            ->post('/emar/stock/adjust', ['client_medication_id' => $med->id, 'new_quantity' => 9.5, 'reason' => 'Physical stock count'])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->get('/emar/stock')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/StockManagement')
                ->has('stockItems.0.movements')
                ->where('stockItems.0.on_hand', 9.5)
                ->where('stockItems.0.movements.0.type', 'counted')
                ->where('stockItems.0.movements.0.delta', -2.5)
                ->where('stockItems.0.mar_url', fn ($url) => is_string($url) && str_contains($url, (string) $client->id))
                ->has('stockItems.0.client_room')
                ->has('pharmacyOrders')
            );
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function denyPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }

    /** @return array<string, mixed> */
    private function controlledDeliveryContext(): array
    {
        $context = $this->seedStock(true);
        $this->grantPermissions($context['user'], ['medications.controlled.record']);
        $witness = $this->makeRoleUser('support_worker');
        $witness->forceFill(['password' => Hash::make('password')])->save();
        $this->grantPermissions($witness, ['medications.controlled.witness']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $witness->id,
            'primary_site_id' => $context['site']->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth(),
            'end_date' => null,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $witness->id,
            'assessor_id' => $context['user']->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today()->subMonth(),
            'expiry_date' => today()->addYear(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_witness_controlled' => true,
        ]);
        Shift::factory()->create([
            'client_id' => $context['client']->id,
            'site_id' => $context['site']->id,
            'service_context_id' => $context['client']->service_context_id,
            'user_id' => $witness->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'actual_starts_at' => now()->subHour(),
            'status' => 'in_progress',
            'created_by' => $context['user']->id,
        ]);
        $order = MedicationPharmacyOrder::create([
            'client_id' => $context['client']->id,
            'client_medication_id' => $context['med']->id,
            'pharmacy_name' => 'Local Pharmacy',
            'quantity_ordered' => 1,
            'status' => 'dispensed',
            'ordered_by' => $context['user']->id,
            'batch_number' => 'CD-LOT-01',
            'batch_expiry' => now()->addYear()->toDateString(),
        ]);
        $payload = [
            'client_medication_id' => $context['med']->id,
            'quantity_received' => '0.50',
            'on_hand_before' => '12.00',
            'on_hand_after' => '12.50',
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
            'delivery_notes' => 'Sealed pack checked against the order.',
            'client_request_uuid' => '13410594-34f1-4650-b5b7-e99038437aad',
            'queued_offline' => false,
        ];

        return [...$context, 'witness' => $witness, 'order' => $order, 'payload' => $payload];
    }

    private function assertAuditFailureRollsBack(string $action, callable $mutation): void
    {
        $injectFailure = true;
        AuditLog::creating(function (AuditLog $audit) use ($action, &$injectFailure): void {
            if ($injectFailure && $audit->action === $action) {
                throw new RuntimeException('Injected strict audit failure for '.$action.'.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $mutation();
            $this->fail('The injected strict audit failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected strict audit failure for '.$action.'.', $exception->getMessage());
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }

        $this->assertDatabaseMissing('audit_logs', ['action' => $action]);
    }
}
