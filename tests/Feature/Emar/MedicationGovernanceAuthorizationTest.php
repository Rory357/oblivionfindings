<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\EnsurePermission;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ControlledDrugLossReport;
use App\Models\MedicationDestruction;
use App\Models\MedicationIdempotencyResult;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationScheduledStockCount;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public function test_reader_routes_and_sidebar_require_module_plus_exact_operational_capabilities(): void
    {
        foreach ([
            'emar.medications',
            'emar.medications.detail',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, $routeName);
            $this->assertContains(
                'permission:'.MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
                $route->gatherMiddleware(),
                $routeName,
            );
        }

        foreach ([
            'emar.controlled' => MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            'emar.destructions' => MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            'emar.cd_loss.index' => MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            'emar.stock' => MedicationGovernanceScopeService::STOCK_CAPABILITY,
        ] as $routeName => $actionCapability) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, $routeName);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('permission:'.MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY, $middleware, $routeName);
            $this->assertContains('permission:'.$actionCapability, $middleware, $routeName);
        }

        foreach ([
            'emar.reports',
            'emar.reports.export',
            'emar.reports.export_mar',
            'emar.reports.export_discrepancies',
            'reports.medications',
            'reports.medications.export_mar',
            'reports.medications.export_discrepancies',
            'api.medications.reports',
            'api.medications.reports.export',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, $routeName);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('permission:medications.view', $middleware, $routeName);
            $this->assertContains('permission:medications.reports.export', $middleware, $routeName);
            $this->assertStringNotContainsString('reports.viewAny', implode('|', $middleware), $routeName);
        }

        $sharedPermissions = file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php'));
        $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));
        $this->assertIsString($sharedPermissions);
        $this->assertIsString($sidebar);
        $this->assertStringContainsString("'controlledView' => \$user->canDo('medications.controlled.view')", $sharedPermissions);
        $this->assertStringContainsString("'stockUpdate' => \$user->canDo('medications.stock.update')", $sharedPermissions);
        $this->assertMatchesRegularExpression(
            "/if \(can\?\.medications\?\.view && can\?\.medications\?\.controlledView\)\s*admin\.push\(\{\s*title: 'Controlled Drugs'/s",
            $sidebar,
        );
        $this->assertMatchesRegularExpression(
            "/if \(can\?\.medications\?\.view && can\?\.medications\?\.controlledView\)\s*compliance\.push\(\{\s*title: 'Destructions'/s",
            $sidebar,
        );
        $this->assertMatchesRegularExpression(
            "/if \(can\?\.medications\?\.view && can\?\.medications\?\.stockUpdate\)\s*mgmt\.push\(\{\s*title: 'Stock Management'/s",
            $sidebar,
        );

        foreach ([
            'view-only' => [true, false, false, false, false],
            'controlled-action-only' => [false, true, false, false, false],
            'stock-action-only' => [false, false, true, false, false],
            'view-and-controlled' => [true, true, false, true, false],
            'view-and-stock' => [true, false, true, false, true],
        ] as $label => [$view, $controlledView, $stockUpdate, $controlledVisible, $stockVisible]) {
            $this->assertSame($controlledVisible, $view && $controlledView, $label.' controlled visibility');
            $this->assertSame($stockVisible, $view && $stockUpdate, $label.' stock visibility');
        }
    }

    public function test_clients_update_never_substitutes_for_controlled_reader_capability(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(['medications.view', 'clients.update'], $context['local_site']);

        $this->actingAs($actor)->get(route('emar.controlled'))->assertForbidden();
        $this->actingAs($actor)->get(route('emar.cd_loss.index'))->assertForbidden();
        $this->withoutMiddleware(EnsurePermission::class);
        $this->actingAs($actor)->get(route('emar.controlled'))->assertForbidden();
        $this->actingAs($actor)->get(route('emar.cd_loss.index'))->assertForbidden();
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
        $beforeLocalStock = (string) $context['local_stock']->fresh()->on_hand;

        foreach ($this->routeRequests($context, foreign: true) as [$method, $name, $parameters, $payload]) {
            $this->actingAs($actor)
                ->call($method, route($name, $parameters), $payload)
                ->assertNotFound();
        }

        $this->assertSame($before, $this->stateSnapshot($context));
        $this->assertSame($beforeLocalStock, (string) $context['local_stock']->fresh()->on_hand);
    }

    public function test_emar_reader_matrix_conceals_foreign_site_client_and_medication_ids(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions([
            'medications.view',
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            MedicationGovernanceScopeService::STOCK_CAPABILITY,
            'medications.audit.view',
            'medications.reports.export',
            'handovers.viewAny',
        ], $context['local_site']);

        foreach ([
            'emar.prn',
            'emar.controlled',
            'emar.medications',
            'emar.stock',
            'emar.prescriptions',
            'emar.competency',
            'emar.reviews',
            'emar.rounds',
            'emar.self_admin',
            'emar.destructions',
            'emar.handovers',
            'emar.cd_loss.index',
        ] as $routeName) {
            $this->actingAs($actor)
                ->get(route($routeName, ['site_id' => $context['foreign_site']->id]))
                ->assertNotFound();
            $this->actingAs($actor)
                ->get(route($routeName, ['site_id' => 999999]))
                ->assertNotFound();
        }

        foreach ([
            'emar.prn',
            'emar.controlled',
            'emar.medications',
            'emar.stock',
            'emar.destructions',
            'emar.cd_loss.index',
        ] as $routeName) {
            $this->actingAs($actor)
                ->get(route($routeName, ['client_id' => $context['foreign_client']->id]))
                ->assertNotFound();
            $this->actingAs($actor)
                ->get(route($routeName, ['client_id' => 999999]))
                ->assertNotFound();
            $this->actingAs($actor)
                ->get(route($routeName, [
                    'site_id' => $context['local_site']->id,
                    'client_id' => $context['foreign_client']->id,
                ]))
                ->assertNotFound();
        }

        $this->actingAs($actor)
            ->get(route('emar.mar', ['client_id' => $context['foreign_client']->id]))
            ->assertNotFound();
        $this->actingAs($actor)
            ->get(route('emar.medications.detail', $context['foreign_medication']))
            ->assertNotFound();
        $this->actingAs($actor)
            ->get(route('emar.medications.detail', ['medication' => 999999]))
            ->assertNotFound();
        $this->actingAs($actor)
            ->get(route('emar.clients.inr.index', $context['foreign_client']))
            ->assertNotFound();

        $foreignShift = Shift::factory()->create([
            'client_id' => $context['foreign_client']->id,
            'site_id' => $context['foreign_site']->id,
            'service_context_id' => $context['foreign_client']->service_context_id,
            'user_id' => $actor->id,
        ]);
        $this->actingAs($actor)
            ->get(route('emar.handovers.shift_medications', ['shift_id' => $foreignShift->id]))
            ->assertNotFound();
    }

    public function test_medication_detail_conceals_forged_client_relationships(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions([
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
        ], $context['local_site']);

        ClientMedicationAdministration::query()->create([
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $context['local_medication']->id,
            'administered_by' => $context['witness']->id,
            'administered_at' => now(),
            'status' => 'given',
            'dose_given' => 'Visible administration',
        ]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $context['foreign_client']->id,
            'client_medication_id' => $context['local_medication']->id,
            'administered_by' => $context['witness']->id,
            'administered_at' => now(),
            'status' => 'given',
            'dose_given' => 'Forged foreign administration',
        ]);
        MedicationScheduledStockCount::query()->create([
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $context['local_medication']->id,
            'scheduled_date' => today(),
            'status' => 'completed',
            'expected_quantity' => 10,
            'actual_quantity' => 10,
            'notes' => 'Visible stock count',
            'completed_by' => $context['witness']->id,
            'completed_at' => now(),
        ]);
        MedicationScheduledStockCount::query()->create([
            'client_id' => $context['foreign_client']->id,
            'client_medication_id' => $context['local_medication']->id,
            'scheduled_date' => today(),
            'status' => 'completed',
            'expected_quantity' => 9,
            'actual_quantity' => 9,
            'notes' => 'Forged foreign stock count',
            'completed_by' => $context['witness']->id,
            'completed_at' => now(),
        ]);

        $detail = $this->actingAs($actor)
            ->get(route('emar.medications.detail', $context['local_medication']))
            ->assertOk();

        $detail->assertJsonFragment(['label' => 'Visible administration']);
        $detail->assertJsonFragment(['note' => 'Visible stock count']);
        $detail->assertJsonMissing(['label' => 'Forged foreign administration']);
        $detail->assertJsonMissing(['note' => 'Forged foreign stock count']);
    }

    public function test_omitted_filters_intersect_reader_rows_pickers_and_dashboard_with_allowed_sites(): void
    {
        $context = $this->context();
        $foreignOnlyWitness = $this->userWithPermissions(
            ['medications.controlled.witness'],
            $context['foreign_site'],
        );
        $actor = $this->userWithPermissions([
            'medications.view',
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            MedicationGovernanceScopeService::STOCK_CAPABILITY,
        ], $context['local_site']);

        $controlled = $this->actingAs($actor)->get(route('emar.controlled'))->assertOk();
        $this->assertSame([$context['local_medication']->id], collect($controlled->inertiaProps('medications'))->pluck('id')->all());
        $this->assertSame([], collect($controlled->inertiaProps('discrepancies'))->pluck('id')->all());
        $this->assertSame([], collect($controlled->inertiaProps('destructions'))->pluck('id')->all());
        $this->assertSame([], collect($controlled->inertiaProps('lossReports'))->pluck('id')->all());
        $this->assertSame([$context['local_client']->id], collect($controlled->inertiaProps('clients'))->pluck('id')->all());
        $this->assertSame([$context['local_site']->id], collect($controlled->inertiaProps('sites'))->pluck('id')->all());

        $stock = $this->actingAs($actor)->get(route('emar.stock'))->assertOk();
        $this->assertSame([$context['local_stock']->id], collect($stock->inertiaProps('stockItems'))->pluck('id')->all());
        $this->assertSame([], collect($stock->inertiaProps('pharmacyOrders'))->pluck('id')->all());
        $this->assertSame([$context['local_client']->id], collect($stock->inertiaProps('clients'))->pluck('id')->all());
        $this->assertSame([$context['local_medication']->id], collect($stock->inertiaProps('activeMedications'))->pluck('id')->all());
        $this->assertSame([$context['local_site']->id], collect($stock->inertiaProps('sites'))->pluck('id')->all());

        $medications = $this->actingAs($actor)->get(route('emar.medications'))->assertOk();
        $this->assertSame([$context['local_medication']->id], collect($medications->inertiaProps('medications'))->pluck('id')->all());
        $this->assertSame([$context['local_client']->id], collect($medications->inertiaProps('clients'))->pluck('id')->all());
        $this->assertSame([$context['local_site']->id], collect($medications->inertiaProps('sites'))->pluck('id')->all());

        $destructions = $this->actingAs($actor)->get(route('emar.destructions'))->assertOk();
        $this->assertSame([], collect($destructions->inertiaProps('destructions'))->pluck('id')->all());
        $this->assertSame([$context['local_medication']->id], collect($destructions->inertiaProps('medications'))->pluck('id')->all());
        $this->assertSame([$context['local_client']->id], collect($destructions->inertiaProps('clients'))->pluck('id')->all());

        $lossReports = $this->actingAs($actor)->get(route('emar.cd_loss.index'))->assertOk();
        $this->assertSame([], collect($lossReports->json())->pluck('id')->all());

        $dashboard = $this->actingAs($actor)->get(route('emar.index'))->assertOk();
        $this->assertSame([$context['local_medication']->id], collect($dashboard->inertiaProps('medicationOptions'))->pluck('id')->all());
        $this->assertSame([$context['local_client']->id], collect($dashboard->inertiaProps('clientOptions'))->pluck('id')->all());

        $mar = $this->actingAs($actor)->get(route('emar.mar', ['client_id' => $context['local_client']->id]))->assertOk();
        $this->assertNotContains($foreignOnlyWitness->id, collect($mar->inertiaProps('witnesses'))->pluck('id')->all());

        $prn = $this->actingAs($actor)->get(route('emar.prn'))->assertOk();
        $this->assertNotContains($foreignOnlyWitness->id, collect($prn->inertiaProps('witnesses'))->pluck('id')->all());
    }

    public function test_explicit_global_site_permission_broadens_reader_scope_but_never_replaces_page_capability(): void
    {
        $context = $this->context();
        $globalWithoutModule = $this->userWithPermissions(
            MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
        );
        $this->actingAs($globalWithoutModule)
            ->get(route('emar.medications'))
            ->assertForbidden();

        foreach (MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS as $bypassPermission) {
            $globalWithoutControlledView = $this->userWithPermissions([
                MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
                $bypassPermission,
                MedicationGovernanceScopeService::STOCK_CAPABILITY,
            ]);
            $this->actingAs($globalWithoutControlledView)
                ->get(route('emar.controlled', ['site_id' => $context['foreign_site']->id]))
                ->assertForbidden();

            $globalReader = $this->userWithPermissions([
                MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
                $bypassPermission,
                MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
                MedicationGovernanceScopeService::STOCK_CAPABILITY,
            ]);
            $this->actingAs($globalReader)
                ->get(route('emar.medications.detail', $context['foreign_medication']))
                ->assertOk();
            $this->actingAs($globalReader)
                ->get(route('emar.controlled', ['site_id' => $context['foreign_site']->id]))
                ->assertOk();
            $this->actingAs($globalReader)
                ->get(route('emar.stock', ['site_id' => $context['foreign_site']->id]))
                ->assertOk();
            $this->actingAs($globalReader)
                ->get(route('emar.destructions', ['site_id' => $context['foreign_site']->id]))
                ->assertOk();
            $this->actingAs($globalReader)
                ->get(route('emar.cd_loss.index', ['site_id' => $context['foreign_site']->id]))
                ->assertOk();
        }
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
                'witness_1_credential' => 'password',
                'witness_2_id' => $context['second_witness']->id,
                'witness_2_credential' => 'password',
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

        $context['foreign_order']->update(['status' => 'dispensed']);
        $this->actingAs($globalActor)
            ->post(route('emar.pharmacy_orders.controlled_delivery', $context['foreign_order']), [
                'client_medication_id' => $context['foreign_medication']->id,
                'quantity_received' => '0.50',
                'on_hand_before' => '9.00',
                'on_hand_after' => '9.50',
                'witnessed_by' => $context['witness']->id,
                'witness_credential' => 'password',
                'client_request_uuid' => '66331c15-a12a-4b44-b26e-604ebd25bd48',
            ])
            ->assertRedirect();

        $this->actingAs($globalActor)
            ->patch(route('emar.stock.update', $context['foreign_stock']), ['reorder_level' => 8])
            ->assertRedirect();

        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_id' => $context['foreign_client']->id,
            'recorded_by' => $globalActor->id,
        ]);
        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'pharmacy_order_id' => $context['foreign_order']->id,
            'recorded_by' => $globalActor->id,
        ]);
        $this->assertSame('delivered', $context['foreign_order']->refresh()->status);
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
            ->assertNotFound();

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
                'witness_1_credential' => 'password',
                'witness_2_id' => $context['second_witness']->id,
                'witness_2_credential' => 'password',
                'authorised_by_name' => 'Pharmacist Pat',
            ])
            ->assertNotFound();

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
            ->assertNotFound();

        $this->assertSame(1, ClientControlledDrugEntry::query()->where('client_id', $context['local_client']->id)->count());
        $this->assertSame(0, ClientControlledDrugEntry::query()->where('client_id', $context['foreign_client']->id)->count());
    }

    public function test_fractional_controlled_entry_is_lossless_across_failure_retry_and_replay(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );
        $uuid = '10000000-0000-4000-8000-000000000010';
        $payload = [
            ...$this->controlledEntryPayload(
                $context['local_client'],
                $context['local_medication'],
                $context['witness'],
            ),
            'quantity' => 0.5,
            'on_hand_before' => 10,
            'on_hand_after' => 9.5,
            'client_request_uuid' => $uuid,
        ];

        $this->actingAs($actor)
            ->postJson(route('emar.controlled.entries.store'), [
                ...$payload,
                'quantity' => 0.001,
                'on_hand_after' => 9.999,
                'client_request_uuid' => '10000000-0000-4000-8000-000000000012',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity', 'on_hand_after']);

        $injectFailure = true;

        MedicationIdempotencyResult::creating(
            function (MedicationIdempotencyResult $result) use (&$injectFailure, $uuid): void {
                if ($injectFailure && $result->request_uuid === $uuid) {
                    $injectFailure = false;

                    throw new RuntimeException('Injected failure after fractional stock mutation.');
                }
            },
        );

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($actor)
                ->postJson(route('emar.controlled.entries.store'), $payload);
            $this->fail('The injected transaction failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure after fractional stock mutation.', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertSame(10.0, (float) $context['local_stock']->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseMissing('medication_idempotency_results', [
            'request_uuid' => $uuid,
        ]);

        $this->actingAs($actor)
            ->postJson(route('emar.controlled.entries.store'), $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', false);
        $this->actingAs($actor)
            ->postJson(route('emar.controlled.entries.store'), $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true);

        $entry = ClientControlledDrugEntry::query()
            ->where('client_medication_id', $context['local_medication']->id)
            ->sole();
        $this->assertSame(0.5, (float) $entry->quantity);
        $this->assertSame(10.0, (float) $entry->on_hand_before);
        $this->assertSame(9.5, (float) $entry->on_hand_after);
        $this->assertSame(9.5, (float) $context['local_stock']->refresh()->on_hand);
        $this->assertSame(1, MedicationIdempotencyResult::query()
            ->where('request_uuid', $uuid)
            ->count());
    }

    public function test_fractional_balance_check_preserves_entry_discrepancy_and_replay_provenance(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );
        $uuid = '10000000-0000-4000-8000-000000000011';
        $payload = [
            'client_medication_id' => $context['local_medication']->id,
            'expected_balance' => 10,
            'actual_balance' => 9.5,
            'witnessed_by' => $context['witness']->id,
            'witness_credential' => 'password',
            'discrepancy_notes' => 'Half unit count variance.',
            'immediate_action_taken' => 'Secured stock and notified the clinical lead.',
            'client_request_uuid' => $uuid,
        ];

        $injectFailure = true;
        MedicationIdempotencyResult::creating(
            function (MedicationIdempotencyResult $result) use (&$injectFailure, $uuid): void {
                if ($injectFailure && $result->request_uuid === $uuid) {
                    $injectFailure = false;

                    throw new RuntimeException('Injected failure after fractional balance check.');
                }
            },
        );

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($actor)
                ->postJson(route('emar.controlled.balance_check.store'), $payload);
            $this->fail('The injected transaction failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure after fractional balance check.', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertSame(10.0, (float) $context['local_stock']->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame(0, ClientControlledDrugDiscrepancy::query()
            ->where('client_medication_id', $context['local_medication']->id)
            ->count());
        $this->assertDatabaseMissing('medication_idempotency_results', [
            'request_uuid' => $uuid,
        ]);

        $this->actingAs($actor)
            ->postJson(route('emar.controlled.balance_check.store'), $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', false);
        $this->actingAs($actor)
            ->postJson(route('emar.controlled.balance_check.store'), $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true);

        $entry = ClientControlledDrugEntry::query()
            ->where('client_medication_id', $context['local_medication']->id)
            ->where('entry_type', 'balance_check')
            ->sole();
        $discrepancy = ClientControlledDrugDiscrepancy::query()
            ->where('client_medication_id', $context['local_medication']->id)
            ->sole();
        $this->assertSame(10.0, (float) $entry->on_hand_before);
        $this->assertSame(9.5, (float) $entry->on_hand_after);
        $this->assertSame(10.0, (float) $discrepancy->on_hand_before);
        $this->assertSame(9.5, (float) $discrepancy->on_hand_after);
        $this->assertSame(-0.5, (float) $discrepancy->difference);
        $this->assertSame(9.5, (float) $context['local_stock']->refresh()->on_hand);
        $this->assertSame(1, MedicationIdempotencyResult::query()
            ->where('request_uuid', $uuid)
            ->count());
    }

    public function test_fractional_controlled_destruction_preserves_stock_and_register_provenance(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );

        $this->actingAs($actor)
            ->post(route('emar.destructions.store'), [
                'client_id' => $context['local_client']->id,
                'client_medication_id' => $context['local_medication']->id,
                'site_id' => $context['local_site']->id,
                'medication_name' => $context['local_medication']->name,
                'quantity' => 0.5,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'denaturing',
                'is_controlled_drug' => true,
                'witness_1_id' => $context['witness']->id,
                'witness_1_credential' => 'password',
                'witness_2_id' => $context['second_witness']->id,
                'witness_2_credential' => 'password',
                'authorised_by_name' => 'Pharmacist Pat',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $entry = ClientControlledDrugEntry::query()
            ->where('client_medication_id', $context['local_medication']->id)
            ->where('entry_type', 'disposal')
            ->sole();
        $destruction = MedicationDestruction::query()
            ->where('client_medication_id', $context['local_medication']->id)
            ->sole();
        $this->assertSame(0.5, (float) $destruction->quantity);
        $this->assertSame(0.5, (float) $entry->quantity);
        $this->assertSame(10.0, (float) $entry->on_hand_before);
        $this->assertSame(9.5, (float) $entry->on_hand_after);
        $this->assertSame(9.5, (float) $context['local_stock']->refresh()->on_hand);
    }

    public function test_controlled_destruction_requires_canonical_locked_stock_before_any_write(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );
        $unstockedMedication = $this->medication(
            $context['local_client'],
            'Unstocked controlled medication',
            'LOCAL-MED-UNSTOCKED',
        );
        $before = $this->stateSnapshot($context);

        $this->actingAs($actor)
            ->from('/emar/destructions')
            ->post(route('emar.destructions.store'), [
                ...$this->controlledDestructionPayload($context),
                'client_medication_id' => $unstockedMedication->id,
                'medication_name' => $unstockedMedication->name,
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertSame($before, $this->stateSnapshot($context));
        $this->assertDatabaseMissing('medication_destructions', [
            'client_medication_id' => $unstockedMedication->id,
        ]);
    }

    public function test_shared_stock_writers_reject_excess_scale_without_any_side_effect(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions([
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
            MedicationGovernanceScopeService::STOCK_CAPABILITY,
        ], $context['local_site']);
        $before = $this->stateSnapshot($context);

        $this->actingAs($actor)
            ->postJson(route('emar.destructions.store'), [
                'client_id' => $context['local_client']->id,
                'client_medication_id' => $context['local_medication']->id,
                'medication_name' => $context['local_medication']->name,
                'quantity' => 0.015,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'denaturing',
                'witness_1_id' => $context['witness']->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
        $this->actingAs($actor)
            ->postJson(route('emar.stock.receive'), [
                'client_medication_id' => $context['local_medication']->id,
                'quantity' => 0.015,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
        $this->actingAs($actor)
            ->postJson(route('emar.stock.adjust'), [
                'client_medication_id' => $context['local_medication']->id,
                'new_quantity' => 9.999,
                'reason' => 'Invalid precision probe',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_quantity');

        $this->assertSame($before, $this->stateSnapshot($context));
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

    public function test_controlled_mutations_bind_to_the_locked_canonical_medication_identity(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );
        $duplicateNameMedication = $this->medication(
            $context['local_client'],
            $context['local_medication']->name,
            'LOCAL-MED-002',
        );
        $duplicateNameStock = ClientMedicationStock::create([
            'client_medication_id' => $duplicateNameMedication->id,
            'on_hand' => 20,
            'unit' => 'tablets',
        ]);

        $canonicalPayload = [
            ...$this->controlledEntryPayload(
                $context['local_client'],
                $duplicateNameMedication,
                $context['witness'],
            ),
            'on_hand_before' => 20,
            'on_hand_after' => 19,
        ];
        unset($canonicalPayload['client_id'], $canonicalPayload['medication_name']);

        $this->actingAs($actor)
            ->post(route('emar.controlled.entries.store'), $canonicalPayload)
            ->assertRedirect();

        $this->assertSame(10, (int) $context['local_stock']->refresh()->on_hand);
        $this->assertSame(19, (int) $duplicateNameStock->refresh()->on_hand);
        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_medication_id' => $duplicateNameMedication->id,
            'client_id' => $context['local_client']->id,
        ]);

        $entryCount = ClientControlledDrugEntry::count();
        $otherClient = Client::factory()->create([
            'site_id' => $context['local_site']->id,
            'status' => 'active',
        ]);
        $invalidPayloads = [
            [
                ...$this->controlledEntryPayload($context['local_client'], $context['local_medication'], $context['witness']),
                'client_id' => $otherClient->id,
            ],
            [
                ...$this->controlledEntryPayload($context['local_client'], $context['local_medication'], $context['witness']),
                'medication_name' => 'Forged medicine name',
            ],
            [
                ...$this->controlledEntryPayload($context['foreign_client'], $context['foreign_medication'], $context['witness']),
                'client_id' => $context['local_client']->id,
            ],
        ];

        foreach ($invalidPayloads as $payload) {
            $this->actingAs($actor)
                ->post(route('emar.controlled.entries.store'), $payload)
                ->assertNotFound();
        }

        $missingMedicationId = $this->controlledEntryPayload(
            $context['local_client'],
            $context['local_medication'],
            $context['witness'],
        );
        unset($missingMedicationId['client_medication_id']);
        $this->actingAs($actor)
            ->from('/emar/controlled')
            ->post(route('emar.controlled.entries.store'), $missingMedicationId)
            ->assertSessionHasErrors('client_medication_id');

        $this->assertSame($entryCount, ClientControlledDrugEntry::count());
        $this->assertSame(10, (int) $context['local_stock']->refresh()->on_hand);
    }

    public function test_controlled_witness_is_current_site_eligible_distinct_and_credential_confirmed(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );
        $foreignWitness = $this->userWithPermissions(
            ['medications.controlled.witness'],
            $context['foreign_site'],
        );
        $inactiveWitness = $this->userWithPermissions(
            ['medications.controlled.witness'],
            $context['local_site'],
        );
        $inactiveWitness->hrEmployeeProfile->update(['is_active' => false]);
        $endedWitness = $this->userWithPermissions(
            ['medications.controlled.witness'],
            $context['local_site'],
        );
        $endedWitness->hrEmployeeProfile->update(['end_date' => now()->subDay()->toDateString()]);
        $unapprovedWitness = $this->userWithPermissions(
            ['medications.controlled.witness'],
            $context['local_site'],
        );
        $unapprovedWitness->update(['approved_at' => null]);
        $permissionlessWitness = $this->userWithPermissions([], $context['local_site']);

        foreach ([$foreignWitness, $inactiveWitness, $endedWitness, $unapprovedWitness, $permissionlessWitness] as $concealedWitness) {
            $this->actingAs($actor)
                ->post(
                    route('emar.controlled.entries.store'),
                    $this->controlledEntryPayload(
                        $context['local_client'],
                        $context['local_medication'],
                        $concealedWitness,
                    ),
                )
                ->assertNotFound();
        }

        $this->actingAs($actor)
            ->from('/emar/controlled')
            ->post(route('emar.controlled.entries.store'), [
                ...$this->controlledEntryPayload($context['local_client'], $context['local_medication'], $actor),
                'witness_credential' => 'password',
            ])
            ->assertSessionHasErrors('witnessed_by');

        foreach ([null, 'wrong-password'] as $credential) {
            $this->actingAs($actor)
                ->from('/emar/controlled')
                ->post(route('emar.controlled.entries.store'), [
                    ...$this->controlledEntryPayload(
                        $context['local_client'],
                        $context['local_medication'],
                        $context['witness'],
                    ),
                    'witness_credential' => $credential,
                ])
                ->assertSessionHasErrors('witness_credential');
        }

        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame(10, (int) $context['local_stock']->refresh()->on_hand);
    }

    public function test_stock_movement_requires_a_complete_valid_transition_and_constrained_initialization(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );
        $basePayload = $this->controlledEntryPayload(
            $context['local_client'],
            $context['local_medication'],
            $context['witness'],
        );

        foreach (['on_hand_before', 'on_hand_after'] as $missingBalance) {
            $payload = $basePayload;
            unset($payload[$missingBalance]);
            $this->actingAs($actor)
                ->from('/emar/controlled')
                ->post(route('emar.controlled.entries.store'), $payload)
                ->assertSessionHasErrors($missingBalance);

            $this->actingAs($actor)
                ->from('/emar/controlled')
                ->post(route('emar.controlled.entries.store'), [
                    ...$basePayload,
                    $missingBalance => null,
                ])
                ->assertSessionHasErrors($missingBalance);
        }

        $this->actingAs($actor)
            ->from('/emar/controlled')
            ->post(route('emar.controlled.entries.store'), [
                ...$basePayload,
                'on_hand_after' => 7,
            ])
            ->assertSessionHasErrors('on_hand_after');
        $this->actingAs($actor)
            ->from('/emar/controlled')
            ->post(route('emar.controlled.entries.store'), [
                ...$basePayload,
                'entry_type' => 'adjustment',
                'quantity' => 2,
                'on_hand_after' => 9,
            ])
            ->assertSessionHasErrors('quantity');

        $uninitializedMedication = $this->medication(
            $context['local_client'],
            'Uninitialized morphine',
            'LOCAL-MED-INIT',
        );
        $initialPayload = [
            ...$this->controlledEntryPayload($context['local_client'], $uninitializedMedication, $context['witness']),
            'entry_type' => 'receipt',
            'quantity' => 3,
            'unit' => 'tablets',
            'on_hand_before' => 0,
            'on_hand_after' => 3,
        ];

        foreach ([
            $initialPayload,
            [...$initialPayload, 'initialize_stock' => true, 'entry_type' => 'transfer_in'],
            [...$initialPayload, 'initialize_stock' => true, 'on_hand_before' => 1, 'on_hand_after' => 4],
            [...$initialPayload, 'initialize_stock' => true, 'unit' => null],
        ] as $invalidInitialization) {
            $this->actingAs($actor)
                ->from('/emar/controlled')
                ->post(route('emar.controlled.entries.store'), $invalidInitialization)
                ->assertSessionHasErrors('initialize_stock');
        }

        $this->assertDatabaseMissing('client_medication_stocks', [
            'client_medication_id' => $uninitializedMedication->id,
        ]);
        $this->actingAs($actor)
            ->post(route('emar.controlled.entries.store'), [
                ...$initialPayload,
                'initialize_stock' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_medication_stocks', [
            'client_medication_id' => $uninitializedMedication->id,
            'on_hand' => 3,
            'unit' => 'tablets',
        ]);
        $this->assertSame(10, (int) $context['local_stock']->refresh()->on_hand);
        $this->assertSame(1, ClientControlledDrugEntry::query()
            ->where('client_medication_id', $uninitializedMedication->id)
            ->count());
    }

    public function test_balance_check_requires_existing_locked_stock_and_canonical_medication(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );
        $uninitializedMedication = $this->medication(
            $context['local_client'],
            'No stock morphine',
            'LOCAL-MED-NO-STOCK',
        );
        $balancePayload = [
            'client_medication_id' => $context['local_medication']->id,
            'expected_balance' => 10,
            'actual_balance' => 10,
            'witnessed_by' => $context['witness']->id,
            'witness_credential' => 'password',
        ];

        foreach (['expected_balance', 'actual_balance'] as $missingBalance) {
            $payload = $balancePayload;
            unset($payload[$missingBalance]);
            $this->actingAs($actor)
                ->from('/emar/controlled')
                ->post(route('emar.controlled.balance_check.store'), $payload)
                ->assertSessionHasErrors($missingBalance);

            $this->actingAs($actor)
                ->from('/emar/controlled')
                ->post(route('emar.controlled.balance_check.store'), [
                    ...$balancePayload,
                    $missingBalance => null,
                ])
                ->assertSessionHasErrors($missingBalance);
        }

        $this->actingAs($actor)
            ->from('/emar/controlled')
            ->post(route('emar.controlled.balance_check.store'), [
                'client_medication_id' => $uninitializedMedication->id,
                'client_id' => $context['local_client']->id,
                'medication_name' => $uninitializedMedication->name,
                'expected_balance' => 0,
                'actual_balance' => 0,
                'witnessed_by' => $context['witness']->id,
                'witness_credential' => 'password',
            ])
            ->assertSessionHasErrors('expected_balance');

        $this->actingAs($actor)
            ->post(route('emar.controlled.balance_check.store'), [
                'client_medication_id' => $context['local_medication']->id,
                'client_id' => $context['local_client']->id,
                'medication_name' => 'Forged medicine name',
                'expected_balance' => 10,
                'actual_balance' => 10,
                'witnessed_by' => $context['witness']->id,
                'witness_credential' => 'password',
            ])
            ->assertNotFound();

        $otherClient = Client::factory()->create([
            'site_id' => $context['local_site']->id,
            'status' => 'active',
        ]);
        $this->actingAs($actor)
            ->post(route('emar.controlled.balance_check.store'), [
                ...$balancePayload,
                'client_id' => $otherClient->id,
            ])
            ->assertNotFound();

        $this->actingAs($actor)
            ->post(route('emar.controlled.balance_check.store'), [
                'client_medication_id' => $context['local_medication']->id,
                'expected_balance' => 10,
                'actual_balance' => 10,
                'witnessed_by' => $context['witness']->id,
                'witness_credential' => 'password',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_medication_id' => $context['local_medication']->id,
            'client_id' => $context['local_client']->id,
            'entry_type' => 'balance_check',
        ]);
        $this->assertSame(10, (int) $context['local_stock']->refresh()->on_hand);
    }

    public function test_idempotency_result_rolls_back_with_the_failed_transaction_and_retry_can_publish_it(): void
    {
        $scope = 'emar-controlled-entry:999:actor:999';
        $request = ['client_request_uuid' => '10000000-0000-4000-8000-000000000099'];
        $payload = ['success' => true, 'entry' => ['id' => 123]];
        $service = app(MedicationGovernanceScopeService::class);

        try {
            DB::transaction(function () use ($service, $scope, $request, $payload): void {
                $service->rememberIdempotencyResult($scope, $request, $payload);

                throw new RuntimeException('Injected failure after durable replay write.');
            });
            $this->fail('The injected transaction failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure after durable replay write.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('medication_idempotency_results', [
            'scope' => $scope,
            'request_uuid' => $request['client_request_uuid'],
        ]);

        DB::transaction(fn () => $service->rememberIdempotencyResult($scope, $request, $payload));
        $stored = DB::transaction(fn () => $service->idempotencyResult($scope, $request));

        $this->assertSame($payload, $stored);
        $this->assertDatabaseHas('medication_idempotency_results', [
            'scope' => $scope,
            'request_uuid' => $request['client_request_uuid'],
        ]);
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
        $medication = ClientMedication::create([
            'client_id' => $context['local_client']->id,
            'name' => 'Audited non-controlled receipt',
            'dosage' => '500mg',
            'frequency' => 'PRN',
            'controlled_drug' => false,
            'is_prn' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $medication->forceFill(['barcode' => 'AUDITED-STOCK-001'])->save();
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
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
                    'client_medication_id' => $medication->id,
                    'quantity' => 0.5,
                    'scan_code' => $medication->barcode,
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

        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.stock.receive']);
    }

    public function test_strict_audit_failure_rolls_back_controlled_entry(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );
        $before = $this->stateSnapshot($context);

        $this->assertStrictAuditFailureRollsBack(
            'medications.controlled.entry.record',
            fn () => $this->actingAs($actor)->post(
                route('emar.controlled.entries.store'),
                $this->controlledEntryPayload(
                    $context['local_client'],
                    $context['local_medication'],
                    $context['witness'],
                ),
            ),
        );

        $this->assertSame($before, $this->stateSnapshot($context));
    }

    public function test_strict_audit_failure_rolls_back_controlled_balance_check(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );
        $before = $this->stateSnapshot($context);

        $this->assertStrictAuditFailureRollsBack(
            'medications.controlled.balance_check.record',
            fn () => $this->actingAs($actor)->post(route('emar.controlled.balance_check.store'), [
                'client_medication_id' => $context['local_medication']->id,
                'expected_balance' => 10,
                'actual_balance' => 9.5,
                'witnessed_by' => $context['witness']->id,
                'witness_credential' => 'password',
                'discrepancy_notes' => 'Half unit variance under investigation.',
                'immediate_action_taken' => 'Secured stock and notified the clinical lead.',
            ]),
        );

        $this->assertSame($before, $this->stateSnapshot($context));
        $this->assertSame(0, ClientControlledDrugDiscrepancy::query()
            ->where('client_medication_id', $context['local_medication']->id)
            ->count());
    }

    public function test_strict_audit_failure_rolls_back_controlled_discrepancy_resolution(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );
        $discrepancy = ClientControlledDrugDiscrepancy::create([
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $context['local_medication']->id,
            'service_context_id' => $context['local_client']->service_context_id,
            'on_hand_before' => 10,
            'on_hand_after' => 9.5,
            'difference' => -0.5,
            'reason' => 'Count mismatch',
            'reported_by' => $context['witness']->id,
            'witnessed_by' => $context['witness']->id,
            'status' => 'open',
            'reported_at' => now(),
        ]);

        $this->assertStrictAuditFailureRollsBack(
            'medications.controlled.discrepancy.resolve',
            fn () => $this->actingAs($actor)->post(
                route('emar.controlled.discrepancies.resolve', $discrepancy),
                [
                    'resolution_notes' => 'Recount completed and source identified.',
                    'resolution_action' => 'Stock secured',
                ],
            ),
        );

        $this->assertSame('open', $discrepancy->fresh()->status);
        $this->assertNull($discrepancy->fresh()->resolved_at);
        $this->assertNull($discrepancy->fresh()->resolved_by);
    }

    public function test_client_medical_discrepancy_resolution_uses_the_same_strict_audit_transaction(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions([
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
            'medications.view',
        ], $context['local_site']);
        $discrepancy = ClientControlledDrugDiscrepancy::create([
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $context['local_medication']->id,
            'service_context_id' => $context['local_client']->service_context_id,
            'on_hand_before' => 10,
            'on_hand_after' => 9.5,
            'difference' => -0.5,
            'reason' => 'Count mismatch',
            'reported_by' => $context['witness']->id,
            'witnessed_by' => $context['witness']->id,
            'status' => 'open',
            'reported_at' => now(),
        ]);

        $this->assertStrictAuditFailureRollsBack(
            'medications.controlled.discrepancy.resolve',
            fn () => $this->actingAs($actor)->post(
                route('clients.medical.controlled_discrepancies.close', [
                    'client' => $context['local_client'],
                    'discrepancy' => $discrepancy,
                ]),
                ['resolution_notes' => 'Recount completed.'],
            ),
        );

        $this->assertSame('open', $discrepancy->fresh()->status);
        $this->assertNull($discrepancy->fresh()->resolved_at);
        $this->assertNull($discrepancy->fresh()->resolved_by);
    }

    public function test_strict_audit_failure_rolls_back_controlled_destruction(): void
    {
        $context = $this->context();
        $actor = $this->userWithPermissions(
            [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY],
            $context['local_site'],
        );
        $before = $this->stateSnapshot($context);

        $this->assertStrictAuditFailureRollsBack(
            'medications.destruction.record',
            fn () => $this->actingAs($actor)->post(
                route('emar.destructions.store'),
                $this->controlledDestructionPayload($context),
            ),
        );

        $this->assertSame($before, $this->stateSnapshot($context));
    }

    public function test_strict_audit_failure_rolls_back_destruction_void(): void
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
        $before = $this->stateSnapshot($context);

        $this->assertStrictAuditFailureRollsBack(
            'medications.destruction.void',
            fn () => $this->actingAs($actor)->post(
                route('emar.destructions.void', $destruction),
                ['void_reason' => 'Duplicate controlled register record'],
            ),
        );

        $this->assertSame($before, $this->stateSnapshot($context));
        $this->assertNull($destruction->fresh()->voided_at);
        $this->assertNull($destruction->fresh()->voided_by);
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
        $witness = $this->userWithPermissions(['medications.controlled.witness'], $localSite);
        $secondWitness = $this->userWithPermissions(['medications.controlled.witness'], $localSite);
        $witness->hrEmployeeProfile->update(['secondary_site_ids' => [$foreignSite->id]]);
        $secondWitness->hrEmployeeProfile->update(['secondary_site_ids' => [$foreignSite->id]]);
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
        $foreignLoss = ControlledDrugLossReport::create([
            'client_id' => $foreignClient->id,
            'client_medication_id' => $foreignMedication->id,
            'medication_name' => $foreignMedication->name,
            'quantity_lost' => 0.5,
            'unit' => 'tablets',
            'circumstances' => 'Foreign Site loss',
            'immediate_action_taken' => 'Secured stock',
            'discovered_by' => $witness->id,
            'discovered_at' => now(),
            'investigation_status' => 'reported',
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
            'foreignLoss',
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
            'foreign_loss' => $foreignLoss,
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
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'password' => Hash::make('password'),
        ]);
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
            'client_medication_id' => $medication->id,
            'client_id' => $client->id,
            'medication_name' => $medication->name,
            'entry_type' => 'administration',
            'quantity' => 1,
            'on_hand_before' => 10,
            'on_hand_after' => 9,
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
        ];
    }

    /** @return array<string, mixed> */
    private function controlledDestructionPayload(array $context): array
    {
        return [
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $context['local_medication']->id,
            'site_id' => $context['local_site']->id,
            'medication_name' => $context['local_medication']->name,
            'quantity' => 0.5,
            'unit' => 'tablets',
            'reason' => 'expired',
            'disposal_method' => 'denaturing',
            'is_controlled_drug' => true,
            'witness_1_id' => $context['witness']->id,
            'witness_1_credential' => 'password',
            'witness_2_id' => $context['second_witness']->id,
            'witness_2_credential' => 'password',
            'authorised_by_name' => 'Pharmacist Pat',
        ];
    }

    private function assertStrictAuditFailureRollsBack(string $action, callable $mutation): void
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
            $this->fail('The injected strict audit failure should escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected strict audit failure for '.$action.'.', $exception->getMessage());
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }

        $this->assertDatabaseMissing('audit_logs', ['action' => $action]);
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function controlledRoutes(): array
    {
        return [
            ['POST', 'emar.controlled.entries.store'],
            ['POST', 'emar.controlled.balance_check.store'],
            ['POST', 'emar.pharmacy_orders.controlled_delivery'],
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
                ['POST', 'emar.pharmacy_orders.controlled_delivery', ['order' => $context['foreign_order']]],
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
                'client_medication_id' => $medication->id,
                'client_id' => $client->id,
                'medication_name' => $medication->name,
                'expected_balance' => 10,
                'actual_balance' => 10,
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
            ]],
            ['POST', 'emar.pharmacy_orders.controlled_delivery', ['order' => $context['foreign_order']], [
                'client_medication_id' => $medication->id,
                'quantity_received' => '0.50',
                'on_hand_before' => '10.00',
                'on_hand_after' => '10.50',
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
                'client_request_uuid' => '4768024e-a514-46a4-bb49-2e01b83a9ad8',
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
                'witness_1_credential' => 'password',
                'witness_2_id' => $context['second_witness']->id,
                'witness_2_credential' => 'password',
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
            'local_stock' => (string) $context['local_stock']->fresh()->on_hand,
            'foreign_stock' => (string) $context['foreign_stock']->fresh()->on_hand,
            'foreign_reorder' => $context['foreign_stock']->fresh()->reorder_level,
            'discrepancy_status' => $context['foreign_discrepancy']->fresh()->status,
            'destruction_voided_at' => $context['foreign_destruction']->fresh()->voided_at?->toIso8601String(),
            'order_status' => $context['foreign_order']->fresh()->status,
            'audits' => AuditLog::count(),
            'idempotency_results' => DB::table('medication_idempotency_results')->count(),
        ];
    }
}
