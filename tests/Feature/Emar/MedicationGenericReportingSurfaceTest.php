<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MedicationGenericReportingSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The /dashboard eMAR widget counts scheduled 09:00 slots on the
        // worker (NZ) day (NF-25): pin the clock after that slot.
        Carbon::setTestNow(Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_generic_reports_and_compliance_conceal_controlled_foreign_and_forged_rows(): void
    {
        $context = $this->reportingContext();
        $ordinary = $this->userWithPermissions([
            'reports.viewAny',
            'medications.view',
            'shifts.manageAny',
            'compliance.view',
        ], $context['site']);

        $administrations = collect($this->actingAs($ordinary)
            ->get(route('reports.modules.show', 'medication_administrations'))
            ->assertOk()
            ->inertiaProps('rows.data'));
        $this->assertSame(
            [$context['ordinary_administration']->id],
            $administrations->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        );

        $administrationCsv = $this->actingAs($ordinary)
            ->get(route('reports.modules.export', 'medication_administrations'))
            ->assertOk()
            ->streamedContent();
        $exportedAdministrationIds = collect(preg_split('/\r?\n/', trim($administrationCsv)))
            ->skip(1)
            ->filter()
            ->map(fn (string $line): int => (int) str_getcsv($line)[0])
            ->values();
        $this->assertSame([$context['ordinary_administration']->id], $exportedAdministrationIds->all());
        foreach ($context['concealed_administration_ids'] as $id) {
            $this->assertFalse($exportedAdministrationIds->contains($id));
        }

        $this->actingAs($ordinary)
            ->get(route('reports.modules.show', 'controlled_drug_discrepancies'))
            ->assertForbidden();
        $this->actingAs($ordinary)
            ->get(route('reports.modules.export', 'controlled_drug_discrepancies'))
            ->assertForbidden();

        $reports = $this->actingAs($ordinary)->get(route('reports.index'))->assertOk();
        $modules = collect($reports->inertiaProps('modules'));
        $this->assertSame(
            1,
            data_get($modules->firstWhere('key', 'medication_administrations'), 'summary.total_records'),
        );
        $this->assertFalse($modules->contains('key', 'controlled_drug_discrepancies'));
        $this->assertSame(1, $reports->inertiaProps('kpis.missedMeds7d'));
        $this->assertNull($reports->inertiaProps('kpis.openDiscrepancies'));

        $combined = $this->actingAs($ordinary)
            ->get(route('reports.combined.show', 'care-quality'))
            ->assertOk();
        $this->assertNotContains(
            'controlled_drug_discrepancies',
            $combined->inertiaProps('report.modules'),
        );
        $this->assertFalse(collect($combined->inertiaProps('metrics'))
            ->contains('label', 'Open controlled discrepancies'));
        $medicationRows = collect($combined->inertiaProps('sections'))
            ->firstWhere('title', 'Recent Medication Exceptions')['rows'];
        $this->assertSame(
            [$context['ordinary_administration']->id],
            collect($medicationRows)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        );

        // Dose numbers come from the dose-slot projection (C6a, P09): every
        // reader counts the same doses, controlled ones included (P09 Q6) —
        // the unrecorded 09:00 doses of the controlled stock medicine, the
        // replacement order and the unverified order (its verified version
        // stays in effect, P01). At 10:00 their windows (to 10:00, inclusive)
        // are still open: due now, none overdue yet. The ordinary and the
        // deleted controlled 09:00 doses are recorded (missed).
        $dashboard = $this->actingAs($ordinary)->get(route('dashboard'))->assertOk();
        $this->assertSame(3, $dashboard->inertiaProps('emarWidgets.dueNow'));
        $this->assertSame(0, $dashboard->inertiaProps('emarWidgets.overdue'));
        $this->assertSame(1, $dashboard->inertiaProps('emarWidgets.lowStock'));

        // The MAR numbers (C6h) count a scheduled dose once its window has
        // ended — at 10:01 — and count controlled doses for every reader (P09
        // Q6): missed 2 (the ordinary and the deleted controlled order's
        // 09:00), not recorded 3 (the controlled stock, replacement and
        // unverified orders' 09:00).
        Carbon::setTestNow(Carbon::parse('2026-05-21 10:01:00', 'Pacific/Auckland')->utc());
        $compliance = $this->actingAs($ordinary)->get(route('compliance.index'))->assertOk();
        $ordinaryKpis = collect($compliance->inertiaProps('kpis'));
        $this->assertFalse($ordinaryKpis->contains('key', 'cd'));
        $this->assertSame(5, $ordinaryKpis->firstWhere('key', 'mar')['value']);
        $this->assertSame([], $compliance->inertiaProps('charts.cdTrend'));
        $this->assertSame(2, collect($compliance->inertiaProps('charts.marTrend'))->sum('missed'));
        $this->assertSame(3, collect($compliance->inertiaProps('charts.marTrend'))->sum('not_recorded'));
        Carbon::setTestNow(Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->utc());

        $generalAudit = AuditLog::query()->create([
            'user_id' => $ordinary->id,
            'client_id' => null,
            'action' => 'focused_general_report_sentinel',
        ]);
        $controlledAudit = AuditLog::query()->create([
            'user_id' => $ordinary->id,
            'client_id' => $context['client']->id,
            'action' => 'focused_neutral_type_sentinel',
            'auditable_type' => ClientMedication::class,
            'auditable_id' => $context['controlled_medication_id'],
        ]);
        $controlledActionAudit = AuditLog::query()->create([
            'user_id' => $ordinary->id,
            'client_id' => $context['client']->id,
            'action' => 'controlled_drug.focused_sentinel',
        ]);
        $auditRows = collect($this->actingAs($ordinary)
            ->get(route('reports.modules.show', [
                'module' => 'audit_logs',
                'search' => 'focused',
            ]))
            ->assertOk()
            ->inertiaProps('rows.data'));
        $auditIds = $auditRows->pluck('id')->map(fn (mixed $id): int => (int) $id);
        $this->assertSame([$generalAudit->id], $auditIds->all());
        $this->assertFalse($auditIds->contains($controlledAudit->id));
        $this->assertFalse($auditIds->contains($controlledActionAudit->id));

        $auditCsv = $this->actingAs($ordinary)
            ->get(route('reports.modules.export', [
                'module' => 'audit_logs',
                'search' => 'focused',
            ]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('focused_general_report_sentinel', $auditCsv);
        $this->assertStringNotContainsString('focused_neutral_type_sentinel', $auditCsv);
        $this->assertStringNotContainsString('controlled_drug.focused_sentinel', $auditCsv);

        $complianceRisk = $this->actingAs($ordinary)
            ->get(route('reports.combined.show', 'compliance-risk'))
            ->assertOk();
        $recentAuditRows = collect($complianceRisk->inertiaProps('sections'))
            ->firstWhere('title', 'Recent Audit Events')['rows'];
        $recentAuditIds = collect($recentAuditRows)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);
        $this->assertTrue($recentAuditIds->contains($generalAudit->id));
        $this->assertFalse($recentAuditIds->contains($controlledAudit->id));
        $this->assertFalse($recentAuditIds->contains($controlledActionAudit->id));

        $controlledReader = $this->userWithPermissions([
            'reports.viewAny',
            'medications.view',
            'medications.controlled.view',
            'shifts.manageAny',
            'compliance.view',
        ], $context['site']);

        $controlledRows = collect($this->actingAs($controlledReader)
            ->get(route('reports.modules.show', 'controlled_drug_discrepancies'))
            ->assertOk()
            ->inertiaProps('rows.data'));
        $this->assertSame(
            [$context['controlled_discrepancy']->id],
            $controlledRows->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        );

        $controlledReports = $this->actingAs($controlledReader)
            ->get(route('reports.index'))
            ->assertOk();
        $controlledModules = collect($controlledReports->inertiaProps('modules'));
        $this->assertSame(
            1,
            data_get($controlledModules->firstWhere('key', 'controlled_drug_discrepancies'), 'summary.total_records'),
        );
        $this->assertSame(2, $controlledReports->inertiaProps('kpis.missedMeds7d'));
        $this->assertSame(1, $controlledReports->inertiaProps('kpis.openDiscrepancies'));

        $controlledDashboard = $this->actingAs($controlledReader)->get(route('dashboard'))->assertOk();
        // The same dose numbers as the ordinary reader (controlled doses count for everyone).
        $this->assertSame(3, $controlledDashboard->inertiaProps('emarWidgets.dueNow'));
        $this->assertSame(2, $controlledDashboard->inertiaProps('emarWidgets.lowStock'));

        // The same MAR numbers as the ordinary reader (P09 Q6).
        Carbon::setTestNow(Carbon::parse('2026-05-21 10:01:00', 'Pacific/Auckland')->utc());
        $controlledCompliance = $this->actingAs($controlledReader)
            ->get(route('compliance.index'))
            ->assertOk();
        $controlledKpis = collect($controlledCompliance->inertiaProps('kpis'));
        $this->assertSame(1, $controlledKpis->firstWhere('key', 'cd')['value']);
        $this->assertSame(5, $controlledKpis->firstWhere('key', 'mar')['value']);
        $this->assertSame(1, collect($controlledCompliance->inertiaProps('charts.cdTrend'))->sum('total'));
        $this->assertSame(2, collect($controlledCompliance->inertiaProps('charts.marTrend'))->sum('missed'));
    }

    /** @return array<string, mixed> */
    private function reportingContext(): array
    {
        $site = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $ordinaryMedication = $this->medication($client, 'Local ordinary medicine');
        $controlledMedication = $this->medication($client, 'Local controlled medicine', true);
        $controlledStockMedication = $this->medication($client, 'Local controlled stock medicine', true);
        $foreignMedication = $this->medication($foreignClient, 'Foreign controlled medicine', true);
        $replacementMedication = $this->medication($client, 'Replacement medicine');
        $unverifiedMedication = $this->medication($client, 'Unverified low stock medicine');
        $unverifiedMedication->forceFill(['approval_status' => 'pending_verification'])->saveQuietly();
        $supersededMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Superseded low stock medicine',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'superseded_by' => $replacementMedication->id,
            // Pinned (the factory picks a random frequency): an evening dose
            // owes nothing at 10:00 either way.
            'dose_times' => ['20:00'],
            'frequency' => '20:00',
            'start_date' => today()->subDay()->toDateString(),
            'end_date' => null,
        ]);
        $recorder = User::factory()->create();

        $ordinaryAdministration = $this->administration($client, $ordinaryMedication, $recorder);
        $controlledAdministration = $this->administration($client, $controlledMedication, $recorder);
        $foreignAdministration = $this->administration($foreignClient, $foreignMedication, $recorder);
        $forgedAdministration = $this->administration($client, $foreignMedication, $recorder);
        $controlledDiscrepancy = $this->discrepancy($client, $controlledMedication, $recorder);
        $foreignDiscrepancy = $this->discrepancy($foreignClient, $foreignMedication, $recorder);
        $forgedDiscrepancy = $this->discrepancy($client, $foreignMedication, $recorder);

        $this->stock($ordinaryMedication);
        $this->stock($controlledStockMedication);
        $this->stock($foreignMedication);
        $this->stock($unverifiedMedication);
        $this->stock($supersededMedication);

        DB::table('client_medications')
            ->where('id', $controlledMedication->id)
            ->update(['deleted_at' => now()]);

        return [
            'site' => $site,
            'client' => $client,
            'controlled_medication_id' => $controlledMedication->id,
            'ordinary_administration' => $ordinaryAdministration,
            'concealed_administration_ids' => [
                $controlledAdministration->id,
                $foreignAdministration->id,
                $forgedAdministration->id,
            ],
            'controlled_discrepancy' => $controlledDiscrepancy,
            'concealed_discrepancy_ids' => [$foreignDiscrepancy->id, $forgedDiscrepancy->id],
        ];
    }

    /** Entered at the start of today (NZ): doses due before an order's entry are not owed. */
    private function medication(Client $client, string $name, bool $controlled = false): ClientMedication
    {
        $startDate = today()->subDay()->toDateString();
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
            'frequency' => '09:00',
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
    ): ClientMedicationAdministration {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'service_context_id' => $client->service_context_id,
            'administered_by' => $recorder->id,
            // On the order's 09:00 slot, so the schedule matches it.
            'scheduled_for' => Carbon::parse('2026-05-21 09:00:00', 'Pacific/Auckland')->utc(),
            'administered_at' => now(),
            'status' => 'missed',
            'dose_given' => '1 tablet',
        ]);
    }

    private function discrepancy(
        Client $client,
        ClientMedication $medication,
        User $recorder,
    ): ClientControlledDrugDiscrepancy {
        return ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'on_hand_before' => '10.00',
            'on_hand_after' => '9.00',
            'difference' => '-1.00',
            'reason' => 'Focused reporting proof',
            'reported_by' => $recorder->id,
            'reported_at' => now(),
            'status' => 'open',
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

    /** @param array<int, string> $permissions */
    private function userWithPermissions(array $permissions, Site $site): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $permissionIds);
        $user->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all(),
        );

        return $user->fresh();
    }
}
