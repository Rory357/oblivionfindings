<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationPharmacyOrder;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
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

    private function seedStock(bool $controlled = false): array
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.stock.update']);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => $controlled ? 'Morphine sulfate' : 'Paracetamol', 'dosage' => '10mg', 'frequency' => 'PRN',
            'controlled_drug' => $controlled, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
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
        $med->update(['barcode' => 'MED-HALF-001']);

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
            'scope' => 'emar-controlled-pharmacy-delivery:'.$context['order']->id.':actor:'.$context['user']->id,
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
