<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FirstAidRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client profile's recent incidents and First Aid lists (Stephan, 26 Oct: "Yes, narrow them"): each list is
 * read through its own module's rules, so another Site's records and HR-confidential incidents stay out.
 */
class ClientProfileHsListsNarrowingTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $otherSite;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['tenant_id' => 1]);
        $this->otherSite = Site::factory()->create(['tenant_id' => 1]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
    }

    public function test_recent_incidents_leave_out_other_sites_and_hr_confidential_incidents(): void
    {
        $this->incident(['title' => 'VISIBLE-INCIDENT']);
        $this->incident(['title' => 'HR-CONFIDENTIAL-INCIDENT', 'is_hr_confidential' => true]);
        $this->incident(['title' => 'OTHER-SITE-INCIDENT', 'site_id' => $this->otherSite->id]);

        $response = $this->profile($this->viewer(['clients.viewAny', 'incidents.viewAny']), 'client_incidents')->assertOk();

        $this->assertSame(['VISIBLE-INCIDENT'], array_column($response->json('props.client_incidents'), 'title'));
        $this->assertStringNotContainsString('HR-CONFIDENTIAL-INCIDENT', $response->getContent());
        $this->assertStringNotContainsString('OTHER-SITE-INCIDENT', $response->getContent());
    }

    public function test_an_hr_case_reader_still_sees_hr_confidential_incidents(): void
    {
        $this->incident(['title' => 'HR-CONFIDENTIAL-INCIDENT', 'is_hr_confidential' => true]);

        $response = $this->profile($this->viewer(['clients.viewAny', 'incidents.viewAny', 'hr.cases.view']), 'client_incidents')->assertOk();

        $this->assertSame(['HR-CONFIDENTIAL-INCIDENT'], array_column($response->json('props.client_incidents'), 'title'));
    }

    public function test_first_aid_lists_only_treatments_at_the_clients_site_with_links(): void
    {
        $visible = $this->treatment($this->site, 'VISIBLE-TREATMENT');
        $this->treatment($this->otherSite, 'OTHER-SITE-TREATMENT');

        $response = $this->profile($this->viewer(['clients.viewAny', 'hazards.view']), 'first_aid_records')->assertOk();

        $rows = $response->json('props.first_aid_records');
        $this->assertSame([$visible->id], array_column($rows, 'id'));
        $this->assertSame('VISIBLE-TREATMENT', $rows[0]['treatment_given']);
        $this->assertNotEmpty($rows[0]['href']);
        $this->assertStringNotContainsString('OTHER-SITE-TREATMENT', $response->getContent());
    }

    private function incident(array $facts): ClientIncident
    {
        return ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $this->client->id, 'site_id' => $this->site->id, 'occurred_at' => now()->subDay(), ...$facts]));
    }

    private function treatment(Site $site, string $treatment): FirstAidRecord
    {
        return FirstAidRecord::withoutEvents(fn () => FirstAidRecord::factory()->create([
            'site_id' => $site->id, 'treated_person_type' => 'client', 'client_id' => $this->client->id,
            'treatment_given' => $treatment, 'treatment_date' => now()->subDay()]));
    }

    private function viewer(array $permissions): User
    {
        $viewer = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
        $role = Role::query()->create(['name' => 'hs_lists_'.$viewer->id, 'label' => 'H&S lists', 'level' => 20, 'type' => 'custom']);
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id')->all());
        $viewer->roles()->attach($role->id);
        HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);

        return $viewer;
    }

    private function profile(User $viewer, string $prop)
    {
        return $this->actingAs($viewer)->get("/operations/clients/{$this->client->id}", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'operations/clients/show',
            'X-Inertia-Partial-Data' => $prop,
        ]);
    }
}
