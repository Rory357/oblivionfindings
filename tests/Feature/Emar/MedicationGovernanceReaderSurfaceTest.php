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
        foreach ([
            'emar.audit' => 'permission:medications.audit.view',
            'emar.pdf.mar' => 'permission:medications.reports.export',
            'emar.pdf.round_sheet' => 'permission:medications.reports.export',
            'emar.pdf.cd_register' => 'permission:medications.reports.export',
        ] as $routeName => $actionMiddleware) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];
            $this->assertContains('permission:medications.view', $middleware, $routeName);
            $this->assertContains($actionMiddleware, $middleware, $routeName);
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
            ->assertForbidden();

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
            $this->actingAs($generalReportsOnly)->get(route($routeName, $parameters))->assertForbidden();
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
                ->assertForbidden();
            $this->actingAs($globalWithoutActions)
                ->get(route('emar.pdf.cd_register', ['client_id' => $context['foreign_client']->id]))
                ->assertForbidden();
        }
    }

    public function test_each_explicit_global_site_permission_broadens_scope_but_retains_reader_actions(): void
    {
        $context = $this->context();
        $pdfPayloads = [];
        $this->fakePdf($pdfPayloads);

        foreach (MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS as $index => $bypassPermission) {
            $global = $this->userWithPermissions([
                MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
                'medications.audit.view',
                'medications.reports.export',
                'medications.administer.correct',
                MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
                $bypassPermission,
            ]);

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
        ]);
        $foreignMedication = ClientMedication::factory()->create([
            'client_id' => $foreignClient->id,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
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

        return [
            'local_site' => $localSite,
            'foreign_site' => $foreignSite,
            'local_client' => $localClient,
            'foreign_client' => $foreignClient,
            'local_alert' => $localAlert,
            'foreign_alert' => $foreignAlert,
            'forged_alert' => $forgedAlert,
            'local_round' => $localRound,
            'foreign_round' => $foreignRound,
            'forged_administration' => $forgedAdministration,
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
        $document = Mockery::mock();
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
                'employment_status' => 'active',
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
