<?php

namespace Tests\Feature\Governance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceDashboardTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->get('/governance/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_dashboard_renders_for_admin(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/governance/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Dashboard')
        );
    }

    public function test_dashboard_data_endpoint_returns_snapshot(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/governance/dashboard/data?period=month');

        $response->assertOk();
        $response->assertJsonStructure([
            'snapshot_id',
            'period' => ['type', 'start', 'end'],
            'widgets',
        ]);
    }
}
