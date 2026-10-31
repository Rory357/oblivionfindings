<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HsWorkspaceNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(GovernancePermissionsSeeder::class);
    }

    public function test_register_directory_accepts_the_exact_specialist_permissions(): void
    {
        foreach (['hazards.view', 'restraints.view', 'hr.wellbeing.view'] as $permission) {
            $this->actingAs($this->viewer([$permission]))->get('/health-safety/registers')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('health-safety/registers/index'));
        }
        foreach (['compliance.view', 'procedures.view', 'incidents.create', 'reports.viewAny'] as $permission) {
            $this->actingAs($this->viewer([$permission]))->get('/health-safety/registers')->assertForbidden();
        }
    }

    public function test_report_directory_is_not_report_authority(): void
    {
        foreach (['hazards.view', 'compliance.view', 'privacy.viewRequests'] as $permission) {
            $this->actingAs($this->viewer([$permission]))->get('/health-safety/reports')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('health-safety/reports/index')->where('report', null));
            foreach (['board', 'notifications', 'investigation-outcomes', 'action-traceability', 'risk-register'] as $view) {
                $this->get('/health-safety/reports?view='.$view)->assertForbidden();
            }
        }
        $this->actingAs($this->viewer(['incidents.create']))->get('/health-safety/reports')->assertForbidden();
    }

    public function test_every_assurance_view_renders_and_uses_the_existing_site_scoped_report(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->viewer(['governance.view'], $site);
        $local = HsEvent::factory()->create(['site_id' => $site->id, 'worksafe_notifiable' => true]);
        $remote = HsEvent::factory()->create(['site_id' => $otherSite->id, 'worksafe_notifiable' => true]);
        $this->actingAs($viewer);
        foreach (['board' => 'board-summary', 'notifications' => 'worksafe-register', 'investigation-outcomes' => 'investigation-outcomes', 'action-traceability' => 'corrective-action-traceability', 'risk-register' => 'risk-assessment-register'] as $view => $api) {
            $this->get('/health-safety/reports?view='.$view)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/reports/index')->where('view', $view)->has('report'));
            $this->get('/health-safety/reports/'.$api)->assertOk()->assertJsonIsObject();
            $this->get('/health-safety/reports?view='.$view.'&site_id='.$otherSite->id)->assertForbidden();
        }
        $this->get('/health-safety/reports?view=notifications')->assertInertia(fn (Assert $page) => $page
            ->has('report.items', 1)->where('report.items.0.reference_number', $local->reference_number)
            ->where('report.items', fn ($items) => ! collect($items)->contains('reference_number', $remote->reference_number)));
    }

    public function test_invalid_report_views_and_dates_are_rejected_without_running_a_report(): void
    {
        $this->actingAs($this->viewer(['governance.view']))->getJson('/health-safety/reports?view=missing')->assertUnprocessable();
        $this->getJson('/health-safety/reports?view=board&from=not-a-date')->assertUnprocessable();
        $this->getJson('/health-safety/reports?view=board&from=2026-10-08&to=2026-10-01')->assertUnprocessable();
        $this->get('/health-safety/reports?view=board&to=2026-10-08')->assertOk();
    }

    private function viewer(array $permissions, ?Site $site = null): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        // Clear role defaults so each navigation case really proves one grant.
        $user->roles()->detach();
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? Site::factory()->create())->id, 'secondary_site_ids' => []]);
        foreach ($permissions as $key) {
            $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => true]]);
        }

        return $user->fresh();
    }
}
