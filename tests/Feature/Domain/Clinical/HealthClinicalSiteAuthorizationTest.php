<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Regression coverage for canonical Site access on Health & Clinical writes.
 *
 * The support worker has the required clinical permissions and is linked to
 * both Clients, but is current staff at only one Site. Direct writes must still
 * be rejected at the other Site, proving assignment never replaces Site access.
 */
class HealthClinicalSiteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $clinicalUser;

    protected Client $visibleClient;

    protected Client $outsideClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $supportWorkerRole = Role::where('name', 'support_worker')->firstOrFail();

        $visibleSite = Site::factory()->create(['is_active' => true]);
        $outsideSite = Site::factory()->create(['is_active' => true]);

        $this->clinicalUser = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->clinicalUser->roles()->attach($supportWorkerRole);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->clinicalUser->id,
            'primary_site_id' => $visibleSite->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        $this->visibleClient = Client::factory()->create(['site_id' => $visibleSite->id]);
        $this->outsideClient = Client::factory()->create(['site_id' => $outsideSite->id]);
        $this->visibleClient->supportWorkers()->attach($this->clinicalUser->id);
        $this->outsideClient->supportWorkers()->attach($this->clinicalUser->id);
    }

    public function test_clinical_user_cannot_record_observation_for_client_at_another_site(): void
    {
        $this->actingAs($this->clinicalUser)
            ->post('/health-clinical/observations', ['client_id' => $this->outsideClient->id])
            ->assertForbidden();

        $this->assertDatabaseMissing('clinical_observations', [
            'client_id' => $this->outsideClient->id,
        ]);
    }

    public function test_clinical_user_cannot_record_event_for_client_at_another_site(): void
    {
        $this->actingAs($this->clinicalUser)
            ->post('/health-clinical/events', ['client_id' => $this->outsideClient->id])
            ->assertForbidden();

        $this->assertDatabaseMissing('clinical_events', [
            'client_id' => $this->outsideClient->id,
        ]);
    }

    public function test_client_policy_allows_assigned_site_and_denies_outside_site_client_view(): void
    {
        $this->assertTrue(
            Gate::forUser($this->clinicalUser)->allows('view', $this->visibleClient),
            'Client view should be allowed at the employee current Site.'
        );

        $this->assertFalse(
            Gate::forUser($this->clinicalUser)->allows('view', $this->outsideClient),
            'Client view outside the employee current Sites must be denied.'
        );
    }
}
