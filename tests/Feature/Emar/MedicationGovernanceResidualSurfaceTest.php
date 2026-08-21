<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationError;
use App\Models\MedicationReview;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MedicationGovernanceResidualSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_medication_audit_rows_pickers_exports_and_direct_events_are_site_scoped_and_safe(): void
    {
        $context = $this->context();
        $reader = $this->userWithPermissions([
            'medications.view',
            'medications.audit.view',
            'medications.reports.export',
            'medications.administer.record',
        ], $context['local_site']);

        $page = $this->actingAs($reader)->get(route('medications.audit.index'))->assertOk();
        $logs = collect($page->inertiaProps('logs'));
        $this->assertTrue($logs->contains('id', $context['local_log']->id));
        $this->assertFalse($logs->contains('id', $context['foreign_log']->id));
        $this->assertFalse($logs->contains('id', $context['forged_log']->id));
        $this->assertSame(
            [$context['local_client']->id],
            $logs->pluck('client.id')->filter()->unique()->values()->all(),
        );
        $this->assertSame(
            ['fields' => ['dose_given']],
            $logs->firstWhere('id', $context['local_log']->id)['meta'],
        );
        $this->assertSame(
            [$context['local_client']->id],
            collect($page->inertiaProps('clients'))->pluck('id')->all(),
        );
        $this->assertSame(
            [$context['local_site']->id],
            collect($page->inertiaProps('sites'))->pluck('id')->all(),
        );

        foreach ([$context['foreign_client']->id, 999999] as $clientId) {
            $this->actingAs($reader)
                ->get(route('medications.audit.index', ['client_id' => $clientId]))
                ->assertNotFound();
            $this->actingAs($reader)
                ->get(route('medications.audit.export', ['client_id' => $clientId]))
                ->assertNotFound();
            $this->actingAs($reader)
                ->get(route('emar.audit.export', ['client_id' => $clientId]))
                ->assertNotFound();
        }

        $csv = $this->actingAs($reader)
            ->get(route('medications.audit.export'))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('Local Resident', $csv);
        $this->assertStringNotContainsString('Foreign Resident', $csv);
        $this->assertStringNotContainsString('198.51.100.77', $csv);
        $this->assertStringNotContainsString('private_history', $csv);
        $emarCsv = $this->actingAs($reader)
            ->get(route('emar.audit.export'))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('Local Resident', $emarCsv);
        $this->assertStringNotContainsString('Foreign Resident', $emarCsv);
        $this->assertStringNotContainsString('198.51.100.77', $emarCsv);

        $this->assertMinimalIntegrityResponse(
            $this->actingAs($reader)
                ->getJson(route('emar.audit.event.integrity', ['id' => 'admin_'.$context['local_administration']->id])),
        );
        $eventCsv = $this->actingAs($reader)
            ->get(route('emar.audit.event.export', ['id' => 'admin_'.$context['local_administration']->id]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('dose_given', $eventCsv);
        $this->assertStringNotContainsString('PRIVATE ADMINISTRATION HISTORY', $eventCsv);
        $this->assertStringNotContainsString('198.51.100.77', $eventCsv);
        $this->assertStringNotContainsString('Change history', $eventCsv);

        $foreignIntegrity = $this->actingAs($reader)
            ->getJson(route('emar.audit.event.integrity', ['id' => 'admin_'.$context['foreign_administration']->id]))
            ->assertNotFound();
        $missingIntegrity = $this->actingAs($reader)
            ->getJson(route('emar.audit.event.integrity', ['id' => 'admin_999999']))
            ->assertNotFound();
        $this->assertSame($foreignIntegrity->getContent(), $missingIntegrity->getContent());

        $errorsBefore = MedicationError::query()->count();
        foreach ([
            'admin_'.$context['foreign_administration']->id,
            'admin_999999',
            'admin_1suffix',
            'omission_999_202608210900',
        ] as $eventId) {
            $this->actingAs($reader)
                ->getJson(route('emar.audit.event.integrity', ['id' => $eventId]))
                ->assertNotFound();
            $this->actingAs($reader)
                ->get(route('emar.audit.event.export', ['id' => $eventId]))
                ->assertNotFound();
            $this->actingAs($reader)
                ->post(route('emar.audit.event.flag', ['id' => $eventId]), ['flag' => 'no_actor'])
                ->assertNotFound();
        }
        $this->assertSame($errorsBefore, MedicationError::query()->count());
        $this->assertSame('active', $context['foreign_alert']->refresh()->status);
    }

    public function test_audit_and_dashboard_fail_closed_without_sites_and_both_global_site_permissions_broaden_only_scope(): void
    {
        $context = $this->context();
        $empty = $this->userWithPermissions([
            'medications.view',
            'medications.audit.view',
            'medications.reports.export',
            'shifts.manageAny',
        ]);

        $emptyAudit = $this->actingAs($empty)->get(route('medications.audit.index'))->assertOk();
        $this->assertSame([], collect($emptyAudit->inertiaProps('logs'))->all());
        $this->assertSame([], collect($emptyAudit->inertiaProps('clients'))->all());
        $this->assertSame([], collect($emptyAudit->inertiaProps('sites'))->all());
        $emptyCsv = $this->actingAs($empty)
            ->get(route('medications.audit.export'))
            ->assertOk()
            ->streamedContent();
        $this->assertStringNotContainsString('Local Resident', $emptyCsv);
        $emptyEmarCsv = $this->actingAs($empty)
            ->get(route('emar.audit.export'))
            ->assertOk()
            ->streamedContent();
        $this->assertStringNotContainsString('Local Resident', $emptyEmarCsv);
        $this->assertDashboardWidgets($empty, 0.0, 0, 0, 0, 0);

        $ordinary = $this->userWithPermissions([
            'medications.view',
            'shifts.manageAny',
        ], $context['local_site']);
        $this->assertDashboardWidgets($ordinary, 100.0, 0, 1, 1, 1);

        foreach (MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS as $bypassPermission) {
            $global = $this->userWithPermissions([
                'medications.view',
                'medications.audit.view',
                'medications.reports.export',
                'shifts.manageAny',
                $bypassPermission,
            ]);
            $audit = $this->actingAs($global)->get(route('medications.audit.index'))->assertOk();
            $auditIds = collect($audit->inertiaProps('logs'))->pluck('id');
            $this->assertTrue($auditIds->contains($context['local_log']->id));
            $this->assertTrue($auditIds->contains($context['foreign_log']->id));
            $this->assertFalse($auditIds->contains($context['forged_log']->id));
            $this->assertMinimalIntegrityResponse(
                $this->actingAs($global)
                    ->getJson(route('emar.audit.event.integrity', ['id' => 'admin_'.$context['foreign_administration']->id])),
            );
            $foreignEventCsv = $this->actingAs($global)
                ->get(route('emar.audit.event.export', ['id' => 'admin_'.$context['foreign_administration']->id]))
                ->assertOk()
                ->streamedContent();
            $this->assertStringContainsString((string) $context['foreign_administration']->id, $foreignEventCsv);
            $this->assertStringNotContainsString('FOREIGN PRIVATE HISTORY', $foreignEventCsv);
            $globalCsv = $this->actingAs($global)
                ->get(route('medications.audit.export'))
                ->assertOk()
                ->streamedContent();
            $this->assertStringContainsString('Local Resident', $globalCsv);
            $this->assertStringContainsString('Foreign Resident', $globalCsv);
            $this->assertDashboardWidgets($global, 50.0, 1, 2, 2, 2);
        }
    }

    public function test_route_contracts_require_exact_audit_alert_and_medication_export_capabilities(): void
    {
        foreach ([
            'medications.audit.index' => ['medications.view', 'medications.audit.view'],
            'medications.audit.export' => ['medications.view', 'medications.audit.view', 'medications.reports.export'],
            'emar.audit.export' => ['medications.view', 'medications.audit.view', 'medications.reports.export'],
            'emar.audit.event.integrity' => ['medications.view', 'medications.audit.view'],
            'emar.audit.event.export' => ['medications.view', 'medications.audit.view', 'medications.reports.export'],
            'emar.audit.event.flag' => ['medications.view', 'medications.audit.view', 'medications.administer.record'],
            'api.medications.alerts.acknowledge' => ['medications.view', 'medications.administer.correct'],
            'api.medications.alerts.resolve' => ['medications.view', 'medications.administer.correct'],
            'emar.alerts.dismiss' => ['medications.view', 'medications.administer.correct'],
        ] as $routeName => $permissions) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];
            foreach ($permissions as $permission) {
                $this->assertContains('permission:'.$permission, $middleware, $routeName);
            }
            $serialized = implode('|', $middleware);
            $this->assertStringNotContainsString('clients.update', $serialized, $routeName);
            $this->assertStringNotContainsString('reports.viewAny', $serialized, $routeName);
        }
        $dismissMiddleware = Route::getRoutes()->getByName('emar.alerts.dismiss')?->gatherMiddleware() ?? [];
        $this->assertStringNotContainsString('medications.orders.manage', implode('|', $dismissMiddleware));

        foreach ([
            'emar.reports',
            'emar.reports.export',
            'emar.reports.export_mar',
            'emar.reports.export_discrepancies',
            'emar.pdf.mar',
            'emar.pdf.cd_register',
            'emar.pdf.round_sheet',
            'reports.medications',
            'reports.medications.export_mar',
            'reports.medications.export_discrepancies',
            'api.medications.reports',
            'api.medications.reports.export',
        ] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];
            $this->assertContains('permission:medications.view', $middleware, $routeName);
            $this->assertContains('permission:medications.reports.export', $middleware, $routeName);
            $this->assertStringNotContainsString('reports.viewAny', implode('|', $middleware), $routeName);
        }

        $emarController = file_get_contents(app_path('Http/Controllers/Emar/EmarController.php'));
        $this->assertIsString($emarController);
        $this->assertStringContainsString("'export_reports' => (bool) \$user", $emarController);
        $this->assertStringContainsString("'can_export' => (bool) \$user?->canDo('medications.view')", $emarController);
        $this->assertStringNotContainsString("canDo('medications.reports.export') || \$user", $emarController);

        $context = $this->context();
        $generalReportsOnly = $this->userWithPermissions([
            'medications.view',
            'medications.controlled.view',
            'reports.viewAny',
        ], $context['local_site']);
        $this->actingAs($generalReportsOnly)->get(route('medications.audit.index'))->assertForbidden();
        $this->actingAs($generalReportsOnly)->get(route('medications.audit.export'))->assertForbidden();
        foreach ([
            'emar.reports',
            'emar.reports.export',
            'emar.pdf.mar',
            'emar.pdf.cd_register',
            'emar.pdf.round_sheet',
            'reports.medications',
            'api.medications.reports',
            'api.medications.reports.export',
        ] as $routeName) {
            $this->actingAs($generalReportsOnly)->get(route($routeName))->assertForbidden();
        }

        $auditOnly = $this->userWithPermissions([
            'medications.view',
            'medications.audit.view',
        ], $context['local_site']);
        $this->actingAs($auditOnly)->get(route('medications.audit.index'))->assertOk();
        $this->actingAs($auditOnly)->get(route('medications.audit.export'))->assertForbidden();

        $ordinaryExporter = $this->userWithPermissions([
            'medications.view',
            'medications.reports.export',
        ], $context['local_site']);
        $this->actingAs($ordinaryExporter)
            ->getJson(route('api.medications.reports'))
            ->assertOk();
        $this->actingAs($ordinaryExporter)
            ->get(route('api.medications.reports.export'))
            ->assertOk();

        foreach (MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS as $bypassPermission) {
            $globalExporter = $this->userWithPermissions([
                'medications.view',
                'medications.reports.export',
                $bypassPermission,
            ]);
            $this->actingAs($globalExporter)
                ->getJson(route('api.medications.reports'))
                ->assertOk();
            $this->actingAs($globalExporter)
                ->get(route('api.medications.reports.export'))
                ->assertOk();
        }
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $localSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $localClient = Client::factory()->create([
            'site_id' => $localSite->id,
            'first_name' => 'Local',
            'last_name' => 'Resident',
            'status' => 'active',
        ]);
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'first_name' => 'Foreign',
            'last_name' => 'Resident',
            'status' => 'active',
        ]);
        $localMedication = $this->medication($localClient, 'Local medicine');
        $foreignMedication = $this->medication($foreignClient, 'Foreign medicine');
        $recorder = User::factory()->create();
        $localAdministration = $this->administration(
            $localClient,
            $localMedication,
            $recorder,
            'given',
            'PRIVATE ADMINISTRATION HISTORY',
        );
        $foreignAdministration = $this->administration(
            $foreignClient,
            $foreignMedication,
            $recorder,
            'missed',
            'FOREIGN PRIVATE HISTORY',
        );
        $localLog = $this->auditLog($localClient, $localAdministration, $recorder, '198.51.100.77');
        $foreignLog = $this->auditLog($foreignClient, $foreignAdministration, $recorder, '203.0.113.88');
        $forgedLog = $this->auditLog($localClient, $foreignAdministration, $recorder, '192.0.2.44');
        $localAlert = $this->alert($localClient, $localMedication, 'Local dashboard alert');
        $foreignAlert = $this->alert($foreignClient, $foreignMedication, 'Foreign dashboard alert');
        $this->review($localClient);
        $this->review($foreignClient);
        $this->stock($localMedication);
        $this->stock($foreignMedication);

        return [
            'local_site' => $localSite,
            'foreign_site' => $foreignSite,
            'local_client' => $localClient,
            'foreign_client' => $foreignClient,
            'local_administration' => $localAdministration,
            'foreign_administration' => $foreignAdministration,
            'local_log' => $localLog,
            'foreign_log' => $foreignLog,
            'forged_log' => $forgedLog,
            'local_alert' => $localAlert,
            'foreign_alert' => $foreignAlert,
        ];
    }

    private function medication(Client $client, string $name): ClientMedication
    {
        return ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => $name,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'is_prn' => false,
            'dose_times' => ['09:00'],
        ]);
    }

    private function administration(
        Client $client,
        ClientMedication $medication,
        User $recorder,
        string $status,
        string $notes,
    ): ClientMedicationAdministration {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'service_context_id' => $client->service_context_id,
            'administered_by' => $recorder->id,
            'scheduled_for' => now(),
            'administered_at' => now(),
            'status' => $status,
            'dose_given' => '1 tablet',
            'notes' => $notes,
        ]);
    }

    private function auditLog(
        Client $client,
        ClientMedicationAdministration $administration,
        User $recorder,
        string $ipAddress,
    ): AuditLog {
        return AuditLog::query()->create([
            'client_id' => $client->id,
            'user_id' => $recorder->id,
            'auditable_type' => $administration->getMorphClass(),
            'auditable_id' => $administration->id,
            'action' => 'medications.administration.record',
            'meta' => [
                'fields' => ['dose_given'],
                'private_history' => 'must not export',
            ],
            'ip_address' => $ipAddress,
            'user_agent' => 'Sensitive test browser history',
        ]);
    }

    private function alert(Client $client, ClientMedication $medication, string $message): MedicationDashboardAlert
    {
        return MedicationDashboardAlert::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'alert_type' => 'overdue',
            'severity' => 'critical',
            'message' => $message,
            'status' => 'active',
        ]);
    }

    private function review(Client $client): MedicationReview
    {
        return MedicationReview::query()->create([
            'client_id' => $client->id,
            'review_type' => 'routine',
            'status' => 'scheduled',
            'scheduled_date' => today()->subDay(),
        ]);
    }

    private function stock(ClientMedication $medication): ClientMedicationStock
    {
        return ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => '1.00',
            'reorder_level' => 2,
            'unit' => 'tablets',
        ]);
    }

    private function assertDashboardWidgets(
        User $user,
        float $adminRate,
        int $pending,
        int $activeAlerts,
        int $overdueReviews,
        int $lowStock,
    ): void {
        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->assertSame($adminRate, (float) $response->inertiaProps('emarWidgets.adminRate'));
        $this->assertSame($pending, $response->inertiaProps('emarWidgets.pending'));
        $this->assertSame($activeAlerts, $response->inertiaProps('emarWidgets.activeAlerts'));
        $this->assertSame($overdueReviews, $response->inertiaProps('emarWidgets.overdueReviews'));
        $this->assertSame($lowStock, $response->inertiaProps('emarWidgets.lowStock'));
    }

    private function assertMinimalIntegrityResponse(TestResponse $response): void
    {
        $response->assertOk()->assertExactJson(['backed' => true]);
        foreach ([
            'ip_address',
            'user_agent',
            'device',
            'edited',
            'edit_count',
            'fingerprint',
            'history',
            'attributes',
            'notes',
        ] as $sensitivePath) {
            $response->assertJsonMissingPath($sensitivePath);
        }
        $payload = (string) $response->getContent();
        $this->assertStringNotContainsString('198.51.100.77', $payload);
        $this->assertStringNotContainsString('203.0.113.88', $payload);
        $this->assertStringNotContainsString('Sensitive test browser history', $payload);
        $this->assertStringNotContainsString('PRIVATE ADMINISTRATION HISTORY', $payload);
        $this->assertStringNotContainsString('FOREIGN PRIVATE HISTORY', $payload);
    }

    /** @param array<int, string> $permissions */
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
