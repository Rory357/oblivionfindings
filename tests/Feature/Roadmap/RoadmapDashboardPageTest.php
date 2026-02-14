<?php

namespace Tests\Feature\Roadmap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class RoadmapDashboardPageTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoadmapModule();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->get('/roadmap/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_board_member_can_open_dashboard_in_gui(): void
    {
        $boardMember = $this->createUserWithRole('board_member');

        $response = $this->actingAs($boardMember)->get('/roadmap/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Roadmap/Dashboard')
            ->where('can.viewDashboard', true)
            ->where('can.viewRoadmap', true)
            ->where('can.viewDecisions', true)
            ->has('summary')
            ->has('triage')
            ->has('auth.can.roadmap')
        );
    }

    public function test_dashboard_still_returns_json_for_api_calls(): void
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

    public function test_support_worker_cannot_open_dashboard(): void
    {
        $worker = $this->createUserWithRole('support_worker');

        $response = $this->actingAs($worker)->get('/roadmap/dashboard');

        $response->assertForbidden();
    }

    public function test_manage_user_receives_manager_assignment_options(): void
    {
        $roadmapManager = $this->createUserWithRole('roadmap_manager', [
            'name' => 'Roadmap Manager',
            'email' => 'roadmap.manager@example.test',
        ]);
        $this->createUserWithRole('it_manager', [
            'name' => 'IT Manager',
            'email' => 'it.manager@example.test',
        ]);
        $this->createUserWithRole('facilities_manager', [
            'name' => 'Facilities Manager',
            'email' => 'facilities.manager@example.test',
        ]);

        $response = $this->actingAs($roadmapManager)->get('/roadmap/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Roadmap/Dashboard')
            ->where('can.manageRoadmap', true)
            ->has('managers', 3)
            ->where('managers.0.name', 'Facilities Manager')
            ->where('managers.1.name', 'IT Manager')
            ->where('managers.2.name', 'Roadmap Manager')
        );
    }

    public function test_non_manage_user_does_not_receive_manager_assignment_options(): void
    {
        $boardMember = $this->createUserWithRole('board_member');
        $this->createUserWithRole('roadmap_manager');

        $response = $this->actingAs($boardMember)->get('/roadmap/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Roadmap/Dashboard')
            ->where('can.manageRoadmap', false)
            ->where('managers', [])
        );
    }
}
