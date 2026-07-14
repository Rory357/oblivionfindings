<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * /health-safety/analytics — the trend/root-cause/governance explorer payload,
 * period/site/lens params, drill records + CSV export, and permission gating.
 */
class HsAnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['tenant_id' => 1]);
    }

    protected function hsOfficer(): User
    {
        $user = User::factory()->create([
            'role' => 'health_safety_officer',
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        if ($role = Role::where('name', 'health_safety_officer')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    public function test_analytics_renders_full_payload(): void
    {
        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/analytics')
                ->has('hero_stats.ltifr')
                ->has('hero_stats.trifr')
                ->has('hero_stats.near_miss_ratio')
                ->has('hero_stats.compliance_pct')
                ->has('scorecard.leading')
                ->has('scorecard.lagging')
                ->has('trends')
                ->has('site_comparison')
                ->has('root_cause_data')
                ->has('hazard_data')
                ->has('period_summary.drills_total')
                ->has('worksafe_notifiable.awaiting')
                ->has('hours_meta.source')
                ->has('sites')
                ->where('filters.lens', 'manager')
                ->where('filters.period', 'ytd')
            );
    }

    public function test_root_cause_analytics_uses_investigation_root_causes(): void
    {
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $this->site->id,
        ]);
        $incident = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $this->site->id,
            'occurred_at' => now()->subDay(),
        ]);
        $event = HsEvent::factory()->forClientIncident($incident)->create([
            'reported_at' => now()->subDay(),
        ]);
        HsInvestigation::factory()->completed()->create([
            'hs_event_id' => $event->id,
            'root_causes' => [
                ['category' => 'Procedural gap', 'description' => 'Handover checklist not followed'],
            ],
        ]);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('root_cause_data.0.cause', 'Procedural gap')
                ->where('root_cause_data.0.count', 1)
            );

        $this->actingAs($this->hsOfficer())
            ->getJson('/health-safety/analytics/records?view=incidents&cause=Procedural%20gap')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.7', 'Procedural gap');
    }

    public function test_analytics_echoes_period_site_lens_and_scopes_drills(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/analytics?period=30d&lens=governance&site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.period', '30d')
                ->where('filters.lens', 'governance')
                ->where('filters.site_id', $site->id)
                // drills are a per-site metric — scoped to the single selected site
                ->where('period_summary.drills_total', 1)
            );
    }

    public function test_invalid_lens_falls_back_to_manager(): void
    {
        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/analytics?lens=bogus')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.lens', 'manager'));
    }

    public function test_records_endpoint_returns_json(): void
    {
        $this->actingAs($this->hsOfficer())
            ->getJson('/health-safety/analytics/records?view=incidents')
            ->assertOk()
            ->assertJsonStructure(['name', 'headers', 'rows', 'total']);
    }

    public function test_export_streams_csv(): void
    {
        $response = $this->actingAs($this->hsOfficer())
            ->get('/health-safety/analytics/export?view=incidents');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
    }

    public function test_requires_hazards_view_permission(): void
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        if ($role = Role::where('name', 'support_worker')->first()) {
            $user->roles()->attach($role);
        }

        $this->actingAs($user)->get('/health-safety/analytics')->assertForbidden();
    }
}
