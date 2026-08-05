<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AttendanceService;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\LoneWorkerSession;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftGpsLog;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
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

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create();
    }

    private function makeSession(array $overrides = []): LoneWorkerSession
    {
        $worker = $this->sessionWorker();

        return LoneWorkerSession::create(array_merge([
            'user_id' => $worker->id,
            'site_id' => $this->site->id,
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
        $worker = $this->supportWorker();
        $site = $this->site;
        $client = Client::factory()->create(['site_id' => $site->id]);
        $shift = Shift::factory()->create([
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subHour(),
            'started_by' => $worker->id,
            'status' => 'in_progress',
        ]);
        ShiftGpsLog::create([
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
                'client_id' => $client->id,
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
        $this->assertSame(0, $session->alerts()->count());
        $this->assertSame(1, ControlRoomAlert::where('source', 'lone_worker')->count());
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

    public function test_destroy_soft_deletes_completed_session_and_drops_it_from_the_register(): void
    {
        $session = $this->makeSession(['status' => 'completed', 'ended_at' => now()->subMinutes(5)]);

        $this->actingAs($this->admin)
            ->from('/health-safety/lone-workers')
            ->delete("/health-safety/lone-workers/sessions/{$session->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        // Soft-delete retains the row for audit (not a hard delete) ...
        $this->assertSoftDeleted($session);
        $this->assertNotNull(LoneWorkerSession::withTrashed()->find($session->id));

        // ... and the removed session no longer appears in the register.
        $this->actingAs($this->admin)
            ->get('/health-safety/lone-workers?period=all')
            ->assertInertia(fn (Assert $p) => $p->has('sessions.data', 0));
    }

    public function test_destroy_rejected_for_a_live_session(): void
    {
        $session = $this->makeSession(['status' => 'active']);

        $this->actingAs($this->admin)
            ->from('/health-safety/lone-workers')
            ->delete("/health-safety/lone-workers/sessions/{$session->id}")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($session);
    }

    public function test_destroy_requires_manage_permission(): void
    {
        $worker = $this->supportWorker();
        $session = $this->makeSession(['status' => 'completed', 'ended_at' => now()->subMinutes(5)]);

        $this->actingAs($worker)
            ->from('/health-safety/lone-workers')
            ->delete("/health-safety/lone-workers/sessions/{$session->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted($session);
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
            'site_id' => $this->site->id,
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

    /* ── Worker self check-in (the My Day cross-module half) ─────────────── */

    /**
     * A frontline support worker (no hazards.manage) must be able to check into
     * THEIR OWN session — this is the whole point of the My Day card. The route
     * is auth-only; checkIn() authorizes the session's own worker.
     */
    public function test_worker_without_manage_can_check_into_own_session(): void
    {
        $worker = $this->supportWorker();
        $this->assertFalse($worker->fresh()->canDo('hazards.manage'));

        $session = $this->makeSession(['user_id' => $worker->id, 'status' => 'active']);

        $this->actingAs($worker)
            ->from('/my-day')
            ->post("/health-safety/lone-workers/sessions/{$session->id}/check-in", [
                'status' => 'ok',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $session->checkIns()->where('status', 'ok')->count());
        $session->refresh();
        $this->assertSame('active', $session->status);
        $this->assertNotNull($session->last_check_in_at);
    }

    /**
     * The same worker must NOT be able to check into someone else's session.
     */
    public function test_worker_cannot_check_into_another_workers_session(): void
    {
        $worker = $this->supportWorker();
        $otherWorker = $this->sessionWorker();

        $session = $this->makeSession(['user_id' => $otherWorker->id, 'status' => 'active']);

        $this->actingAs($worker)
            ->from('/my-day')
            ->post("/health-safety/lone-workers/sessions/{$session->id}/check-in", [
                'status' => 'ok',
            ])
            ->assertForbidden();

        $this->assertSame(0, $session->checkIns()->count());
        $this->assertSame('active', $session->fresh()->status);
    }

    /* ── Auto-end on shift clock-out ────────────────────────────────────── */

    public function test_clocking_out_a_monitored_shift_auto_ends_the_session(): void
    {
        $worker = $this->supportWorker();
        [$shift, $attendance] = $this->clockedInShift($worker);

        $session = $this->makeSession([
            'user_id' => $worker->id,
            'shift_id' => $shift->id,
            'status' => 'active',
        ]);

        $this->actingAs($worker);
        app(AttendanceService::class)->clockOut($worker, $attendance, [
            'force' => true,
            'override_reason' => 'auto-end test',
        ]);

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertNotNull($session->ended_at);
    }

    public function test_clocking_out_never_clears_an_emergency_session(): void
    {
        $worker = $this->supportWorker();
        [$shift, $attendance] = $this->clockedInShift($worker);

        $session = $this->makeSession([
            'user_id' => $worker->id,
            'shift_id' => $shift->id,
            'status' => 'emergency',
            'emergency_triggered_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($worker);
        app(AttendanceService::class)->clockOut($worker, $attendance, [
            'force' => true,
            'override_reason' => 'auto-end test',
        ]);

        // An unresolved emergency must be resolved deliberately in the Control
        // Room — a routine clock-out must never silently complete it.
        $this->assertSame('emergency', $session->fresh()->status);
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    /** A frontline support worker — no hazards.* permission. */
    private function supportWorker(): User
    {
        $worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $worker->roles()->attach(Role::where('name', 'support_worker')->first());
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        return $worker;
    }

    private function sessionWorker(): User
    {
        $worker = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        return $worker;
    }

    /**
     * An in-progress shift the worker has clocked into, with a client that has
     * no medications or incidents (so no *clinical* clock-out blockers can fire).
     * The tests force the clock-out, which clears the remaining non-clinical
     * blockers (tasks / handover) without a manager override.
     *
     * @return array{0: Shift, 1: HrAttendanceSession}
     */
    private function clockedInShift(User $worker): array
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $shift = Shift::factory()->create([
            'user_id' => $worker->id,
            'client_id' => $client->id,
            'site_id' => $this->site->id,
            'status' => 'in_progress',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->addHours(4),
            'actual_starts_at' => now()->subHours(2),
            'started_by' => $worker->id,
            'created_by' => $worker->id,
        ]);

        $attendance = HrAttendanceSession::create([
            'user_id' => $worker->id,
            'shift_id' => $shift->id,
            'site_id' => $shift->site_id,
            'clock_in_at' => now()->subHours(2),
            'status' => 'open',
            'source' => 'manual',
            'created_by' => $worker->id,
        ]);

        return [$shift, $attendance];
    }

    public function test_is_lone_worker_flag_marks_a_shift_as_lone(): void
    {
        // Two in-progress shifts at the SAME site (so neither is "solo cover")
        // and neither on-call — isolating the explicit flag as the only reason a
        // shift is treated as lone.
        $site = $this->site;
        $client = Client::factory()->create(['site_id' => $site->id]);
        $mk = function (bool $flagged) use ($client, $site): Shift {
            $worker = $this->supportWorker();

            return Shift::create([
                'user_id' => $worker->id,
                'client_id' => $client->id,
                'site_id' => $site->id,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addHours(2),
                'actual_starts_at' => now()->subHour(),
                'status' => 'in_progress',
                'is_on_call' => false,
                'is_lone_worker' => $flagged,
                'created_by' => $this->admin->id,
            ]);
        };
        $mk(true);   // flagged → lone
        $mk(false);  // co-worker at same site → not solo, not on-call, not flagged → not lone

        $this->actingAs($this->admin)
            ->get('/health-safety/lone-workers')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('options.shifts', 2)
                ->where('hero.lone_shifts_unmonitored', 1));
    }
}
