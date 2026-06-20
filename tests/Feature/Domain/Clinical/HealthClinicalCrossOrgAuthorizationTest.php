<?php

namespace Tests\Feature\Domain\Clinical;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Regression coverage for the per-record organization boundary on the
 * Health & Clinical write endpoints.
 *
 * The cross-client register endpoints resolve a client purely from a
 * `client_id` request field. Because there is NO global organization scope on
 * App\Models\Client, the only thing preventing a clinical user in organization
 * A from recording against a client in organization B is the per-record
 * `authorize('view', $client)` call in HealthClinicalDashboardController, which
 * delegates to ClientPolicy::view → ClientPolicy::sharesOrganization().
 *
 * The acting user is a `coordinator` deliberately: that role holds BOTH the
 * clinical recording permissions (so the request passes the route's
 * `permission:clinical.*.record` middleware) AND `clients.viewAny` (so
 * ClientPolicy::view's global branch would otherwise allow any client). That
 * isolates the organization check as the sole reason a cross-org write is
 * rejected — this test fails if that guard is ever removed.
 */
class HealthClinicalCrossOrgAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $clinicalUserOrgA;

    protected Client $clientOrgA;

    protected Client $clientOrgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $coordinatorRole = Role::where('name', 'coordinator')->firstOrFail();

        $this->clinicalUserOrgA = User::factory()->create([
            'role' => 'coordinator',
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        $this->clinicalUserOrgA->roles()->attach($coordinatorRole);

        $this->clientOrgA = Client::factory()->create(['organization_id' => 1]);
        $this->clientOrgB = Client::factory()->create(['organization_id' => 2]);
    }

    public function test_clinical_user_cannot_record_observation_for_client_in_another_organization(): void
    {
        // authorize('view') runs before the detailed input validation, so a bare
        // client_id is enough to reach (and be rejected by) the org guard.
        $this->actingAs($this->clinicalUserOrgA)
            ->post('/health-clinical/observations', ['client_id' => $this->clientOrgB->id])
            ->assertForbidden();

        $this->assertDatabaseMissing('clinical_observations', [
            'client_id' => $this->clientOrgB->id,
        ]);
    }

    public function test_clinical_user_cannot_record_event_for_client_in_another_organization(): void
    {
        $this->actingAs($this->clinicalUserOrgA)
            ->post('/health-clinical/events', ['client_id' => $this->clientOrgB->id])
            ->assertForbidden();

        $this->assertDatabaseMissing('clinical_events', [
            'client_id' => $this->clientOrgB->id,
        ]);
    }

    /**
     * Positive + negative control at the policy level. The controller authz
     * delegates to ClientPolicy::view, so asserting the decision both ways
     * proves the 403s above are the organization guard and that same-org access
     * is not over-blocked.
     */
    public function test_client_policy_allows_same_org_and_denies_cross_org_client_view(): void
    {
        $this->assertTrue(
            Gate::forUser($this->clinicalUserOrgA)->allows('view', $this->clientOrgA),
            'Same-organization client view should be allowed.'
        );

        $this->assertFalse(
            Gate::forUser($this->clinicalUserOrgA)->allows('view', $this->clientOrgB),
            'Cross-organization client view must be denied.'
        );
    }
}
