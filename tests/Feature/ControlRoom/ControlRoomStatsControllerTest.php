<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Factories\ControlRoomAlertFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomStatsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create([
            'organization_id' => 1,
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
        ]);
    }

    public function test_stats_requires_authentication(): void
    {
        $this->get('/control-room/stats')->assertRedirect('/login');
    }

    public function test_stats_blocked_for_user_without_permission(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'approved_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/control-room/stats')
            ->assertForbidden();
    }

    public function test_stats_renders_inertia_page_with_kpis(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/stats')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/stats')
                ->has('kpis')
                ->has('volume_trend')
                ->has('top_sources')
                ->has('top_alert_types')
                ->has('severity_distribution')
                ->has('operator_performance')
                ->has('shift_comparison')
                ->has('signal_sources')
                ->where('period', '7d')
            );
    }

    public function test_stats_accepts_24h_period(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/stats?period=24h')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('period', '24h'));
    }

    public function test_stats_falls_back_to_default_for_invalid_period(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/stats?period=bogus')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('period', '7d'));
    }

    public function test_stats_aggregates_open_alerts(): void
    {
        $this->alertFactory()->open()->count(3)->create();
        $this->alertFactory()->resolved()->count(2)->create();

        $this->actingAs($this->admin)
            ->get('/control-room/stats')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.open_alerts', 3)
            );
    }

    private function alertFactory(): ControlRoomAlertFactory
    {
        return ControlRoomAlert::factory()->state([
            'site_id' => $this->site->id,
        ]);
    }
}
