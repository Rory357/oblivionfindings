<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthSafetyDashboardClientPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create();
        $this->viewer = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->viewer->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true]);
        foreach (['hazards.view' => true, 'healthSafety.viewAllSites' => false, 'clients.viewAny' => false, 'clients.viewAssigned' => false, 'sites.viewAll' => false, 'clinical.accessAllSites' => false] as $key => $allowed) {
            $this->grant($key, $allowed);
        }
    }

    public function test_actual_dashboard_does_not_disclose_same_site_client_names_without_independent_read_permission(): void
    {
        $private = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'SecretPickerFirst', 'last_name' => 'SecretPickerSurname']);
        $this->viewer->assignedClients()->attach($private->id);
        $props = $this->dashboard();
        $this->assertSame([], $props['clients']);
        $this->assertStringNotContainsString('SecretPicker', json_encode($props));
        $this->assertDatabaseHas('clients', ['id' => $private->id]);
    }

    public function test_actual_dashboard_retains_exact_assigned_client_but_excludes_unassigned_and_foreign_site_clients(): void
    {
        $this->grant('clients.viewAssigned', true);
        $visible = Client::factory()->create(['site_id' => $this->site->id]);
        Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'DeniedUnassigned']);
        $foreign = Client::factory()->create(['site_id' => Site::factory()->create()->id, 'first_name' => 'DeniedForeign']);
        $this->viewer->assignedClients()->attach([$visible->id, $foreign->id]);
        $props = $this->dashboard();
        $this->assertSame([$visible->id], array_column($props['clients'], 'id'));
        $this->assertSame($visible->first_name, $props['clients'][0]['first_name']);
        $this->assertSame($visible->last_name, $props['clients'][0]['last_name']);
        $this->assertStringNotContainsString('Denied', json_encode($props['clients']));
    }

    public function test_all_site_hs_authority_does_not_grant_client_read_but_existing_client_site_bypass_is_preserved(): void
    {
        $this->grant('healthSafety.viewAllSites', true);
        $this->grant('clients.viewAny', true);
        $local = Client::factory()->create(['site_id' => $this->site->id]);
        $remote = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
        $this->assertSame([$local->id], array_column($this->dashboard()['clients'], 'id'));
        $this->grant('clinical.accessAllSites', true);
        $this->assertEqualsCanonicalizing([$local->id, $remote->id], array_column($this->dashboard()['clients'], 'id'));
        $filtered = $this->dashboard('?site='.$remote->site_id);
        $this->assertSame([$remote->id], array_column($filtered['clients'], 'id'));
    }

    public function test_cached_permissions_and_assignments_cannot_resurrect_client_picker_identity(): void
    {
        $this->grant('clients.viewAssigned', true);
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->viewer->assignedClients()->attach($client->id);
        $this->viewer->load('permissionOverrides', 'assignedClients', 'hrEmployeeProfile');
        $this->assertSame([$client->id], array_column($this->dashboard()['clients'], 'id'));
        $this->grant('clients.viewAssigned', false);
        $this->assertSame([], $this->dashboard()['clients']);
        $this->grant('clients.viewAssigned', true);
        $this->assertSame([$client->id], array_column($this->dashboard()['clients'], 'id'));
        $this->viewer->assignedClients()->detach($client->id);
        $this->assertSame([], $this->dashboard()['clients']);
        $this->viewer->assignedClients()->attach($client->id);
        $this->assertSame([$client->id], array_column($this->dashboard()['clients'], 'id'));
        User::query()->whereKey($this->viewer->id)->update(['approved_at' => null]);
        // An account that is no longer approved is signed out by main's approval check before any H&S read.
        $this->actingAs($this->viewer)->get('/health-safety')->assertRedirect();
    }

    public function test_current_hr_and_approved_site_revocation_suppress_picker_without_requiring_first_aid_module_authority(): void
    {
        $this->grant('clients.viewAssigned', true);
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->viewer->assignedClients()->attach($client->id);
        $this->viewer->load('hrEmployeeProfile');
        $this->assertSame([$client->id], array_column($this->dashboard()['clients'], 'id'));
        HrEmployeeProfile::query()->where('user_id', $this->viewer->id)->update(['is_active' => false]);
        // H&S data needs current staff (Stephan, 25 Oct): with no current HR profile and no all-Sites authority the
        // overview is refused rather than opened with an empty picker.
        $this->actingAs($this->viewer)->get('/health-safety')->assertForbidden();
        // Existing canonical Client + Site bypasses remain valid even without a current HR profile.
        $this->grant('healthSafety.viewAllSites', true);
        $this->grant('clients.viewAny', true);
        $this->grant('clinical.accessAllSites', true);
        $this->assertSame([$client->id], array_column($this->dashboard()['clients'], 'id'));
        $this->site->update(['is_active' => false]);
        $this->assertSame([], $this->dashboard()['clients']);
    }

    private function dashboard(string $query = ''): array
    {
        return $this->actingAs($this->viewer)->get('/health-safety'.$query)->assertOk()->inertiaProps();
    }

    private function grant(string $key, bool $allowed): void
    {
        $permission = Permission::query()->where('key', $key)->firstOrFail();
        $this->viewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
    }
}
