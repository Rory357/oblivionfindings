<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\EnsurePermission;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationDestruction;
use App\Models\MedicationPharmacyOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class MedicationGovernanceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_direct_route_matrix_uses_only_the_exact_action_capability(): void
    {
        foreach ($this->controlledRoutes() as [$method, $name]) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $middleware = $route->gatherMiddleware();

            $this->assertContains($method, $route->methods(), $name);
            $this->assertContains('permission:'.MedicationGovernanceScopeService::CONTROLLED_CAPABILITY, $middleware, $name);
            $this->assertStringNotContainsString('medications.orders.manage', implode('|', $middleware), $name);
            $this->assertStringNotContainsString('clients.update', implode('|', $middleware), $name);
        }

        foreach ($this->stockRoutes() as [$method, $name]) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $middleware = $route->gatherMiddleware();

            $this->assertContains($method, $route->methods(), $name);
            $this->assertContains('permission:'.MedicationGovernanceScopeService::STOCK_CAPABILITY, $middleware, $name);
            $this->assertStringNotContainsString('medications.orders.manage', implode('|', $middleware), $name);
            $this->assertStringNotContainsString('clients.update', implode('|', $middleware), $name);
        }
    }

    public function test_orders_only_actor_is_denied_across_the_direct_route_matrix_without_side_effects(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(['medications.orders.manage'], $context['local_site']);
        $before = $this->stateSnapshot($context);

        foreach ($this->routeRequests($context) as [$method, $name, $parameters]) {
            $this->actingAs($actor)
                ->call($method, route($name, $parameters))
                ->assertForbidden();
        }

        $this->assertSame($before, $this->stateSnapshot($context));
    }

    public function test_governance_scope_rechecks_exact_capability_behind_route_middleware(): void
    {
        $this->withoutMiddleware(EnsurePermission::class);
        $context = $this->context();
        $actor = $this->userWithPermissions(['medications.orders.manage'], $context['local_site']);

        $this->actingAs($actor)
            ->post(
                route('emar.controlled.entries.store'),
                $this->controlledEntryPayload(
                    $context['local_client'],
                    $context['local_medication'],
                    $context['witness'],
                ),
            )
            ->assertForbidden();
        $this->actingAs($actor)
            ->patch(route('emar.stock.update', $context['local_stock']), ['reorder_level' => 7])
            ->assertForbidden();

        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame(5, (int) $context['local_stock']->refresh()->reorder_level);
    }

    public function test_site_scoped_exact_capabilities_allow_their_own_duties(): void
    {
        $context = $this->context();
        $controlledActor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );
        $stockActor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::STOCK_CAPABILITY],
            $context['local_site'],
        );

        $this->actingAs($controlledActor)
            ->patch(route('emar.stock.update', $context['local_stock']), ['reorder_level' => 7])
            ->assertForbidden();
        $this->actingAs($stockActor)
            ->post(route('emar.controlled.entries.store'), $this->controlledEntryPayload($context['local_client'], $context['local_medication'], $context['witness']))
            ->assertForbidden();

        $this->actingAs($controlledActor)
            ->post(route('emar.controlled.entries.store'), $this->controlledEntryPayload($context['local_client'], $context['local_medication'], $context['witness']))
            ->assertRedirect();

        $this->actingAs($stockActor)
            ->patch(route('emar.stock.update', $context['local_stock']), ['reorder_level' => 7])
            ->assertRedirect();

        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $context['local_medication']->id,
            'recorded_by' => $controlledActor->id,
        ]);
        $this->assertSame(7, (int) $context['local_stock']->refresh()->reorder_level);
    }

    public function test_exact_capabilities_do_not_authorize_foreign_site_direct_ids(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions([
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
            MedicationGovernanceScopeService::STOCK_CAPABILITY,
        ], $context['local_site']);
        $before = $this->stateSnapshot($context);

        foreach ($this->routeRequests($context, foreign: true) as [$method, $name, $parameters, $payload]) {
            $this->actingAs($actor)
                ->call($method, route($name, $parameters), $payload)
                ->assertForbidden();
        }

        $this->assertSame($before, $this->stateSnapshot($context));
    }

    public function test_forged_same_site_parent_ids_are_concealed_without_mutation(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions([
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
            MedicationGovernanceScopeService::STOCK_CAPABILITY,
        ], $context['local_site']);
        $otherClient = Client::factory()->create([
            'site_id' => $context['local_site']->id,
            'status' => 'active',
        ]);
        $entryCount = ClientControlledDrugEntry::count();
        $destructionCount = MedicationDestruction::count();
        $orderCount = MedicationPharmacyOrder::count();

        $this->actingAs($actor)
            ->post(route('emar.controlled.entries.store'), [
                ...$this->controlledEntryPayload($otherClient, $context['local_medication'], $context['witness']),
                'medication_name' => $context['local_medication']->name,
            ])
            ->assertNotFound();

        $this->actingAs($actor)
            ->post(route('emar.destructions.store'), [
                'client_id' => $otherClient->id,
                'client_medication_id' => $context['local_medication']->id,
                'medication_name' => $context['local_medication']->name,
                'quantity' => 1,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'denaturing',
                'is_controlled_drug' => true,
                'witness_1_id' => $context['witness']->id,
                'witness_2_id' => $context['second_witness']->id,
                'authorised_by_name' => 'Pharmacist Pat',
            ])
            ->assertNotFound();

        $this->actingAs($actor)
            ->post(route('emar.pharmacy_orders.store'), [
                'client_id' => $otherClient->id,
                'client_medication_id' => $context['local_medication']->id,
                'pharmacy_name' => 'Local Pharmacy',
                'quantity_ordered' => 5,
            ])
            ->assertNotFound();

        $this->assertSame($entryCount, ClientControlledDrugEntry::count());
        $this->assertSame($destructionCount, MedicationDestruction::count());
        $this->assertSame($orderCount, MedicationPharmacyOrder::count());
        $this->assertSame(10, (int) $context['local_stock']->refresh()->on_hand);
    }

    public function test_explicit_global_site_role_still_requires_and_honours_each_exact_capability(): void
    {
        $context = $this->context();
        $globalWithoutDuties = $this->userWithPermissions([
            'clinical.accessAllSites',
            'medications.orders.manage',
        ]);

        $this->actingAs($globalWithoutDuties)
            ->post(
                route('emar.controlled.entries.store'),
                $this->controlledEntryPayload($context['foreign_client'], $context['foreign_medication'], $context['witness']),
            )
            ->assertForbidden();
        $this->actingAs($globalWithoutDuties)
            ->patch(route('emar.stock.update', $context['foreign_stock']), ['reorder_level' => 8])
            ->assertForbidden();

        $globalActor = $this->userWithPermissions([
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
            MedicationGovernanceScopeService::STOCK_CAPABILITY,
            'clinical.accessAllSites',
        ]);

        $this->actingAs($globalActor)
            ->post(
                route('emar.controlled.entries.store'),
                $this->controlledEntryPayload($context['foreign_client'], $context['foreign_medication'], $context['witness']),
            )
            ->assertRedirect();

        $this->actingAs($globalActor)
            ->patch(route('emar.stock.update', $context['foreign_stock']), ['reorder_level' => 8])
            ->assertRedirect();

        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_id' => $context['foreign_client']->id,
            'recorded_by' => $globalActor->id,
        ]);
        $this->assertSame(8, (int) $context['foreign_stock']->refresh()->reorder_level);
    }

    public function test_controlled_recorder_and_witness_capabilities_remain_distinct(): void
    {
        $context = $this->context();
        $recorder = $this->userWithPermissions(
            [
                MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
                'medications.controlled.witness',
            ],
            $context['local_site'],
        );
        $unauthorisedWitness = $this->userWithPermissions([]);

        $this->actingAs($recorder)
            ->from('/emar/controlled')
            ->post(
                route('emar.controlled.entries.store'),
                $this->controlledEntryPayload($context['local_client'], $context['local_medication'], $unauthorisedWitness),
            )
            ->assertSessionHasErrors('witnessed_by');

        $this->actingAs($recorder)
            ->from('/emar/controlled')
            ->post(
                route('emar.controlled.entries.store'),
                $this->controlledEntryPayload($context['local_client'], $context['local_medication'], $recorder),
            )
            ->assertSessionHasErrors('witnessed_by');

        $this->actingAs($recorder)
            ->from('/emar/destructions')
            ->post(route('emar.destructions.store'), [
                'client_id' => $context['local_client']->id,
                'client_medication_id' => $context['local_medication']->id,
                'medication_name' => $context['local_medication']->name,
                'quantity' => 1,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'denaturing',
                'is_controlled_drug' => true,
                'witness_1_id' => $unauthorisedWitness->id,
                'witness_2_id' => $context['second_witness']->id,
                'authorised_by_name' => 'Pharmacist Pat',
            ])
            ->assertSessionHasErrors('witness_1_id');

        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_destructions', 1);
    }

    public function test_idempotent_replay_is_object_bound_and_authorized_before_cache_lookup(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );
        $uuid = '10000000-0000-4000-8000-000000000001';
        $localPayload = $this->controlledEntryPayload(
            $context['local_client'],
            $context['local_medication'],
            $context['witness'],
        ) + ['client_request_uuid' => $uuid];

        $this->actingAs($actor)
            ->postJson(route('emar.controlled.entries.store'), $localPayload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', false);

        $this->actingAs($actor)
            ->postJson(route('emar.controlled.entries.store'), $localPayload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true);

        $foreignPayload = $this->controlledEntryPayload(
            $context['foreign_client'],
            $context['foreign_medication'],
            $context['witness'],
        ) + ['client_request_uuid' => $uuid];
        $this->actingAs($actor)
            ->postJson(route('emar.controlled.entries.store'), $foreignPayload)
            ->assertForbidden();

        $this->assertSame(1, ClientControlledDrugEntry::query()->where('client_id', $context['local_client']->id)->count());
        $this->assertSame(0, ClientControlledDrugEntry::query()->where('client_id', $context['foreign_client']->id)->count());
    }

    public function test_stale_controlled_balance_is_rejected_without_partial_effect(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );

        $this->actingAs($actor)
            ->from('/emar/controlled')
            ->post(route('emar.controlled.entries.store'), [
                ...$this->controlledEntryPayload(
                    $context['local_client'],
                    $context['local_medication'],
                    $context['witness'],
                ),
                'on_hand_before' => 9,
                'on_hand_after' => 8,
            ])
            ->assertSessionHasErrors('on_hand_before');

        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame(10, (int) $context['local_stock']->refresh()->on_hand);
    }

    public function test_replayed_destruction_void_preserves_the_original_void_history(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );
        $destruction = MedicationDestruction::create([
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $context['local_medication']->id,
            'site_id' => $context['local_site']->id,
            'medication_name' => $context['local_medication']->name,
            'quantity' => 1,
            'unit' => 'tablets',
            'reason' => 'expired',
            'disposal_method' => 'denaturing',
            'is_controlled_drug' => true,
            'destroyed_by' => $context['witness']->id,
            'witness_1_id' => $context['witness']->id,
            'destroyed_at' => now(),
        ]);

        $this->actingAs($actor)
            ->from('/emar/destructions')
            ->post(route('emar.destructions.void', $destruction), ['void_reason' => 'Duplicate record'])
            ->assertSessionHasNoErrors();

        $firstVoid = $destruction->fresh();
        $this->actingAs($actor)
            ->from('/emar/destructions')
            ->post(route('emar.destructions.void', $destruction), ['void_reason' => 'Replacement reason'])
            ->assertSessionHasErrors('void_reason');

        $replayedVoid = $destruction->fresh();
        $this->assertSame('Duplicate record', $replayedVoid->void_reason);
        $this->assertSame($firstVoid->voided_at?->toIso8601String(), $replayedVoid->voided_at?->toIso8601String());
        $this->assertSame($actor->id, $replayedVoid->voided_by);
        $this->assertDatabaseCount('medication_destructions', 2);
    }

    public function test_audit_failure_rolls_back_stock_receipt(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::STOCK_CAPABILITY],
            $context['local_site'],
        );
        $injectFailure = true;
        AuditLog::creating(function (AuditLog $audit) use (&$injectFailure): void {
            if ($injectFailure && $audit->action === 'medications.stock.receive') {
                throw new RuntimeException('Injected medication stock audit failure.');
            }
        });

        try {
            $this->withoutExceptionHandling()
                ->actingAs($actor)
                ->post(route('emar.stock.receive'), [
                    'client_medication_id' => $context['local_medication']->id,
                    'quantity' => 2,
                    'scan_code' => $context['local_medication']->barcode,
                    'scan_source' => 'scanner',
                    'scan_verified' => true,
                    'scan_match_source' => 'vendor_barcode',
                ]);

            $this->fail('The injected audit failure should escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected medication stock audit failure.', $exception->getMessage());
        } finally {
            $injectFailure = false;
        }

        $this->assertSame(10, (int) $context['local_stock']->refresh()->on_hand);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.stock.receive']);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $localSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $localClient = Client::factory()->create(['site_id' => $localSite->id, 'status' => 'active']);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
        $localMedication = $this->medication($localClient, 'Local morphine', 'LOCAL-MED-001');
        $foreignMedication = $this->medication($foreignClient, 'Foreign morphine', 'FOREIGN-MED-001');
        $localStock = ClientMedicationStock::create([
            'client_medication_id' => $localMedication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
            'reorder_level' => 5,
        ]);
        $foreignStock = ClientMedicationStock::create([
            'client_medication_id' => $foreignMedication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
            'reorder_level' => 5,
        ]);
        $witness = $this->userWithPermissions(['medications.controlled.witness']);
        $secondWitness = $this->userWithPermissions(['medications.controlled.witness']);
        $foreignDiscrepancy = ClientControlledDrugDiscrepancy::create([
            'client_id' => $foreignClient->id,
            'client_medication_id' => $foreignMedication->id,
            'service_context_id' => $foreignClient->service_context_id,
            'on_hand_before' => 10,
            'on_hand_after' => 9,
            'difference' => -1,
            'reason' => 'Count mismatch',
            'reported_by' => $witness->id,
            'witnessed_by' => $witness->id,
            'status' => 'open',
            'reported_at' => now(),
        ]);
        $foreignDestruction = MedicationDestruction::create([
            'client_id' => $foreignClient->id,
            'client_medication_id' => $foreignMedication->id,
            'site_id' => $foreignSite->id,
            'medication_name' => $foreignMedication->name,
            'quantity' => 1,
            'unit' => 'tablets',
            'reason' => 'expired',
            'disposal_method' => 'denaturing',
            'is_controlled_drug' => true,
            'destroyed_by' => $witness->id,
            'witness_1_id' => $witness->id,
            'destroyed_at' => now(),
        ]);
        $foreignOrder = MedicationPharmacyOrder::create([
            'client_id' => $foreignClient->id,
            'client_medication_id' => $foreignMedication->id,
            'pharmacy_name' => 'Foreign Pharmacy',
            'quantity_ordered' => 5,
            'status' => 'draft',
            'ordered_by' => $witness->id,
        ]);

        return compact(
            'localSite',
            'foreignSite',
            'localClient',
            'foreignClient',
            'localMedication',
            'foreignMedication',
            'localStock',
            'foreignStock',
            'witness',
            'secondWitness',
            'foreignDiscrepancy',
            'foreignDestruction',
            'foreignOrder',
        ) + [
            'local_site' => $localSite,
            'foreign_site' => $foreignSite,
            'local_client' => $localClient,
            'foreign_client' => $foreignClient,
            'local_medication' => $localMedication,
            'foreign_medication' => $foreignMedication,
            'local_stock' => $localStock,
            'foreign_stock' => $foreignStock,
            'foreign_discrepancy' => $foreignDiscrepancy,
            'foreign_destruction' => $foreignDestruction,
            'foreign_order' => $foreignOrder,
            'second_witness' => $secondWitness,
        ];
    }

    private function medication(Client $client, string $name, string $barcode): ClientMedication
    {
        $medication = ClientMedication::create([
            'client_id' => $client->id,
            'name' => $name,
            'dosage' => '10mg',
            'frequency' => 'PRN',
            'controlled_drug' => true,
            'is_prn' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $medication->forceFill(['barcode' => $barcode])->save();

        return $medication;
    }

    private function userWithPermissions(array $permissions, ?Site $site = null): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $permissionMap = Permission::query()
            ->whereIn('key', $permissions)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();
        $user->permissionOverrides()->sync($permissionMap);

        if ($site) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $user->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
                'start_date' => now()->subYear()->toDateString(),
                'end_date' => null,
            ]);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function controlledEntryPayload(Client $client, ClientMedication $medication, User $witness): array
    {
        return [
            'client_id' => $client->id,
            'medication_name' => $medication->name,
            'entry_type' => 'administration',
            'quantity' => 1,
            'on_hand_before' => 10,
            'on_hand_after' => 9,
            'witnessed_by' => $witness->id,
        ];
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function controlledRoutes(): array
    {
        return [
            ['POST', 'emar.controlled.entries.store'],
            ['POST', 'emar.controlled.balance_check.store'],
            ['POST', 'emar.controlled.discrepancies.resolve'],
            ['POST', 'emar.destructions.store'],
            ['POST', 'emar.destructions.void'],
        ];
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function stockRoutes(): array
    {
        return [
            ['POST', 'emar.pharmacy_orders.store'],
            ['PUT', 'emar.pharmacy_orders.update'],
            ['POST', 'emar.pharmacy_orders.advance'],
            ['PATCH', 'emar.stock.update'],
            ['POST', 'emar.stock.receive'],
            ['POST', 'emar.stock.adjust'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}|array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>}>
     */
    private function routeRequests(array $context, bool $foreign = false): array
    {
        if (! $foreign) {
            return [
                ['POST', 'emar.controlled.entries.store', []],
                ['POST', 'emar.controlled.balance_check.store', []],
                ['POST', 'emar.controlled.discrepancies.resolve', ['discrepancy' => $context['foreign_discrepancy']]],
                ['POST', 'emar.destructions.store', []],
                ['POST', 'emar.destructions.void', ['destruction' => $context['foreign_destruction']]],
                ['POST', 'emar.pharmacy_orders.store', []],
                ['PUT', 'emar.pharmacy_orders.update', ['order' => $context['foreign_order']]],
                ['POST', 'emar.pharmacy_orders.advance', ['order' => $context['foreign_order']]],
                ['PATCH', 'emar.stock.update', ['stock' => $context['foreign_stock']]],
                ['POST', 'emar.stock.receive', []],
                ['POST', 'emar.stock.adjust', []],
            ];
        }

        $client = $context['foreign_client'];
        $medication = $context['foreign_medication'];
        $witness = $context['witness'];

        return [
            ['POST', 'emar.controlled.entries.store', [], $this->controlledEntryPayload($client, $medication, $witness)],
            ['POST', 'emar.controlled.balance_check.store', [], [
                'client_id' => $client->id,
                'medication_name' => $medication->name,
                'expected_balance' => 10,
                'actual_balance' => 10,
                'witnessed_by' => $witness->id,
            ]],
            ['POST', 'emar.controlled.discrepancies.resolve', ['discrepancy' => $context['foreign_discrepancy']], [
                'resolution_notes' => 'Reconciled against the signed register.',
            ]],
            ['POST', 'emar.destructions.store', [], [
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'site_id' => $client->site_id,
                'medication_name' => $medication->name,
                'quantity' => 1,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'denaturing',
                'is_controlled_drug' => true,
                'witness_1_id' => $witness->id,
                'witness_2_id' => $context['second_witness']->id,
                'authorised_by_name' => 'Pharmacist Pat',
            ]],
            ['POST', 'emar.destructions.void', ['destruction' => $context['foreign_destruction']], ['void_reason' => 'Duplicate record']],
            ['POST', 'emar.pharmacy_orders.store', [], [
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'pharmacy_name' => 'Foreign Pharmacy',
                'quantity_ordered' => 5,
            ]],
            ['PUT', 'emar.pharmacy_orders.update', ['order' => $context['foreign_order']], ['order_notes' => 'Foreign update']],
            ['POST', 'emar.pharmacy_orders.advance', ['order' => $context['foreign_order']], []],
            ['PATCH', 'emar.stock.update', ['stock' => $context['foreign_stock']], ['reorder_level' => 2]],
            ['POST', 'emar.stock.receive', [], [
                'client_medication_id' => $medication->id,
                'quantity' => 2,
            ]],
            ['POST', 'emar.stock.adjust', [], [
                'client_medication_id' => $medication->id,
                'new_quantity' => 3,
                'reason' => 'Foreign count',
            ]],
        ];
    }

    /** @return array<string, int|string|null> */
    private function stateSnapshot(array $context): array
    {
        return [
            'entries' => ClientControlledDrugEntry::count(),
            'destructions' => MedicationDestruction::count(),
            'orders' => MedicationPharmacyOrder::count(),
            'foreign_stock' => (string) $context['foreign_stock']->fresh()->on_hand,
            'foreign_reorder' => $context['foreign_stock']->fresh()->reorder_level,
            'discrepancy_status' => $context['foreign_discrepancy']->fresh()->status,
            'destruction_voided_at' => $context['foreign_destruction']->fresh()->voided_at?->toIso8601String(),
            'order_status' => $context['foreign_order']->fresh()->status,
            'audits' => AuditLog::count(),
        ];
    }
}
