<?php

namespace Tests\Feature\Compliance;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientSupportPlan;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceDashboardSiteScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_site_bound_dashboard_scopes_operational_metrics_and_pickers_but_keeps_governance_obligations_application_wide(): void
    {
        $visibleSite = Site::factory()->create(['name' => 'Visible Site']);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden Site']);
        $viewer = $this->makeViewer($visibleSite, [
            'compliance.view',
            'governance.compliance.manage',
            'controlRoom.viewAny',
            'audit.viewAny',
        ]);

        $visibleOwner = $this->makeCurrentStaff($visibleSite, ['name' => 'Visible Owner']);
        $hiddenOwner = $this->makeCurrentStaff($hiddenSite, ['name' => 'Hidden Owner']);
        $portalOwner = $this->makeCurrentStaff($visibleSite, [
            'name' => 'Portal Account',
            'role' => 'client',
        ]);
        $unapprovedOwner = $this->makeCurrentStaff($visibleSite, [
            'name' => 'Unapproved Owner',
            'approved_at' => null,
        ]);
        $endedOwner = $this->makeCurrentStaff($visibleSite, ['name' => 'Ended Owner']);
        $endedOwner->hrEmployeeProfile->update([
            'is_active' => false,
            'end_date' => today()->subDay(),
        ]);

        $visibleClient = Client::factory()->create(['site_id' => $visibleSite->id, 'status' => 'active']);
        $hiddenClient = Client::factory()->create(['site_id' => $hiddenSite->id, 'status' => 'active']);

        $visibleIncident = ClientIncident::factory()->submitted()->atSite($visibleSite)->create([
            'client_id' => $visibleClient->id,
            'severity' => 'high',
            'title' => 'Visible incident',
        ]);
        ClientIncident::factory()->submitted()->atSite($hiddenSite)->create([
            'client_id' => $hiddenClient->id,
            'severity' => 'low',
            'title' => 'Hidden incident',
        ]);

        ClientControlledDrugDiscrepancy::create(['client_id' => $visibleClient->id, 'status' => 'open']);
        ClientControlledDrugDiscrepancy::create(['client_id' => $hiddenClient->id, 'status' => 'open']);

        $visibleMedication = ClientMedication::factory()->create(['client_id' => $visibleClient->id]);
        $hiddenMedication = ClientMedication::factory()->create(['client_id' => $hiddenClient->id]);
        ClientMedicationAdministration::create([
            'client_id' => $visibleClient->id,
            'client_medication_id' => $visibleMedication->id,
            'administered_by' => $viewer->id,
            'scheduled_for' => now(),
            'status' => 'missed',
        ]);
        ClientMedicationAdministration::create([
            'client_id' => $hiddenClient->id,
            'client_medication_id' => $hiddenMedication->id,
            'administered_by' => $viewer->id,
            'scheduled_for' => now(),
            'status' => 'refused',
        ]);

        ClientBreakGlassAccess::create([
            'client_id' => $visibleClient->id,
            'user_id' => $viewer->id,
            'reason' => 'Visible emergency access',
        ]);
        ClientBreakGlassAccess::create([
            'client_id' => $hiddenClient->id,
            'user_id' => $viewer->id,
            'reason' => 'Hidden emergency access',
        ]);

        $visibleReview = ClientSupportPlan::create([
            'client_id' => $visibleClient->id,
            'next_review_at' => today()->addDays(5),
        ]);
        ClientSupportPlan::create([
            'client_id' => $hiddenClient->id,
            'next_review_at' => today()->addDays(5),
        ]);

        // Incident/medication observers may bridge their own canonical alerts.
        // Reset those side effects so this assertion is owned by the explicit
        // visible/hidden alert pair below.
        ControlRoomAlert::query()->delete();
        $visibleAlert = ControlRoomAlert::factory()->open()->critical()->create([
            'site_id' => $visibleSite->id,
            'client_id' => $visibleClient->id,
        ]);
        ControlRoomAlert::factory()->open()->create([
            'site_id' => $hiddenSite->id,
            'client_id' => $hiddenClient->id,
        ]);

        $obligation = $this->makeOverdueObligation($hiddenOwner);

        $response = $this->actingAs($viewer)->get('/compliance')->assertOk();
        $props = $response->inertiaProps();
        $kpis = collect($props['kpis'])->keyBy('key');

        $this->assertSame(1, $kpis['incidents']['value']);
        $this->assertSame(1, $kpis['cd']['value']);
        $this->assertSame(1, $kpis['mar']['value']);
        $this->assertSame(1, $kpis['break_glass']['value']);
        $this->assertSame(1, $kpis['obligations']['value']);
        $this->assertSame('/audit-logs', $kpis['audit']['href']);
        $this->assertSame([$visibleReview->id], collect($props['whatsDue']['reviews'])->pluck('id')->all());
        $this->assertContains($obligation->id, collect($props['whatsDue']['obligations'])->pluck('id')->all());
        $this->assertSame(1, $props['controlRoom']['open']);
        $this->assertSame([$visibleAlert->id], collect($props['controlRoom']['recentAlerts'])->pluck('id')->all());
        $this->assertSame(
            [['severity' => 'high', 'total' => 1]],
            $props['charts']['incidentBySeverity'],
        );
        $this->assertSame(1, collect($props['charts']['marTrend'])->sum('missed'));
        $this->assertSame(0, collect($props['charts']['marTrend'])->sum('refused'));
        $this->assertSame(1, collect($props['charts']['cdTrend'])->sum('total'));

        $ownerIds = collect($props['owners'])->pluck('id');
        $this->assertTrue($ownerIds->contains($visibleOwner->id));
        $this->assertFalse($ownerIds->contains($hiddenOwner->id));
        $this->assertFalse($ownerIds->contains($portalOwner->id));
        $this->assertFalse($ownerIds->contains($unapprovedOwner->id));
        $this->assertFalse($ownerIds->contains($endedOwner->id));
        $this->assertSame([$visibleIncident->id], collect($props['relatedIncidents'])->pluck('id')->all());
        $this->assertTrue($props['can']['viewControlRoom']);
        $this->assertTrue($props['can']['viewAudit']);
    }

    public function test_viewer_without_a_current_site_fails_closed_for_operational_data_but_keeps_application_governance(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->makeViewer(null, ['compliance.view', 'governance.compliance.manage']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        ClientIncident::factory()->submitted()->atSite($site)->create(['client_id' => $client->id]);
        ControlRoomAlert::factory()->open()->create(['site_id' => $site->id]);
        $obligation = $this->makeOverdueObligation($viewer);

        $props = $this->actingAs($viewer)->get('/compliance')->assertOk()->inertiaProps();
        $kpis = collect($props['kpis'])->keyBy('key');

        $this->assertSame(0, $kpis['incidents']['value']);
        $this->assertSame(0, $kpis['cd']['value']);
        $this->assertSame(0, $kpis['mar']['value']);
        $this->assertSame(0, $kpis['break_glass']['value']);
        $this->assertSame(1, $kpis['obligations']['value']);
        $this->assertSame(0, $props['controlRoom']['open']);
        $this->assertEmpty($props['whatsDue']['reviews']);
        $this->assertContains($obligation->id, collect($props['whatsDue']['obligations'])->pluck('id')->all());
        $this->assertEmpty($props['owners']);
        $this->assertEmpty($props['relatedIncidents']);
        $this->assertEmpty($props['charts']['incidentBySeverity']);
    }

    public function test_reports_view_any_is_the_explicit_application_reporting_bypass(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $viewer = $this->makeViewer(null, [
            'compliance.view',
            'governance.compliance.manage',
            'reports.viewAny',
            'controlRoom.viewAny',
        ]);
        $ownerA = $this->makeCurrentStaff($siteA);
        $ownerB = $this->makeCurrentStaff($siteB);
        $clientA = Client::factory()->create(['site_id' => $siteA->id]);
        $clientB = Client::factory()->create(['site_id' => $siteB->id]);
        $incidentA = ClientIncident::factory()->submitted()->atSite($siteA)->create(['client_id' => $clientA->id]);
        $incidentB = ClientIncident::factory()->submitted()->atSite($siteB)->create(['client_id' => $clientB->id]);
        ControlRoomAlert::factory()->open()->create(['site_id' => $siteA->id]);
        ControlRoomAlert::factory()->open()->create(['site_id' => $siteB->id]);

        AuditLog::query()->delete();
        AuditLog::create(['action' => 'system.visible-one']);
        AuditLog::create(['action' => 'system.visible-two']);

        $props = $this->actingAs($viewer)->get('/compliance')->assertOk()->inertiaProps();
        $kpis = collect($props['kpis'])->keyBy('key');

        $this->assertSame(2, $kpis['incidents']['value']);
        $this->assertSame(2, $kpis['audit']['value']);
        $this->assertSame(2, $props['controlRoom']['open']);
        $this->assertEqualsCanonicalizing(
            [$ownerA->id, $ownerB->id],
            collect($props['owners'])->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$incidentA->id, $incidentB->id],
            collect($props['relatedIncidents'])->pluck('id')->all(),
        );
    }

    public function test_incident_and_alert_metrics_use_canonical_site_precedence(): void
    {
        $visibleSite = Site::factory()->create();
        $hiddenSite = Site::factory()->create();
        $viewer = $this->makeViewer($visibleSite, [
            'compliance.view',
            'governance.compliance.manage',
            'controlRoom.viewAny',
        ]);
        $visibleClient = Client::factory()->create(['site_id' => $visibleSite->id]);
        $hiddenClient = Client::factory()->create(['site_id' => $hiddenSite->id]);

        ClientIncident::factory()->submitted()->create([
            'site_id' => $hiddenSite->id,
            'client_id' => $visibleClient->id,
            'title' => 'Direct hidden Site wins',
        ]);
        $visibleIncident = ClientIncident::factory()->submitted()->create([
            'site_id' => null,
            'shift_id' => null,
            'client_id' => $visibleClient->id,
            'title' => 'Visible Client fallback',
        ]);
        ClientIncident::factory()->submitted()->create([
            'site_id' => null,
            'shift_id' => null,
            'client_id' => $hiddenClient->id,
            'title' => 'Hidden Client fallback',
        ]);

        ControlRoomAlert::factory()->open()->create([
            'site_id' => $hiddenSite->id,
            'client_id' => $visibleClient->id,
            'context' => ['site_id' => $visibleSite->id],
        ]);
        $clientFallback = ControlRoomAlert::factory()->open()->create([
            'site_id' => null,
            'client_id' => $visibleClient->id,
            'context' => ['site_id' => $hiddenSite->id],
        ]);
        $contextFallback = ControlRoomAlert::factory()->open()->create([
            'site_id' => null,
            'client_id' => null,
            'context' => ['site_id' => $visibleSite->id],
        ]);

        $props = $this->actingAs($viewer)->get('/compliance')->assertOk()->inertiaProps();
        $kpis = collect($props['kpis'])->keyBy('key');

        $this->assertSame(1, $kpis['incidents']['value']);
        $this->assertSame([$visibleIncident->id], collect($props['relatedIncidents'])->pluck('id')->all());
        $this->assertSame(2, $props['controlRoom']['open']);
        $this->assertEqualsCanonicalizing(
            [$clientFallback->id, $contextFallback->id],
            collect($props['controlRoom']['recentAlerts'])->pluck('id')->all(),
        );
    }

    public function test_audit_kpi_is_bounded_to_visible_operational_records_and_global_obligations(): void
    {
        $visibleSite = Site::factory()->create();
        $hiddenSite = Site::factory()->create();
        $viewer = $this->makeViewer($visibleSite, ['compliance.view']);
        $visibleClient = Client::factory()->create(['site_id' => $visibleSite->id]);
        $hiddenClient = Client::factory()->create(['site_id' => $hiddenSite->id]);
        $visibleIncident = ClientIncident::factory()->create(['site_id' => $visibleSite->id, 'client_id' => $visibleClient->id]);
        $hiddenIncident = ClientIncident::factory()->create(['site_id' => $hiddenSite->id, 'client_id' => $hiddenClient->id]);
        $visibleAlert = ControlRoomAlert::factory()->create(['site_id' => $visibleSite->id]);
        $hiddenAlert = ControlRoomAlert::factory()->create(['site_id' => $hiddenSite->id]);
        $obligation = $this->makeOverdueObligation($viewer);

        AuditLog::query()->delete();
        AuditLog::create(['client_id' => $visibleClient->id, 'action' => 'client.visible']);
        AuditLog::create(['client_id' => $hiddenClient->id, 'action' => 'client.hidden']);
        AuditLog::create([
            'action' => 'incident.visible',
            'auditable_type' => $visibleIncident->getMorphClass(),
            'auditable_id' => $visibleIncident->id,
        ]);
        AuditLog::create([
            'action' => 'incident.hidden',
            'auditable_type' => $hiddenIncident->getMorphClass(),
            'auditable_id' => $hiddenIncident->id,
        ]);
        AuditLog::create([
            'action' => 'alert.visible',
            'auditable_type' => $visibleAlert->getMorphClass(),
            'auditable_id' => $visibleAlert->id,
        ]);
        AuditLog::create([
            'action' => 'alert.hidden',
            'auditable_type' => $hiddenAlert->getMorphClass(),
            'auditable_id' => $hiddenAlert->id,
        ]);
        AuditLog::create([
            'action' => 'obligation.global',
            'auditable_type' => $obligation->getMorphClass(),
            'auditable_id' => $obligation->id,
        ]);
        AuditLog::create(['action' => 'system.unattributed']);

        $props = $this->actingAs($viewer)->get('/compliance')->assertOk()->inertiaProps();
        $audit = collect($props['kpis'])->firstWhere('key', 'audit');

        $this->assertSame(4, $audit['value']);
        $this->assertSame(4, array_sum($audit['spark']));
        $this->assertNull($audit['href']);
        $this->assertFalse($props['can']['viewAudit']);
        $this->assertFalse($props['can']['viewControlRoom']);
        $this->assertEmpty($props['controlRoom']['recentAlerts']);
    }

    public function test_compliance_writes_reject_hidden_or_nonstaff_picker_ids_and_accept_visible_targets(): void
    {
        $visibleSite = Site::factory()->create();
        $hiddenSite = Site::factory()->create();
        $viewer = $this->makeViewer($visibleSite, [
            'governance.compliance.view',
            'governance.compliance.manage',
        ]);
        $visibleOwner = $this->makeCurrentStaff($visibleSite);
        $hiddenOwner = $this->makeCurrentStaff($hiddenSite);
        $portalOwner = $this->makeCurrentStaff($visibleSite, ['role' => 'client']);
        $unapprovedOwner = $this->makeCurrentStaff($visibleSite, ['approved_at' => null]);

        foreach ([$hiddenOwner, $portalOwner, $unapprovedOwner] as $forgedOwner) {
            $this->actingAs($viewer)
                ->from('/compliance')
                ->post('/governance/compliance', $this->obligationPayload($forgedOwner->id))
                ->assertRedirect('/compliance')
                ->assertSessionHasErrors('owner_id');
        }
        $this->assertDatabaseCount('compliance_obligations', 0);

        $this->actingAs($viewer)
            ->post('/governance/compliance', $this->obligationPayload($visibleOwner->id))
            ->assertRedirect();
        $obligation = ComplianceObligation::query()->firstOrFail();
        $this->assertSame($visibleOwner->id, $obligation->owner_id);

        $this->actingAs($viewer)
            ->from('/compliance')
            ->put("/governance/compliance/{$obligation->id}", ['owner_id' => $hiddenOwner->id])
            ->assertRedirect('/compliance')
            ->assertSessionHasErrors('owner_id');
        $this->assertSame($visibleOwner->id, $obligation->fresh()->owner_id);

        $visibleClient = Client::factory()->create(['site_id' => $visibleSite->id]);
        $hiddenClient = Client::factory()->create(['site_id' => $hiddenSite->id]);
        $visibleIncident = ClientIncident::factory()->atSite($visibleSite)->create(['client_id' => $visibleClient->id]);
        $hiddenIncident = ClientIncident::factory()->atSite($hiddenSite)->create(['client_id' => $hiddenClient->id]);

        $this->actingAs($viewer)
            ->from('/compliance')
            ->post('/governance/compliance/notifiable-incident', $this->notifiablePayload($hiddenIncident->id))
            ->assertRedirect('/compliance')
            ->assertSessionHasErrors('related_incident_id');
        $this->assertDatabaseCount('notifiable_incidents', 0);

        $this->actingAs($viewer)
            ->post('/governance/compliance/notifiable-incident', $this->notifiablePayload($visibleIncident->id))
            ->assertRedirect();
        $this->assertDatabaseHas('notifiable_incidents', [
            'related_incident_id' => $visibleIncident->id,
            'submitted_by' => $viewer->id,
        ]);
    }

    /** @param array<int, string> $permissions */
    private function makeViewer(?Site $site, array $permissions): User
    {
        $viewer = User::factory()->create([
            'role' => 'coordinator',
            'approved_at' => now(),
        ]);
        $this->grant($viewer, $permissions);

        if ($site) {
            $this->attachCurrentProfile($viewer, $site);
        }

        return $viewer;
    }

    private function makeCurrentStaff(Site $site, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => 'support_worker',
            'approved_at' => now(),
        ], $attributes));
        $this->attachCurrentProfile($user, $site);

        return $user->load('hrEmployeeProfile');
    }

    private function attachCurrentProfile(User $user, Site $site): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    /** @param array<int, string> $keys */
    private function grant(User $user, array $keys): void
    {
        foreach ($keys as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['description' => $key, 'group' => 'compliance', 'module' => 'Compliance'],
            );
            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }
    }

    private function makeOverdueObligation(User $owner): ComplianceObligation
    {
        return ComplianceObligation::create([
            'framework' => 'privacy_act',
            'obligation_code' => 'GLOBAL-'.fake()->unique()->numerify('####'),
            'obligation_title' => 'Application-wide governance obligation',
            'description' => 'This obligation is not owned by a Site.',
            'frequency' => 'annual',
            'due_date' => today()->subDay(),
            'next_due_date' => today()->subDay(),
            'reminder_days' => [30, 14, 7],
            'owner_id' => $owner->id,
            'status' => 'overdue',
            'evidence_required' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function obligationPayload(int $ownerId): array
    {
        return [
            'framework' => 'privacy_act',
            'title' => 'Scoped owner validation',
            'description' => 'Only a current visible staff member may own this obligation.',
            'due_date' => today()->addMonth()->toDateString(),
            'owner_id' => $ownerId,
        ];
    }

    /** @return array<string, mixed> */
    private function notifiablePayload(int $incidentId): array
    {
        return [
            'incident_type' => 'health_safety',
            'notification_authority' => 'worksafe',
            'title' => 'Scoped incident notification',
            'description' => 'The related incident must remain inside canonical Site access.',
            'severity' => 'high',
            'occurred_at' => now()->toIso8601String(),
            'related_incident_id' => $incidentId,
        ];
    }
}
