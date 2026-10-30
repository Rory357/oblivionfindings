<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AttendanceService;
use App\Http\Controllers\Sites\SiteGeocodingController;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\LoneWorkerSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftGpsLog;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\LoneWorkerEmergencyDeliveryService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lone Worker Safety redesign — controller coverage (hero/tabs/detail/filters,
 * shift link + GPS prefill, lifecycle endpoints, sessions.update gating).
 */
class LoneWorkerControllerTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\LoneWorkerReviewedCommandFixtures;

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
        HrEmployeeProfile::factory()->create(['user_id' => $this->admin->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear()->toDateString(), 'end_date' => null]);
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

    public function test_start_session_links_shift_without_promoting_gps_to_a_planned_or_current_observation(): void
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
                'expected_end_at' => now()->addHours(3)->toISOString(),
                'check_in_interval_minutes' => 30,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $session = LoneWorkerSession::latest('id')->first();
        $this->assertSame($shift->id, $session->shift_id);
        $this->assertNull($session->location_lat);
        $this->assertNull($session->location_lng);
        $this->assertNull($session->planned_location);
        $this->assertDatabaseHas('shift_gps_logs', ['shift_id' => $shift->id, 'latitude' => -37.6878, 'longitude' => 176.1651]);
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
        app(LoneWorkerEmergencyDeliveryService::class)->deliver($session->emergencyEpisodes()->sole()->id);
        $this->assertSame(1, ControlRoomAlert::where('source', 'lone_worker')->count());
    }

    public function test_update_session_extends_and_clears_overdue(): void
    {
        $session = $this->makeSession(['status' => 'overdue', 'expected_end_at' => now()->subMinutes(10)]);
        $newEnd = now()->addHours(2);

        $this->actingAs($this->admin)
            ->from('/health-safety/lone-workers')
            ->patch("/health-safety/lone-workers/sessions/{$session->id}", [
                'expected_end_at' => $newEnd->toISOString(),
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
                'expected_end_at' => now()->addHour()->toISOString(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');

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
            ->assertSessionHasErrors('status');

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
            ->get("/health-safety/lone-workers/sessions/{$session->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
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
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
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

    private function geocoder(): void
    {
        Http::preventStrayRequests();
        config([
            'fleet.maps.address_search_endpoint' => 'https://'.Str::uuid().'.geocoder.example.test',
            'fleet.maps.address_search_cache_store' => 'database',
            'cache.stores.database.connection' => DB::getDefaultConnection(),
            'cache.stores.database.lock_connection' => DB::getDefaultConnection(),
        ]);
    }

    public function test_address_provider_failure_is_retryable_and_does_not_cache_false_empty_success(): void
    {
        $this->geocoder();
        Http::fakeSequence()->push([], 503)->push([['display_name' => 'Synthetic selected address', 'lat' => '-36.85', 'lon' => '174.76', 'address' => []]], 200);
        $url = '/health-safety/lone-workers/geocode/search?q=Synthetic%20retry%20address';
        $this->actingAs($this->admin)->getJson($url)->assertStatus(503)->assertJsonPath('retryable', true)->assertJsonMissingPath('results');
        $this->getJson($url)->assertOk()->assertJsonPath('results.0.display_name', 'Synthetic selected address');
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'results');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => array_diff(array_keys($request->data()), ['q', 'format', 'addressdetails', 'limit', 'countrycodes']) === []);
    }

    public function test_address_transport_exception_is_retryable_instead_of_an_empty_result(): void
    {
        $this->geocoder();
        Http::fake(fn () => throw new ConnectionException('Synthetic unavailable transport'));
        $this->actingAs($this->admin)->getJson('/health-safety/lone-workers/geocode/search?q=Synthetic%20transport')
            ->assertStatus(503)->assertJsonPath('retryable', true)->assertJsonMissingPath('results');
    }

    public function test_address_cache_failure_remains_distinct_from_shared_sites_legacy_behavior(): void
    {
        $this->geocoder();
        config(['fleet.maps.address_search_cache_store' => 'array']);
        $this->actingAs($this->admin)->getJson('/health-safety/lone-workers/geocode/search?q=Synthetic%20cache')
            ->assertStatus(503)->assertJsonMissingPath('results');
        $shared = app(SiteGeocodingController::class)->search(Request::create('/synthetic-shared-search', 'GET', ['q' => 'Synthetic cache']));
        $this->assertSame(200, $shared->status());
        $this->assertSame(['results' => []], $shared->getData(true));
        Http::assertNothingSent();
    }

    public function test_successful_address_search_with_no_matches_returns_a_real_empty_result(): void
    {
        $this->geocoder();
        Http::fakeSequence()->push([], 200)->push([], 200);
        $this->actingAs($this->admin)->getJson('/health-safety/lone-workers/geocode/search?q=Synthetic%20no%20matches')
            ->assertOk()->assertExactJson(['results' => []]);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => ($request->data()['countrycodes'] ?? null) === 'nz');
        Http::assertSent(fn ($request) => ! array_key_exists('countrycodes', $request->data()));
    }

    public function test_address_validation_and_current_staff_permission_deny_before_transport(): void
    {
        $this->geocoder();
        $url = '/health-safety/lone-workers/geocode/search?q=';
        $this->actingAs($this->admin)->getJson($url.str_repeat('a', 201))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson($url.'ab')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->actingAs($this->supportWorker())->getJson($url.'Synthetic')->assertForbidden();
        $this->admin->hrEmployeeProfile()->update(['is_active' => false]);
        $this->actingAs($this->admin)->getJson($url.'Synthetic')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_address_results_are_withheld_if_current_staff_authority_is_revoked_during_transport(): void
    {
        $this->geocoder();
        Http::fake(function () {
            $this->admin->hrEmployeeProfile()->update(['is_active' => false]);

            return Http::response([['display_name' => 'Synthetic withheld result', 'address' => []]], 200);
        });
        $this->actingAs($this->admin)->getJson('/health-safety/lone-workers/geocode/search?q=Synthetic%20revocation')->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_overdue_register_matches_the_shared_deadline_without_monitor_or_delivery_side_effects(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08T00:00:00Z'));
        $viewer = $this->sessionWorker();
        $viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.view')->firstOrFail()->id => ['allowed' => true]]);
        $pastDue = $this->makeSession(['last_check_in_at' => now()->subMinutes(31)]);
        $pastDue->checkIns()->create(['checked_in_at' => now()->subMinutes(31), 'received_at' => now()->subMinutes(31), 'recorded_by_user_id' => $pastDue->user_id, 'status' => 'ok']);
        $boundary = $this->makeSession(['last_check_in_at' => now()->subMinutes(30)]);
        $stored = $this->makeSession(['status' => 'overdue']);
        $legacyDue = $this->makeSession(['last_check_in_at' => null, 'started_at' => now()->subHours(2)]);
        $legacyDue->checkIns()->create(['checked_in_at' => now()->subMinutes(5), 'received_at' => now()->subMinutes(4), 'status' => 'ok']);
        // Receipt ordering, rather than occurrence ordering, matches Coverage exactly.
        $legacyDue->checkIns()->create(['checked_in_at' => now()->subMinutes(40), 'received_at' => now()->subMinute(), 'status' => 'ok']);
        $legacyFresh = $this->makeSession(['last_check_in_at' => null, 'started_at' => now()->subHours(2)]);
        $legacyFresh->checkIns()->create(['checked_in_at' => now()->subMinutes(5), 'received_at' => now()->subMinutes(4), 'status' => 'ok']);
        $this->makeSession(['status' => 'completed', 'last_check_in_at' => now()->subHours(2), 'ended_at' => now()->subMinute()]);
        $this->makeSession(['status' => 'emergency', 'last_check_in_at' => now()->subHours(2)]);
        $this->makeSession(['status' => 'active', 'last_check_in_at' => now()->subHours(2), 'ended_at' => now()->subMinute()]);
        $this->makeSession(['status' => 'overdue', 'ended_at' => now()->subMinute()]);
        $foreign = $this->makeSession(['last_check_in_at' => now()->subHours(2)]);
        $otherSite = Site::factory()->create(['is_active' => true]);
        $foreign->user->hrEmployeeProfile()->update(['primary_site_id' => $otherSite->id]);
        $foreign->updateQuietly(['site_id' => $otherSite->id]);
        $before = DB::table('lone_worker_sessions')->orderBy('id')->get()->toJson();
        $checkIns = DB::table('lone_worker_check_ins')->orderBy('id')->get()->toJson();
        $receipts = DB::table('lone_worker_command_receipts')->count();
        $episodes = DB::table('lone_worker_emergency_episodes')->count();
        $response = $this->actingAs($viewer)->getJson('/health-safety/lone-workers?status=overdue&period=all')->assertOk();
        $expected = [$pastDue->id, $boundary->id, $stored->id, $legacyDue->id];
        $this->assertEqualsCanonicalizing($expected, array_column($response->json('sessions.data'), 'id'));
        $this->assertSame(count($expected), $response->json('sessions.total'));
        $this->assertSame(3, $response->json('hero.clusters.alerts.no_recent_checkin'));
        $this->assertSame(4, $response->json('hero.clusters.live.overdue'));
        $this->assertSame(1, $response->json('hero.clusters.live.active'));
        $this->assertSame(6, $response->json('hero.badges.monitored_total'));
        $this->assertSame(6, $response->json('tabCounts.sessions'));
        $this->assertSame(1, $response->json('hero.clusters.live.emergency'));
        foreach ($response->json('sessions.data') as $row) {
            $this->assertTrue($row['is_check_in_overdue']);
            $this->assertSame('unknown', $row['observation']['monitor']['state']);
        }
        foreach ([$pastDue, $boundary, $stored, $legacyDue] as $row) {
            $attributes = $row->getAttributes();
            $this->assertTrue($row->isCheckInOverdue());
            $this->assertTrue($row->isCheckInOverdue());
            $this->assertSame($attributes, $row->getAttributes());
        }
        $this->assertFalse($legacyFresh->isCheckInOverdue());
        $this->assertSame($before, DB::table('lone_worker_sessions')->orderBy('id')->get()->toJson());
        $this->assertSame($checkIns, DB::table('lone_worker_check_ins')->orderBy('id')->get()->toJson());
        $this->assertSame($receipts, DB::table('lone_worker_command_receipts')->count());
        $this->assertSame($episodes, DB::table('lone_worker_emergency_episodes')->count());
    }

    public function test_across_all_sites_summary_uses_disjoint_derived_live_counts_with_fresh_scope_and_filters(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08T00:00:00Z'));
        $viewer = $this->sessionWorker();
        $viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.view')->firstOrFail()->id => ['allowed' => true]]);
        $secondSite = Site::factory()->create(['is_active' => true]);
        $viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$secondSite->id]]);
        $active = $this->makeSession(['last_check_in_at' => now()->subMinute(), 'activity_description' => 'Current scoped session']);
        $elapsed = $this->makeSession(['status' => 'active', 'last_check_in_at' => now()->subMinutes(31), 'activity_description' => 'Elapsed scoped session']);
        $stored = $this->makeSession(['status' => 'overdue']);
        $this->makeSession(['status' => 'emergency']);
        $ended = $this->makeSession(['status' => 'overdue', 'ended_at' => now()->subMinute()]);
        $second = $this->makeSession(['last_check_in_at' => now()->subMinutes(31)]);
        $second->user->hrEmployeeProfile()->update(['primary_site_id' => $secondSite->id]);
        $second->updateQuietly(['site_id' => $secondSite->id]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreign = $this->makeSession(['last_check_in_at' => now()->subMinutes(31)]);
        $foreign->user->hrEmployeeProfile()->update(['primary_site_id' => $foreignSite->id]);
        $foreign->updateQuietly(['site_id' => $foreignSite->id]);
        $before = DB::table('lone_worker_sessions')->orderBy('id')->get()->toJson();
        $response = $this->actingAs($viewer)->getJson('/health-safety/lone-workers?site_id='.$this->site->id.'&status=active&q=No%20matching%20session&period=all')->assertOk();
        $this->assertSame(0, $response->json('sessions.total'));
        $this->assertSame(1, $response->json('hero.clusters.live.active'));
        $this->assertSame(3, $response->json('hero.clusters.live.overdue'));
        $this->assertSame(1, $response->json('hero.clusters.live.emergency'));
        $this->assertSame(5, $response->json('hero.badges.monitored_total'));
        $this->assertSame(5, $response->json('tabCounts.sessions'));
        $this->assertSame(3, $response->json('hero.badges.overdue'));
        $this->assertSame($response->json('pickers.sites'), $response->json('options.sites'));
        $this->assertSame($response->json('pickers.workers'), $response->json('options.staff'));
        $this->assertSame($response->json('pickers.clients'), $response->json('options.clients'));
        $this->assertEqualsCanonicalizing([$this->site->id, $secondSite->id], array_column($response->json('options.sites'), 'id'));
        $this->assertSame($before, DB::table('lone_worker_sessions')->orderBy('id')->get()->toJson());
        $this->assertSame('active', $elapsed->fresh()->status);
        $this->assertNull($elapsed->fresh()->last_monitor_checked_at);
        $viewer->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
        $revoked = $this->getJson('/health-safety/lone-workers?period=all')->assertOk();
        $this->assertSame(2, $revoked->json('hero.clusters.live.overdue'));
        $this->assertSame(4, $revoked->json('hero.badges.monitored_total'));
        $this->assertSame(4, $revoked->json('tabCounts.sessions'));
        $this->assertEqualsCanonicalizing([$active->id, $elapsed->id, $stored->id], array_filter(array_column($revoked->json('sessions.data'), 'id'), fn ($id) => in_array($id, [$active->id, $elapsed->id, $stored->id], true)));
        $this->assertNotContains($second->id, array_column($revoked->json('sessions.data'), 'id'));
        $this->assertNotContains($foreign->id, array_column($revoked->json('sessions.data'), 'id'));
        $this->assertFalse($ended->isCheckInOverdue());
    }
}
