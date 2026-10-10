<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Services\HrEligibilityRuleSettings;
use App\Domain\Hr\Services\HrFatiguePolicySettings;
use App\Http\Controllers\Operations\WorkforceSettingsController;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkforceEligibilityRecheck;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Qualification settings have their own current revision, audit and committed result. */
class WorkforceEligibilityRuleSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 00:00:00', 'UTC'));
        config(['hr.eligibility_rules' => HrEligibilityRuleSettings::DEFAULTS]);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake();
        $this->assertFalse(AppSetting::where('key', HrEligibilityRuleSettings::KEY)->exists());
        $this->manager = $this->actor(['rostering.viewAny', 'hr.settings.manage']);
    }

    protected function tearDown(): void
    {
        try {
            DB::disconnect('wf32_settings_writer');
            if ($this->committed && DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction()) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public function test_roster_readers_see_explicit_defaults_without_obtaining_global_policy_authority(): void
    {
        $reader = $this->actor(['rostering.viewAny', 'reports.viewAny', 'shifts.manageAny']);
        $before = $this->state();
        $queue = $this->queue();
        $this->actingAs($reader)->get(route('operations.workforce.settings'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('eligibilityRules.values', HrEligibilityRuleSettings::DEFAULTS)
            ->where('eligibilityRules.defaults', HrEligibilityRuleSettings::DEFAULTS)
            ->where('eligibilityRules.source', 'deployment_defaults')
            ->where('eligibilityRules.scope', 'organisation')
            ->where('eligibilityRules.can_edit', false)->where('eligibilityRules.can_view_history', false)
            ->where('eligibilityRules.urls.update', null)->where('eligibilityRules.urls.history', null));
        $this->patchJson($this->url(), $this->body())->assertForbidden();
        $this->getJson($this->history())->assertForbidden();
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public function test_hr_settings_permission_alone_does_not_open_the_roster_workspace_or_writer(): void
    {
        $hrOnly = $this->actor(['hr.settings.manage']);
        $before = $this->state();
        $queue = $this->queue();
        $this->actingAs($hrOnly)->patchJson($this->url(), $this->body())->assertForbidden();
        $this->getJson($this->history())->assertForbidden();
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    #[DataProvider('savedModes')]
    public function test_actual_root_save_certifies_exact_values_actor_revision_audit_and_staged_recheck(string $mode, string $house, bool $changed): void
    {
        $before = $this->policy()->snapshot();
        $body = $this->body(['unmapped_mandatory_qualification' => $mode, 'house_qualification_approach' => $house]);
        $this->commitFixtures();
        $queue = $this->queue();
        $this->withManager(function () use ($before, $body, $changed, $queue): void {
            $response = $this->actingAs($this->manager)->patch($this->url(), $body)->assertRedirect(route('operations.workforce.settings'))
                ->assertSessionHasNoErrors();
            $after = $this->policy()->snapshot();
            $this->assertSame($body['values'], $after['values']);
            $this->assertSame($changed ? 1 : 0, $after['version']);
            $this->assertSame($changed ? 'saved_override' : 'deployment_defaults', $after['source']);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $intent = $changed ? $this->intents()->sole() : null;
            $receipt = $this->receipt($before, $after, $changed, $intent);
            $response->assertSessionHas('workforce_settings_result', $receipt);
            $this->assertSame($receipt, session('workforce_settings_result'));
            $this->assertArrayNotHasKey('reason', $receipt);
            $this->assertCount($changed ? 1 : 0, $this->audits()->get());
            if ($changed) {
                $this->assertTrue($intent->all_assigned);
                $this->assertSame('pending', $intent->status);
                $this->assertSame($after['revision'], $intent->source_fingerprint);
                $this->assertSame(1, $intent->source_version);
                $this->assertRefreshDelta($queue, $intent);
                Queue::assertPushed(RefreshWorkforceEligibility::class, fn ($job) => $job->recheckId === $intent->id && $job->sourceVersion === 1);
                $audit = $this->audits()->sole();
                $this->assertSame($this->manager->id, $audit->user_id);
                $this->assertSame('Reviewed operational qualifications.', $audit->meta['reason']);
                $this->assertEquals($before['values'], $audit->meta['before']);
                $this->assertEquals($body['values'], $audit->meta['after']);
            } else {
                $this->assertSame($queue, $this->queue());
                $this->assertFalse(AppSetting::where('key', HrEligibilityRuleSettings::KEY)->exists());
            }
            $this->get(route('operations.workforce.settings'))->assertInertia(fn (Assert $page) => $page
                ->where('eligibilityRules.values', $body['values'])
                ->where('eligibilityRules.urls.update', $this->url())
                ->where('eligibilityRules.urls.history', $this->history())
                ->where('flash.workforce_settings_result', fn ($value) => $value->all() == $receipt));
            $this->get(route('operations.workforce.settings'))->assertInertia(fn (Assert $page) => $page->where('flash.workforce_settings_result', null));
        });
    }

    public static function savedModes(): array
    {
        return ['default unchanged' => ['warn', 'per_requirement', false],
            'block and all workers' => ['block', 'all_workers', true],
            'warn and minimum staff' => ['warn', 'minimum_staff', true]];
    }

    public function test_same_second_restore_changes_revision_but_exact_repeat_changes_no_store_or_queue(): void
    {
        $this->commitFixtures();
        $this->withManager(function (): void {
            $initial = $this->policy()->snapshot();
            $this->actingAs($this->manager)->patch($this->url(), $this->body(['unmapped_mandatory_qualification' => 'block', 'house_qualification_approach' => 'minimum_staff']))->assertSessionHasNoErrors();
            $first = $this->policy()->snapshot();
            $this->patch($this->url(), $this->body($initial['values']))->assertSessionHasNoErrors();
            $restored = $this->policy()->snapshot();
            $this->assertSame($initial['values'], $restored['values']);
            $this->assertSame(2, $restored['version']);
            $this->assertNotSame($initial['revision'], $restored['revision']);
            $this->assertSame(2, $this->intents()->sole()->source_version);
            $this->assertCount(2, $this->audits()->get());
            $state = $this->state();
            $queue = $this->queue();
            $this->patch($this->url(), $this->body($restored['values']))->assertSessionHasNoErrors()
                ->assertSessionHas('workforce_settings_result', $this->receipt($restored, $restored, false));
            $this->assertSame($state, $this->state());
            $this->assertSame($queue, $this->queue());
            $stale = ['values' => $first['values'], 'expected_revision' => $initial['revision'], 'reason' => 'Stale draft.'];
            $this->patchJson($this->url(), $stale)->assertUnprocessable()->assertJsonValidationErrors('expected_revision')->assertSessionMissing('workforce_settings_result');
            $this->assertSame($state, $this->state());
            $this->assertSame($queue, $this->queue());
        });
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_policy_input_clears_old_metadata_and_preserves_exact_state(string $kind, string $error): void
    {
        $body = $this->body();
        match ($kind) {
            'unknown key' => $body['values']['private_rule'] = 'warn',
            'mode' => $body['values']['unmapped_mandatory_qualification'] = 'ignore',
            'house' => $body['values']['house_qualification_approach'] = 'role_count',
            'reason' => $body['reason'] = ' ',
            'revision' => $body['expected_revision'] = str_repeat('b', 64),
        };
        $this->commitFixtures();
        $state = $this->state();
        $queue = $this->queue();
        $this->actingAs($this->manager)->withSession(['workforce_settings_result' => ['action' => 'old']])
            ->patchJson($this->url(), $body)->assertUnprocessable()->assertJsonValidationErrors($error)->assertSessionMissing('workforce_settings_result');
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public static function invalidBodies(): array
    {
        return ['unknown key' => ['unknown key', 'values'], 'unmapped mode' => ['mode', 'values.unmapped_mandatory_qualification'],
            'house mode' => ['house', 'values.house_qualification_approach'], 'reason' => ['reason', 'reason'], 'stale revision' => ['revision', 'expected_revision']];
    }

    #[DataProvider('nestedCases')]
    public function test_nested_save_never_claims_root_commit_and_root_rollback_discards_only_its_work(bool $changed, bool $commit): void
    {
        $body = $this->body($changed ? ['unmapped_mandatory_qualification' => 'block', 'house_qualification_approach' => 'per_requirement'] : HrEligibilityRuleSettings::DEFAULTS);
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queue();
        $this->withManager(function () use ($body, $changed, $commit, $before, $queue): void {
            DB::beginTransaction();
            $this->actingAs($this->manager)->withSession(['workforce_settings_result' => ['action' => 'prior']])->patch($this->url(), $body)
                ->assertSessionHasNoErrors()->assertSessionMissing('workforce_settings_result');
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($queue, $this->queue());
            $commit ? DB::commit() : DB::rollBack();
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertNull(session('workforce_settings_result'));
            if (! $commit || ! $changed) {
                $this->assertSame($before, $this->state());
                $this->assertSame($queue, $this->queue());
            } else {
                $intent = $this->intents()->sole();
                $this->assertSame($body['values'], $this->policy()->snapshot()['values']);
                $this->assertRefreshDelta($queue, $intent);
                Queue::assertPushed(RefreshWorkforceEligibility::class, fn ($job) => $job->recheckId === $intent->id && $job->sourceVersion === 1);
            }
        });
    }

    public static function nestedCases(): array
    {
        return ['changed commit' => [true, true], 'changed rollback' => [true, false], 'same commit' => [false, true], 'same rollback' => [false, false]];
    }

    #[DataProvider('currentRevocations')]
    public function test_a_committed_account_or_exact_grant_denial_wins_over_primed_actor_and_rr_reads(string $change): void
    {
        $body = $this->body();
        $role = $this->manager->roles()->sole();
        $permission = $change === 'approval' ? null : Permission::where('key', $change)->sole();
        $this->commitFixtures();
        $this->withManager(function () use ($body, $role, $permission, $change): void {
            DB::beginTransaction();
            $this->manager->load(['roles.permissions', 'permissionOverrides']);
            DB::table('users')->where('id', $this->manager->id)->first();
            $writer = $this->writer();
            $writer->beginTransaction();
            if ($change === 'approval') {
                $writer->table('users')->where('id', $this->manager->id)->update(['approved_at' => null]);
            } else {
                $writer->table('role_permission')->where('role_id', $role->id)->where('permission_id', $permission->id)->delete();
            }
            $writer->commit();
            $this->assertSame(0, $writer->transactionLevel());
            $this->assertFalse($writer->getPdo()->inTransaction());
            $this->assertTrue($this->manager->isApproved());
            $this->assertTrue($this->manager->canDo($change === 'approval' ? 'hr.settings.manage' : $change));
            $state = $this->state($writer);
            $queue = $this->queue();
            $error = null;
            try {
                $request = Request::create($this->url(), 'PATCH', $body);
                $request->setUserResolver(fn () => $this->manager);
                $this->policy()->save($this->manager, $body['values'], $body['expected_revision'], $body['reason'], $request);
            } catch (HttpException $exception) {
                $error = $exception;
            }
            $this->assertNotNull($error);
            $this->assertSame(403, $error->getStatusCode());
            $this->assertSame($state, $this->state($writer));
            $this->assertSame($queue, $this->queue());
            DB::rollBack();
        });
    }

    public static function currentRevocations(): array
    {
        return ['account approval' => ['approval'], 'settings grant' => ['hr.settings.manage'], 'workspace grant' => ['rostering.viewAny']];
    }

    #[DataProvider('refusedWrites')]
    public function test_save_audit_or_recheck_refusal_rolls_back_values_revision_and_complete_work(string $model, string $event, string $mode): void
    {
        $body = $this->body();
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queue();
        $this->withManager(function () use ($model, $event, $mode, $body): void {
            $this->withListener($model, $event, function ($row) use ($mode) {
                if ($mode === 'alter') {
                    $row->value = ['version' => 1, 'values' => HrEligibilityRuleSettings::DEFAULTS];

                    return;
                }

                return false;
            }, function () use ($body): void {
                $caught = null;
                try {
                    $request = Request::create($this->url(), 'PATCH', $body);
                    $request->setUserResolver(fn () => $this->manager);
                    $this->policy()->save($this->manager, $body['values'], $body['expected_revision'], $body['reason'], $request);
                } catch (ValidationException|RuntimeException $exception) {
                    $caught = $exception;
                }
                $this->assertNotNull($caught, 'No refused persisted suffix may be represented as a saved policy.');
            });
        });
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queue());
        $this->assertNull(session('workforce_settings_result'));
    }

    public static function refusedWrites(): array
    {
        return ['value veto' => [AppSetting::class, 'saving', 'veto'], 'altered value' => [AppSetting::class, 'saving', 'alter'],
            'mandatory audit veto' => [AuditLog::class, 'creating', 'veto'], 'recheck veto' => [WorkforceEligibilityRecheck::class, 'saving', 'veto']];
    }

    public function test_current_policy_conflict_does_not_replace_an_independently_committed_revision(): void
    {
        $initial = $this->policy()->snapshot();
        $body = $this->body();
        $this->commitFixtures();
        $this->withManager(function () use ($initial, $body): void {
            DB::beginTransaction();
            $this->assertNull(DB::table('app_settings')->where('key', HrEligibilityRuleSettings::KEY)->first());
            $writer = $this->writer();
            $current = ['unmapped_mandatory_qualification' => 'warn', 'house_qualification_approach' => 'all_workers'];
            $writer->table('app_settings')->insert(['key' => HrEligibilityRuleSettings::KEY, 'value' => json_encode(['version' => 1, 'values' => $current]), 'created_at' => now(), 'updated_at' => now()]);
            $this->assertNull(DB::table('app_settings')->where('key', HrEligibilityRuleSettings::KEY)->first(), 'Ordinary RR remains the primed missing row.');
            $state = $this->state($writer);
            $queue = $this->queue();
            $error = null;
            try {
                $this->policy()->save($this->manager, $body['values'], $initial['revision'], $body['reason'], Request::create($this->url(), 'PATCH'));
            } catch (ValidationException $exception) {
                $error = $exception;
            }
            $this->assertNotNull($error);
            $this->assertArrayHasKey('expected_revision', $error->errors());
            $this->assertSame($state, $this->state($writer));
            $this->assertSame($queue, $this->queue());
            DB::rollBack();
        });
    }

    public function test_history_selects_only_the_new_policy_and_preserves_default_staffing_history(): void
    {
        $this->commitFixtures();
        $this->withManager(function (): void {
            $this->actingAs($this->manager)->patch($this->url(), $this->body())->assertSessionHasNoErrors();
            $this->getJson($this->history())->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('total', 1)->assertJsonPath('data.0.reason', 'Reviewed operational qualifications.');
            $this->getJson(route('operations.workforce.settings.history'))->assertOk()->assertJsonPath('total', 0);
            $this->assertFalse(AppSetting::where('key', HrFatiguePolicySettings::KEY)->exists());
        });
    }

    public function test_raw_pdo_boundary_withholds_a_receipt_and_projection_failure_cannot_reverse_the_saved_policy(): void
    {
        $this->commitFixtures();
        $this->withManager(function (): void {
            $this->actingAs($this->manager)->patch($this->url(), $this->body())->assertSessionHasNoErrors();
            $outcome = session('workforce_settings_result');
            $this->assertIsArray($outcome);
            session()->forget('workforce_settings_result');
            $state = $this->state();
            $queue = $this->queue();
            $pdo = DB::connection()->getPdo();
            $pdo->beginTransaction();
            try {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertTrue($pdo->inTransaction());
                (new ReflectionMethod(WorkforceSettingsController::class, 'committedResult'))->invoke(app(WorkforceSettingsController::class), redirect('/policy-review'), true, 'eligibility_rules', $outcome);
                $this->assertNull(session('workforce_settings_result'));
            } finally {
                $pdo->rollBack();
            }
            $outcome['values'] = null;
            Log::partialMock()->shouldReceive('warning')->once()->andThrow(new RuntimeException('Controlled unavailable log.'));
            (new ReflectionMethod(WorkforceSettingsController::class, 'committedResult'))->invoke(app(WorkforceSettingsController::class), redirect('/policy-review'), true, 'eligibility_rules', $outcome);
            $this->assertNull(session('workforce_settings_result'));
            $this->assertSame($state, $this->state());
            $this->assertSame($queue, $this->queue());
        });
    }

    public function test_a_restored_prior_actors_receipt_is_not_shared_with_another_approved_roster_reader(): void
    {
        $other = $this->actor(['rostering.viewAny']);
        $this->commitFixtures();
        $this->withManager(function () use ($other): void {
            $this->actingAs($this->manager)->patch($this->url(), $this->body())->assertSessionHasNoErrors();
            $receipt = session('workforce_settings_result');
            $this->assertSame($this->manager->id, $receipt['actor_id']);
            $state = $this->state();
            $queue = $this->queue();
            $this->actingAs($other)->withSession(['workforce_settings_result' => $receipt])->get(route('operations.workforce.settings'))
                ->assertInertia(fn (Assert $page) => $page->where('flash.workforce_settings_result', null));
            $this->assertSame($state, $this->state());
            $this->assertSame($queue, $this->queue());
        });
    }

    private function actor(array $keys): User
    {
        $actor = User::factory()->create(['approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'wf32-settings-'.uniqid(), 'label' => 'WF32 settings fixture', 'type' => 'custom', 'level' => 10]);
        $ids = collect($keys)->map(fn ($key) => Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'Operations', 'module' => 'operations'])->id)->all();
        $role->permissions()->sync($ids);
        $actor->roles()->attach($role);

        return $actor;
    }

    private function policy(): HrEligibilityRuleSettings
    {
        return app(HrEligibilityRuleSettings::class);
    }

    private function url(): string
    {
        return route('operations.workforce.settings.eligibility-rules.update');
    }

    private function history(): string
    {
        return route('operations.workforce.settings.history', ['action' => 'eligibility_rules']);
    }

    private function body(?array $values = null): array
    {
        return ['expected_revision' => $this->policy()->snapshot()['revision'], 'values' => $values ?? ['unmapped_mandatory_qualification' => 'block', 'house_qualification_approach' => 'per_requirement'], 'reason' => '  Reviewed operational qualifications.  '];
    }

    private function receipt(array $before, array $after, bool $changed, ?WorkforceEligibilityRecheck $intent = null): array
    {
        return ['action' => 'eligibility_rules', 'actor_id' => (int) $this->manager->id, 'expected_revision' => $before['revision'],
            'prior_revision' => $before['revision'], 'revision' => $after['revision'], 'values' => $after['values'], 'changed' => $changed,
            'refresh' => ['status' => $changed ? 'staged' : 'not_requested', 'recheck_id' => $intent?->id, 'source_version' => $intent?->source_version]];
    }

    private function intents()
    {
        return WorkforceEligibilityRecheck::where('source_type', 'hr_eligibility_rules');
    }

    private function audits()
    {
        return AuditLog::where('action', HrEligibilityRuleSettings::AUDIT_ACTION);
    }

    private function state(?Connection $connection = null): array
    {
        $connection ??= DB::connection();

        return collect(['app_settings', 'audit_logs', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations'])
            ->mapWithKeys(fn ($table) => [$table => $connection->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function queue(): array
    {
        return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
    }

    private function assertRefreshDelta(array $before, WorkforceEligibilityRecheck $intent): void
    {
        $after = $this->queue();
        $entries = $after[RefreshWorkforceEligibility::class] ?? [];
        $this->assertCount(count($before[RefreshWorkforceEligibility::class] ?? []) + 1, $entries);
        $last = array_pop($entries);
        $job = unserialize($last['job']);
        $this->assertInstanceOf(RefreshWorkforceEligibility::class, $job);
        $this->assertSame((int) $intent->id, $job->recheckId);
        $this->assertSame((int) $intent->source_version, $job->sourceVersion);
        if (array_key_exists(RefreshWorkforceEligibility::class, $before)) {
            $after[RefreshWorkforceEligibility::class] = $entries;
        } else {
            unset($after[RefreshWorkforceEligibility::class]);
        }
        $this->assertSame($before, $after, 'The policy save adds exactly its one durable refresh delivery.');
    }

    private function commitFixtures(): void
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), getmypid()));
        $this->assertSame(static::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertSame($connection->getDatabaseName(), $connection->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertSame(1, $connection->transactionLevel());
        DB::commit();
        $this->committed = true;
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    private function writer(): Connection
    {
        config(['database.connections.wf32_settings_writer' => [...DB::connection()->getConfig(), 'name' => 'wf32_settings_writer']]);
        DB::purge('wf32_settings_writer');
        $writer = DB::connection('wf32_settings_writer');
        $this->assertSame('wf32_settings_writer', $writer->getName());
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        $this->assertSame($writer->getDatabaseName(), $writer->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertNotSame(DB::connection()->getPdo(), $writer->getPdo());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($writer->getDatabaseName(), getmypid()));
        $this->assertSame(0, $writer->transactionLevel());
        $this->assertFalse($writer->getPdo()->inTransaction());

        return $writer;
    }

    private function withManager(callable $proof): void
    {
        $connection = DB::connection();
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $testing = $this->app['db.transactions'];
        $manager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $manager);
        $connection->setTransactionManager($manager);
        try {
            $proof();
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $this->app->instance('db.transactions', $testing);
            $connection->setTransactionManager($testing);
        }
    }

    private function withListener(string $model, string $event, callable $listener, callable $command): void
    {
        new $model;
        $original = Model::getEventDispatcher();
        $scoped = clone $original;
        $scoped->listen('eloquent.'.$event.': '.$model, $listener);
        Model::setEventDispatcher($scoped);
        try {
            $command();
        } finally {
            Model::setEventDispatcher($original);
        }
    }
}
