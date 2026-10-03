<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationDestruction;
use App\Models\MedicationError;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationReview;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\ReadsRetainedMedicationAuditEvidence;
use Tests\TestCase;

class MedicationGovernanceResidualSurfaceTest extends TestCase
{
    use ReadsRetainedMedicationAuditEvidence;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_medication_audit_rows_pickers_exports_and_direct_events_are_site_scoped_and_safe(): void
    {
        $context = $this->context();
        $reader = $this->userWithPermissions([
            'medications.view',
            'medications.audit.view',
            'medications.audit.export',
            'medications.reports.view',
            'medications.reports.export',
            'medications.administer.record',
        ], $context['local_site']);

        $page = $this->retainedAuditLogs($reader)->assertOk();
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
            $this->canonicalGet($reader, 'medications.audit.index', ['client_id' => $clientId])
                ->assertNotFound();
            $this->actingAs($reader)
                ->get(route('medications.audit.export', ['client_id' => $clientId, 'purpose' => 'audit']))
                ->assertNotFound();
            $this->actingAs($reader)
                ->get(route('emar.audit.export', ['client_id' => $clientId, 'purpose' => 'audit']))
                ->assertNotFound();
        }

        $csv = $this->actingAs($reader)
            ->get(route('medications.audit.export', ['purpose' => 'audit']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Local Resident', $csv);
        $this->assertStringNotContainsString('Foreign Resident', $csv);
        $this->assertStringNotContainsString('198.51.100.77', $csv);
        $this->assertStringNotContainsString('private_history', $csv);
        $emarCsv = $this->actingAs($reader)
            ->get(route('emar.audit.export', ['purpose' => 'audit']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Local Resident', $emarCsv);
        $this->assertStringNotContainsString('Foreign Resident', $emarCsv);
        $this->assertStringNotContainsString('198.51.100.77', $emarCsv);

        $this->assertMinimalIntegrityResponse(
            $this->actingAs($reader)
                ->getJson(route('emar.audit.event.integrity', ['id' => 'admin_'.$context['local_administration']->id])),
        );
        $eventCsv = $this->actingAs($reader)
            ->get(route('emar.audit.event.export', ['id' => 'admin_'.$context['local_administration']->id, 'purpose' => 'audit']))
            ->assertOk()
            ->getContent();
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
        $foreignIntegrity->assertJsonStructure(['message']);
        $missingIntegrity->assertJsonStructure(['message']);
        $this->assertSame(
            [
                'status' => $foreignIntegrity->status(),
                'message' => $foreignIntegrity->json('message'),
            ],
            [
                'status' => $missingIntegrity->status(),
                'message' => $missingIntegrity->json('message'),
            ],
        );

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
                ->get(route('emar.audit.event.export', ['id' => $eventId, 'purpose' => 'audit']))
                ->assertNotFound();
            $this->actingAs($reader)
                ->post(route('emar.audit.event.flag', ['id' => $eventId]), ['flag' => 'no_actor'])
                ->assertNotFound();
        }
        $this->assertSame($errorsBefore, MedicationError::query()->count());
        $this->assertSame('active', $context['foreign_alert']->refresh()->status);
    }

    public function test_controlled_content_is_concealed_across_audit_lists_exports_and_direct_events_without_exact_permission(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 12:00:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());

        try {
            $context = $this->controlledAuditContext();
            $ordinary = $this->userWithPermissions([
                'medications.view',
                'medications.audit.view',
                'medications.audit.export',
                'medications.reports.view',
                'medications.reports.export',
                'medications.administer.record',
            ], $context['local_site'], ['medications.controlled.view']);
            $this->assertFalse($ordinary->canDo('medications.controlled.view'));

            $ordinaryAudit = $this->retainedAuditLogs($ordinary)
                ->assertOk();
            $ordinaryLogs = collect($ordinaryAudit->inertiaProps('logs'));
            $this->assertTrue($ordinaryLogs->contains('id', $context['local_log']->id));
            foreach ($context['controlled_audit_log_ids'] as $logId) {
                $this->assertFalse($ordinaryLogs->contains('id', $logId));
            }
            $this->assertFalse($ordinaryLogs->contains('id', $context['forged_audit_log_id']));
            $this->assertFalse($ordinaryLogs->contains('id', $context['foreign_audit_log_id']));

            $ordinaryFeed = collect($this->retainedAuditFeed($ordinary)
                ->assertOk()
                ->inertiaProps('events'));
            $ordinaryEventIds = $ordinaryFeed->pluck('id');
            $this->assertTrue($ordinaryEventIds->contains('admin_'.$context['local_administration']->id));
            foreach (array_keys($context['controlled_event_exports']) as $eventId) {
                $this->assertFalse($ordinaryEventIds->contains($eventId), $eventId.' leaked into the ordinary audit feed.');
            }
            foreach ($context['canonical_excluded_event_ids'] as $eventId) {
                $this->assertFalse($ordinaryEventIds->contains($eventId));
            }
            $this->assertFalse($ordinaryFeed->contains(
                fn (array $event): bool => $event['event_type'] === 'omission'
                    && data_get($event, 'details.medication') === $context['scheduled_controlled_medication']->name,
            ));
            $ordinaryPayload = (string) json_encode($ordinaryFeed->all());
            foreach ($context['controlled_sentinels'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $ordinaryPayload);
            }

            foreach (['medications.audit.export', 'emar.audit.export'] as $routeName) {
                $csv = $this->actingAs($ordinary)
                    ->get(route($routeName, ['purpose' => 'audit']))
                    ->assertOk()
                    ->getContent();
                foreach ($context['controlled_audit_actions'] as $action) {
                    $this->assertStringNotContainsString($action, $csv, $routeName);
                }
            }

            $errorsBefore = MedicationError::query()->count();
            foreach ([
                ...array_keys($context['controlled_event_exports']),
                'omission_'.$context['scheduled_controlled_medication']->id.'_202608262100',
            ] as $eventId) {
                $this->actingAs($ordinary)
                    ->getJson(route('emar.audit.event.integrity', ['id' => $eventId]))
                    ->assertNotFound();
                $this->actingAs($ordinary)
                    ->get(route('emar.audit.event.export', ['id' => $eventId, 'purpose' => 'audit']))
                    ->assertNotFound();
                $this->actingAs($ordinary)
                    ->post(route('emar.audit.event.flag', ['id' => $eventId]), ['flag' => 'no_actor'])
                    ->assertNotFound();
            }
            $this->actingAs($ordinary)
                ->post(
                    route('emar.audit.event.flag', ['id' => 'admin_'.$context['local_administration']->id]),
                    ['severity' => 'invalid-before-authority'],
                )
                ->assertNotFound();
            $this->assertSame($errorsBefore, MedicationError::query()->count());

            $controlledReader = $this->userWithPermissions([
                'medications.view',
                'medications.audit.view',
                'medications.audit.export',
                'medications.reports.view',
                'medications.reports.export',
                'medications.administer.record',
                'medications.controlled.view',
            ], $context['local_site']);
            $this->assertTrue($controlledReader->canDo('medications.controlled.view'));

            $controlledLogs = collect($this->retainedAuditLogs($controlledReader)
                ->assertOk()
                ->inertiaProps('logs'));
            foreach ($context['controlled_audit_log_ids'] as $logId) {
                $this->assertTrue($controlledLogs->contains('id', $logId));
            }
            $this->assertFalse($controlledLogs->contains('id', $context['forged_audit_log_id']));
            $this->assertFalse($controlledLogs->contains('id', $context['foreign_audit_log_id']));

            $controlledFeed = collect($this->retainedAuditFeed($controlledReader)
                ->assertOk()
                ->inertiaProps('events'));
            $controlledEventIds = $controlledFeed->pluck('id');
            foreach (array_keys($context['controlled_event_exports']) as $eventId) {
                $this->assertTrue($controlledEventIds->contains($eventId), $eventId.' was missing for the controlled reader.');
            }
            foreach ($context['canonical_excluded_event_ids'] as $eventId) {
                $this->assertFalse($controlledEventIds->contains($eventId));
            }
            $this->assertTrue($controlledFeed->contains(
                fn (array $event): bool => $event['event_type'] === 'omission'
                    && data_get($event, 'details.medication') === $context['scheduled_controlled_medication']->name,
            ));

            foreach (['medications.audit.export', 'emar.audit.export'] as $routeName) {
                $csv = $this->actingAs($controlledReader)
                    ->get(route($routeName, ['purpose' => 'audit']))
                    ->assertOk()
                    ->getContent();
                foreach ($context['controlled_audit_actions'] as $action) {
                    $this->assertStringContainsString($action, $csv, $routeName);
                }
            }

            foreach ($context['controlled_event_exports'] as $eventId => $recordLabel) {
                $this->assertMinimalIntegrityResponse(
                    $this->actingAs($controlledReader)
                        ->getJson(route('emar.audit.event.integrity', ['id' => $eventId])),
                );
                $eventCsv = $this->actingAs($controlledReader)
                    ->get(route('emar.audit.event.export', ['id' => $eventId, 'purpose' => 'audit']))
                    ->assertOk()
                    ->getContent();
                $this->assertStringContainsString($recordLabel, $eventCsv);
            }

            foreach ($context['canonical_excluded_event_ids'] as $eventId) {
                $this->actingAs($controlledReader)
                    ->getJson(route('emar.audit.event.integrity', ['id' => $eventId]))
                    ->assertNotFound();
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_audit_and_dashboard_fail_closed_without_sites_and_both_global_site_permissions_broaden_only_scope(): void
    {
        // The /dashboard eMAR widget counts the 09:00 dose slots on the
        // worker (NZ) day from the dose-slot projection (NF-25, C6a): pin the
        // clock after the 09:00 window has ended (10:00) and put the recorded
        // doses on that slot through the model, so the slot outcome is written.
        Carbon::setTestNow(Carbon::parse('2026-05-21 10:30:00', 'Pacific/Auckland')->utc());
        $context = $this->context();
        $slotUtc = Carbon::parse('2026-05-21 09:00:00', 'Pacific/Auckland')->utc();
        ClientMedicationAdministration::query()
            ->whereKey([$context['local_administration']->id, $context['foreign_administration']->id])
            ->get()
            ->each(fn (ClientMedicationAdministration $administration) => $administration->update(['scheduled_for' => $slotUtc]));
        $empty = $this->userWithPermissions([
            'medications.view',
            'medications.audit.view',
            'medications.audit.export',
            'medications.reports.view',
            'medications.reports.export',
            'shifts.manageAny',
        ]);

        $emptyAudit = $this->retainedAuditLogs($empty)->assertOk();
        $this->assertSame([], collect($emptyAudit->inertiaProps('logs'))->all());
        $this->assertSame([], collect($emptyAudit->inertiaProps('clients'))->all());
        $this->assertSame([], collect($emptyAudit->inertiaProps('sites'))->all());
        $this->actingAs($empty)->getJson(route('medications.audit.export', ['purpose' => 'audit']))->assertUnprocessable();
        $this->actingAs($empty)->getJson(route('emar.audit.export', ['purpose' => 'audit']))->assertUnprocessable();
        $this->assertDatabaseCount('medication_events', 0);
        // No readable Site: no scheduled doses, so the admin rate is n/a.
        $this->assertDashboardWidgets($empty, null, 0, 0, 0, 0);

        // Dose numbers count the people the reader may open (the person
        // rule) and controlled doses for every reader (P09 Q6): the local
        // 09:00 dose given and the local controlled 09:00 dose unrecorded,
        // now overdue → 1 of 2 due doses given, 1 due now.
        $ordinary = $this->userWithPermissions([
            'medications.view',
            'clients.viewAny',
            'shifts.manageAny',
        ], $context['local_site']);
        $this->assertDashboardWidgets($ordinary, 50.0, 1, 1, 1, 1);

        // The same dose numbers; the controlled alert and stock are its own.
        $controlledReader = $this->userWithPermissions([
            'medications.view',
            'clients.viewAny',
            'medications.controlled.view',
            'shifts.manageAny',
        ], $context['local_site']);
        $this->assertDashboardWidgets($controlledReader, 50.0, 1, 2, 1, 2);

        foreach (MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS as $bypassPermission) {
            $global = $this->userWithPermissions([
                'medications.view',
                'medications.audit.view',
                'medications.audit.export',
                'medications.reports.view',
                'medications.reports.export',
                'shifts.manageAny',
                $bypassPermission,
            ]);
            $audit = $this->retainedAuditLogs($global)->assertOk();
            $auditIds = collect($audit->inertiaProps('logs'))->pluck('id');
            $this->assertTrue($auditIds->contains($context['local_log']->id));
            $this->assertTrue($auditIds->contains($context['foreign_log']->id));
            $this->assertFalse($auditIds->contains($context['forged_log']->id));
            $this->assertMinimalIntegrityResponse(
                $this->actingAs($global)
                    ->getJson(route('emar.audit.event.integrity', ['id' => 'admin_'.$context['foreign_administration']->id])),
            );
            $foreignEventCsv = $this->actingAs($global)
                ->get(route('emar.audit.event.export', ['id' => 'admin_'.$context['foreign_administration']->id, 'purpose' => 'audit']))
                ->assertOk()
                ->getContent();
            $this->assertStringContainsString((string) $context['foreign_administration']->id, $foreignEventCsv);
            $this->assertStringNotContainsString('FOREIGN PRIVATE HISTORY', $foreignEventCsv);
            $globalCsv = $this->actingAs($global)
                ->get(route('medications.audit.export', ['purpose' => 'audit']))
                ->assertOk()
                ->getContent();
            $this->assertStringContainsString('Local Resident', $globalCsv);
            $this->assertStringContainsString('Foreign Resident', $globalCsv);
            // Both Sites: local given, foreign recorded missed, and both
            // controlled 09:00 doses unrecorded (overdue) → 1 of 4 given.
            $this->assertDashboardWidgets($global, 25.0, 2, 2, 2, 2);
        }
    }

    public function test_route_contracts_keep_report_and_audit_capabilities_exact(): void
    {
        foreach ([
            'medications.audit.index' => ['medications.view', 'medications.audit.view'],
            'medications.audit.export' => ['medications.view', 'medications.audit.view', 'medications.audit.export'],
            'emar.audit.export' => ['medications.view', 'medications.audit.view', 'medications.audit.export'],
            'emar.audit.event.integrity' => ['medications.view', 'medications.audit.view'],
            'emar.audit.event.export' => ['medications.view', 'medications.audit.view', 'medications.audit.export'],
            'emar.reports.history' => ['medications.reports.view', 'medications.view', 'medications.audit.view'],
            'emar.reports.history_logs' => ['medications.reports.view', 'medications.view', 'medications.audit.view'],
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
        ] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];
            $this->assertContains(
                'permission:medications.reports.view',
                $middleware,
                $routeName,
            );
            $this->assertNotContains('permission:medications.reports.export|reports.viewAny', $middleware, $routeName);
            if (! in_array($routeName, ['emar.reports', 'emar.reports.export', 'reports.medications'], true)) {
                $this->assertContains('permission:medications.reports.export', $middleware, $routeName);
            }
            $this->assertNotContains('permission:medications.view', $middleware, $routeName);
        }

        $this->assertSame(['POST'], Route::getRoutes()->getByName('emar.reports.export')->methods());

        $context = $this->context();
        $reportsWithoutAudit = $this->userWithPermissions([
            'medications.view',
            'reports.viewAny',
        ], $context['local_site']);
        $this->actingAs($reportsWithoutAudit)->get(route('medications.audit.index'))->assertForbidden();
        $this->actingAs($reportsWithoutAudit)->get(route('medications.audit.export', ['purpose' => 'audit']))->assertForbidden();

        $generalReportsOnly = $this->userWithPermissions([
            'reports.viewAny',
        ], $context['local_site'], ['medications.view']);
        $this->assertFalse($generalReportsOnly->canDo('medications.view'));
        $this->actingAs($generalReportsOnly)->get(route('medications.audit.index'))->assertForbidden();
        $this->actingAs($generalReportsOnly)->get(route('medications.audit.export', ['purpose' => 'audit']))->assertForbidden();
        foreach ([
            'emar.reports',
            'reports.medications',
            'api.medications.reports',
            'api.medications.reports.export',
        ] as $routeName) {
            $this->canonicalGet($generalReportsOnly, $routeName, ['purpose' => 'audit'])->assertForbidden();
        }
        $this->actingAs($generalReportsOnly)->postJson(route('emar.reports.export'), ['type' => 'doses', 'purpose' => 'care'])->assertForbidden();

        $auditOnly = $this->userWithPermissions([
            'medications.view',
            'medications.audit.view',
            'medications.reports.view',
        ], $context['local_site']);
        $this->retainedAuditLogs($auditOnly)->assertOk();
        $this->actingAs($auditOnly)->get(route('medications.audit.export', ['purpose' => 'audit']))->assertForbidden();

        $ordinaryExporter = $this->userWithPermissions([
            'medications.reports.view',
            'medications.reports.export',
        ], $context['local_site'], ['medications.view']);
        $this->assertFalse($ordinaryExporter->canDo('medications.view'));
        $this->canonicalGet($ordinaryExporter, 'reports.medications')
            ->assertOk();
        $this->actingAs($ordinaryExporter)
            ->get(route('emar.reports'))
            ->assertOk();
        $this->actingAs($ordinaryExporter)
            ->getJson(route('api.medications.reports'))
            ->assertOk();
        $this->actingAs($ordinaryExporter)
            ->get(route('api.medications.reports.export', ['purpose' => 'care']))
            ->assertOk();

        foreach (MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS as $bypassPermission) {
            $globalExporter = $this->userWithPermissions([
                'medications.reports.view',
                'medications.reports.export',
                $bypassPermission,
            ], null, ['medications.view']);
            $this->assertFalse($globalExporter->canDo('medications.view'));
            $this->actingAs($globalExporter)
                ->getJson(route('api.medications.reports'))
                ->assertOk();
            $this->actingAs($globalExporter)
                ->get(route('api.medications.reports.export', ['purpose' => 'care']))
                ->assertOk();
        }
    }

    /** @return array<string, mixed> */
    private function controlledAuditContext(): array
    {
        $context = $this->context();
        $recorder = $context['recorder'];
        $historicalMedication = $this->medication(
            $context['local_client'],
            'CONTROLLED HISTORICAL MEDICATION SENTINEL',
            true,
        );
        $historicalAdministration = $this->administration(
            $context['local_client'],
            $historicalMedication,
            $recorder,
            'given',
            'CONTROLLED ADMINISTRATION SENTINEL',
        );
        $controlledEntry = ClientControlledDrugEntry::query()->create([
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $historicalMedication->id,
            'entry_type' => 'receipt',
            'quantity' => '12.50',
            'unit' => 'tablets',
            'on_hand_before' => '1.25',
            'on_hand_after' => '13.75',
            'reason' => 'CONTROLLED REGISTER SENTINEL',
            'recorded_by' => $recorder->id,
            'recorded_at' => now(),
        ]);
        $controlledDiscrepancy = ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $historicalMedication->id,
            'on_hand_before' => '13.75',
            'on_hand_after' => '12.75',
            'difference' => '-1.00',
            'reason' => 'CONTROLLED DISCREPANCY SENTINEL',
            'reported_by' => $recorder->id,
            'reported_at' => now(),
            'status' => 'open',
        ]);
        $controlledDestruction = MedicationDestruction::query()->create([
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $historicalMedication->id,
            'site_id' => $context['local_site']->id,
            'medication_name' => 'CONTROLLED DESTRUCTION SENTINEL',
            'quantity' => '1.25',
            'unit' => 'tablets',
            'reason' => 'expired',
            'disposal_method' => 'denaturing',
            'is_controlled_drug' => true,
            'destroyed_by' => $recorder->id,
            'witness_1_id' => $recorder->id,
            'destroyed_at' => now(),
        ]);
        $controlledPharmacyOrder = MedicationPharmacyOrder::query()->create([
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $historicalMedication->id,
            'pharmacy_name' => 'CONTROLLED PHARMACY SENTINEL',
            'order_type' => 'repeat',
            'status' => 'delivered',
            'ordered_by' => $recorder->id,
            'received_by' => $recorder->id,
            'quantity_ordered' => 12,
            'quantity_received' => '12.50',
            'delivered_at' => now(),
        ]);
        $controlledVersion = MedicationOrderVersion::query()->create([
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $context['local_medication']->id,
            'version_number' => 2,
            'name' => 'CONTROLLED VERSION SNAPSHOT SENTINEL',
            'dosage' => '2 mg',
            'frequency' => 'OD',
            'controlled_drug' => true,
            'changed_by' => $recorder->id,
            'changed_at' => now(),
        ]);

        $forgedAdministration = $this->administration(
            $context['local_client'],
            $context['foreign_medication'],
            $recorder,
            'given',
            'FORGED CROSS CLIENT ADMINISTRATION',
        );
        $foreignControlledAdministration = $this->administration(
            $context['foreign_client'],
            $context['foreign_controlled_medication'],
            $recorder,
            'given',
            'FOREIGN CONTROLLED ADMINISTRATION',
        );
        $forgedSiteDestruction = MedicationDestruction::query()->create([
            'client_id' => $context['local_client']->id,
            'client_medication_id' => $context['scheduled_controlled_medication']->id,
            'site_id' => $context['foreign_site']->id,
            'medication_name' => 'FORGED SITE CONTROLLED DESTRUCTION',
            'quantity' => '1.00',
            'unit' => 'tablets',
            'reason' => 'expired',
            'disposal_method' => 'denaturing',
            'is_controlled_drug' => true,
            'destroyed_by' => $recorder->id,
            'witness_1_id' => $recorder->id,
            'destroyed_at' => now(),
        ]);

        $controlledAuditLogs = [
            $this->auditLogFor($context['local_client'], $context['scheduled_controlled_medication'], $recorder, 'CONTROLLED_MEDICATION_AUDIT'),
            $this->auditLogFor($context['local_client'], $historicalAdministration, $recorder, 'CONTROLLED_ADMINISTRATION_AUDIT'),
            $this->auditLogFor($context['local_client'], $controlledEntry, $recorder, 'CONTROLLED_ENTRY_AUDIT'),
            $this->auditLogFor($context['local_client'], $controlledDiscrepancy, $recorder, 'CONTROLLED_DISCREPANCY_AUDIT'),
        ];
        $forgedAuditLog = $this->auditLogFor(
            $context['local_client'],
            $forgedAdministration,
            $recorder,
            'FORGED_CROSS_CLIENT_AUDIT',
        );
        $foreignAuditLog = $this->auditLogFor(
            $context['foreign_client'],
            $foreignControlledAdministration,
            $recorder,
            'FOREIGN_CONTROLLED_AUDIT',
        );

        DB::table('client_medications')
            ->where('id', $historicalMedication->id)
            ->update(['deleted_at' => now()]);

        return [
            ...$context,
            'controlled_audit_log_ids' => collect($controlledAuditLogs)->pluck('id')->all(),
            'controlled_audit_actions' => collect($controlledAuditLogs)->pluck('action')->all(),
            'forged_audit_log_id' => $forgedAuditLog->id,
            'foreign_audit_log_id' => $foreignAuditLog->id,
            'controlled_event_exports' => [
                'med_start_'.$context['scheduled_controlled_medication']->id => 'ClientMedication #'.$context['scheduled_controlled_medication']->id,
                'admin_'.$historicalAdministration->id => 'ClientMedicationAdministration #'.$historicalAdministration->id,
                'cd_'.$controlledEntry->id => 'ClientControlledDrugEntry #'.$controlledEntry->id,
                'dest_'.$controlledDestruction->id => 'MedicationDestruction #'.$controlledDestruction->id,
                'ver_'.$controlledVersion->id => 'MedicationOrderVersion #'.$controlledVersion->id,
                'stock_recv_'.$controlledPharmacyOrder->id => 'MedicationPharmacyOrder #'.$controlledPharmacyOrder->id,
            ],
            'canonical_excluded_event_ids' => [
                'admin_'.$forgedAdministration->id,
                'admin_'.$foreignControlledAdministration->id,
                'dest_'.$forgedSiteDestruction->id,
            ],
            'controlled_sentinels' => [
                'CONTROLLED HISTORICAL MEDICATION SENTINEL',
                'CONTROLLED ADMINISTRATION SENTINEL',
                'CONTROLLED REGISTER SENTINEL',
                'CONTROLLED DESTRUCTION SENTINEL',
                'CONTROLLED PHARMACY SENTINEL',
                'CONTROLLED VERSION SNAPSHOT SENTINEL',
            ],
        ];
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
        $localControlledMedication = $this->medication($localClient, 'Local controlled medicine', true);
        $foreignControlledMedication = $this->medication($foreignClient, 'Foreign controlled medicine', true);
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
        $this->alert(
            $localClient,
            $localControlledMedication,
            'Local controlled dashboard alert',
            'controlled_discrepancy',
        );
        $this->alert(
            $foreignClient,
            $foreignControlledMedication,
            'Foreign controlled dashboard alert',
            'controlled_loss',
        );
        $this->review($localClient);
        $this->review($foreignClient);
        $this->stock($localMedication);
        $this->stock($foreignMedication);
        $this->stock($localControlledMedication);
        $this->stock($foreignControlledMedication);

        return [
            'local_site' => $localSite,
            'foreign_site' => $foreignSite,
            'local_client' => $localClient,
            'foreign_client' => $foreignClient,
            'local_medication' => $localMedication,
            'foreign_medication' => $foreignMedication,
            'scheduled_controlled_medication' => $localControlledMedication,
            'foreign_controlled_medication' => $foreignControlledMedication,
            'recorder' => $recorder,
            'local_administration' => $localAdministration,
            'foreign_administration' => $foreignAdministration,
            'local_log' => $localLog,
            'foreign_log' => $foreignLog,
            'forged_log' => $forgedLog,
            'local_alert' => $localAlert,
            'foreign_alert' => $foreignAlert,
        ];
    }

    /** Entered at the start of today (NZ): doses due before an order's entry are not owed. */
    private function medication(Client $client, string $name, bool $controlled = false): ClientMedication
    {
        $startDate = today()->subDays(2)->toDateString();
        $now = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::now('Pacific/Auckland')->startOfDay()->utc());
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => $name,
            'controlled_drug' => $controlled,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'is_prn' => false,
            'dose_times' => ['09:00'],
            'start_date' => $startDate,
            'end_date' => null,
        ]);
        Carbon::setTestNow($now);

        return $medication;
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

    private function auditLogFor(
        Client $client,
        Model $auditable,
        User $recorder,
        string $action,
    ): AuditLog {
        return AuditLog::query()->create([
            'client_id' => $client->id,
            'user_id' => $recorder->id,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'action' => $action,
            'meta' => ['fields' => ['controlled_marker']],
        ]);
    }

    private function alert(
        Client $client,
        ClientMedication $medication,
        string $message,
        string $alertType = 'overdue',
    ): MedicationDashboardAlert {
        return MedicationDashboardAlert::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'alert_type' => $alertType,
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
        ?float $adminRate,
        int $dueNow,
        int $activeAlerts,
        int $overdueReviews,
        int $lowStock,
    ): void {
        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $actualRate = $response->inertiaProps('emarWidgets.adminRate');
        $this->assertSame($adminRate, $actualRate === null ? null : (float) $actualRate);
        $this->assertSame($dueNow, $response->inertiaProps('emarWidgets.dueNow'));
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

    /**
     * @param  array<int, string>  $permissions
     * @param  array<int, string>  $deniedPermissions
     */
    private function userWithPermissions(
        array $permissions,
        ?Site $site = null,
        array $deniedPermissions = [],
    ): User {
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
        $deniedPermissions = array_values(array_unique([...$deniedPermissions, ...array_diff([
            'medications.controlled.view', 'medications.audit.view', 'medications.audit.export',
            'medications.reports.view', 'medications.reports.export',
            ...MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
        ], $permissions)]));

        $deniedPermissionIds = Permission::query()->whereIn('key', $deniedPermissions)->pluck('id');
        $this->assertCount(count($deniedPermissions), $deniedPermissionIds, 'Missing denied permission in test setup.');
        $user->permissionOverrides()->syncWithoutDetaching(
            $deniedPermissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all(),
        );

        return $user->refresh();
    }
}
