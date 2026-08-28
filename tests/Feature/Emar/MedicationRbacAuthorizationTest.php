<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\EnsurePermission;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationError;
use App\Models\MedicationIdempotencyResult;
use App\Models\MedicationMarAttachment;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationScheduledStockCount;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\EnhancedMarService;
use App\Services\Fleet\ResidentTransportJourneyScope;
use App\Services\Fleet\ResidentTransportJourneyService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Tasks\Providers\CdLossReportProvider;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class MedicationRbacAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_medication_routes_use_their_exact_action_capabilities(): void
    {
        $this->assertExactRoutes([
            'medications.controlled.record' => [
                'emar.controlled.entries.store',
                'emar.controlled.balance_check.store',
                'emar.controlled.discrepancies.resolve',
                'emar.destructions.store',
                'emar.destructions.void',
                'emar.cd_loss.store',
                'emar.cd_loss.investigate',
                'emar.cd_loss.resolve',
                'clients.medical.controlled_discrepancies.close',
            ],
            'medications.stock.update' => [
                'emar.pharmacy_orders.store',
                'emar.pharmacy_orders.update',
                'emar.pharmacy_orders.advance',
                'emar.stock.update',
                'emar.stock.receive',
                'emar.stock.adjust',
                'api.medications.scheduled_counts.store',
                'api.medications.scheduled_counts.complete',
                'clients.medical.medications.stock.update',
                'operations.clients.medical.medications.stock.update',
            ],
            'medications.administer.record' => [
                'meds.today.prn',
                'meds.today.record',
                'meds.today.prn_effect',
                'emar.prn_effectiveness.store',
                'meds.round.show',
                'meds.round.start',
                'meds.round.administer',
                'meds.round.complete',
                'api.medications.scan.verify',
                'api.medications.administrations.record',
                'clients.medical.medications.administrations.store',
                'operations.clients.medical.medications.administrations.store',
            ],
            'fleet.medication.manage' => [
                'fleet-assets.transports.pack-medication',
                'fleet-assets.medication-transit.correct-packing-attestation',
                'fleet-assets.medication-transit.return',
            ],
        ]);

        $fleetRoute = Route::getRoutes()->getByName('fleet-assets.medication-transit.administer');
        $this->assertNotNull($fleetRoute);
        $this->assertContains('permission:medications.administer.record', $fleetRoute->gatherMiddleware());
        $this->assertNotContains('permission:fleet.medication.manage', $fleetRoute->gatherMiddleware());

        foreach ([
            'api.medications.attachments.upload',
            'api.medications.supporting_attachments.upload',
            'api.medications.attachments.delete',
            'api.medications.supporting_attachments.delete',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $middleware = implode('|', $route->gatherMiddleware());
            $this->assertStringNotContainsString('clients.update', $middleware, $name);
            $this->assertStringNotContainsString('medications.controlled.view', $middleware, $name);
            $this->assertStringNotContainsString('medications.orders.manage', $middleware, $name);
        }
    }

    public function test_sensitive_reader_routes_require_module_view_and_the_exact_reader_capability(): void
    {
        foreach ([
            'emar.controlled' => 'medications.controlled.view',
            'emar.destructions' => 'medications.controlled.view',
            'emar.cd_loss.index' => 'medications.controlled.view',
            'emar.stock' => 'medications.stock.update',
        ] as $name => $capability) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('permission:medications.view', $route->gatherMiddleware(), $name);
            $this->assertContains('permission:'.$capability, $route->gatherMiddleware(), $name);
        }

        foreach ([
            'api.medications.dashboard.widgets',
            'api.medications.alerts.index',
            'api.medications.shift.summary',
            'api.medications.interactions.index',
            'api.medications.scheduled_counts.index',
            'api.medications.attachments.download',
            'api.medications.supporting_attachments.download',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('permission:medications.view', $route->gatherMiddleware(), $name);
            $this->assertStringNotContainsString('clients.viewAny', implode('|', $route->gatherMiddleware()), $name);
            $this->assertStringNotContainsString('clients.viewAssigned', implode('|', $route->gatherMiddleware()), $name);
        }
    }

    public function test_global_medication_dashboard_readers_reject_client_view_permission(): void
    {
        $actor = $this->userWithPermissions(['clients.viewAny']);

        foreach ([
            'api.medications.dashboard.widgets',
            'api.medications.alerts.index',
        ] as $routeName) {
            $this->actingAs($actor)->getJson(route($routeName))->assertForbidden();
        }

        $this->withoutMiddleware(EnsurePermission::class);

        foreach ([
            'api.medications.dashboard.widgets',
            'api.medications.alerts.index',
        ] as $routeName) {
            $this->actingAs($actor)->getJson(route($routeName))->assertForbidden();
        }
    }

    public function test_global_dashboard_omits_controlled_alerts_and_widgets_without_the_exact_reader(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $ordinaryMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => today()->subMonth(),
            'end_date' => null,
        ]);
        $controlledMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Restricted controlled medication',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $ordinaryAlert = MedicationDashboardAlert::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $ordinaryMedication->id,
            'alert_type' => 'overdue',
            'severity' => 'warning',
            'message' => 'Ordinary alert remains visible',
            'status' => 'active',
        ]);
        $controlledAlert = MedicationDashboardAlert::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'alert_type' => 'controlled_discrepancy',
            'severity' => 'critical',
            'message' => 'Restricted controlled alert',
            'status' => 'active',
        ]);
        $controlledMedicationClinicalAlert = MedicationDashboardAlert::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'alert_type' => 'overdue',
            'severity' => 'warning',
            'message' => 'Controlled medication clinical alert remains visible',
            'status' => 'active',
        ]);
        ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'difference' => -1,
            'reason' => 'Restricted discrepancy reason',
            'reported_at' => now(),
            'status' => 'open',
        ]);

        $reader = $this->userWithPermissions(['medications.view'], $site);
        $alertsResponse = $this->actingAs($reader)
            ->getJson(route('api.medications.alerts.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $ordinaryAlert->id])
            ->assertJsonMissing(['message' => 'Restricted controlled alert'])
            ->assertJsonMissing(['message' => 'Controlled medication clinical alert remains visible']);
        $this->assertNotContains(
            $controlledAlert->id,
            collect($alertsResponse->json('alerts'))->pluck('id')->all(),
        );
        $this->actingAs($reader)
            ->getJson(route('api.medications.dashboard.widgets'))
            ->assertOk()
            ->assertJsonMissingPath('controlled_discrepancies')
            ->assertJsonMissing(['message' => 'Controlled medication clinical alert remains visible']);

        $controlledReader = $this->userWithPermissions([
            'medications.view',
            'medications.controlled.view',
        ], $site);
        $this->actingAs($controlledReader)
            ->getJson(route('api.medications.alerts.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $controlledAlert->id])
            ->assertJsonFragment(['id' => $controlledMedicationClinicalAlert->id]);
        $this->actingAs($controlledReader)
            ->getJson(route('api.medications.dashboard.widgets'))
            ->assertOk()
            ->assertJsonPath('controlled_discrepancies.count', 1)
            ->assertJsonFragment(['medication' => 'Restricted controlled medication']);
    }

    public function test_controlled_medication_clinical_alerts_are_concealed_from_direct_actions_and_audit_aggregates(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $controlledMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $alert = MedicationDashboardAlert::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'alert_type' => 'overdue',
            'severity' => 'warning',
            'message' => 'Controlled medication alert with an ordinary type',
            'status' => 'active',
        ]);
        $actor = $this->userWithPermissions([
            'medications.view',
            'medications.administer.correct',
            'medications.reports.export',
            'shifts.manageAny',
        ], $site);

        $this->actingAs($actor)
            ->postJson(route('api.medications.alerts.acknowledge', $alert))
            ->assertNotFound();
        $this->actingAs($actor)
            ->postJson(route('api.medications.alerts.resolve', $alert), [
                'resolution_notes' => 'Must remain concealed.',
            ])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post(route('emar.alerts.dismiss', $alert))
            ->assertNotFound();
        $this->actingAs($actor)
            ->getJson(route('api.medications.reports', ['type' => 'audit']))
            ->assertOk()
            ->assertJsonPath('safety_alerts.total_alerts', 0)
            ->assertJsonMissing(['message' => 'Controlled medication alert with an ordinary type']);
        $dashboard = $this->actingAs($actor)
            ->get(route('dashboard'))
            ->assertOk();
        $this->assertSame(0, $dashboard->inertiaProps('emarWidgets.activeAlerts'));
        $this->assertSame('active', $alert->fresh()->status);

        $controlledView = Permission::query()
            ->where('key', MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
            ->firstOrFail();
        $actor->permissionOverrides()->syncWithoutDetaching([
            $controlledView->id => ['allowed' => true],
        ]);
        $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($actor)
            ->postJson(route('api.medications.alerts.acknowledge', $alert))
            ->assertNotFound();
        $this->actingAs($actor)
            ->postJson(route('api.medications.alerts.resolve', $alert), [
                'resolution_notes' => 'Controlled view alone cannot mutate this alert.',
            ])
            ->assertNotFound();
        $this->assertSame('active', $alert->fresh()->status);

        $controlledRecord = Permission::query()
            ->where('key', MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)
            ->firstOrFail();
        $actor->permissionOverrides()->syncWithoutDetaching([
            $controlledRecord->id => ['allowed' => true],
        ]);
        $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($actor)
            ->postJson(route('api.medications.alerts.acknowledge', $alert))
            ->assertOk();
        $this->actingAs($actor)
            ->postJson(route('api.medications.alerts.resolve', $alert), [
                'resolution_notes' => 'Resolved by an exact controlled writer.',
            ])
            ->assertOk();
        $this->assertSame('resolved', $alert->fresh()->status);
    }

    public function test_emar_overview_payload_and_actions_follow_exact_controlled_and_stock_capabilities(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $reporter = User::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $ordinaryMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Ordinary stock item',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $controlledMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Restricted dashboard medication',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        ClientMedicationStock::query()->create([
            'client_medication_id' => $ordinaryMedication->id,
            'on_hand' => 0,
            'reorder_level' => 1,
        ]);
        ClientMedicationStock::query()->create([
            'client_medication_id' => $controlledMedication->id,
            'on_hand' => 0,
            'reorder_level' => 1,
        ]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
        $foreignMedication = ClientMedication::factory()->create([
            'client_id' => $foreignClient->id,
            'name' => 'Foreign ordinary stock item',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        ClientMedicationStock::query()->create([
            'client_medication_id' => $foreignMedication->id,
            'on_hand' => 0,
            'reorder_level' => 1,
        ]);
        $ordinaryAlert = MedicationDashboardAlert::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $ordinaryMedication->id,
            'alert_type' => 'overdue',
            'severity' => 'warning',
            'message' => 'Ordinary overview alert',
            'status' => 'active',
        ]);
        $controlledClinicalAlert = MedicationDashboardAlert::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'alert_type' => 'overdue',
            'severity' => 'warning',
            'message' => 'Restricted controlled overview alert',
            'status' => 'active',
        ]);
        MedicationDashboardAlert::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $foreignMedication->id,
            'alert_type' => 'overdue',
            'severity' => 'critical',
            'message' => 'FORGED FOREIGN OVERVIEW ALERT',
            'status' => 'active',
        ]);
        ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'difference' => -1,
            'reason' => 'Restricted dashboard discrepancy',
            'reported_at' => now(),
            'status' => 'open',
        ]);
        ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $foreignMedication->id,
            'difference' => -9,
            'reason' => 'FORGED FOREIGN OVERVIEW DISCREPANCY',
            'reported_at' => now(),
            'status' => 'open',
        ]);
        MedicationError::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $foreignMedication->id,
            'error_type' => 'documentation',
            'severity' => 'minor',
            'description' => 'FORGED FOREIGN OVERVIEW ERROR',
            'reported_at' => now(),
            'reported_by' => $reporter->id,
            'status' => 'reported',
        ]);
        MedicationError::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'error_type' => 'documentation',
            'severity' => 'minor',
            'description' => 'Restricted controlled overview error',
            'reported_at' => now(),
            'reported_by' => $reporter->id,
            'status' => 'reported',
        ]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $foreignMedication->id,
            'administered_by' => $reporter->id,
            'status' => 'missed',
            'scheduled_for' => now(),
            'administered_at' => now(),
            'notes' => 'FORGED FOREIGN OVERVIEW ADMINISTRATION',
        ]);

        $reader = $this->userWithPermissions(['medications.view'], $site);
        $this->actingAs($reader)
            ->get(route('emar.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.view_controlled', false)
                ->where('can.manage_stock', false)
                ->where('stats.controlledCount', 0)
                ->where('stats.activeDiscrepancies', 0)
                ->where('stats.activeAlerts', 1)
                ->where('stats.stockAlerts', 0)
                ->where('stats.totalToday', 0)
                ->where('medicationErrors.open', 0)
                ->where('medicationErrors.byType', [])
                ->where('medicationErrors.trend', fn ($rows) => collect($rows)->sum('count') === 0)
                ->where('activeAlertsList', fn ($alerts) => collect($alerts)->pluck('id')->all() === [$ordinaryAlert->id])
                ->where('recentActivity', fn ($rows) => collect($rows)->isEmpty())
                ->where('medicationOptions', [])
                ->where('witnesses', [])
                ->where('actionCentre', fn ($items) => collect($items)->every(
                    fn ($item) => ! in_array(data_get($item, 'category'), ['controlled', 'stock'], true)
                        && data_get($item, 'summary') !== 'Restricted controlled overview error'
                )));

        $controlledReader = $this->userWithPermissions([
            'medications.view',
            'medications.controlled.view',
        ], $site);
        $this->actingAs($controlledReader)
            ->get(route('emar.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.view_controlled', true)
                ->where('can.manage_stock', false)
                ->where('stats.controlledCount', 1)
                ->where('stats.activeDiscrepancies', 1)
                ->where('stats.activeAlerts', 2)
                ->where('stats.stockAlerts', 0)
                ->where('medicationErrors.open', 1)
                ->where('medicationErrors.byType', fn ($rows) => collect($rows)->sum('count') === 1)
                ->where('medicationErrors.trend', fn ($rows) => collect($rows)->sum('count') === 1)
                ->where('activeAlertsList', fn ($alerts) => collect($alerts)->pluck('id')->sort()->values()->all() === collect([$ordinaryAlert->id, $controlledClinicalAlert->id])->sort()->values()->all())
                ->where('actionCentre', fn ($items) => collect($items)->contains(
                    fn ($item) => data_get($item, 'category') === 'controlled'
                ))
                ->where('actionCentre', fn ($items) => collect($items)->contains(
                    fn ($item) => data_get($item, 'summary') === 'Restricted controlled overview error'
                )));

        $stockReader = $this->userWithPermissions([
            'medications.view',
            'medications.stock.update',
        ], $site);
        $this->actingAs($stockReader)
            ->get(route('emar.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.view_controlled', false)
                ->where('can.manage_stock', true)
                ->where('stats.controlledCount', 0)
                ->where('stats.stockAlerts', 1)
                ->where('actionCentre', fn ($items) => collect($items)
                    ->where('category', 'stock')
                    ->contains(fn ($item) => data_get($item, 'title') === 'Ordinary stock item — low stock'))
                ->where('actionCentre', fn ($items) => collect($items)
                    ->where('category', 'stock')
                    ->doesntContain(fn ($item) => str_contains(
                        (string) data_get($item, 'title'),
                        'Restricted dashboard medication',
                    ))));
    }

    public function test_mar_payload_omits_controlled_discrepancies_and_alerts_without_the_exact_reader(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $controlledMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Restricted MAR medication',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $controlledAlert = MedicationDashboardAlert::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'alert_type' => 'controlled_discrepancy',
            'severity' => 'critical',
            'message' => 'Restricted MAR alert',
            'status' => 'active',
        ]);
        MedicationDashboardAlert::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'alert_type' => 'overdue',
            'severity' => 'warning',
            'message' => 'Controlled medication clinical MAR alert',
            'status' => 'active',
        ]);
        ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'difference' => -1,
            'reason' => 'Restricted MAR discrepancy',
            'reported_at' => now(),
            'status' => 'open',
        ]);

        $reader = $this->userWithPermissions([
            'clients.viewAssigned',
            'medications.view',
        ], $site);
        $client->supportWorkers()->syncWithoutDetaching([$reader->id]);
        $this->actingAs($reader)
            ->get(route('emar.mar', ['client_id' => $client->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.view_controlled', false)
                ->where('controlledDiscrepancies', [])
                ->where('alerts', fn ($alerts) => collect($alerts)->doesntContain(
                    fn ($alert) => data_get($alert, 'id') === $controlledAlert->id
                )));
        $this->actingAs($reader)
            ->getJson(route('api.medications.mar.show', $client))
            ->assertOk()
            ->assertJsonPath('can.view_controlled', false)
            ->assertJsonCount(0, 'controlled_discrepancies')
            ->assertJsonMissing(['message' => 'Restricted MAR alert'])
            ->assertJsonMissing(['message' => 'Controlled medication clinical MAR alert']);
        $this->actingAs($reader)
            ->get(route('operations.clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('emar_summary.pending_alerts_count', 0));

        $controlledReader = $this->userWithPermissions([
            'clients.viewAssigned',
            'medications.view',
            'medications.controlled.view',
        ], $site);
        $client->supportWorkers()->syncWithoutDetaching([$controlledReader->id]);
        $this->actingAs($controlledReader)
            ->get(route('emar.mar', ['client_id' => $client->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.view_controlled', true)
                ->has('controlledDiscrepancies', 1)
                ->where('controlledDiscrepancies.0.reason', 'Restricted MAR discrepancy'));
        $this->actingAs($controlledReader)
            ->getJson(route('api.medications.mar.show', $client))
            ->assertOk()
            ->assertJsonPath('can.view_controlled', true)
            ->assertJsonCount(1, 'controlled_discrepancies')
            ->assertJsonFragment(['message' => 'Restricted MAR alert']);
        $this->actingAs($controlledReader)
            ->get(route('operations.clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('emar_summary.pending_alerts_count', 2));
    }

    public function test_scheduled_stock_count_reader_requires_controlled_view_only_for_controlled_medication(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $ordinaryMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => false,
        ]);
        $controlledMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
        ]);
        $ordinaryCount = MedicationScheduledStockCount::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $ordinaryMedication->id,
            'scheduled_date' => today(),
            'status' => 'pending',
            'expected_quantity' => 4,
        ]);
        $controlledCount = MedicationScheduledStockCount::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'scheduled_date' => today(),
            'status' => 'pending',
            'expected_quantity' => 8,
        ]);
        $actor = $this->userWithPermissions(['medications.view'], $site);
        $client->supportWorkers()->syncWithoutDetaching([$actor->id]);

        $this->withoutMiddleware(EnsurePermission::class);

        $this->actingAs($actor)
            ->getJson(route('api.medications.scheduled_counts.index', [$client, $ordinaryMedication]))
            ->assertOk()
            ->assertJsonPath('counts.0.id', $ordinaryCount->id);

        $this->actingAs($actor)
            ->getJson(route('api.medications.scheduled_counts.index', [$client, $controlledMedication]))
            ->assertNotFound();

        $controlledView = Permission::query()
            ->where('key', 'medications.controlled.view')
            ->firstOrFail();
        $actor->permissionOverrides()->syncWithoutDetaching([
            $controlledView->id => ['allowed' => true],
        ]);
        $actor->unsetRelation('permissionOverrides');
        $actor->unsetRelation('roles');

        $this->actingAs($actor)
            ->getJson(route('api.medications.scheduled_counts.index', [$client, $controlledMedication]))
            ->assertOk()
            ->assertJsonPath('counts.0.id', $controlledCount->id);
    }

    public function test_medication_detail_conceals_controlled_count_evidence_without_controlled_view(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $ordinaryMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => false,
        ]);
        $controlledMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
        ]);
        MedicationScheduledStockCount::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $ordinaryMedication->id,
            'scheduled_date' => today(),
            'status' => 'completed',
            'expected_quantity' => 4,
            'actual_quantity' => 4,
            'notes' => 'Ordinary count evidence',
            'completed_at' => now(),
        ]);
        MedicationScheduledStockCount::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'scheduled_date' => today(),
            'status' => 'completed',
            'expected_quantity' => 8,
            'actual_quantity' => 8,
            'notes' => 'Controlled count evidence',
            'completed_at' => now(),
        ]);
        $actor = $this->userWithPermissions(['medications.view'], $site);
        $client->supportWorkers()->syncWithoutDetaching([$actor->id]);

        $this->actingAs($actor)
            ->getJson(route('emar.medications.detail', $ordinaryMedication))
            ->assertOk()
            ->assertJsonFragment(['note' => 'Ordinary count evidence']);
        $this->actingAs($actor)
            ->getJson(route('emar.medications.detail', $controlledMedication))
            ->assertNotFound();

        $controlledView = Permission::query()
            ->where('key', 'medications.controlled.view')
            ->firstOrFail();
        $actor->permissionOverrides()->syncWithoutDetaching([
            $controlledView->id => ['allowed' => true],
        ]);
        $actor->unsetRelation('permissionOverrides');
        $actor->unsetRelation('roles');

        $this->actingAs($actor)
            ->getJson(route('emar.medications.detail', $controlledMedication))
            ->assertOk()
            ->assertJsonFragment(['note' => 'Controlled count evidence']);
    }

    public function test_sidebar_reachability_and_deep_links_match_the_exact_reader_capabilities(): void
    {
        $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));
        $this->assertIsString($sidebar);
        $inertiaMiddleware = file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php'));
        $this->assertIsString($inertiaMiddleware);

        $this->assertMatchesRegularExpression(
            '/const canAdminEmar =(?:(?!;).)*can\\?\\.medications\\?\\.ordersManage(?:(?!;).)*can\\?\\.medications\\?\\.stockUpdate(?:(?!;).)*can\\?\\.medications\\?\\.controlledView(?:(?!;).)*can\\?\\.medications\\?\\.controlledRecord/s',
            $sidebar,
        );
        $this->assertStringNotContainsString(
            '(can?.medications?.view && can?.medications?.controlledView) ||',
            $sidebar,
        );
        $this->assertStringNotContainsString(
            '(can?.medications?.view && can?.medications?.controlledRecord) ||',
            $sidebar,
        );
        $this->assertStringNotContainsString(
            "((\$can['medications']['view'] ?? false) && (\$can['medications']['controlledView'] ?? false))",
            $inertiaMiddleware,
        );

        $this->assertMatchesRegularExpression(
            "/if \\(can\\?\\.medications\\?\\.view && can\\?\\.medications\\?\\.controlledView\\)\\s*admin\\.push\\(\\{\\s*title: 'Controlled Drugs'/s",
            $sidebar,
        );
        $this->assertMatchesRegularExpression(
            "/if \\(can\\?\\.medications\\?\\.view && can\\?\\.medications\\?\\.controlledView\\)\\s*compliance\\.push\\(\\{\\s*title: 'Destructions'/s",
            $sidebar,
        );
        $this->assertMatchesRegularExpression(
            "/if \\(can\\?\\.medications\\?\\.view && can\\?\\.medications\\?\\.stockUpdate\\)\\s*mgmt\\.push\\(\\{\\s*title: 'Stock Management'/s",
            $sidebar,
        );
    }

    public function test_broad_substitute_permissions_fail_controller_rechecks_without_side_effects(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'controlled_drug' => false,
        ]);
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'reorder_level' => 5,
        ]);
        $actor = $this->userWithPermissions([
            'clients.viewAssigned',
            'clients.update',
            'medications.view',
            'medications.orders.manage',
        ], $site);
        $client->supportWorkers()->syncWithoutDetaching([$actor->id]);

        $this->withoutMiddleware(EnsurePermission::class);

        $this->actingAs($actor)
            ->post(route('emar.controlled.entries.store'))
            ->assertForbidden();
        $this->actingAs($actor)
            ->patch(route('emar.stock.update', $stock), ['reorder_level' => 8])
            ->assertForbidden();
        $this->actingAs($actor)
            ->post(route('emar.cd_loss.store'))
            ->assertForbidden();
        $this->actingAs($actor)
            ->put(route('clients.medical.medications.stock.update', [$client, $medication]), ['on_hand' => 8])
            ->assertForbidden();
        $this->actingAs($actor)
            ->post(route('clients.medical.medications.administrations.store', [$client, $medication]), ['status' => 'given'])
            ->assertForbidden();
        $this->actingAs($actor)
            ->post(route('api.medications.scheduled_counts.store', [$client, $medication]), ['scheduled_date' => today()->toDateString()])
            ->assertForbidden();

        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('controlled_drug_loss_reports', 0);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_scheduled_stock_counts', 0);
        $this->assertSame(5, (int) $stock->refresh()->reorder_level);
        $this->assertSame(10, (int) $stock->on_hand);
    }

    public function test_shared_administration_service_rejects_a_broad_permission_actor(): void
    {
        $client = Client::factory()->create();
        $medication = ClientMedication::factory()->create(['client_id' => $client->id]);
        $actor = $this->userWithPermissions(['clients.update', 'medications.orders.manage']);

        try {
            app(EnhancedMarService::class)->recordAdministration($client, $medication, [], $actor->id);
            $this->fail('The shared administration service accepted a broad substitute permission.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_controlled_administration_requires_both_exact_action_capabilities(): void
    {
        $client = Client::factory()->create(['status' => 'active']);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 5,
            'reorder_level' => 2,
        ]);
        $actor = $this->userWithPermissions(['medications.administer.record']);

        try {
            app(EnhancedMarService::class)->recordAdministration(
                $client,
                $medication,
                ['status' => 'given', 'quantity_administered' => 1],
                $actor->id,
            );
            $this->fail('The shared service accepted a controlled dose without controlled-record authority.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame(5.0, (float) $stock->refresh()->on_hand);
    }

    public function test_dedicated_permissions_preserve_positive_medication_jobs_without_broad_substitutes(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $controlledMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Morphine sulfate',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        ClientMedicationStock::query()->create([
            'client_medication_id' => $controlledMedication->id,
            'on_hand' => 10,
            'reorder_level' => 5,
        ]);
        $controlledActor = $this->userWithPermissions(['medications.controlled.record'], $site);
        $witness = $this->userWithPermissions(['medications.controlled.witness'], $site);
        $this->qualifyControlledWitness($witness, $site, $client, $controlledActor);

        $this->assertFalse($controlledActor->canDo('medications.orders.manage'));
        $this->assertFalse($controlledActor->canDo('clients.update'));
        $this->actingAs($controlledActor)
            ->post(route('emar.controlled.entries.store'), [
                'client_medication_id' => $controlledMedication->id,
                'client_id' => $client->id,
                'medication_name' => $controlledMedication->name,
                'entry_type' => 'administration',
                'quantity' => 2,
                'on_hand_before' => 10,
                'on_hand_after' => 8,
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
            ])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_medication_id' => $controlledMedication->id,
            'recorded_by' => $controlledActor->id,
            'witnessed_by' => $witness->id,
        ]);

        $ordinaryMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => today()->subMonth(),
            'end_date' => null,
        ]);
        $ordinaryStock = ClientMedicationStock::query()->create([
            'client_medication_id' => $ordinaryMedication->id,
            'on_hand' => 5,
            'reorder_level' => 2,
        ]);
        $stockActor = $this->userWithPermissions(['medications.stock.update'], $site);
        $this->actingAs($stockActor)
            ->post(route('emar.stock.adjust'), [
                'client_medication_id' => $ordinaryMedication->id,
                'new_quantity' => 3,
                'reason' => 'Physical count',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, (int) $ordinaryStock->refresh()->on_hand);

        $administrationActor = $this->userWithPermissions(['medications.administer.record'], $site);
        $result = app(EnhancedMarService::class)->recordAdministration(
            $client,
            $ordinaryMedication,
            [
                'status' => 'refused',
                'reason' => 'Client declined',
                'reason_code' => 'refused',
            ],
            $administrationActor->id,
        );

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $ordinaryMedication->id,
            'administered_by' => $administrationActor->id,
            'status' => 'refused',
        ]);
    }

    public function test_manual_controlled_records_reject_self_witnessing_without_side_effects(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Self witness morphine',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'reorder_level' => 5,
        ]);
        $actor = $this->userWithPermissions([
            'medications.controlled.record',
            'medications.controlled.witness',
        ], $site);

        $this->actingAs($actor)
            ->postJson(route('emar.controlled.entries.store'), [
                'client_medication_id' => $medication->id,
                'client_id' => $client->id,
                'medication_name' => $medication->name,
                'entry_type' => 'administration',
                'quantity' => 1,
                'on_hand_before' => 10,
                'on_hand_after' => 9,
                'witnessed_by' => $actor->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('witnessed_by');

        $this->actingAs($actor)
            ->postJson(route('emar.controlled.balance_check.store'), [
                'client_medication_id' => $medication->id,
                'client_id' => $client->id,
                'medication_name' => $medication->name,
                'expected_balance' => 10,
                'actual_balance' => 10,
                'witnessed_by' => $actor->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('witnessed_by');

        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('client_controlled_drug_discrepancies', 0);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
    }

    public function test_manual_controlled_record_replays_recheck_witness_and_canonical_binding(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Replay bound morphine',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'reorder_level' => 5,
        ]);
        $actor = $this->userWithPermissions(['medications.controlled.record'], $site);
        $unauthorisedWitness = $this->userWithPermissions([], $site);
        $authorisedWitness = $this->userWithPermissions(['medications.controlled.witness'], $site);
        $this->qualifyControlledWitness($authorisedWitness, $site, $client, $actor);
        $entryUuid = '0d7527ad-b469-4480-858e-a49a966c9370';
        $balanceUuid = 'd88c78e5-34cf-4f7f-b6a3-31d586d8447e';

        $entryReplay = MedicationIdempotencyResult::query()->create([
            'scope' => 'emar-controlled-entry',
            'request_uuid' => $entryUuid,
            'response_payload' => [
                'success' => true,
                '_request_fingerprint' => 'unauthorised-witness-binding',
            ],
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($actor)
            ->postJson(route('emar.controlled.entries.store'), [
                'client_medication_id' => $medication->id,
                'client_id' => $client->id,
                'medication_name' => $medication->name,
                'entry_type' => 'administration',
                'quantity' => 1,
                'on_hand_before' => 10,
                'on_hand_after' => 9,
                'witnessed_by' => $unauthorisedWitness->id,
                'client_request_uuid' => $entryUuid,
            ])
            ->assertNotFound();

        $entryReplay->forceFill([
            'response_payload' => [
                'success' => true,
                '_request_fingerprint' => 'different-client-binding',
            ],
        ]);
        $entryReplay->save();

        $this->actingAs($actor)
            ->postJson(route('emar.controlled.entries.store'), [
                'client_medication_id' => $medication->id,
                'client_id' => $client->id,
                'medication_name' => $medication->name,
                'entry_type' => 'administration',
                'quantity' => 1,
                'on_hand_before' => 10,
                'on_hand_after' => 9,
                'witnessed_by' => $authorisedWitness->id,
                'witness_credential' => 'password',
                'client_request_uuid' => $entryUuid,
            ])
            ->assertConflict()
            ->assertJsonPath('sync.status', 'conflict');

        MedicationIdempotencyResult::query()->create([
            'scope' => 'emar-controlled-balance-check',
            'request_uuid' => $balanceUuid,
            'response_payload' => [
                'success' => true,
                '_request_fingerprint' => 'different-balance-binding',
            ],
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($actor)
            ->postJson(route('emar.controlled.balance_check.store'), [
                'client_medication_id' => $medication->id,
                'client_id' => $client->id,
                'medication_name' => $medication->name,
                'expected_balance' => 10,
                'actual_balance' => 10,
                'witnessed_by' => $authorisedWitness->id,
                'witness_credential' => 'password',
                'client_request_uuid' => $balanceUuid,
            ])
            ->assertConflict()
            ->assertJsonPath('sync.status', 'conflict');

        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('client_controlled_drug_discrepancies', 0);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
    }

    public function test_controlled_stock_replay_rechecks_exact_authority_before_returning_cached_success(): void
    {
        $medication = ClientMedication::factory()->create([
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'reorder_level' => 5,
        ]);
        $actor = $this->userWithPermissions(['medications.stock.update']);
        $requestUuid = '4ed258d1-9005-4631-bbdd-c6982731df88';

        MedicationIdempotencyResult::query()->create([
            'scope' => 'emar-stock-receive',
            'request_uuid' => $requestUuid,
            'response_payload' => [
                'success' => true,
                'stock' => [
                    'id' => $stock->id,
                    'client_medication_id' => $medication->id,
                    'on_hand' => 12,
                ],
                '_request_fingerprint' => 'controlled-stock-binding',
            ],
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($actor)
            ->postJson(route('emar.stock.receive'), [
                'client_medication_id' => $medication->id,
                'quantity' => 2,
                'client_request_uuid' => $requestUuid,
            ])
            ->assertNotFound();

        $this->assertSame(10, (int) $stock->refresh()->on_hand);
    }

    public function test_stock_receipt_replay_is_bound_to_the_requested_medication(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $cachedMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
        ]);
        $requestedMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => false,
        ]);
        $requestedStock = ClientMedicationStock::query()->create([
            'client_medication_id' => $requestedMedication->id,
            'on_hand' => 4,
            'reorder_level' => 2,
        ]);
        $actor = $this->userWithPermissions(['medications.stock.update'], $site);
        $requestUuid = '9bcbb6d7-6e64-47df-b455-87f23f792328';

        MedicationIdempotencyResult::query()->create([
            'scope' => 'emar-stock-receive',
            'request_uuid' => $requestUuid,
            'response_payload' => [
                'success' => true,
                'stock' => [
                    'id' => 987654,
                    'client_medication_id' => $cachedMedication->id,
                    'on_hand' => 99,
                ],
                '_request_fingerprint' => 'different-medication-binding',
            ],
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($actor)
            ->postJson(route('emar.stock.receive'), [
                'client_medication_id' => $requestedMedication->id,
                'quantity' => 2,
                'client_request_uuid' => $requestUuid,
            ])
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('sync.status', 'conflict')
            ->assertJsonMissing(['client_medication_id' => $cachedMedication->id]);

        $this->assertSame(4.0, (float) $requestedStock->refresh()->on_hand);
    }

    public function test_soft_deleted_controlled_medication_keeps_exact_pharmacy_delivery_authority(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $actor = $this->userWithPermissions([
            'medications.view',
            'medications.stock.update',
        ], $site);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
        ]);
        $order = MedicationPharmacyOrder::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'pharmacy_name' => 'Test Pharmacy',
            'status' => 'dispensed',
            'ordered_by' => $actor->id,
            'quantity_ordered' => 2,
        ]);
        ClientMedication::query()
            ->whereKey($medication->id)
            ->update(['deleted_at' => now()]);

        $this->actingAs($actor)
            ->post(route('emar.pharmacy_orders.advance', $order), ['quantity_received' => 2])
            ->assertNotFound();

        $this->assertSame('dispensed', $order->refresh()->status);
        $this->assertDatabaseMissing('client_medication_stocks', [
            'client_medication_id' => $medication->id,
        ]);
    }

    public function test_stock_payload_requires_exact_controlled_view_for_controlled_rows_orders_and_selectors(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $ordinaryMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Ordinary stock medication',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $controlledMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Restricted controlled medication',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        ClientMedicationStock::query()->create([
            'client_medication_id' => $ordinaryMedication->id,
            'on_hand' => 20,
            'reorder_level' => 5,
        ]);
        ClientMedicationStock::query()->create([
            'client_medication_id' => $controlledMedication->id,
            'on_hand' => 10,
            'reorder_level' => 5,
        ]);
        $stockOnlyActor = $this->userWithPermissions([
            'medications.view',
            'medications.stock.update',
        ], $site);
        foreach ([$ordinaryMedication, $controlledMedication] as $medication) {
            MedicationPharmacyOrder::query()->create([
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'pharmacy_name' => 'Test Pharmacy',
                'ordered_by' => $stockOnlyActor->id,
                'quantity_ordered' => 2,
            ]);
        }

        $this->actingAs($stockOnlyActor)
            ->get(route('emar.stock', ['site_id' => $site->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_view_controlled', false)
                ->has('stockItems', 1)
                ->where('stockItems.0.medication_id', $ordinaryMedication->id)
                ->has('pharmacyOrders', 1)
                ->where('pharmacyOrders.0.medication_id', $ordinaryMedication->id)
                ->has('activeMedications', 1)
                ->where('activeMedications.0.id', $ordinaryMedication->id)
                ->has('controlledRegister', 0));

        $controlledViewer = $this->userWithPermissions([
            'medications.view',
            'medications.stock.update',
            'medications.controlled.view',
        ], $site);
        $this->actingAs($controlledViewer)
            ->get(route('emar.stock', ['site_id' => $site->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_view_controlled', true)
                ->has('stockItems', 2)
                ->where('stockItems', fn ($items) => $items->contains('medication_id', $controlledMedication->id))
                ->has('pharmacyOrders', 2)
                ->where('pharmacyOrders', fn ($orders) => $orders->contains('medication_id', $controlledMedication->id))
                ->has('activeMedications', 2)
                ->where('activeMedications', fn ($medications) => $medications->contains('id', $controlledMedication->id))
                ->has('controlledRegister', 1));
    }

    public function test_controlled_stock_metadata_update_requires_exact_controlled_record_permission(): void
    {
        $localSite = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $localClient = Client::factory()->create(['site_id' => $localSite->id, 'status' => 'active']);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
        $ordinaryMedication = ClientMedication::factory()->create([
            'client_id' => $localClient->id,
            'controlled_drug' => false,
        ]);
        $controlledMedication = ClientMedication::factory()->create([
            'client_id' => $localClient->id,
            'controlled_drug' => true,
        ]);
        $foreignOrdinaryMedication = ClientMedication::factory()->create([
            'client_id' => $foreignClient->id,
            'controlled_drug' => false,
        ]);
        $foreignControlledMedication = ClientMedication::factory()->create([
            'client_id' => $foreignClient->id,
            'controlled_drug' => true,
        ]);
        $ordinaryStock = ClientMedicationStock::query()->create([
            'client_medication_id' => $ordinaryMedication->id,
            'on_hand' => 10,
            'reorder_level' => 5,
        ]);
        $controlledStock = ClientMedicationStock::query()->create([
            'client_medication_id' => $controlledMedication->id,
            'on_hand' => 10,
            'reorder_level' => 5,
        ]);
        $foreignOrdinaryStock = ClientMedicationStock::query()->create([
            'client_medication_id' => $foreignOrdinaryMedication->id,
            'on_hand' => 10,
            'reorder_level' => 5,
        ]);
        $foreignControlledStock = ClientMedicationStock::query()->create([
            'client_medication_id' => $foreignControlledMedication->id,
            'on_hand' => 10,
            'reorder_level' => 5,
        ]);
        $stockOnlyActor = $this->userWithPermissions(['medications.stock.update'], $localSite);

        $this->actingAs($stockOnlyActor)
            ->from(route('emar.stock'))
            ->patch(route('emar.stock.update', $ordinaryStock), ['reorder_level' => 7])
            ->assertRedirect(route('emar.stock'));
        $this->actingAs($stockOnlyActor)
            ->patch(route('emar.stock.update', $controlledStock), ['reorder_level' => 8])
            ->assertNotFound();
        foreach ([$foreignOrdinaryStock, $foreignControlledStock] as $foreignStock) {
            $this->actingAs($stockOnlyActor)
                ->patch(route('emar.stock.update', $foreignStock), ['reorder_level' => 9])
                ->assertNotFound();
        }
        $this->actingAs($stockOnlyActor)
            ->patch(route('emar.stock.update', 999999999), ['reorder_level' => 9])
            ->assertNotFound();

        $this->assertSame(7, (int) $ordinaryStock->refresh()->reorder_level);
        $this->assertSame(5, (int) $controlledStock->refresh()->reorder_level);
        $this->assertSame(5, (int) $foreignOrdinaryStock->refresh()->reorder_level);
        $this->assertSame(5, (int) $foreignControlledStock->refresh()->reorder_level);

        $controlledWriter = $this->userWithPermissions([
            'medications.stock.update',
            'medications.controlled.record',
        ], $localSite);
        $this->actingAs($controlledWriter)
            ->from(route('emar.stock'))
            ->patch(route('emar.stock.update', $controlledStock), ['reorder_level' => 8])
            ->assertRedirect(route('emar.stock'));

        $this->assertSame(8, (int) $controlledStock->refresh()->reorder_level);
    }

    public function test_retired_controlled_scheduled_count_is_concealed_even_with_exact_controlled_authority(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
            'barcode' => 'COUNT-123',
        ]);
        $count = MedicationScheduledStockCount::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'scheduled_date' => today(),
            'status' => 'pending',
            'expected_quantity' => 10,
        ]);
        $stockOnlyActor = $this->userWithPermissions([
            'medications.view',
            'medications.stock.update',
        ], $site);
        ClientMedication::query()
            ->whereKey($medication->id)
            ->update(['deleted_at' => now()]);

        $this->actingAs($stockOnlyActor)
            ->postJson(route('api.medications.scheduled_counts.complete', [$client, $count]), [
                'actual_quantity' => 10,
            ])
            ->assertNotFound();
        $this->assertSame('pending', $count->refresh()->status);

        $controlledActor = $this->userWithPermissions([
            'medications.view',
            'medications.stock.update',
            'medications.controlled.view',
            'medications.controlled.record',
        ], $site);
        $this->assertTrue($controlledActor->canDo('medications.controlled.view'));
        $this->assertTrue($controlledActor->canDo('medications.controlled.record'));
        $this->actingAs($controlledActor)
            ->postJson(route('api.medications.scheduled_counts.complete', [$client, $count]), [
                'actual_quantity' => 10,
            ])
            ->assertNotFound();

        $this->assertSame('pending', $count->refresh()->status);
        $this->assertDatabaseMissing('client_medication_stocks', [
            'client_medication_id' => $medication->id,
        ]);
    }

    public function test_scheduled_count_replays_are_bound_to_the_current_target(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $otherClient = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => false,
        ]);
        $otherMedication = ClientMedication::factory()->create([
            'client_id' => $otherClient->id,
            'controlled_drug' => false,
        ]);
        $actor = $this->userWithPermissions([
            'medications.view',
            'medications.stock.update',
        ], $site);

        $createUuid = 'be6f691b-5047-4521-b46a-22a8afe581ef';
        MedicationIdempotencyResult::query()->create([
            'scope' => 'scheduled-count:create',
            'request_uuid' => $createUuid,
            'response_payload' => [
                'success' => true,
                'count' => [
                    'id' => 123456,
                    'client_id' => $otherClient->id,
                    'client_medication_id' => $otherMedication->id,
                    'scheduled_date' => today()->toDateString(),
                    'status' => 'pending',
                ],
                '_request_fingerprint' => 'different-create-target-binding',
            ],
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($actor)
            ->postJson(route('api.medications.scheduled_counts.store', [$client, $medication]), [
                'scheduled_date' => today()->toDateString(),
                'client_request_uuid' => $createUuid,
            ])
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('sync.status', 'conflict')
            ->assertJsonMissing(['client_id' => $otherClient->id]);
        $this->assertDatabaseCount('medication_scheduled_stock_counts', 0);

        $firstCount = MedicationScheduledStockCount::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'scheduled_date' => today(),
            'status' => 'pending',
            'expected_quantity' => 4,
        ]);
        $secondCount = MedicationScheduledStockCount::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'scheduled_date' => today()->addDay(),
            'status' => 'pending',
            'expected_quantity' => 4,
        ]);
        $completeUuid = 'd90e1928-64c2-431e-ab85-c8a106e48f26';
        MedicationIdempotencyResult::query()->create([
            'scope' => 'scheduled-count:complete',
            'request_uuid' => $completeUuid,
            'response_payload' => [
                'success' => true,
                'count' => [
                    'id' => $firstCount->id,
                    'status' => 'completed',
                    'actual_quantity' => 4,
                ],
                '_request_fingerprint' => 'different-completion-target-binding',
            ],
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($actor)
            ->postJson(route('api.medications.scheduled_counts.complete', [$client, $secondCount]), [
                'actual_quantity' => 4,
                'client_request_uuid' => $completeUuid,
            ])
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('sync.status', 'conflict')
            ->assertJsonMissing(['id' => $firstCount->id]);

        $this->assertSame('pending', $firstCount->refresh()->status);
        $this->assertSame('pending', $secondCount->refresh()->status);
    }

    public function test_medication_evidence_uses_canonical_target_specific_permissions(): void
    {
        $disk = (string) config('filesystems.default');
        Storage::fake($disk);

        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
        ]);
        $reporter = $this->userWithPermissions([], $site);
        $discrepancy = ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'difference' => -1,
            'reason' => 'Count mismatch',
            'reported_at' => now(),
            'reported_by' => $reporter->id,
            'status' => 'open',
        ]);

        $broadActor = $this->userWithPermissions([
            'clients.viewAssigned',
            'clients.update',
            'medications.view',
            'medications.administer.record',
            'medications.controlled.view',
        ], $site);
        $client->supportWorkers()->syncWithoutDetaching([$broadActor->id]);

        $this->withoutMiddleware(EnsurePermission::class);
        $this->actingAs($broadActor)
            ->post(route('api.medications.supporting_attachments.upload', $client), [
                'target_type' => 'discrepancy',
                'target_id' => $discrepancy->id,
                'file' => UploadedFile::fake()->create('broad-evidence.pdf', 12, 'application/pdf'),
            ])
            ->assertForbidden();
        $this->assertDatabaseCount('medication_mar_attachments', 0);
        $this->assertSame([], Storage::disk($disk)->allFiles('medication_mar_attachments'));

        $controlledActor = $this->userWithPermissions([
            'clients.viewAssigned',
            'medications.view',
            'medications.controlled.record',
        ], $site);
        $client->supportWorkers()->syncWithoutDetaching([$controlledActor->id]);
        $this->actingAs($controlledActor)
            ->post(route('api.medications.supporting_attachments.upload', $client), [
                'target_type' => 'discrepancy',
                'target_id' => $discrepancy->id,
                'file' => UploadedFile::fake()->create('controlled-evidence.pdf', 12, 'application/pdf'),
            ])
            ->assertOk()
            ->assertJsonPath('attachment.can_delete', true);

        $attachment = MedicationMarAttachment::query()->sole();
        Storage::disk($disk)->assertExists($attachment->file_path);

        $ordinaryViewer = $this->userWithPermissions([
            'clients.viewAssigned',
            'medications.view',
        ], $site);
        $client->supportWorkers()->syncWithoutDetaching([$ordinaryViewer->id]);
        $this->actingAs($ordinaryViewer)
            ->get(route('api.medications.supporting_attachments.download', [$client, $attachment]))
            ->assertNotFound();

        $controlledViewer = $this->userWithPermissions([
            'clients.viewAssigned',
            'medications.view',
            'medications.controlled.view',
        ], $site);
        $client->supportWorkers()->syncWithoutDetaching([$controlledViewer->id]);
        $this->actingAs($controlledViewer)
            ->get(route('api.medications.supporting_attachments.download', [$client, $attachment]))
            ->assertOk();

        $recordPermission = Permission::query()->where('key', 'medications.controlled.record')->firstOrFail();
        $controlledActor->permissionOverrides()->updateExistingPivot($recordPermission->id, ['allowed' => false]);
        $controlledActor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($controlledActor)
            ->deleteJson(route('api.medications.supporting_attachments.delete', [$client, $attachment]))
            ->assertNotFound();
        $this->assertDatabaseHas('medication_mar_attachments', ['id' => $attachment->id]);
        Storage::disk($disk)->assertExists($attachment->file_path);

        $controlledActor->permissionOverrides()->updateExistingPivot($recordPermission->id, ['allowed' => true]);
        $controlledActor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($controlledActor)
            ->deleteJson(route('api.medications.supporting_attachments.delete', [$client, $attachment]))
            ->assertOk();
        $this->assertDatabaseMissing('medication_mar_attachments', ['id' => $attachment->id]);
        Storage::disk($disk)->assertMissing($attachment->file_path);

        $recordActor = $this->userWithPermissions([
            'clients.viewAssigned',
            'medications.view',
            'medications.administer.record',
        ], $site);
        $client->supportWorkers()->syncWithoutDetaching([$recordActor->id]);
        $controlledError = MedicationError::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'error_type' => 'documentation',
            'severity' => 'minor',
            'description' => 'Restricted controlled medication evidence',
            'status' => 'reported',
            'reported_by' => $reporter->id,
            'reported_at' => now(),
        ]);
        $this->actingAs($recordActor)
            ->post(route('api.medications.supporting_attachments.upload', $client), [
                'target_type' => 'error',
                'target_id' => $controlledError->id,
                'file' => UploadedFile::fake()->create('concealed-error-evidence.pdf', 12, 'application/pdf'),
            ])
            ->assertNotFound();
        $this->assertDatabaseCount('medication_mar_attachments', 0);

        $controlledViewPermission = Permission::query()
            ->where('key', MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
            ->firstOrFail();
        $recordActor->permissionOverrides()->syncWithoutDetaching([
            $controlledViewPermission->id => ['allowed' => true],
        ]);
        $recordActor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($recordActor)
            ->post(route('api.medications.supporting_attachments.upload', $client), [
                'target_type' => 'error',
                'target_id' => $controlledError->id,
                'file' => UploadedFile::fake()->create('controlled-error-evidence.pdf', 12, 'application/pdf'),
            ])
            ->assertNotFound();
        $recordActor->permissionOverrides()->syncWithoutDetaching([
            $recordPermission->id => ['allowed' => true],
        ]);
        $recordActor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($recordActor)
            ->post(route('api.medications.supporting_attachments.upload', $client), [
                'target_type' => 'error',
                'target_id' => $controlledError->id,
                'file' => UploadedFile::fake()->create('controlled-error-evidence.pdf', 12, 'application/pdf'),
            ])
            ->assertOk();
        $errorAttachment = MedicationMarAttachment::query()->sole();

        $this->actingAs($ordinaryViewer)
            ->get(route('api.medications.supporting_attachments.download', [$client, $errorAttachment]))
            ->assertNotFound();
        $this->actingAs($controlledViewer)
            ->get(route('api.medications.supporting_attachments.download', [$client, $errorAttachment]))
            ->assertOk();

        $errorEditor = $this->userWithPermissions([
            'clients.viewAssigned',
            'medications.view',
            'medications.administer.correct',
        ], $site);
        $client->supportWorkers()->syncWithoutDetaching([$errorEditor->id]);
        $this->actingAs($errorEditor)
            ->deleteJson(route('api.medications.supporting_attachments.delete', [$client, $errorAttachment]))
            ->assertNotFound();
        $errorEditor->permissionOverrides()->syncWithoutDetaching([
            $controlledViewPermission->id => ['allowed' => true],
        ]);
        $errorEditor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($errorEditor)
            ->deleteJson(route('api.medications.supporting_attachments.delete', [$client, $errorAttachment]))
            ->assertNotFound();
        $errorEditor->permissionOverrides()->syncWithoutDetaching([
            $recordPermission->id => ['allowed' => true],
        ]);
        $errorEditor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($errorEditor)
            ->deleteJson(route('api.medications.supporting_attachments.delete', [$client, $errorAttachment]))
            ->assertOk();

        $correction = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $recordActor->id,
            'status' => 'given',
            'is_correction' => true,
        ]);

        $this->actingAs($recordActor)
            ->post(route('api.medications.supporting_attachments.upload', $client), [
                'target_type' => 'administration',
                'target_id' => $correction->id,
                'file' => UploadedFile::fake()->create('mislabelled-correction.pdf', 12, 'application/pdf'),
            ])
            ->assertNotFound();
        $this->actingAs($recordActor)
            ->post(route('api.medications.attachments.upload', [$client, $correction]), [
                'file' => UploadedFile::fake()->create('nested-correction.pdf', 12, 'application/pdf'),
            ])
            ->assertForbidden();
        $this->assertDatabaseCount('medication_mar_attachments', 0);
        $this->assertSame([], Storage::disk($disk)->allFiles('medication_mar_attachments'));
    }

    public function test_broad_permissions_do_not_manage_transit_custody_or_export_mar(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $actor = $this->userWithPermissions([
            'clients.viewAssigned',
            'clients.update',
            'medications.view',
            'medications.orders.manage',
            'medications.stock.update',
        ], $site);
        $client->supportWorkers()->syncWithoutDetaching([$actor->id]);

        $scope = app(ResidentTransportJourneyScope::class);
        $this->assertFalse($scope->canManageMedicationTransit($actor));

        try {
            app(ResidentTransportJourneyService::class)->packMedication($actor, 0, []);
            $this->fail('The transit service accepted broad substitute permissions.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->actingAs($actor)
            ->get(route('operations.clients.mar.export_csv', $client))
            ->assertForbidden();

        $this->actingAs($actor)
            ->get(route('operations.clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.record_medication_administration', false));

        $shiftActor = $this->userWithPermissions([
            'clients.update',
            'shifts.viewAssigned',
        ], $site);
        $shift = Shift::factory()->forSite($site)->create([
            'client_id' => $client->id,
            'user_id' => $shiftActor->id,
        ]);
        $this->actingAs($shiftActor)
            ->get(route('operations.shifts.show', $shift))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('medications', null)
                ->where('can.view_medication', false)
                ->where('can.record_medication', false));

        $fleetActor = $this->userWithPermissions(['fleet.medication.manage']);
        $this->assertTrue($scope->canManageMedicationTransit($fleetActor));
        $this->assertTrue($scope->canViewMedicationTransit($fleetActor));

        $administrationActor = $this->userWithPermissions(['medications.administer.record']);
        $this->assertFalse($scope->canManageMedicationTransit($administrationActor));
        $this->assertTrue($scope->canViewMedicationTransit($administrationActor));

        try {
            app(ResidentTransportJourneyService::class)->packMedication($administrationActor, 0, []);
            $this->fail('The transit service accepted administer authority for a custody action.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        try {
            app(ResidentTransportJourneyService::class)->administerMedication($fleetActor, 0, []);
            $this->fail('The transit service accepted fleet custody authority for dose administration.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_worker_board_fallback_is_limited_to_the_actors_approved_sites(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
        ClientMedication::factory()->create([
            'client_id' => $client->id,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        ClientMedication::factory()->create([
            'client_id' => $foreignClient->id,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $actor = $this->userWithPermissions(['medications.view'], $site);

        $this->actingAs($actor)
            ->get(route('meds.today'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients', 1)
                ->where('clients.0.id', $client->id));

        $administrationOnlyActor = $this->userWithPermissions([
            'medications.administer.record',
        ], $site);
        $this->actingAs($administrationOnlyActor)
            ->get(route('meds.today'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients', 0)
                ->where('has_shift_context', false));
    }

    public function test_shift_summary_conceals_a_shift_at_an_unapproved_site(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
        $actor = $this->userWithPermissions(['medications.view', 'shifts.viewAny'], $site);
        $foreignWorker = $this->userWithPermissions([], $foreignSite);
        $localShift = Shift::factory()->forSite($site)->create([
            'client_id' => $client->id,
            'user_id' => $actor->id,
        ]);
        $foreignShift = Shift::factory()->forSite($foreignSite)->create([
            'client_id' => $foreignClient->id,
            'user_id' => $foreignWorker->id,
        ]);

        $this->actingAs($actor)
            ->getJson(route('api.medications.shift.summary', $localShift->id))
            ->assertOk();
        $this->actingAs($actor)
            ->getJson(route('api.medications.shift.summary', $foreignShift->id))
            ->assertNotFound();
    }

    public function test_cd_loss_task_visibility_uses_the_exact_controlled_reader_permission(): void
    {
        $provider = app(CdLossReportProvider::class);

        $this->assertFalse($provider->canView($this->userWithPermissions(['clients.update'])));
        $this->assertFalse($provider->canView($this->userWithPermissions(['medications.view'])));
        $this->assertFalse($provider->canView($this->userWithPermissions(['medications.controlled.view'])));
        $this->assertTrue($provider->canView($this->userWithPermissions([
            'medications.view',
            'medications.controlled.view',
        ])));
    }

    /** @param array<string, list<string>> $routesByCapability */
    private function assertExactRoutes(array $routesByCapability): void
    {
        foreach ($routesByCapability as $capability => $routeNames) {
            foreach ($routeNames as $name) {
                $route = Route::getRoutes()->getByName($name);
                $this->assertNotNull($route, $name);
                $middleware = $route->gatherMiddleware();
                $this->assertContains('permission:'.$capability, $middleware, $name);
                $this->assertStringNotContainsString('medications.orders.manage', implode('|', $middleware), $name);
                $this->assertStringNotContainsString('clients.update', implode('|', $middleware), $name);
                if ($capability === 'fleet.medication.manage') {
                    $this->assertNotContains('permission:medications.administer.record', $middleware, $name);
                    $this->assertNotContains('permission:medications.stock.update', $middleware, $name);
                }
                if ($capability === 'medications.administer.record') {
                    $this->assertNotContains('permission:fleet.medication.manage', $middleware, $name);
                }
            }
        }
    }

    /** @param list<string> $permissions */
    private function qualifyControlledWitness(User $witness, Site $site, Client $client, User $assessor): void
    {
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $witness->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today()->subMonth(),
            'expiry_date' => today()->addYear(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_witness_controlled' => true,
        ]);
        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $client->service_context_id,
            'user_id' => $witness->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'actual_starts_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);
    }

    private function userWithPermissions(array $permissions, ?Site $site = null): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
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
                'start_date' => today()->subYear(),
                'end_date' => null,
            ]);
        }

        return $user;
    }
}
