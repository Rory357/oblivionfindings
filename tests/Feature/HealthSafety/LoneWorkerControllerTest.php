<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\LoneWorkerSession;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftGpsLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lone Worker Safety redesign — controller coverage (hero/tabs/detail/filters,
 * shift link + GPS prefill, lifecycle endpoints, sessions.update gating).
 */
class LoneWorkerControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    private function makeSession(array $overrides = []): LoneWorkerSession
    {
        return LoneWorkerSession::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'site_id' => Site::factory()->create()->id,
            'started_at' => now()->subHour(),
            'expected_end_at' => now()->addHours(2),
            'last_check_in_at' => now()->subMinutes(10),
            'check_in_interval_minutes' => 30,
            'status' => 'active',
            'activity_description' => 'Home visit',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $overrides));
    }

    public function test_index_renders_hero_tabcounts_and_can(): void
    {
        $this->makeSession(['status' => 'active']);
        $this->makeSession(['status' => 'overdue']);

        $this->actingAs($this->admin)
            ->get('/health-safety/lone-workers')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('health-safety/lone-workers/index')
                ->where('tab', 'sessions')
                ->where('tabCounts.sessions', 2)
                ->where('can.manage', true)
                ->has('hero.clusters.live')
                ->has('hero.badges')
                ->has('options.shifts'));
    }

    public function test_start_session_links_shift_and_prefills_gps(): void
    {
        $worker = User::factory()->create();
        $site = Site::factory()->create();
        $shift = Shift::factory()->create([
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'ends_at' => now()->addHours(3),
        ]);
        ShiftGpsLog::create([
            'organization_id' => 1,
            'shift_id' => $shift->id,
            'user_id' => $worker->id,
            'event_type' => 'ping',
            'latitude' => -37.6878,
            'longitude' => 176.1651,
            'captured_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($this->admin)
            ->from('/health-safety/lone-workers')
            ->post('/health-safety/lone-workers/sessions', [
                'user_id' => $worker->id,
                'site_id' => $site->id,
                'shift_id' => $shift->id,
                'expected_end_at' => now()->addHours(3)->toDateTimeString(),
                'check_in_interval_minutes' => 30,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $session = LoneWorkerSession::latest('id')->first();
        $this->assertSame($shift->id, $session->shift_id);
        // GPS prefill from the shift's last ping.
        $this->assertEqualsWithDelta(-37.6878, (float) $session->location_lat, 0.0001);
        $this->assertEqualsWithDelta(176.1651, (float) $session->location_lng, 0.0001);
    }

    public function test_check_in_emergency_flips_status_and_emits_signal(): void
    {
        $session = $this->makeSession();

        $this->actingAs($this->admin)
            ->from('/health-safety/lone-workers')
            ->post("/health-safety/lone-workers/sessions/{$session->id}/check-in", [
                'status' => 'emergency',
                'notes' => 'No response',
            ])
            ->assertRedirect();

        $session->refresh();
        $this->assertSame('emergency', $session->status);
        $this->assertNotNull($session->emergency_triggered_at);
        $this->assertSame(1, $session->checkIns()->where('status', 'emergency')->count());
        // Canonical alert raised via the signal pipeline.
        $this->assertTrue(ControlRoomAlert::where('source', 'lone_worker')->exists());
    }

    public function test_update_session_extends_and_clears_overdue(): void
    {
        $session = $this->makeSession(['status' => 'overdue', 'expected_end_at' => now()->subMinutes(10)]);
        $newEnd = now()->addHours(2);

        $this->actingAs($this->admin)
            ->from('/health-safety/lone-workers')
            ->patch("/health-safety/lone-workers/sessions/{$session->id}", [
                'expected_end_at' => $newEnd->toDateTimeString(),
                'check_in_interval_minutes' => 60,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $session->refresh();
        $this->assertSame('active', $session->status);
        $this->assertSame(60, $session->check_in_interval_minutes);
        $this->assertEqualsWithDelta($newEnd->timestamp, $session->expected_end_at->timestamp, 5);
    }

    public function test_update_session_rejected_when_completed(): void
    {
        $session = $this->makeSession(['status' => 'completed', 'ended_at' => now()->subHour()]);

        $this->actingAs($this->admin)
            ->from('/health-safety/lone-workers')
            ->patch("/health-safety/lone-workers/sessions/{$session->id}", [
                'expected_end_at' => now()->addHour()->toDateTimeString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_end_session_marks_completed(): void
    {
        $session = $this->makeSession();

        $this->actingAs($this->admin)
            ->from('/health-safety/lone-workers')
            ->post("/health-safety/lone-workers/sessions/{$session->id}/end")
            ->assertRedirect();

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertNotNull($session->ended_at);
    }

    public function test_session_detail_hydrates_check_ins(): void
    {
        $session = $this->makeSession();
        $session->checkIns()->create(['checked_in_at' => now()->subMinutes(20), 'status' => 'ok']);
        $session->checkIns()->create(['checked_in_at' => now()->subMinutes(5), 'status' => 'ok']);

        $this->actingAs($this->admin)
            ->get("/health-safety/lone-workers?session={$session->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('detail._type', 'session')
                ->where('detail.id', $session->id)
                ->has('detail.check_ins', 2));
    }

    public function test_index_q_filter_matches_activity(): void
    {
        $this->makeSession(['activity_description' => 'Night lock-up at depot']);
        $this->makeSession(['activity_description' => 'Medication support visit']);

        $this->actingAs($this->admin)
            ->get('/health-safety/lone-workers?q=lock-up')
            ->assertInertia(fn (Assert $p) => $p->has('sessions.data', 1));
    }

    public function test_alerts_tab_paginates_canonical_lone_worker_alerts(): void
    {
        ControlRoomAlert::factory()->create([
            'source' => 'lone_worker',
            'alert_type' => 'lone_worker_emergency',
            'status' => 'open',
            'triggered_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get('/health-safety/lone-workers?tab=alerts')
            ->assertInertia(fn (Assert $p) => $p
                ->where('tab', 'alerts')
                ->has('alerts.data', 1)
                ->where('alerts.data.0.source', 'control_room'));
    }
}
