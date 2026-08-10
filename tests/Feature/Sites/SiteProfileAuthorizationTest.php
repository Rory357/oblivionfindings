<?php

namespace Tests\Feature\Sites;

use App\Domain\Hr\Models\HrEmployeeProfile;
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
            'approved_at' => now(),
        ]);
        $this->admin->roles()->sync([
            Role::query()->where('name', 'admin')->firstOrFail()->id,
        ]);
        $this->site = Site::factory()->create([
            'type' => 'house',
            'archived' => false,
        ]);
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

        $this->partial('clientsData,contactsData,staffRequirementsData')
            ->assertJsonPath('props.clientsData.can_create', false)
            ->assertJsonPath('props.clientsData.can_place_existing', false)
            ->assertJsonPath('props.contactsData.can_manage', false)
            ->assertJsonPath('props.staffRequirementsData.can_manage', false);

        $this->partial('mealPlannerData')
            ->assertJsonPath('props.mealPlannerData.locked', true);
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
                ->missing('hazardsData')
                ->missing('calendarData')
                ->missing('financialsData')
            );

        $this->assertStringNotContainsString('PROTECTED_HAZARD_SENTINEL', $shell->getContent());
        $this->assertStringNotContainsString('PROTECTED_CALENDAR_SENTINEL', $shell->getContent());

        $this->actingAs($restricted);
        $safety = $this->partial('hazardsData')
            ->assertJsonPath('props.hazardsData.locked', true)
            ->assertJsonPath('props.hazardsData.summary', null);
        $this->assertStringNotContainsString('PROTECTED_HAZARD_SENTINEL', $safety->getContent());

        $operations = $this->partial('calendarData,checklistsData,assetsData,fleetData,hardwareData')
            ->assertJsonPath('props.calendarData.locked', true)
            ->assertJsonPath('props.calendarData.canCreate', false)
            ->assertJsonPath('props.calendarData.canManage', false)
            ->assertJsonPath('props.calendarData.canApprove', false)
            ->assertJsonMissingPath('props.calendarData.people.0')
            ->assertJsonPath('props.checklistsData.locked', true)
            ->assertJsonMissingPath('props.checklistsData.templates')
            ->assertJsonMissingPath('props.checklistsData.activeRuns')
            ->assertJsonPath('props.assetsData.locked', true)
            ->assertJsonCount(0, 'props.assetsData.items')
            ->assertJsonPath('props.fleetData.locked', true)
            ->assertJsonMissingPath('props.fleetData.vehicles')
            ->assertJsonPath('props.hardwareData.locked', true)
            ->assertJsonMissingPath('props.hardwareData.devices');
        $this->assertStringNotContainsString('PROTECTED_CALENDAR_SENTINEL', $operations->getContent());

        $this->partial('clientsData,shiftCoverageData')
            ->assertJsonPath('props.clientsData.summary', null)
            ->assertJsonPath('props.shiftCoverageData.preview', null);

        $this->partial('financialsData,vendorsCredentialsData')
            ->assertJsonPath('props.financialsData.locked', true)
            ->assertJsonPath('props.financialsData.href', null)
            ->assertJsonPath('props.financialsData.house_ledger', null)
            ->assertJsonPath('props.vendorsCredentialsData.locked', true)
            ->assertJsonPath('props.vendorsCredentialsData.summary', null);
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
            'approved_at' => now(),
        ]);

        $grants = Permission::query()
            ->whereIn('key', ['sites.viewAny', 'sites.type.house.view'])
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();
        $viewer->permissionOverrides()->attach($grants);

        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $viewer->fresh(['hrEmployeeProfile']);
    }

    private function partial(string $props): TestResponse
    {
        return $this->get(
            route('sites.show', $this->site),
            $this->inertiaPartialHeaders('sites/show', $props),
        )->assertOk();
    }
}
