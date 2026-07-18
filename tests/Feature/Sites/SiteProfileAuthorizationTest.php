<?php

namespace Tests\Feature\Sites;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\SiteHazard;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SiteProfileAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        $this->admin->roles()->sync([
            Role::query()->where('name', 'admin')->firstOrFail()->id,
        ]);
        $this->site = Site::factory()->create([
            'tenant_id' => 1,
            'type' => 'house',
            'archived' => false,
        ]);
    }

    public function test_tenant_user_cannot_open_a_foreign_site_profile(): void
    {
        $foreign = Site::factory()->create([
            'tenant_id' => 2,
            'type' => 'house',
        ]);

        $this->actingAs($this->admin)
            ->get(route('sites.show', $foreign))
            ->assertForbidden();
    }

    public function test_tenant_user_cannot_open_an_unscoped_site_but_platform_admin_can(): void
    {
        $unscoped = Site::factory()->create([
            'tenant_id' => null,
            'type' => 'house',
        ]);

        $this->actingAs($this->admin)
            ->get(route('sites.show', $unscoped))
            ->assertForbidden();

        $platformAdmin = User::factory()->create([
            'role' => 'admin',
            'organization_id' => null,
            'approved_at' => now(),
        ]);
        $platformAdmin->roles()->sync([
            Role::query()->where('name', 'admin')->firstOrFail()->id,
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('sites.show', $unscoped))
            ->assertOk();
    }

    public function test_archived_site_profile_is_identified_and_read_only(): void
    {
        $this->site->update([
            'archived' => true,
            'archived_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('sites.show', $this->site))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/show')
                ->where('site.archived', true)
                ->where('hero.status', 'archived')
                ->has('hero.quick_actions', 0)
            );

        $this->partial('peopleData')
            ->assertJsonPath('props.peopleData.clients.can_create', false)
            ->assertJsonPath('props.peopleData.clients.can_place_existing', false)
            ->assertJsonPath('props.peopleData.contacts.can_manage', false)
            ->assertJsonPath('props.peopleData.staff_requirements.can_manage', false);

        $this->partial('operationsData')
            ->assertJsonPath('props.operationsData.meal_planner.locked', true);
    }

    public function test_restricted_deep_links_and_partial_payloads_expose_no_protected_counts(): void
    {
        SiteHazard::query()->create([
            'site_id' => $this->site->id,
            'reported_by_user_id' => $this->admin->id,
            'hazard_type' => 'other',
            'severity' => 'critical',
            'likelihood' => 'likely',
            'description' => 'PROTECTED_HAZARD_SENTINEL',
            'status' => 'open',
        ]);
        SiteCalendarEvent::query()->create([
            'site_id' => $this->site->id,
            'tenant_id' => $this->site->tenant_id,
            'event_type' => 'general',
            'title' => 'PROTECTED_CALENDAR_SENTINEL',
            'start_at' => now()->addDay(),
            'created_by_user_id' => $this->admin->id,
            'owner_user_id' => $this->admin->id,
            'status' => 'approved',
            'approval_status' => 'not_required',
        ]);

        $restricted = $this->restrictedViewer();
        $shell = $this->actingAs($restricted)
            ->get(route('sites.show', [$this->site, 'tab' => 'financials']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/show')
                ->where('permissions', fn ($permissions) => $permissions['finance.dashboard'] === false
                    && $permissions['hazards.view'] === false)
                ->missing('safetyData')
                ->missing('operationsData')
                ->missing('adminData')
            );

        $this->assertStringNotContainsString('PROTECTED_HAZARD_SENTINEL', $shell->getContent());
        $this->assertStringNotContainsString('PROTECTED_CALENDAR_SENTINEL', $shell->getContent());

        $this->actingAs($restricted);
        $safety = $this->partial('safetyData')
            ->assertJsonPath('props.safetyData.locked', true)
            ->assertJsonPath('props.safetyData.hazards.summary', null);
        $this->assertStringNotContainsString('PROTECTED_HAZARD_SENTINEL', $safety->getContent());

        $operations = $this->partial('operationsData')
            ->assertJsonPath('props.operationsData.calendar.locked', true)
            ->assertJsonPath('props.operationsData.calendar.summary', null)
            ->assertJsonPath('props.operationsData.checklists.summary', null)
            ->assertJsonPath('props.operationsData.assets.summary', null)
            ->assertJsonPath('props.operationsData.fleet.summary', null)
            ->assertJsonPath('props.operationsData.hardware.summary', null);
        $this->assertStringNotContainsString('PROTECTED_CALENDAR_SENTINEL', $operations->getContent());

        $this->partial('peopleData')
            ->assertJsonPath('props.peopleData.clients.summary', null)
            ->assertJsonPath('props.peopleData.shift_coverage.summary', null);

        $this->partial('adminData')
            ->assertJsonPath('props.adminData.financials.locked', true)
            ->assertJsonPath('props.adminData.financials.href', null)
            ->assertJsonPath('props.adminData.financials.house_ledger', null)
            ->assertJsonPath('props.adminData.vendors_credentials.locked', true)
            ->assertJsonPath('props.adminData.vendors_credentials.summary', null);
    }

    public function test_active_site_quick_actions_use_the_canonical_workflows(): void
    {
        $this->actingAs($this->admin)
            ->get(route('sites.show', $this->site))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('hero.quick_actions.0.id', 'edit_site')
                ->where('hero.quick_actions.0.href', route('sites.edit', $this->site))
                ->where('hero.quick_actions.1.id', 'add_client')
                ->where('hero.quick_actions.1.href', route('clients.create', ['site_id' => $this->site->id]))
                ->where('hero.quick_actions.2.id', 'add_calendar_event')
                ->where('hero.quick_actions.2.href', route('sites.calendar.index', [$this->site, 'action' => 'create']))
                ->where('hero.quick_actions.3.id', 'report_hazard')
                ->where('hero.quick_actions.3.href', route('sites.hazards.create', $this->site))
            );
    }

    private function restrictedViewer(): User
    {
        $viewer = User::factory()->create([
            'role' => 'viewer',
            'organization_id' => 1,
            'approved_at' => now(),
        ]);

        $grants = Permission::query()
            ->whereIn('key', ['sites.viewAny', 'sites.type.house.view'])
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();
        $viewer->permissionOverrides()->attach($grants);

        return $viewer;
    }

    private function partial(string $group): TestResponse
    {
        return $this->get(
            route('sites.show', $this->site),
            $this->inertiaPartialHeaders('sites/show', $group),
        )->assertOk();
    }
}
