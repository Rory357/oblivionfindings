<?php

namespace Tests\Feature\Safeguarding;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SafeguardingConcernCanonicalScopeTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;
    private Site $siteB;
    private User $userA;
    private User $userMulti;
    private Client $clientA;
    private Client $clientA2;
    private Client $clientB;
    private ClientIncident $incidentA;
    private ClientIncident $incidentA2;
    private ClientIncident $incidentB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->siteA = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->siteB = Site::factory()->create(['type' => 'house', 'is_active' => true]);

        $perms = Permission::query()->whereIn('key', ['safeguarding.create', 'safeguarding.viewAny', 'safeguarding.view', 'safeguarding.manage'])->pluck('id');
        $role = Role::query()->where('name', 'team_lead')->first();

        // User A: only site A access
        $this->userA = User::factory()->create([
            'approved_at' => now(),
            'role' => 'team_lead',
        ]);
        if ($role) {
            $this->userA->roles()->attach($role);
        }
        $this->userA->permissionOverrides()->syncWithoutDetaching(
            $perms->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->userA->id,
            'primary_site_id' => $this->siteA->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subYear(),
            'is_active' => true,
        ]);

        // Multi-site user: access to site A and site B
        $this->userMulti = User::factory()->create([
            'approved_at' => now(),
            'role' => 'team_lead',
        ]);
        if ($role) {
            $this->userMulti->roles()->attach($role);
        }
        $this->userMulti->permissionOverrides()->syncWithoutDetaching(
            $perms->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->userMulti->id,
            'primary_site_id' => $this->siteA->id,
            'secondary_site_ids' => [$this->siteB->id],
            'start_date' => now()->subYear(),
            'is_active' => true,
        ]);

        $this->clientA = Client::factory()->create(['site_id' => $this->siteA->id]);
        $this->clientA2 = Client::factory()->create(['site_id' => $this->siteA->id]);
        $this->clientB = Client::factory()->create(['site_id' => $this->siteB->id]);

        $this->incidentA = ClientIncident::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
        ]);
        $this->incidentA2 = ClientIncident::factory()->create([
            'client_id' => $this->clientA2->id,
            'site_id' => $this->siteA->id,
        ]);
        $this->incidentB = ClientIncident::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
        ]);
    }

    public function test_rejects_intake_when_client_does_not_belong_to_submitted_site_for_multisite_user(): void
    {
        $response = $this->actingAs($this->userMulti)->post('/safeguarding', [
            'site_id' => $this->siteA->id,
            'subject_type' => 'client',
            'subject_id' => $this->clientB->id, // clientB belongs to siteB!
            'concern_type' => 'neglect',
            'severity' => 'medium',
            'description' => 'Mismatched client and site test.',
        ]);

        $response->assertSessionHasErrors('subject_id');
    }

    public function test_rejects_intake_when_client_belongs_to_foreign_site_unauthorized(): void
    {
        $response = $this->actingAs($this->userA)->post('/safeguarding', [
            'site_id' => $this->siteA->id,
            'subject_type' => 'client',
            'subject_id' => $this->clientB->id, // userA has no access to clientB!
            'concern_type' => 'neglect',
            'severity' => 'medium',
            'description' => 'Unauthorized foreign client test.',
        ]);

        $response->assertForbidden();
    }

    public function test_rejects_intake_when_related_incident_belongs_to_different_client(): void
    {
        $response = $this->actingAs($this->userA)->post('/safeguarding', [
            'site_id' => $this->siteA->id,
            'subject_type' => 'client',
            'subject_id' => $this->clientA->id,
            'related_incident_id' => $this->incidentA2->id, // incidentA2 belongs to clientA2, not clientA!
            'concern_type' => 'neglect',
            'severity' => 'medium',
            'description' => 'Mismatched incident test.',
        ]);

        $response->assertSessionHasErrors('related_incident_id');
    }

    public function test_rejects_intake_when_related_incident_belongs_to_unauthorized_site(): void
    {
        $response = $this->actingAs($this->userA)->post('/safeguarding', [
            'site_id' => $this->siteA->id,
            'subject_type' => 'client',
            'subject_id' => $this->clientA->id,
            'related_incident_id' => $this->incidentB->id, // incidentB belongs to siteB!
            'concern_type' => 'neglect',
            'severity' => 'medium',
            'description' => 'Foreign incident test.',
        ]);

        $response->assertForbidden();
    }

    public function test_rejects_intake_for_foreign_site_actor_cannot_access(): void
    {
        $response = $this->actingAs($this->userA)->post('/safeguarding', [
            'site_id' => $this->siteB->id, // userA only has siteA access!
            'subject_type' => 'client',
            'subject_id' => $this->clientB->id,
            'concern_type' => 'neglect',
            'severity' => 'medium',
            'description' => 'Foreign site test.',
        ]);

        $response->assertForbidden();
    }

    public function test_intake_succeeds_and_reconciles_site_when_omitted(): void
    {
        $response = $this->actingAs($this->userA)->post('/safeguarding', [
            // site_id omitted
            'subject_type' => 'client',
            'subject_id' => $this->clientA->id,
            'concern_type' => 'physical_abuse',
            'severity' => 'high',
            'description' => 'Canonical site inference test.',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('safeguarding_concerns', [
            'subject_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'concern_type' => 'physical_abuse',
        ]);
    }

    public function test_intake_succeeds_with_reconciled_client_and_incident_at_approved_site(): void
    {
        $response = $this->actingAs($this->userA)->post('/safeguarding', [
            'site_id' => $this->siteA->id,
            'subject_type' => 'client',
            'subject_id' => $this->clientA->id,
            'related_incident_id' => $this->incidentA->id,
            'concern_type' => 'financial_abuse',
            'severity' => 'critical',
            'description' => 'Fully reconciled intake test.',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('safeguarding_concerns', [
            'subject_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'related_incident_id' => $this->incidentA->id,
            'concern_type' => 'financial_abuse',
        ]);
    }
}
