<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supportWorkerA;

    protected User $supportWorkerB;

    protected User $nextOfKin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $supportRole = Role::where('name', 'support_worker')->firstOrFail();
        $nokRole = Role::where('name', 'next_of_kin')->firstOrFail();

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach($adminRole);

        $this->supportWorkerA = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorkerA->roles()->attach($supportRole);

        $this->supportWorkerB = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorkerB->roles()->attach($supportRole);

        $this->nextOfKin = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now()]);
        $this->nextOfKin->roles()->attach($nokRole);
    }

    public function test_unrelated_support_worker_cannot_view_safeguarding_concern(): void
    {
        $concern = SafeguardingConcern::factory()->create([
            'reported_by_user_id' => $this->supportWorkerA->id,
            'assigned_to_user_id' => null,
        ]);

        $this->actingAs($this->supportWorkerB)
            ->get("/safeguarding/{$concern->id}")
            ->assertForbidden();
    }

    public function test_support_worker_cannot_view_other_staff_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'shift_id' => null,
            'user_id' => $this->supportWorkerA->id,
        ]);

        $this->actingAs($this->supportWorkerB)
            ->get("/operations/timesheets/{$timesheet->id}")
            ->assertForbidden();
    }

    public function test_support_worker_cannot_update_other_staff_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'shift_id' => null,
            'user_id' => $this->supportWorkerA->id,
            'status' => 'draft',
            'notes' => 'Original note',
        ]);

        $this->actingAs($this->supportWorkerB)
            ->put("/operations/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->toDateString(),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => $timesheet->break_minutes,
                'notes' => 'Tampered',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'notes' => 'Original note',
        ]);
    }

    public function test_incident_update_cannot_escalate_portal_visibility_without_permission(): void
    {
        $client = Client::factory()->create();
        $client->supportWorkers()->attach($this->supportWorkerA->id);

        $incident = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $this->supportWorkerA->id,
            'status' => 'draft',
            'portal_visible' => false,
        ]);

        // Force a deny override so this user can update incident content
        // but cannot toggle portal visibility.
        $portalManage = Permission::where('key', 'incidents.portal.manage')->firstOrFail();
        $this->supportWorkerA->permissionOverrides()->syncWithoutDetaching([
            $portalManage->id => ['allowed' => false],
        ]);

        $this->actingAs($this->supportWorkerA)
            ->put("/incidents/{$incident->id}", [
                'type' => $incident->type,
                'severity' => $incident->severity,
                'occurred_at' => $incident->occurred_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
                'description' => $incident->description,
                'portal_visible' => true,
            ])
            ->assertRedirect();

        $incident->refresh();
        $this->assertFalse((bool) $incident->portal_visible);
    }

    public function test_portal_attachment_download_denied_for_unlinked_next_of_kin(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        // User is only linked to a different client.
        $this->nextOfKin->portalClients()->attach($clientB->id, ['relation' => 'next_of_kin']);

        $incident = ClientIncident::factory()->create([
            'client_id' => $clientA->id,
            'status' => 'reviewed',
            'reviewed_at' => now(),
            'portal_visible' => true,
        ]);

        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $this->admin->id,
            'original_name' => 'evidence.pdf',
            'path' => 'incidents/evidence.pdf',
            'portal_visible' => true,
            'disk' => 'public',
        ]);

        $this->actingAs($this->nextOfKin)
            ->get("/portal/clients/{$clientA->id}/incidents/{$incident->id}/attachments/{$attachment->id}/download")
            ->assertForbidden();
    }

    public function test_portal_attachment_download_denied_when_incident_not_portal_visible(): void
    {
        $client = Client::factory()->create();
        $this->nextOfKin->portalClients()->attach($client->id, ['relation' => 'next_of_kin']);

        $incident = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'status' => 'reviewed',
            'reviewed_at' => now(),
            'portal_visible' => false,
        ]);

        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $this->admin->id,
            'original_name' => 'restricted.pdf',
            'path' => 'incidents/restricted.pdf',
            'portal_visible' => true,
            'disk' => 'public',
        ]);

        $this->actingAs($this->nextOfKin)
            ->get("/portal/clients/{$client->id}/incidents/{$incident->id}/attachments/{$attachment->id}/download")
            ->assertForbidden();
    }

    // ── Cross-organization isolation (ClientPolicy) ──────────────────────────
    //
    // There is NO global organization scope on the Client model and the client
    // index/controllers lean on `authorize('view', $client)` (and friends) as the
    // per-record tenancy guard. ClientPolicy must therefore confine a
    // `clients.viewAny` manager/admin to their own organization, otherwise it is a
    // cross-org IDOR (org A manager reading/acting on an org B client by id).

    /**
     * A manager (provider_manager holds `clients.viewAny` and is not a support
     * worker) seeded in organization A must NOT be able to open a client that
     * belongs to organization B.
     */
    public function test_manager_cannot_view_client_in_another_organization(): void
    {
        $manager = $this->providerManagerInOrganization(1);
        $clientInOtherOrg = Client::factory()->create(['organization_id' => 2]);

        $this->actingAs($manager)
            ->get(route('clients.show', $clientInOtherOrg))
            ->assertForbidden();
    }

    /**
     * The same manager must still be able to open a client in their OWN
     * organization — proves the tenancy guard does not regress single-org access.
     */
    public function test_manager_can_view_client_in_same_organization(): void
    {
        $manager = $this->providerManagerInOrganization(1);
        $clientInSameOrg = Client::factory()->create(['organization_id' => 1]);

        $this->actingAs($manager)
            ->getJson(route('clients.show', $clientInSameOrg))
            ->assertOk()
            ->assertJsonPath('client.id', $clientInSameOrg->id);
    }

    /**
     * The medications surface is guarded by the separate `viewMedications`
     * ability, which must enforce the same org boundary. `exportCsv` calls
     * `authorize('viewMedications', $client)` directly, so a cross-org manager
     * gets a hard 403 here (unlike the MAR page, which diverts break-glass
     * holders to the emergency-access wizard).
     */
    public function test_manager_cannot_export_medications_for_client_in_another_organization(): void
    {
        $manager = $this->providerManagerInOrganization(1);
        $clientInOtherOrg = Client::factory()->create(['organization_id' => 2]);

        $this->actingAs($manager)
            ->get("/clients/{$clientInOtherOrg->id}/mar/export.csv")
            ->assertForbidden();
    }

    /**
     * Policy-level proof across every "global" branch (view / viewMedications /
     * update). Same org → allowed, different org → denied, and a null org on
     * either side stays permissive so single-tenant and "lighter schema"
     * deployments (where the column may be unset) are unaffected.
     */
    public function test_client_policy_isolates_organizations_across_global_branches(): void
    {
        $manager = $this->providerManagerInOrganization(1);

        $sameOrg = Client::factory()->create(['organization_id' => 1]);
        $otherOrg = Client::factory()->create(['organization_id' => 2]);
        $nullOrg = Client::factory()->create(['organization_id' => null]);

        foreach (['view', 'viewMedications', 'update'] as $ability) {
            $this->assertTrue(
                $manager->can($ability, $sameOrg),
                "Manager should be allowed to {$ability} a client in their own organization."
            );
            $this->assertFalse(
                $manager->can($ability, $otherOrg),
                "Manager must NOT be allowed to {$ability} a client in another organization."
            );
            $this->assertTrue(
                $manager->can($ability, $nullOrg),
                "A null organization must stay permissive for {$ability} (single-tenant safety)."
            );
        }
    }

    private function providerManagerInOrganization(int $organizationId): User
    {
        $role = Role::where('name', 'provider_manager')->firstOrFail();

        $manager = User::factory()->create([
            'role' => 'provider_manager',
            'organization_id' => $organizationId,
            'approved_at' => now(),
        ]);
        $manager->roles()->attach($role);

        return $manager;
    }
}
