<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Operations\JobBoardController;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Proposed only: real root commits, one organisation and exact current Site authority. */
class WorkforceJobBoardPostingCommitIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $foreignSite;

    private Client $client;

    private ServiceContext $context;

    private User $actor;

    private User $other;

    private User $foreign;

    private User $worker;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-10-10T00:10:11.123456Z'));
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->context = ServiceContext::factory()->create(['is_active' => true, 'site_id' => null]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
        $this->actor = $this->person($this->site, ['job_board.create']);
        $this->other = $this->person($this->site, ['job_board.create']);
        $this->foreign = $this->person($this->foreignSite, ['job_board.create']);
        $this->worker = $this->person($this->site, []);
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('job_posting_observer');
            if ($this->committed && DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction()) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    #[DataProvider('postingSources')]
    public function test_root_post_returns_the_current_actor_intent_and_independently_committed_row(bool $assigned): void
    {
        $shift = $this->shift($assigned);
        $replacement = $assigned ? $this->replacement($shift) : null;
        $this->commitFixtures();
        $this->withProductionManager(function () use ($shift, $replacement): void {
            $body = $this->body($shift);
            $before = $this->state();
            $queue = $this->queue();
            $result = $this->actingAs($this->actor)->postJson(route('operations.job_board.create', $shift), $body)
                ->assertCreated()->assertSessionMissing('success')->json('result');
            $this->assertRootResult($body, $result, $this->actor, $shift, $replacement);
            $this->assertOnlyPositionAdded($before, $result['position']['id']);
            $this->assertSame($queue, $this->queue());

            // The original source is now stale; a refreshed source proves an active duplicate.
            $saved = $this->state($this->observer());
            $this->postJson(route('operations.job_board.create', $shift), $body)->assertConflict()->assertSessionMissing('success');
            $option = $this->option($shift);
            $this->assertSame($result['position']['id'], $option['active_position']['id']);
            $this->assertFalse($option['can_post']);
            $this->assertSame('duplicate', $option['reason']);
            $body['source_revision'] = $option['source_revision'];
            $this->postJson(route('operations.job_board.create', $shift), $body)->assertUnprocessable()->assertJsonValidationErrors('shift_id');
            $this->assertSame($saved, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    public static function postingSources(): array
    {
        return ['unassigned duty' => [false], 'assigned canonical replacement' => [true]];
    }

    public function test_a_different_authorised_actor_gets_only_its_own_correlated_result(): void
    {
        $shift = $this->shift();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($shift): void {
            $body = $this->body($shift);
            $before = $this->state();
            $queue = $this->queue();
            $result = $this->actingAs($this->other)->postJson(route('operations.job_board.create', $shift), $body)->assertCreated()->json('result');
            $this->assertRootResult($body, $result, $this->other, $shift);
            $this->assertNotSame($this->actor->id, $result['actor_id']);
            $this->assertOnlyPositionAdded($before, $result['position']['id']);
            $saved = $this->state($this->observer());
            $this->actingAs($this->actor)->postJson(route('operations.job_board.create', $shift), $body)->assertConflict();
            $this->assertSame($saved, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    public function test_a_foreign_posting_actor_cannot_read_or_post_a_captured_local_source(): void
    {
        $shift = $this->shift();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($shift): void {
            $body = $this->body($shift);
            $before = $this->state();
            $queue = $this->queue();
            $this->assertFalse($this->foreign->canDo('shifts.manageAny'));
            $this->assertFalse($this->foreign->canDo('reports.viewAny'));
            $this->actingAs($this->foreign)->getJson(route('operations.job_board.postable_shifts', ['q' => $shift->id]))
                ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('has_more', false);
            $this->postJson(route('operations.job_board.create', $shift), $body)->assertNotFound()->assertSessionMissing('success');
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    public function test_an_independently_revoked_grant_is_not_replaced_by_loaded_authority(): void
    {
        $shift = $this->shift();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($shift): void {
            $body = $this->body($shift);
            $stale = $this->actor->fresh()->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
            $this->assertTrue($stale->canDo('job_board.create'));
            $observer = $this->observer();
            $permission = Permission::where('key', 'job_board.create')->sole();
            $this->assertSame(1, $observer->table('permission_user')->where('user_id', $stale->id)->where('permission_id', $permission->id)->update(['allowed' => false]));
            $this->assertTrue($stale->canDo('job_board.create'), 'The request still has its old loaded grant.');
            $before = $this->state($observer);
            $queue = $this->queue();
            $this->actingAs($stale)->postJson(route('operations.job_board.create', $shift), $body)->assertForbidden();
            $this->getJson(route('operations.job_board.postable_shifts'))->assertForbidden();
            $this->assertSame($before, $this->state($observer));
            $this->assertSame($queue, $this->queue());
        });
    }

    public function test_current_site_profile_loss_withholds_the_previously_displayed_source(): void
    {
        $shift = $this->shift();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($shift): void {
            $body = $this->body($shift);
            $stale = $this->actor->fresh()->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
            $observer = $this->observer();
            $this->assertSame(1, $observer->table('hr_employee_profiles')->where('user_id', $stale->id)->update(['end_date' => '2026-10-09']));
            $this->assertNull($stale->hrEmployeeProfile->end_date);
            $before = $this->state($observer);
            $queue = $this->queue();
            $this->actingAs($stale)->getJson(route('operations.job_board.postable_shifts', ['q' => $shift->id]))
                ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('has_more', false);
            $this->postJson(route('operations.job_board.create', $shift), $body)->assertNotFound();
            $this->assertSame($before, $this->state($observer));
            $this->assertSame($queue, $this->queue());
        });
    }

    public function test_changed_displayed_duty_requires_a_new_source_before_an_intentional_post(): void
    {
        $shift = $this->shift();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($shift): void {
            $body = $this->body($shift);
            $shift->update(['ends_at' => $shift->ends_at->copy()->addHour()]);
            $before = $this->state();
            $queue = $this->queue();
            $this->postJson(route('operations.job_board.create', $shift), $body)->assertConflict();
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
            $body['request_id'] = (string) Str::uuid();
            $body['source_revision'] = $this->option($shift)['source_revision'];
            $result = $this->postJson(route('operations.job_board.create', $shift), $body)->assertCreated()->json('result');
            $this->assertRootResult($body, $result, $this->actor, $shift->fresh());
            $this->assertOnlyPositionAdded($before, $result['position']['id']);
            $this->assertSame($queue, $this->queue());
        });
    }

    public function test_an_external_pdo_transaction_cannot_receive_a_posted_result_or_write(): void
    {
        $shift = $this->shift();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($shift): void {
            $body = $this->body($shift);
            $before = $this->state($this->observer());
            $queue = $this->queue();
            $pdo = DB::connection()->getPdo();
            $pdo->beginTransaction();
            try {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertTrue($pdo->inTransaction());
                $this->postJson(route('operations.job_board.create', $shift), $body)->assertConflict()->assertSessionMissing('success');
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    public function test_an_unpresented_root_result_requires_current_read_and_does_not_replay_the_post(): void
    {
        $shift = $this->shift();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($shift): void {
            $body = $this->body($shift);
            $before = $this->state();
            $queue = $this->queue();
            // Only the presentation seam returns unknown; the real writer/commit stay active.
            app()->instance(JobBoardController::class, new class(app(UserSiteAccessService::class)) extends JobBoardController
            {
                protected function postingResult(bool $rootEntry, Request $request, array $saved): ?array
                {
                    return null;
                }
            });
            $this->postJson(route('operations.job_board.create', $shift), $body)->assertStatus(202)
                ->assertExactJson(['result' => null])->assertSessionMissing('success');
            $observer = $this->observer();
            $row = $observer->table('shift_open_positions')->where('shift_id', $shift->id)->sole();
            $this->assertOnlyPositionAdded($before, (int) $row->id);
            $saved = $this->state($observer);
            $this->postJson(route('operations.job_board.create', $shift), $body)->assertConflict();
            $option = $this->option($shift);
            $this->assertSame((int) $row->id, $option['active_position']['id']);
            $this->assertFalse($option['can_post']);
            $body['source_revision'] = $option['source_revision'];
            $this->postJson(route('operations.job_board.create', $shift), $body)->assertUnprocessable();
            $this->assertSame($saved, $this->state($observer));
            $this->assertSame($queue, $this->queue());
        });
    }

    #[DataProvider('saveFaults')]
    public function test_root_veto_or_observer_alteration_leaves_no_position_or_success(string $kind): void
    {
        $shift = $this->shift();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($shift, $kind): void {
            $body = $this->body($shift);
            $before = $this->state();
            $queue = $this->queue();
            $original = Model::getEventDispatcher();
            $events = clone $original;
            $events->listen('eloquent.saving: '.ShiftOpenPosition::class, function (ShiftOpenPosition $row) use ($kind) {
                if ($kind === 'veto') {
                    return false;
                }
                $row->notes = 'Observer changed the submitted intent';
            });
            Model::setEventDispatcher($events);
            try {
                $this->postJson(route('operations.job_board.create', $shift), $body)->assertUnprocessable()
                    ->assertJsonValidationErrors('shift_id')->assertSessionMissing('success');
            } finally {
                Model::setEventDispatcher($original);
            }
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    public static function saveFaults(): array
    {
        return ['save veto' => ['veto'], 'changed persisted intent' => ['alteration']];
    }

    public function test_legacy_root_post_keeps_its_redirect_and_only_flashes_after_commit(): void
    {
        $shift = $this->shift();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($shift): void {
            $before = $this->state();
            $queue = $this->queue();
            $this->actingAs($this->actor)->from('/operations/job-board')->post(route('operations.job_board.create', $shift), [
                'shift_id' => $shift->id, 'required_skills' => ['Legacy skill'], 'notes' => null,
            ])->assertRedirect('/operations/job-board')->assertSessionHas('success', 'Open position published.');
            $row = $this->observer()->table('shift_open_positions')->where('shift_id', $shift->id)->sole();
            $this->assertSame(['Legacy skill'], json_decode($row->required_skills, true, 512, JSON_THROW_ON_ERROR));
            $this->assertNull($row->notes);
            $this->assertNull($row->expires_at);
            $this->assertOnlyPositionAdded($before, (int) $row->id);
            $this->assertSame($queue, $this->queue());
        });
    }

    public function test_request_uuid_is_correlation_only_after_an_explicit_terminal_position_change(): void
    {
        $shift = $this->shift();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($shift): void {
            $body = $this->body($shift);
            $first = $this->postJson(route('operations.job_board.create', $shift), $body)->assertCreated()->json('result');
            ShiftOpenPosition::findOrFail($first['position']['id'])->update(['status' => 'cancelled']);
            $before = $this->state();
            $queue = $this->queue();
            // This is an explicit new posting using fresh source, not an automatic replay.
            $body['source_revision'] = $this->option($shift)['source_revision'];
            $second = $this->postJson(route('operations.job_board.create', $shift), $body)->assertCreated()->json('result');
            $this->assertSame($first['request_id'], $second['request_id']);
            $this->assertNotSame($first['position']['id'], $second['position']['id']);
            $this->assertRootResult($body, $second, $this->actor, $shift);
            $this->assertOnlyPositionAdded($before, $second['position']['id']);
            $this->assertSame($queue, $this->queue());
        });
    }

    private function person(Site $site, array $keys): User
    {
        $user = User::factory()->create(['role' => 'coordinator', 'approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'posting-proof-'.Str::uuid(), 'label' => 'Synthetic posting participant', 'level' => 10, 'type' => 'custom']);
        $user->roles()->attach($role);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'workforce', 'module' => 'Operations']);
            $user->permissionOverrides()->attach($permission, ['allowed' => true]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function shift(bool $assigned = false): Shift
    {
        return Shift::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->context->id, 'user_id' => $assigned ? $this->worker->id : null,
            'created_by' => $this->actor->id, 'starts_at' => Carbon::parse('2026-10-20T20:00:00Z'),
            'ends_at' => Carbon::parse('2026-10-20T21:00:00Z'), 'coverage_roles' => ['driver'], 'status' => 'scheduled', 'published_at' => null])->fresh();
    }

    private function replacement(Shift $shift): ShiftReplacementRequest
    {
        return ShiftReplacementRequest::create(['shift_id' => $shift->id, 'requested_by' => $this->worker->id,
            'current_staff_id' => $this->worker->id, 'status' => 'requested', 'reason' => 'Synthetic supported replacement',
            'requested_at' => now()->startOfSecond()])->fresh();
    }

    private function option(Shift $shift): array
    {
        $response = $this->actingAs($this->actor)->getJson(route('operations.job_board.postable_shifts', ['q' => $shift->id]))
            ->assertOk()->assertJsonCount(1, 'data');
        $option = $response->json('data.0');
        $this->assertSame($shift->id, $option['id']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $option['source_revision']);

        return $option;
    }

    private function body(Shift $shift): array
    {
        return ['shift_id' => $shift->id, 'request_id' => (string) Str::uuid(), 'source_revision' => $this->option($shift)['source_revision'],
            'required_skills' => ['Hoist', 'Hoist'], 'notes' => 'Exact synthetic posting intent',
            'expires_at' => now()->addHour()->startOfSecond()->toISOString()];
    }

    private function assertRootResult(array $body, array $result, User $actor, Shift $shift, ?ShiftReplacementRequest $replacement = null): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $observer = $this->observer();
        $stored = $observer->table('shift_open_positions')->where('id', $result['position']['id'])->sole();
        $this->assertSame(1, $result['version']);
        $this->assertSame('post_position', $result['action']);
        $this->assertSame('posted', $result['outcome']);
        $this->assertTrue($result['changed']);
        $this->assertSame($actor->id, $result['actor_id']);
        $this->assertSame($body['request_id'], $result['request_id']);
        $this->assertSame($body['source_revision'], $result['source_revision']);
        $this->assertSame(now('UTC')->startOfSecond()->toISOString(), $result['committed_at']);
        $this->assertSame(['id' => (int) $stored->id, 'shift_id' => $shift->id, 'replacement_request_id' => $replacement?->id,
            'status' => 'open', 'required_skills' => $body['required_skills'], 'coverage_roles' => ['driver'], 'notes' => $body['notes'],
            'claimed_by' => null, 'claimed_at' => null, 'approved_by' => null, 'approved_at' => null, 'expires_at' => $body['expires_at']], $result['position']);
        $this->assertSame($shift->id, (int) $stored->shift_id);
        $this->assertSame($replacement?->id, $stored->replacement_request_id === null ? null : (int) $stored->replacement_request_id);
        $this->assertSame('open', $stored->status);
        $this->assertSame($body['required_skills'], json_decode($stored->required_skills, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(['driver'], json_decode($stored->coverage_roles, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($body['notes'], $stored->notes);
        $this->assertSame(Carbon::parse($body['expires_at'])->format('Y-m-d H:i:s'), $stored->expires_at);
        foreach (['claimed_by', 'claimed_at', 'approved_by', 'approved_at'] as $field) {
            $this->assertNull($stored->{$field});
        }
        $this->assertSame(['id' => $shift->id, 'site_id' => $this->site->id, 'assigned' => $shift->user_id !== null,
            'status' => 'scheduled', 'starts_at' => $shift->starts_at->copy()->utc()->toISOString(),
            'ends_at' => $shift->ends_at->copy()->utc()->toISOString()], $result['shift']);
    }

    private function state(?Connection $connection = null): array
    {
        $tables = ['users', 'permission_user', 'hr_employee_profiles', 'sites', 'clients', 'service_contexts', 'shifts', 'shift_tasks',
            'shift_open_positions', 'shift_replacement_requests', 'coverage_reservations', 'shift_eligibility_overrides',
            'workforce_eligibility_rechecks', 'workforce_eligibility_observations', 'timeline_events', 'audit_logs',
            'notifications', 'shift_signals', 'shift_signal_outbox'];
        $sort = ['permission_user' => ['user_id', 'permission_id']];

        return collect($tables)->mapWithKeys(function (string $table) use ($connection, $sort): array {
            $query = ($connection ?? DB::connection())->table($table);
            foreach ($sort[$table] ?? ['id'] as $key) {
                $query->orderBy($key);
            }

            return [$table => $query->get()->map(fn ($row) => (array) $row)->all()];
        })->all();
    }

    private function assertOnlyPositionAdded(array $before, int $id): void
    {
        $after = $this->state($this->observer());
        $this->assertCount(count($before['shift_open_positions']) + 1, $after['shift_open_positions']);
        $after['shift_open_positions'] = array_values(array_filter($after['shift_open_positions'], fn ($row) => (int) $row['id'] !== $id));
        $this->assertSame($before, $after);
    }

    private function queue(): array
    {
        return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
    }

    private function commitFixtures(): void
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame(static::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), getmypid()));
        $this->assertSame($connection->getDatabaseName(), $connection->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertSame(1, $connection->transactionLevel());
        DB::commit();
        $this->committed = true;
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    private function observer(): Connection
    {
        $name = 'job_posting_observer';
        config(['database.connections.'.$name => [...DB::connection()->getConfig(), 'name' => $name]]);
        $observer = DB::connection($name);
        $this->assertSame($name, $observer->getName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($observer->getDatabaseName(), getmypid()));
        $this->assertSame(DB::connection()->getDatabaseName(), $observer->getDatabaseName());
        $this->assertSame($observer->getDatabaseName(), $observer->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertNotSame(DB::connection()->getPdo(), $observer->getPdo());
        $this->assertSame(0, $observer->transactionLevel());
        $this->assertFalse($observer->getPdo()->inTransaction());

        return $observer;
    }

    private function withProductionManager(callable $proof): void
    {
        $connection = DB::connection();
        $testing = app('db.transactions');
        $production = new DatabaseTransactionsManager;
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        app()->instance('db.transactions', $production);
        $connection->setTransactionManager($production);
        try {
            $proof();
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $this->assertFalse($connection->getPdo()->inTransaction());
            app()->instance('db.transactions', $testing);
            $connection->setTransactionManager($testing);
        }
    }
}
