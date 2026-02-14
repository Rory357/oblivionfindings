<?php

namespace Tests\Feature\Roadmap;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class RoadmapPermissionsTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoadmapModule();
    }

    public function test_support_worker_cannot_view_roadmap_initiatives(): void
    {
        $worker = $this->createUserWithRole('support_worker');

        $response = $this->actingAs($worker)->getJson('/roadmap/initiatives');

        $response->assertForbidden();
    }

    public function test_board_member_can_view_roadmap_dashboard_via_governance_permission(): void
    {
        $boardMember = $this->createUserWithRole('board_member');

        $response = $this->actingAs($boardMember)->getJson('/roadmap/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'summary',
            'triage' => ['pending', 'overload'],
            'generated_at',
        ]);
    }

    public function test_board_member_cannot_create_initiative_without_roadmap_manage_permission(): void
    {
        $boardMember = $this->createUserWithRole('board_member');

        $response = $this->actingAs($boardMember)->postJson('/roadmap/initiatives', [
            'title' => 'Should be denied',
        ]);

        $response->assertForbidden();
    }

    public function test_roadmap_operational_roles_are_seeded_with_expected_permissions(): void
    {
        $this->assertTrue(Role::where('name', 'roadmap_manager')->exists());
        $this->assertTrue(Role::where('name', 'it_manager')->exists());
        $this->assertTrue(Role::where('name', 'facilities_manager')->exists());
        $this->assertTrue(Role::where('name', 'ceo')->exists());
        $this->assertTrue(Role::where('name', 'cfo')->exists());
        $this->assertTrue(Role::where('name', 'coo')->exists());
        $this->assertTrue(Role::where('name', 'compliance_lead')->exists());
        $this->assertTrue(Role::where('name', 'risk_lead')->exists());

        $this->assertTrue(
            Role::where('name', 'roadmap_manager')->firstOrFail()
                ->permissions()
                ->where('key', 'roadmap.manage')
                ->exists()
        );
        $this->assertTrue(
            Role::where('name', 'it_manager')->firstOrFail()
                ->permissions()
                ->where('key', 'roadmap.manage')
                ->exists()
        );
        $this->assertTrue(
            Role::where('name', 'facilities_manager')->firstOrFail()
                ->permissions()
                ->where('key', 'roadmap.budget.manage')
                ->exists()
        );
        $this->assertTrue(
            Role::where('name', 'ceo')->firstOrFail()
                ->permissions()
                ->where('key', 'roadmap.approve')
                ->exists()
        );
        $this->assertTrue(
            Role::where('name', 'cfo')->firstOrFail()
                ->permissions()
                ->where('key', 'roadmap.budget.manage')
                ->exists()
        );
    }

    public function test_roadmap_roles_are_visible_in_access_control(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get('/settings/access')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/access')
                ->where('roles', fn ($roles) => collect($roles)->pluck('name')->intersect([
                    'roadmap_manager',
                    'it_manager',
                    'facilities_manager',
                ])->count() === 3)
            );
    }
}
