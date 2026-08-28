<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class MedicationGovernanceReaderSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_reader_routes_require_module_plus_exact_action_capabilities(): void
    {
        $auditMiddleware = Route::getRoutes()->getByName('emar.audit')?->gatherMiddleware() ?? [];
        $this->assertContains('permission:medications.view', $auditMiddleware, 'emar.audit');
        $this->assertContains('permission:medications.audit.view', $auditMiddleware, 'emar.audit');

        foreach (['emar.pdf.mar', 'emar.pdf.round_sheet', 'emar.pdf.cd_register'] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];
            $this->assertContains(
                'permission:medications.reports.export|reports.viewAny',
                $middleware,
                $routeName,
            );
            $this->assertNotContains('permission:medications.view', $middleware, $routeName);
        }
        $this->assertContains(
            'permission:medications.controlled.view',
            Route::getRoutes()->getByName('emar.pdf.cd_register')?->gatherMiddleware() ?? [],
        );

        foreach ([
            'api.medications.dashboard.widgets',
            'api.medications.alerts.index',
            'api.medications.alerts.client',
        ] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];
            $this->assertContains('permission:medications.view', $middleware, $routeName);
            $this->assertStringNotContainsString('clients.viewAny', implode('|', $middleware), $routeName);
        }
        foreach (['api.medications.alerts.acknowledge', 'api.medications.alerts.resolve'] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];
            $this->assertContains('permission:medications.view', $middleware, $routeName);
            $this->assertContains('permission:medications.administer.correct', $middleware, $routeName);
            $this->assertStringNotContainsString('clients.update', implode('|', $middleware), $routeName);
        }
    }

    public function test_omitted_filters_scope_audit_alert_widgets_and_round_pdf_and_empty_sites_fail_closed(): void
    {
        $context = $this->context();
        $localReader = $this->userWithPermissions([
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            'medications.audit.view',
            'medications.reports.export',
        ], $context['local_site']);
        $noSiteReader = $this->userWithPermissions([
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            'medications.audit.view',
            'medications.reports.export',
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
        ]);

        $audit = $this->actingAs($localReader)->get(route('emar.audit'))->assertOk();
        $this->assertSame(
            [$context['local_site']->id],
            collect($audit->inertiaProps('sites'))->pluck('id')->all(),
        );
        $this->assertSame(
            [$context['local_client']->id],
            collect($audit->inertiaProps('clients'))->pluck('id')->all(),
        );
        $this->assertNotContains(
            $context['foreign_client']->id,
            collect($audit->inertiaProps('events'))->pluck('client_id')->filter()->all(),
        );
        $this->assertNotContains(
            'admin_'.$context['forged_administration']->id,
            collect($audit->inertiaProps('events'))->pluck('id')->all(),
        );

        $alerts = $this->actingAs($localReader)
            ->getJson(route('api.medications.alerts.index'))
            ->assertOk();
        $this->assertSame([$context['local_alert']->id], collect($alerts->json('alerts'))->pluck('id')->all());
        $this->assertNotContains(
            $context['forged_alert']->id,
            collect($alerts->json('alerts'))->pluck('id')->all(),
        );

        $widgets = $this->actingAs($localReader)
            ->getJson(route('api.medications.dashboard.widgets'))
            ->assertOk();
        $this->assertSame(
            [$context['local_alert']->id],
            collect($widgets->json('overdue_meds.items'))->pluck('id')->all(),
        );

        $pdfPayloads = [];
        $this->fakePdf($pdfPayloads);
        $this->actingAs($localReader)
            ->get(route('emar.pdf.round_sheet'))
            ->assertOk();
        $this->assertSame(
            [$context['local_round']->id],
            $pdfPayloads['pdf.round-sheet'][0]['rounds']->pluck('id')->all(),
        );
        $this->assertSame(
            [],
            $pdfPayloads['pdf.round-sheet'][0]['rounds']->first()->administrations->pluck('id')->all(),
        );

        $emptyAudit = $this->actingAs($noSiteReader)->get(route('emar.audit'))->assertOk();
        $this->assertSame([], collect($emptyAudit->inertiaProps('events'))->all());
        $this->assertSame([], collect($emptyAudit->inertiaProps('clients'))->all());
        $this->assertSame([], collect($emptyAudit->inertiaProps('sites'))->all());
        $this->actingAs($noSiteReader)
            ->getJson(route('api.medications.alerts.index'))
            ->assertOk()
            ->assertJsonCount(0, 'alerts');
        $emptyWidgets = $this->actingAs($noSiteReader)
            ->getJson(route('api.medications.dashboard.widgets'))
            ->assertOk();
        $this->assertSame([], $emptyWidgets->json('overdue_meds.items'));

        $this->actingAs($noSiteReader)
            ->get(route('emar.pdf.round_sheet'))
            ->assertOk();
        $this->assertSame([], $pdfPayloads['pdf.round-sheet'][1]['rounds']->all());
        $this->actingAs($noSiteReader)
            ->get(route('emar.audit', ['client_id' => $context['local_client']->id]))
            ->assertNotFound();
        $this->actingAs($noSiteReader)
            ->get(route('emar.pdf.mar', ['client_id' => $context['local_client']->id]))
            ->assertNotFound();
        $this->actingAs($noSiteReader)
            ->get(route('emar.pdf.cd_register', ['client_id' => $context['local_client']->id]))
            ->assertNotFound();
    }

    public function test_mar_and_round_pdfs_hide_controlled_clinical_rows_without_exact_controlled_view(): void
    {
        $context = $this->context();
        $ordinaryReader = $this->userWithPermissions([
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            'medications.reports.export',
        ], $context['local_site']);
        $controlledReader = $this->userWithPermissions([
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            'medications.reports.export',
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
        ], $context['local_site']);
        $pdfPayloads = [];
        $this->fakePdf($pdfPayloads);

        foreach ([$ordinaryReader, $controlledReader] as $reader) {
            $this->actingAs($reader)
                ->get(route('emar.pdf.mar', ['client_id' => $context['local_client']->id]))
                ->assertOk();
            $this->actingAs($reader)
                ->get(route('emar.pdf.round_sheet'))
                ->assertOk();
        }

        $ordinaryMar = $pdfPayloads['pdf.mar-chart'][0];
        $this->assertContains($context['local_medication']->id, $ordinaryMar['scheduledMedications']->pluck('id')->all());
        $this->assertNotContains($context['controlled_medication']->id, $ordinaryMar['scheduledMedications']->pluck('id')->all());
        $this->assertNotContains(
            $context['controlled_administration']->id,
            $pdfPayloads['pdf.round-sheet'][0]['rounds']
                ->flatMap(fn (MedicationRound $round) => $round->administrations)
                ->pluck('id')
                ->all(),
        );

        $controlledMar = $pdfPayloads['pdf.mar-chart'][1];
        $this->assertContains($context['controlled_medication']->id, $controlledMar['scheduledMedications']->pluck('id')->all());
        $this->assertContains(
            $context['controlled_administration']->id,
            $pdfPayloads['pdf.round-sheet'][1]['rounds']
                ->flatMap(fn (MedicationRound $round) => $round->administrations)
                ->pluck('id')
                ->all(),
        );
    }

    public function test_foreign_and_missing_reader_ids_are_concealed_and_capabilities_do_not_substitute(): void
    {
        $context = $this->context();
        $reader = $this->userWithPermissions([
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            'medications.audit.view',
            'medications.reports.export',
            'medications.administer.correct',
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
        ], $context['local_site']);

        foreach ([
            ['emar.audit', ['client_id' => $context['foreign_client']->id]],
            ['emar.audit', ['client_id' => 999999]],
            ['emar.audit', ['site_id' => $context['foreign_site']->id]],
            ['emar.audit', ['site_id' => 999999]],
            ['emar.pdf.mar', ['client_id' => $context['foreign_client']->id]],
            ['emar.pdf.mar', ['client_id' => 999999]],
            ['emar.pdf.cd_register', ['client_id' => $context['foreign_client']->id]],
            ['emar.pdf.cd_register', ['client_id' => 999999]],
        ] as [$routeName, $parameters]) {
            $this->actingAs($reader)->get(route($routeName, $parameters))->assertNotFound();
        }
        $this->actingAs($reader)
            ->getJson(route('api.medications.alerts.client', $context['foreign_client']))
            ->assertNotFound();
        $this->actingAs($reader)
            ->getJson(route('api.medications.alerts.client', ['client' => 999999]))
            ->assertNotFound();
        $this->actingAs($reader)
            ->getJson(route('api.medications.dashboard.widgets', ['client_id' => $context['foreign_client']->id]))
            ->assertNotFound();
        $this->actingAs($reader)
            ->getJson(route('api.medications.dashboard.widgets', ['client_id' => 999999]))
            ->assertNotFound();
        $this->actingAs($reader)
            ->postJson(route('api.medications.alerts.acknowledge', $context['foreign_alert']))
            ->assertNotFound();
        $this->actingAs($reader)
            ->postJson(route('api.medications.alerts.acknowledge', $context['forged_alert']))
            ->assertNotFound();
        $this->actingAs($reader)
            ->postJson(route('api.medications.alerts.acknowledge', ['alertId' => 999999]))
            ->assertNotFound();
        $this->actingAs($reader)
            ->postJson(route('api.medications.alerts.acknowledge', $context['local_alert']))
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->actingAs($reader)
            ->postJson(route('api.medications.alerts.acknowledge', $context['local_alert']))
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->actingAs($reader)
            ->postJson(route('api.medications.alerts.resolve', $context['foreign_alert']), ['resolution_notes' => 'Foreign'])
            ->assertNotFound();
        $this->actingAs($reader)
            ->postJson(route('api.medications.alerts.resolve', $context['forged_alert']), ['resolution_notes' => 'Forged'])
            ->assertNotFound();
        $this->actingAs($reader)
            ->postJson(route('api.medications.alerts.resolve', ['alertId' => 999999]), ['resolution_notes' => 'Missing'])
            ->assertNotFound();
        $this->actingAs($reader)
            ->postJson(route('api.medications.alerts.resolve', $context['local_alert']), ['resolution_notes' => 'Reviewed locally'])
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->actingAs($reader)
            ->postJson(route('api.medications.alerts.resolve', $context['local_alert']), ['resolution_notes' => 'Reviewed locally'])
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->actingAs($reader)
            ->postJson(route('api.medications.alerts.resolve', $context['local_alert']), ['resolution_notes' => 'Conflicting replay'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('resolution_notes');
        $this->actingAs($reader)
            ->postJson(route('api.medications.alerts.acknowledge', $context['local_alert']))
            ->assertOk()
            ->assertJson(['success' => false]);
        $this->assertSame('active', $context['foreign_alert']->refresh()->status);
        $this->assertSame('active', $context['forged_alert']->refresh()->status);

        $clientsOnly = $this->userWithPermissions(['clients.viewAny'], $context['local_site']);
        $this->actingAs($clientsOnly)->getJson(route('api.medications.alerts.index'))->assertForbidden();
        $this->actingAs($clientsOnly)->getJson(route('api.medications.dashboard.widgets'))->assertForbidden();
        $clientsUpdateOnly = $this->userWithPermissions(['clients.update'], $context['local_site']);
        $this->actingAs($clientsUpdateOnly)
            ->postJson(route('api.medications.alerts.acknowledge', $context['foreign_alert']))
            ->assertForbidden();
        $this->actingAs($clientsUpdateOnly)
            ->postJson(route('api.medications.alerts.resolve', $context['foreign_alert']), ['resolution_notes' => 'No authority'])
            ->assertForbidden();

        $actionOnly = $this->userWithPermissions([
            'medications.audit.view',
            'medications.reports.export',
        ], $context['local_site']);
        $this->actingAs($actionOnly)->get(route('emar.audit'))->assertForbidden();
        $this->actingAs($actionOnly)
            ->get(route('emar.pdf.mar', ['client_id' => $context['local_client']->id]))
            ->assertOk();

        $moduleOnly = $this->userWithPermissions([
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
        ], $context['local_site']);
        $this->actingAs($moduleOnly)->get(route('emar.audit'))->assertForbidden();
        $this->actingAs($moduleOnly)
            ->get(route('emar.pdf.mar', ['client_id' => $context['local_client']->id]))
            ->assertForbidden();
        $this->actingAs($moduleOnly)
            ->postJson(route('api.medications.alerts.acknowledge', $context['foreign_alert']))
            ->assertForbidden();

        $alertActionOnly = $this->userWithPermissions([
            'medications.administer.correct',
        ], $context['local_site']);
        $this->actingAs($alertActionOnly)
            ->postJson(route('api.medications.alerts.acknowledge', $context['foreign_alert']))
            ->assertForbidden();

        $exportWithoutControlled = $this->userWithPermissions([
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            'medications.reports.export',
        ], $context['local_site']);
        $this->actingAs($exportWithoutControlled)
            ->get(route('emar.pdf.cd_register', ['client_id' => $context['local_client']->id]))
            ->assertForbidden();

        $generalReportsOnly = $this->userWithPermissions([
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            'reports.viewAny',
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
        ], $context['local_site']);
        foreach (['emar.pdf.mar', 'emar.pdf.round_sheet', 'emar.pdf.cd_register'] as $routeName) {
            $parameters = $routeName === 'emar.pdf.round_sheet'
                ? []
                : ['client_id' => $context['local_client']->id];
            $this->actingAs($generalReportsOnly)->get(route($routeName, $parameters))->assertOk();
        }

        foreach (MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS as $bypassPermission) {
            $globalWithoutActions = $this->userWithPermissions([
                MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
                MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
                'reports.viewAny',
                $bypassPermission,
            ]);
            $this->actingAs($globalWithoutActions)->get(route('emar.audit'))->assertForbidden();
            $this->actingAs($globalWithoutActions)
                ->get(route('emar.pdf.mar', ['client_id' => $context['foreign_client']->id]))
                ->assertOk();
            $this->actingAs($globalWithoutActions)
                ->get(route('emar.pdf.cd_register', ['client_id' => $context['foreign_client']->id]))
                ->assertOk();
        }
    }

    public function test_each_explicit_global_site_permission_broadens_scope_but_retains_reader_actions(): void
    {
        $context = $this->context();
        $pdfPayloads = [];
        $this->fakePdf($pdfPayloads);

        foreach (MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS as $index => $bypassPermission) {
            MedicationDashboardAlert::query()->whereKey($context['foreign_alert']->id)->update([
                'status' => 'active',
                'acknowledged_at' => null,
                'acknowledged_by' => null,
            ]);

            $global = $this->userWithPermissions([
                MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
                'medications.audit.view',
                'medications.reports.export',
                'medications.administer.correct',
                MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
                $bypassPermission,
            ], $context['local_site']);

            $audit = $this->actingAs($global)->get(route('emar.audit'))->assertOk();
            $this->assertEqualsCanonicalizing(
                [$context['local_site']->id, $context['foreign_site']->id],
                collect($audit->inertiaProps('sites'))->pluck('id')->all(),
            );
            $this->assertNotContains(
                'admin_'.$context['forged_administration']->id,
                collect($audit->inertiaProps('events'))->pluck('id')->all(),
            );
            $alerts = $this->actingAs($global)
                ->getJson(route('api.medications.alerts.index'))
                ->assertOk();
            $this->assertEqualsCanonicalizing(
                [$context['local_alert']->id, $context['foreign_alert']->id],
                collect($alerts->json('alerts'))->pluck('id')->all(),
            );
            $widgets = $this->actingAs($global)
                ->getJson(route('api.medications.dashboard.widgets'))
                ->assertOk();
            $this->assertEqualsCanonicalizing(
                [$context['local_alert']->id, $context['foreign_alert']->id],
                collect($widgets->json('overdue_meds.items'))->pluck('id')->all(),
            );
            $this->actingAs($global)
                ->postJson(route('api.medications.alerts.acknowledge', $context['foreign_alert']))
                ->assertOk()
                ->assertJson(['success' => true]);

            $this->actingAs($global)->get(route('emar.pdf.round_sheet'))->assertOk();
            $this->assertEqualsCanonicalizing(
                [$context['local_round']->id, $context['foreign_round']->id],
                $pdfPayloads['pdf.round-sheet'][$index]['rounds']->pluck('id')->all(),
            );
            $this->assertNotContains(
                $context['forged_administration']->id,
                $pdfPayloads['pdf.round-sheet'][$index]['rounds']
                    ->flatMap(fn (MedicationRound $round) => $round->administrations)
                    ->pluck('id')
                    ->all(),
            );
            $this->actingAs($global)
                ->get(route('emar.pdf.mar', ['client_id' => $context['foreign_client']->id]))
                ->assertOk();
            $this->actingAs($global)
                ->get(route('emar.pdf.cd_register', ['client_id' => $context['foreign_client']->id]))
                ->assertOk();
        }
    }

    public function test_legacy_emar_alert_dismiss_uses_exact_action_canonical_scope_and_idempotent_replay(): void
    {
        $context = $this->context();

        foreach (['clients.update', 'medications.orders.manage'] as $substitutePermission) {
            $substitute = $this->userWithPermissions([
                MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
                $substitutePermission,
            ], $context['local_site']);
            $this->actingAs($substitute)
                ->post(route('emar.alerts.dismiss', $context['local_alert']))
                ->assertForbidden();
        }
        $actionOnly = $this->userWithPermissions([
            'medications.administer.correct',
        ], $context['local_site']);
        $this->actingAs($actionOnly)
            ->post(route('emar.alerts.dismiss', $context['local_alert']))
            ->assertForbidden();

        $emptyScope = $this->userWithPermissions([
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            'medications.administer.correct',
        ]);
        $this->actingAs($emptyScope)
            ->post(route('emar.alerts.dismiss', $context['local_alert']))
            ->assertNotFound();

        $actor = $this->userWithPermissions([
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            'medications.administer.correct',
        ], $context['local_site']);
        $controlledAlerts = collect([
            'controlled_discrepancy',
            'controlled_overdue_check',
            'controlled_loss',
        ])->map(fn (string $alertType) => MedicationDashboardAlert::query()->create([
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $context['local_medication']->id,
            'alert_type' => $alertType,
            'severity' => 'critical',
            'message' => 'Restricted controlled alert '.$alertType,
            'status' => 'active',
        ]));
        $foreignResponse = $this->actingAs($actor)
            ->post(route('emar.alerts.dismiss', $context['foreign_alert']))
            ->assertNotFound();
        $this->actingAs($actor)
            ->post(route('emar.alerts.dismiss', $context['forged_alert']))
            ->assertNotFound();
        $missingResponse = $this->actingAs($actor)
            ->post(route('emar.alerts.dismiss', ['alert' => 999999]))
            ->assertNotFound();
        $this->assertSame($foreignResponse->getContent(), $missingResponse->getContent());
        foreach ($controlledAlerts as $controlledAlert) {
            $controlledResponse = $this->actingAs($actor)
                ->post(route('emar.alerts.dismiss', $controlledAlert))
                ->assertNotFound();
            $this->assertSame($missingResponse->getContent(), $controlledResponse->getContent());
            $this->assertSame('active', $controlledAlert->fresh()->status);
            $this->assertNull($controlledAlert->fresh()->acknowledged_at);
            $this->assertNull($controlledAlert->fresh()->acknowledged_by);
        }
        $this->assertSame('active', $context['foreign_alert']->fresh()->status);
        $this->assertSame('active', $context['forged_alert']->fresh()->status);

        $this->actingAs($actor)
            ->from('/emar')
            ->post(route('emar.alerts.dismiss', $context['local_alert']))
            ->assertRedirect('/emar');
        $acknowledgedAt = $context['local_alert']->fresh()->acknowledged_at?->toISOString();
        $this->assertSame('acknowledged', $context['local_alert']->fresh()->status);
        $this->actingAs($actor)
            ->from('/emar')
            ->post(route('emar.alerts.dismiss', $context['local_alert']))
            ->assertRedirect('/emar');
        $this->assertSame('acknowledged', $context['local_alert']->fresh()->status);
        $this->assertSame($acknowledgedAt, $context['local_alert']->fresh()->acknowledged_at?->toISOString());

        $controlledActor = $this->userWithPermissions([
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            'medications.administer.correct',
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
        ], $context['local_site']);
        foreach ($controlledAlerts as $controlledAlert) {
            $this->actingAs($controlledActor)
                ->from('/emar')
                ->post(route('emar.alerts.dismiss', $controlledAlert))
                ->assertRedirect('/emar');
            $this->assertSame('acknowledged', $controlledAlert->fresh()->status);
            $this->assertSame($controlledActor->id, (int) $controlledAlert->fresh()->acknowledged_by);
        }

        foreach (MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS as $bypassPermission) {
            $globalAlert = MedicationDashboardAlert::query()->create([
                'client_id' => $context['foreign_client']->id,
                'client_medication_id' => $context['foreign_medication']->id,
                'alert_type' => 'overdue',
                'severity' => 'critical',
                'message' => 'Global dismiss '.$bypassPermission,
                'status' => 'active',
            ]);
            $globalWithoutAction = $this->userWithPermissions([
                MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
                'medications.orders.manage',
                $bypassPermission,
            ], $context['local_site']);
            $this->actingAs($globalWithoutAction)
                ->post(route('emar.alerts.dismiss', $globalAlert))
                ->assertForbidden();
            $this->assertSame('active', $globalAlert->fresh()->status);

            $global = $this->userWithPermissions([
                MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
                'medications.administer.correct',
                $bypassPermission,
            ], $context['local_site']);
            $this->actingAs($global)
                ->from('/emar')
                ->post(route('emar.alerts.dismiss', $globalAlert))
                ->assertRedirect('/emar');
            $this->assertSame('acknowledged', $globalAlert->fresh()->status);
        }
    }

    public function test_api_correction_conceals_foreign_forged_and_missing_aggregates_before_writes(): void
    {
        config()->set('app.debug', false);
        $context = $this->context();
        $actor = $this->userWithPermissions([
            'medications.administer.correct',
        ], $context['local_site']);
        $localAdministration = ClientMedicationAdministration::query()->create([
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $context['local_medication']->id,
            'service_context_id' => $context['local_client']->service_context_id,
            'administered_by' => $actor->id,
            'scheduled_for' => now(),
            'administered_at' => now(),
            'status' => 'given',
            'dose_given' => '1 tablet',
            'client_request_uuid' => '11111111-1111-4111-8111-111111111111',
        ]);
        $foreignAdministration = ClientMedicationAdministration::query()->create([
            'client_id' => $context['foreign_client']->id,
            'client_medication_id' => $context['foreign_medication']->id,
            'service_context_id' => $context['foreign_client']->service_context_id,
            'administered_by' => $actor->id,
            'scheduled_for' => now(),
            'administered_at' => now(),
            'status' => 'given',
            'dose_given' => '1 tablet',
        ]);
        $payload = [
            'status' => 'refused',
            'reason' => 'Client declined the dose.',
            'correction_reason' => 'The original outcome was charted incorrectly.',
        ];

        $correctionsBefore = ClientMedicationAdministration::query()
            ->where('is_correction', true)
            ->count();
        $foreignResponse = $this->actingAs($actor)
            ->postJson(route('api.medications.administrations.correct', [
                'client' => $context['foreign_client'],
                'administration' => $foreignAdministration,
            ]), $payload)
            ->assertNotFound();
        $forgedResponse = $this->actingAs($actor)
            ->postJson(route('api.medications.administrations.correct', [
                'client' => $context['local_client'],
                'administration' => $context['forged_administration'],
            ]), $payload)
            ->assertNotFound();
        $missingResponse = $this->actingAs($actor)
            ->postJson(route('api.medications.administrations.correct', [
                'client' => $context['local_client'],
                'administration' => 999999,
            ]), $payload)
            ->assertNotFound();

        $this->assertSame($missingResponse->getContent(), $foreignResponse->getContent());
        $this->assertSame($missingResponse->getContent(), $forgedResponse->getContent());
        $this->assertSame(
            $correctionsBefore,
            ClientMedicationAdministration::query()->where('is_correction', true)->count(),
        );
        $this->assertDatabaseMissing('client_medication_administrations', [
            'corrected_of_id' => $foreignAdministration->id,
            'is_correction' => true,
        ]);
        $this->assertDatabaseMissing('client_medication_administrations', [
            'corrected_of_id' => $context['forged_administration']->id,
            'is_correction' => true,
        ]);
        $this->assertSame('given', $foreignAdministration->fresh()->status);
        $this->assertSame('given', $context['forged_administration']->fresh()->status);

        $lockOrder = [];
        $assertLockOrder = DB::connection()->getDriverName() === 'mysql';
        if ($assertLockOrder) {
            DB::listen(function ($event) use (&$lockOrder): void {
                $sql = strtolower($event->sql);
                if (! str_contains($sql, 'for update')) {
                    return;
                }
                foreach (['clients', 'client_medications', 'client_medication_administrations'] as $table) {
                    if (str_contains($sql, "`{$table}`")) {
                        $lockOrder[] = $table;
                        break;
                    }
                }
            });
        }

        $this->actingAs($actor)
            ->postJson(route('api.medications.administrations.correct', [
                'client' => $context['local_client'],
                'administration' => $localAdministration,
            ]), $payload)
            ->assertOk()
            ->assertJsonPath('correction.status', 'refused')
            ->assertJsonPath('correction.is_correction', true)
            ->assertJsonPath('correction.correction_status', 'pending');

        $this->assertSame('given', $localAdministration->fresh()->status);
        $this->assertDatabaseHas('client_medication_administrations', [
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $context['local_medication']->id,
            'corrected_of_id' => $localAdministration->id,
            'is_correction' => true,
            'status' => 'refused',
            'administered_by' => $actor->id,
            'client_request_uuid' => null,
            'correction_status' => 'pending',
        ]);
        if ($assertLockOrder) {
            $this->assertSame(
                ['clients', 'client_medications', 'client_medication_administrations'],
                array_values(array_unique($lockOrder)),
            );
        }
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $localSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $localClient = Client::factory()->create(['site_id' => $localSite->id, 'status' => 'active']);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
        $localMedication = ClientMedication::factory()->create([
            'client_id' => $localClient->id,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'is_prn' => false,
        ]);
        $foreignMedication = ClientMedication::factory()->create([
            'client_id' => $foreignClient->id,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $controlledMedication = ClientMedication::factory()->create([
            'client_id' => $localClient->id,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'controlled_drug' => true,
            'is_prn' => false,
        ]);
        $localAlert = MedicationDashboardAlert::query()->create([
            'client_id' => $localClient->id,
            'client_medication_id' => $localMedication->id,
            'alert_type' => 'overdue',
            'severity' => 'critical',
            'message' => 'Local overdue dose',
            'status' => 'active',
        ]);
        $foreignAlert = MedicationDashboardAlert::query()->create([
            'client_id' => $foreignClient->id,
            'client_medication_id' => $foreignMedication->id,
            'alert_type' => 'overdue',
            'severity' => 'critical',
            'message' => 'Foreign overdue dose',
            'status' => 'active',
        ]);
        $forgedAlert = MedicationDashboardAlert::query()->create([
            'client_id' => $localClient->id,
            'client_medication_id' => $foreignMedication->id,
            'alert_type' => 'overdue',
            'severity' => 'critical',
            'message' => 'Forged cross-client relationship',
            'status' => 'active',
        ]);
        $localRound = $this->round($localSite, $localClient, 'Local round');
        $foreignRound = $this->round($foreignSite, $foreignClient, 'Foreign round');
        $recorder = User::factory()->create();
        $forgedAdministration = ClientMedicationAdministration::query()->create([
            'client_id' => $localClient->id,
            'client_medication_id' => $foreignMedication->id,
            'medication_round_id' => $localRound->id,
            'service_context_id' => $localClient->service_context_id,
            'administered_by' => $recorder->id,
            'scheduled_for' => now(),
            'administered_at' => now(),
            'status' => 'given',
            'dose_given' => '1 tablet',
        ]);
        $controlledAdministration = ClientMedicationAdministration::query()->create([
            'client_id' => $localClient->id,
            'client_medication_id' => $controlledMedication->id,
            'medication_round_id' => $localRound->id,
            'service_context_id' => $localClient->service_context_id,
            'administered_by' => $recorder->id,
            'scheduled_for' => now(),
            'administered_at' => now(),
            'status' => 'given',
            'dose_given' => '1 tablet',
        ]);

        return [
            'local_site' => $localSite,
            'foreign_site' => $foreignSite,
            'local_client' => $localClient,
            'foreign_client' => $foreignClient,
            'local_medication' => $localMedication,
            'foreign_medication' => $foreignMedication,
            'controlled_medication' => $controlledMedication,
            'local_alert' => $localAlert,
            'foreign_alert' => $foreignAlert,
            'forged_alert' => $forgedAlert,
            'local_round' => $localRound,
            'foreign_round' => $foreignRound,
            'forged_administration' => $forgedAdministration,
            'controlled_administration' => $controlledAdministration,
        ];
    }

    private function round(Site $site, Client $client, string $name): MedicationRound
    {
        return MedicationRound::query()->create([
            'service_context_id' => $client->service_context_id,
            'site_id' => $site->id,
            'name' => $name,
            'round_type' => 'morning',
            'scheduled_time' => '09:00',
            'window_minutes' => 60,
            'round_date' => today(),
            'status' => 'pending',
        ]);
    }

    /** @param array<string, array<int, array<string, mixed>>> $payloads */
    private function fakePdf(array &$payloads): void
    {
        $document = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $document->shouldReceive('setPaper')->andReturnSelf();
        $document->shouldReceive('download')->andReturn(response('pdf'));
        Pdf::shouldReceive('loadView')
            ->andReturnUsing(function (string $view, array $data) use (&$payloads, $document) {
                $payloads[$view][] = $data;

                return $document;
            });
    }

    private function userWithPermissions(array $permissions, ?Site $site = null): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        if ($site) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $user->id,
                'primary_site_id' => $site->id,
                'is_active' => true,
                'start_date' => today()->subDay(),
            ]);
        }

        $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $permissionIds, 'Missing seeded permission in test setup.');
        $user->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all(),
        );

        return $user->refresh();
    }
}
