<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Client;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkforceEligibilityObservation;
use App\Models\WorkforceEligibilityRecheck;
use App\Services\Eligibility\WorkforceEligibilityRefresh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

class WorkforceEligibilityRefreshReadScopeTest extends TestCase
{
    use RefreshDatabase;

    private bool $retryFixturesCommitted = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland', 'features.rostering.publish' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-07 00:00:00', 'UTC'));
        Queue::fake();
        Notification::fake();
    }

    private function commitRetryFixtures(): void
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame(static::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), getmypid()));
        $this->assertSame($connection->getDatabaseName(), $connection->selectOne('SELECT DATABASE() AS selected_database')->selected_database);
        $this->assertSame(1, $connection->transactionLevel());
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        DB::commit();
        $this->retryFixturesCommitted = true;
        Queue::fake();
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    protected function tearDown(): void
    {
        try {
            if ($this->retryFixturesCommitted && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public function test_refresh_read_filters_nonemployee_unassigned_past_and_foreign_duties_before_metadata_projection(): void
    {
        $local = $this->site();
        $foreign = $this->site();
        $actor = $this->worker($local, ['rostering.viewAny', 'shifts.manageAny', 'shifts.update']);
        $visible = $this->shift($local, $this->worker($local));
        $hidden = $this->shift($foreign, $this->worker($foreign));
        $unassigned = $this->shift($local, $this->worker($local), ['user_id' => null]);
        $past = $this->shift($local, $this->worker($local), ['starts_at' => now()->subHours(3), 'ends_at' => now()->subHour()]);
        $stay = $this->shift($local, $this->worker($local));
        DB::table('shifts')->where('id', $stay->id)->update(['respite_booking_id' => RespiteBooking::factory()->create()->id]);
        foreach ([$visible, $hidden, $unassigned, $past, $stay] as $row) {
            $this->observe($row->fresh());
        }
        $before = $this->rawHistory();
        $this->actingAs($actor)->getJson(route('operations.workforce.eligibility-refresh.index'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.shift_id', $visible->id)->assertJsonPath('data.0.can_retry', true)
            ->assertJsonMissing(['shift_id' => $hidden->id]);
        $this->assertSame($before, $this->rawHistory());
    }

    public function test_frontline_refresh_is_own_published_only_and_view_permission_cannot_retry(): void
    {
        $site = $this->site();
        $actor = $this->worker($site, ['shifts.viewAssigned']);
        $own = $this->shift($site, $actor);
        $unpublished = $this->shift($site, $actor);
        $unpublished->forceFill(['published_at' => null])->save();
        $other = $this->shift($site, $this->worker($site));
        foreach ([$own, $unpublished, $other] as $row) {
            $this->observe($row);
        }
        $this->actingAs($actor)->getJson(route('operations.workforce.eligibility-refresh.index'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.shift_id', $own->id)
            ->assertJsonPath('data.0.can_retry', false)->assertJsonPath('data.0.retry_url', null);
        $this->postJson(route('operations.workforce.eligibility-refresh.retry', $own))->assertForbidden();
    }

    public function test_explicit_reports_site_bypass_does_not_rescue_a_contradictory_client_site_tuple(): void
    {
        $local = $this->site();
        $foreign = $this->site();
        $actor = $this->worker($local, ['rostering.viewAny', 'shifts.manageAny', 'reports.viewAny']);
        $allowed = $this->shift($foreign, $this->worker($foreign));
        $bad = $this->shift($local, $this->worker($local));
        DB::table('shifts')->where('id', $bad->id)->update(['client_id' => $allowed->client_id]);
        $this->observe($allowed);
        $this->observe($bad->fresh());
        $this->actingAs($actor)->getJson(route('operations.workforce.eligibility-refresh.index'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.shift_id', $allowed->id);
    }

    public function test_retry_stages_one_durable_intent_and_never_claims_or_changes_assignment(): void
    {
        $site = $this->site();
        $actor = $this->worker($site, ['rostering.viewAny', 'shifts.manageAny', 'shifts.update']);
        $shift = $this->shift($site, $this->worker($site));
        WorkforceEligibilityRecheck::query()->delete();
        Queue::fake();
        $before = $this->rawHistory();
        $this->commitRetryFixtures();
        $this->actingAs($actor)->postJson(route('operations.workforce.eligibility-refresh.retry', $shift))
            ->assertStatus(202)->assertExactJson(['shift_id' => $shift->id, 'queued' => true]);
        $this->postJson(route('operations.workforce.eligibility-refresh.retry', $shift))->assertStatus(202);
        $this->assertSame(1, WorkforceEligibilityRecheck::count());
        $this->assertSame('pending', WorkforceEligibilityRecheck::firstOrFail()->status);
        $this->assertSame(0, WorkforceEligibilityObservation::count());
        $this->assertSame($before, $this->rawHistory());
        Queue::assertPushed(RefreshWorkforceEligibility::class, 1);
    }

    public function test_retry_denies_foreign_unassigned_ended_and_stay_rows_without_staging_work(): void
    {
        $local = $this->site();
        $foreign = $this->site();
        $actor = $this->worker($local, ['rostering.viewAny', 'shifts.manageAny', 'shifts.update']);
        $hidden = $this->shift($foreign, $this->worker($foreign));
        $unassigned = $this->shift($local, $this->worker($local), ['user_id' => null]);
        $ended = $this->shift($local, $this->worker($local), ['starts_at' => now()->subHours(3), 'ends_at' => now()->subHour()]);
        $stay = $this->shift($local, $this->worker($local));
        DB::table('shifts')->where('id', $stay->id)->update(['respite_booking_id' => RespiteBooking::factory()->create()->id]);
        WorkforceEligibilityRecheck::query()->delete();
        Queue::fake();
        $before = $this->rawHistory();
        foreach ([$hidden, $unassigned, $ended, $stay] as $row) {
            $this->actingAs($actor)->postJson(route('operations.workforce.eligibility-refresh.retry', $row))->assertForbidden();
        }
        $this->assertSame(0, WorkforceEligibilityRecheck::count());
        $this->assertSame($before, $this->rawHistory());
        Queue::assertNotPushed(RefreshWorkforceEligibility::class);
    }

    public function test_broader_roster_listing_does_not_reveal_out_of_scope_refresh_metadata_or_retry_links(): void
    {
        $local = $this->site();
        $foreign = $this->site();
        $actor = $this->worker($local, ['rostering.viewAny', 'shifts.manageAny', 'shifts.update']);
        $visible = $this->shift($local, $this->worker($local));
        $hidden = $this->shift($foreign, $this->worker($foreign));
        $unassigned = $this->shift($local, $this->worker($local), ['user_id' => null]);
        $past = $this->shift($local, $this->worker($local), ['starts_at' => now()->subHours(3), 'ends_at' => now()->subHour()]);
        $stay = $this->shift($local, $this->worker($local));
        DB::table('shifts')->where('id', $stay->id)->update(['respite_booking_id' => RespiteBooking::factory()->create()->id]);
        foreach ([$visible, $hidden, $unassigned, $past, $stay] as $row) {
            $this->observe($row->fresh());
        }
        $this->actingAs($actor)->get(route('operations.rostering.index', ['week' => '2026-10-05']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('shifts', fn ($rows) => collect($rows)->pluck('id')->intersect([$visible->id, $hidden->id, $unassigned->id, $past->id, $stay->id])->count() === 5)
            ->where('eligibilityFreshness', fn ($rows) => collect($rows)->keys()->map(fn ($id) => (int) $id)->all() === [$visible->id])
            ->where('eligibilityFreshness.'.$visible->id.'.can_retry', true)
            ->where('eligibilityFreshness.'.$visible->id.'.posture', 'clear'));
    }

    public function test_refresh_metadata_never_includes_private_failed_rule_names_or_observed_source_versions(): void
    {
        $site = $this->site();
        $actor = $this->worker($site, ['rostering.viewAny', 'shifts.manageAny']);
        $shift = $this->shift($site, $this->worker($site));
        $this->observe($shift, ['failed_rules' => [['rule' => 'PRIVATE-SOURCE-LABEL', 'severity' => 'block']],
            'observed_versions' => [999999 => 42], 'posture' => 'blocked', 'block_count' => 1]);
        $response = $this->actingAs($actor)->getJson(route('operations.workforce.eligibility-refresh.index'))
            ->assertOk()->assertJsonPath('data.0.posture', 'blocked')->assertJsonPath('data.0.block_count', 1);
        $this->assertStringNotContainsString('PRIVATE-SOURCE-LABEL', $response->getContent());
        $this->assertStringNotContainsString('observed_versions', $response->getContent());
        $this->assertStringNotContainsString('999999', $response->getContent());
    }

    public function test_refresh_is_paginated_with_full_scoped_count_and_no_assignment_side_effects(): void
    {
        $site = $this->site();
        $actor = $this->worker($site, ['rostering.viewAny', 'shifts.manageAny']);
        $worker = $this->worker($site);
        foreach (range(1, 51) as $day) {
            $this->shift($site, $worker, ['starts_at' => now()->addDays($day), 'ends_at' => now()->addDays($day)->addHours(2)]);
        }
        $before = $this->rawHistory();
        $this->actingAs($actor)->getJson(route('operations.workforce.eligibility-refresh.index'))
            ->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('total', 51)->assertJsonPath('last_page', 2);
        $this->getJson(route('operations.workforce.eligibility-refresh.index', ['page' => 2]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 51);
        $this->assertSame($before, $this->rawHistory());
    }

    private function site(): Site
    {
        return Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    }

    private function worker(Site $site, array $permissions = []): User
    {
        $worker = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'start_date' => '2025-01-01', 'end_date' => null, 'is_active' => true, 'manager_user_id' => null]);
        $role = Role::create(['name' => 'refresh-read-'.str()->uuid(), 'label' => 'Refresh read regression', 'type' => 'custom', 'level' => 10]);
        $role->permissions()->sync(collect($permissions)->map(fn (string $key) => Permission::firstOrCreate(['key' => $key],
            ['description' => $key, 'group' => 'Workforce', 'module' => 'operations'])->id));
        $worker->roles()->attach($role);

        return $worker;
    }

    private function shift(Site $site, User $worker, array $overrides = []): Shift
    {
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null]);

        return Shift::factory()->create(['site_id' => $site->id, 'client_id' => $client->id, 'user_id' => $worker->id,
            'service_context_id' => null, 'status' => 'scheduled', 'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2), 'published_at' => now(), ...$overrides]);
    }

    private function observe(Shift $shift, array $overrides = []): void
    {
        WorkforceEligibilityObservation::create(['shift_id' => $shift->id, 'user_id' => $shift->user_id,
            'shift_fingerprint' => app(WorkforceEligibilityRefresh::class)->shiftFingerprint($shift),
            'posture' => 'clear', 'block_count' => 0, 'warning_count' => 0, 'failed_rules' => [],
            'observed_versions' => [], 'checked_at' => now(), 'last_successful_at' => now(), ...$overrides]);
    }

    private function rawHistory(): array
    {
        return ['shifts' => Shift::orderBy('id')->get()->map->getRawOriginal()->all(),
            'timeline' => DB::table('timeline_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    }
}
