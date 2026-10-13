<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Operations\WorkforceEligibilityRefreshController;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkforceEligibilityObservation;
use App\Models\WorkforceEligibilityRecheck;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

class WorkforceEligibilityRefreshCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $worker;

    private Site $site;

    private Site $foreign;

    private Shift $shift;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 00:00:00', 'UTC'));
        config(['app.worker_timezone' => 'Pacific/Auckland', 'features.rostering.publish' => true]);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake();
        $this->assertSame(0, WorkforceEligibilityRecheck::count(), 'Unexpected recovery metadata must fail before fixture creation.');
        $this->assertSame(0, WorkforceEligibilityObservation::count(), 'Unexpected observation metadata must fail before fixture creation.');
        $this->site = $this->site();
        $this->foreign = $this->site();
        $this->actor = $this->worker($this->site, ['shifts.update', 'shifts.manageAny', 'rostering.viewAny']);
        $this->worker = $this->worker($this->site);
        $this->shift = $this->shift($this->site, $this->worker);
        WorkforceEligibilityRecheck::query()->delete();
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('eligibility_retry_writer');
            if ($this->committed && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public function test_root_acknowledgement_proves_durable_intent_and_deduplicates_without_assignment_writes(): void
    {
        $this->commitFixtures();
        $before = $this->history();
        $this->actingAs($this->actor)->postJson($this->url())->assertStatus(202)
            ->assertExactJson(['shift_id' => $this->shift->id, 'queued' => true]);
        $intent = WorkforceEligibilityRecheck::sole();
        $this->assertSame('manual_shift', $intent->source_type);
        $this->assertSame($this->shift->id, (int) $intent->source_id);
        $this->assertSame([$this->shift->id], $intent->shift_ids);
        $this->assertSame(1, $intent->source_version);
        $this->assertSame('pending', $intent->status);
        Queue::assertPushed(RefreshWorkforceEligibility::class, 1);
        $this->postJson($this->url())->assertStatus(202)->assertExactJson(['shift_id' => $this->shift->id, 'queued' => true]);
        $this->assertSame(1, WorkforceEligibilityRecheck::count());
        Queue::assertPushed(RefreshWorkforceEligibility::class, 1);
        $this->assertSame($before, $this->history());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_an_existing_valid_merged_intent_remains_a_confirmed_request(): void
    {
        WorkforceEligibilityRecheck::create(['source_type' => 'manual_shift', 'source_id' => $this->shift->id,
            'source_version' => 3, 'source_fingerprint' => hash('sha256', 'existing-current-intent'),
            'shift_ids' => [$this->shift->id, 999999], 'user_ids' => [], 'site_ids' => [], 'client_ids' => [],
            'all_assigned' => false, 'status' => 'pending', 'available_at' => now()]);
        $this->commitFixtures();
        $before = $this->state();
        $this->actingAs($this->actor)->postJson($this->url())->assertStatus(202)
            ->assertExactJson(['shift_id' => $this->shift->id, 'queued' => true]);
        $this->assertSame($before, $this->state());
        Queue::assertNotPushed(RefreshWorkforceEligibility::class);
    }

    #[DataProvider('outerBoundaries')]
    public function test_nested_success_is_unconfirmed_and_outer_commit_or_rollback_preserves_atomicity(bool $commit): void
    {
        $this->commitFixtures();
        $before = $this->state();
        $this->withPhysicalCommitCallbacks(function () use ($commit, $before): void {
            DB::beginTransaction();
            $this->actingAs($this->actor)->postJson($this->url())->assertStatus(409)
                ->assertExactJson(['shift_id' => $this->shift->id, 'queued' => null, 'status' => 'unconfirmed']);
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertSame(1, WorkforceEligibilityRecheck::count());
            Queue::assertNotPushed(RefreshWorkforceEligibility::class);
            $commit ? DB::commit() : DB::rollBack();
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            if ($commit) {
                $this->assertSame(1, WorkforceEligibilityRecheck::count());
                Queue::assertPushed(RefreshWorkforceEligibility::class, 1);
                $this->assertSame($before['history'], $this->history());
            } else {
                $this->assertSame($before, $this->state());
                Queue::assertNotPushed(RefreshWorkforceEligibility::class);
            }
        });
    }

    public static function outerBoundaries(): array
    {
        return ['real outer commit' => [true], 'real outer rollback' => [false]];
    }

    #[DataProvider('authorityChanges')]
    public function test_committed_revocation_is_denied_after_a_stale_repeatable_read_snapshot(string $change): void
    {
        $roleId = $this->actor->roles()->sole()->id;
        $permissionId = Permission::where('key', 'shifts.update')->sole()->id;
        $portal = Role::firstOrCreate(['name' => 'client'], ['label' => 'Client', 'type' => 'custom', 'level' => 0]);
        $this->commitFixtures();
        $writer = $this->writer();
        [$evidence, $revoke] = match ($change) {
            'actor approval' => [
                fn (Connection $db) => $db->table('users')->where('id', $this->actor->id)->value('approved_at'),
                fn () => $writer->table('users')->where('id', $this->actor->id)->update(['approved_at' => null]),
            ],
            'exact update grant' => [
                fn (Connection $db) => $db->table('role_permission')->where('role_id', $roleId)->where('permission_id', $permissionId)->count(),
                fn () => $writer->table('role_permission')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete(),
            ],
            'inactive site' => [
                fn (Connection $db) => $db->table('sites')->where('id', $this->site->id)->value('is_active'),
                fn () => $writer->table('sites')->where('id', $this->site->id)->update(['is_active' => false]),
            ],
            'archived site' => [
                fn (Connection $db) => $db->table('sites')->where('id', $this->site->id)->value('archived'),
                fn () => $writer->table('sites')->where('id', $this->site->id)->update(['archived' => true]),
            ],
            'client site' => [
                fn (Connection $db) => $db->table('clients')->where('id', $this->shift->client_id)->value('site_id'),
                fn () => $writer->table('clients')->where('id', $this->shift->client_id)->update(['site_id' => $this->foreign->id]),
            ],
            'worker approval' => [
                fn (Connection $db) => $db->table('users')->where('id', $this->worker->id)->value('approved_at'),
                fn () => $writer->table('users')->where('id', $this->worker->id)->update(['approved_at' => null]),
            ],
            'worker site membership' => [
                fn (Connection $db) => $db->table('hr_employee_profiles')->where('user_id', $this->worker->id)->value('primary_site_id'),
                fn () => $writer->table('hr_employee_profiles')->where('user_id', $this->worker->id)->update(['primary_site_id' => $this->foreign->id]),
            ],
            'worker inactive profile' => [
                fn (Connection $db) => $db->table('hr_employee_profiles')->where('user_id', $this->worker->id)->value('is_active'),
                fn () => $writer->table('hr_employee_profiles')->where('user_id', $this->worker->id)->update(['is_active' => false]),
            ],
            'worker portal role' => [
                fn (Connection $db) => $db->table('role_user')->where('user_id', $this->worker->id)->where('role_id', $portal->id)->count(),
                fn () => $writer->table('role_user')->insert(['user_id' => $this->worker->id, 'role_id' => $portal->id]),
            ],
        };
        DB::beginTransaction();
        $old = $evidence(DB::connection());
        $before = $this->state();
        $fired = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($evidence, $revoke, $writer, $old, &$fired): void {
            if ($fired || ! str_contains($sql, 'hr_payroll_run_mutexes')) {
                return;
            }
            $fired = true;
            $revoke();
            $this->assertNotSame($old, $evidence($writer));
            $this->assertSame($old, $evidence(DB::connection()), 'Ordinary main evidence remains in the earlier snapshot.');
        });
        try {
            $this->actingAs($this->actor)->postJson($this->url())->assertForbidden();
            $this->assertTrue($fired);
            $this->assertSame($before, $this->state());
            Queue::assertNotPushed(RefreshWorkforceEligibility::class);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertNotSame($old, $evidence(DB::connection()));
        $this->assertSame($before, $this->state());
    }

    public static function authorityChanges(): array
    {
        return array_map(fn ($value) => [$value], [
            'actor approval', 'exact update grant', 'inactive site', 'archived site', 'client site',
            'worker approval', 'worker site membership', 'worker inactive profile', 'worker portal role',
        ]);
    }

    #[DataProvider('retargets')]
    public function test_a_bound_source_cannot_retarget_to_another_permitted_owner_or_client_after_waiting(string $field): void
    {
        $replacement = $field === 'user_id' ? $this->worker($this->site)->id
            : Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null])->id;
        $this->commitFixtures();
        // Creating a replacement worker or Client legitimately stages source
        // refresh intents. Preserve them rather than attribute them to retry.
        $before = $this->state();
        $this->assertSame(0, WorkforceEligibilityRecheck::where('source_type', 'manual_shift')->count());
        Queue::assertNotPushed(RefreshWorkforceEligibility::class);
        $writer = $this->writer();
        DB::beginTransaction();
        $original = $this->shift->fresh()->getRawOriginal();
        $fired = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($writer, $field, $replacement, $original, &$fired): void {
            if ($fired || ! str_contains($sql, 'hr_payroll_run_mutexes')) {
                return;
            }
            $fired = true;
            $writer->table('shifts')->where('id', $this->shift->id)->update([$field => $replacement]);
            $this->assertSame($original[$field], DB::table('shifts')->where('id', $this->shift->id)->value($field));
            $this->assertSame($replacement, $writer->table('shifts')->where('id', $this->shift->id)->value($field));
        });
        try {
            $this->actingAs($this->actor)->postJson($this->url())->assertForbidden();
            $this->assertTrue($fired);
            $this->assertSame($before, $this->state());
            $this->assertSame(0, WorkforceEligibilityRecheck::where('source_type', 'manual_shift')->count());
            Queue::assertNotPushed(RefreshWorkforceEligibility::class);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame([...$original, $field => $replacement], $this->shift->fresh()->getRawOriginal());
        $expected = $before;
        foreach ($expected['history']['shifts'] as &$row) {
            if ((int) $row['id'] === $this->shift->id) {
                $row[$field] = $replacement;
            }
        }
        unset($row);
        $this->assertSame($expected, $this->state(), 'Only the independently committed retarget may change; recovery and audit state must remain exact.');
        Queue::assertNotPushed(RefreshWorkforceEligibility::class);
    }

    public static function retargets(): array
    {
        return ['other permitted worker' => ['user_id'], 'other permitted client' => ['client_id']];
    }

    public function test_held_site_evidence_yields_a_recoverable_conflict_without_staging(): void
    {
        $this->commitFixtures();
        $before = $this->state();
        $holder = $this->writer();
        $holder->beginTransaction();
        try {
            $holder->table('sites')->where('id', $this->site->id)->lockForUpdate()->first();
            $this->actingAs($this->actor)->postJson($this->url())->assertUnprocessable()->assertJsonValidationErrors('eligibility_refresh');
            $this->assertSame($before, $this->state());
            Queue::assertNotPushed(RefreshWorkforceEligibility::class);
        } finally {
            $holder->rollBack();
        }
    }

    public function test_a_filled_but_unsaved_stage_is_not_acknowledged_and_rolls_back(): void
    {
        $this->commitFixtures();
        $before = $this->state();
        $this->withPhysicalCommitCallbacks(function () use ($before): void {
            $original = Model::getEventDispatcher();
            $scoped = clone $original;
            $scoped->listen('eloquent.saving: '.WorkforceEligibilityRecheck::class, fn () => false);
            Model::setEventDispatcher($scoped);
            try {
                $this->actingAs($this->actor)->postJson($this->url())->assertUnprocessable()->assertJsonValidationErrors('eligibility_refresh');
                $this->assertSame($before, $this->state());
                Queue::assertNotPushed(RefreshWorkforceEligibility::class);
            } finally {
                Model::setEventDispatcher($original);
            }
        });
    }

    public function test_postcommit_root_metadata_and_logger_failure_return_unknown_without_reversing_staging(): void
    {
        $this->commitFixtures();
        $this->actingAs($this->actor)->postJson($this->url())->assertStatus(202);
        $before = $this->state();
        $database = DB::getFacadeRoot();
        $logger = Log::getFacadeRoot();
        $partial = Mockery::mock($logger)->makePartial();
        $partial->shouldReceive('warning')->once()->andThrow(new RuntimeException('Controlled logger failure.'));
        Log::swap($partial);
        DB::swap(new class
        {
            public function connection(): never
            {
                throw new RuntimeException('Private connection evidence must not escape.');
            }
        });
        try {
            $response = (new ReflectionMethod(WorkforceEligibilityRefreshController::class, 'confirmedResponse'))->invoke(
                app(WorkforceEligibilityRefreshController::class), true, $this->shift->id,
            );
            $this->assertSame(503, $response->getStatusCode());
            $this->assertSame(['shift_id' => $this->shift->id, 'queued' => null, 'status' => 'unconfirmed'], $response->getData(true));
        } finally {
            DB::swap($database);
            Log::swap($logger);
        }
        $this->assertSame($before, $this->state());
    }

    public function test_a_pdo_transaction_without_a_laravel_level_is_not_a_confirmed_commit(): void
    {
        $this->commitFixtures();
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            $this->assertSame(0, DB::transactionLevel());
            $response = (new ReflectionMethod(WorkforceEligibilityRefreshController::class, 'confirmedResponse'))->invoke(
                app(WorkforceEligibilityRefreshController::class), true, $this->shift->id,
            );
            $this->assertSame(409, $response->getStatusCode());
            $this->assertSame(['shift_id' => $this->shift->id, 'queued' => null, 'status' => 'unconfirmed'], $response->getData(true));
        } finally {
            $pdo->rollBack();
        }
        $this->assertSame(0, WorkforceEligibilityRecheck::count());
    }

    private function worker(Site $site, array $permissions = []): User
    {
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'manager_user_id' => null]);
        $role = Role::create(['name' => 'retry-proof-'.str()->uuid(), 'label' => 'Retry proof', 'type' => 'custom', 'level' => 10]);
        $role->permissions()->sync(collect($permissions)->map(fn ($key) => Permission::firstOrCreate(['key' => $key],
            ['description' => $key, 'group' => 'Workforce', 'module' => 'operations'])->id));
        $worker->roles()->attach($role);

        return $worker->fresh();
    }

    private function site(): Site
    {
        return Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    }

    private function shift(Site $site, User $worker): Shift
    {
        return Shift::factory()->create(['site_id' => $site->id, 'user_id' => $worker->id,
            'client_id' => Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null])->id,
            'service_context_id' => null, 'status' => 'scheduled', 'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(2), 'published_at' => now()])->fresh();
    }

    private function url(): string
    {
        return route('operations.workforce.eligibility-refresh.retry', $this->shift);
    }

    private function history(): array
    {
        return collect(['shifts', 'timeline_events', 'audit_logs'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function state(): array
    {
        return ['history' => $this->history(), 'intents' => DB::table('workforce_eligibility_rechecks')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'observations' => DB::table('workforce_eligibility_observations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    }

    private function commitFixtures(): void
    {
        $db = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $db->getDriverName());
        $this->assertSame(static::$isolatedMysqlDatabase, $db->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($db->getDatabaseName(), getmypid()));
        $this->assertSame($db->getDatabaseName(), $db->selectOne('SELECT DATABASE() AS selected_database')->selected_database);
        $this->assertSame(1, $db->transactionLevel());
        DB::commit();
        $this->committed = true;
        Queue::fake();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($db->getPdo()->inTransaction());
    }

    private function withPhysicalCommitCallbacks(callable $proof): void
    {
        $connection = DB::connection();
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $testManager = $this->app['db.transactions'];
        // After the fixture wrapper commits, a new real level-one transaction
        // must not be treated as RefreshDatabase's logical testing wrapper.
        $manager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $manager);
        $connection->setTransactionManager($manager);
        try {
            $proof();
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertFalse($connection->getPdo()->inTransaction());
        } finally {
            try {
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            } finally {
                $this->app->instance('db.transactions', $testManager);
                $connection->setTransactionManager($testManager);
            }
        }
    }

    private function writer(): Connection
    {
        config(['database.connections.eligibility_retry_writer' => DB::connection()->getConfig()]);
        DB::purge('eligibility_retry_writer');
        $writer = DB::connection('eligibility_retry_writer');
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        $this->assertSame($writer->getDatabaseName(), $writer->selectOne('SELECT DATABASE() AS selected_database')->selected_database);
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($writer->getDatabaseName(), getmypid()));
        $this->assertSame(0, $writer->transactionLevel());
        $this->assertFalse($writer->getPdo()->inTransaction());

        return $writer;
    }
}
