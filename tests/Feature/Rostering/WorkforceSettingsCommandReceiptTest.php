<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Services\HrFatiguePolicySettings;
use App\Http\Controllers\Operations\WorkforceSettingsController;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserUiPreference;
use App\Models\WorkforceEligibilityRecheck;
use App\Services\Operations\WorkforcePreferences;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Real root commits and current authority; queued delivery is deliberately separate. */
class WorkforceSettingsCommandReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $personal;

    private User $manager;

    private bool $fixturesCommitted = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland', 'hr.fatigue' => [
            'max_hours_per_day' => 12, 'max_hours_per_week' => 50, 'warning_threshold_weekly' => 40,
            'min_rest_between_shifts_hours' => 10, 'max_consecutive_days' => 7,
        ]]);
        Carbon::setTestNow(Carbon::parse('2026-10-07 05:00:00', 'UTC'));
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake([RefreshWorkforceEligibility::class]);
        $this->assertFalse(AppSetting::where('key', HrFatiguePolicySettings::KEY)->exists(), 'The disposable baseline has no staffing override.');
        $this->personal = $this->actor(['rostering.viewAny']);
        $this->manager = $this->actor(['rostering.viewAny', 'hr.settings.manage']);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixturesCommitted && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    #[DataProvider('rootCommands')]
    public function test_root_receipt_matches_actual_values_actor_revisions_and_staged_intent(string $action, bool $changed): void
    {
        $actor = $this->actorFor($action);
        $this->assertNull($actor->hrEmployeeProfile, 'Settings grants do not require an employee profile or Site.');
        $body = $this->body($action, $changed);
        $before = $this->snapshot($action);
        $this->commitFixtures();
        $response = $this->actingAs($actor)->command($action, $body)->assertRedirect(route('operations.workforce.settings'))
            ->assertSessionHasNoErrors()->assertSessionHas('success', $this->success($action, $changed));
        $after = $this->snapshot($action);
        $receipt = $this->assertReceipt($response, $action, $body, $before, $after, $changed);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        if ($action === 'preferences') {
            $this->assertSame($body['default_tab'], UserUiPreference::where('user_id', $actor->id)->sole()->value['default_tab']);
            $this->assertFalse(AppSetting::where('key', HrFatiguePolicySettings::KEY)->exists());
            $this->assertSame(0, $this->policyIntents()->count());
        } elseif ($changed) {
            $intent = $this->policyIntents()->sole();
            $this->assertSame($after['revision'], $intent->source_fingerprint);
            $this->assertSame('pending', $intent->status);
            $this->assertTrue($intent->all_assigned);
            $this->assertSame(1, $intent->source_version);
            Queue::assertPushed(RefreshWorkforceEligibility::class, fn ($job) => $job->recheckId === $intent->id && $job->sourceVersion === 1);
        } else {
            $this->assertFalse(AppSetting::where('key', HrFatiguePolicySettings::KEY)->exists());
            $this->assertSame(0, $this->policyIntents()->count());
        }
        $audits = AuditLog::where('action', $action === 'preferences' ? 'workforce.preferences.updated' : HrFatiguePolicySettings::AUDIT_ACTION)->get();
        $this->assertCount($changed ? 1 : 0, $audits);
        if ($changed && $action === 'staffing_rules') {
            $this->assertSame(trim($body['reason']), $audits->sole()->meta['reason']);
        }
        $this->get(route('operations.workforce.settings'))->assertInertia(fn (Assert $page) => $page
            ->where('flash.workforce_settings_result', fn ($value) => $value->all() == $receipt));
        $this->get(route('operations.workforce.settings'))->assertInertia(fn (Assert $page) => $page->where('flash.workforce_settings_result', null));
    }

    public static function rootCommands(): array
    {
        return ['preferences changed' => ['preferences', true], 'preferences first default save' => ['preferences', false],
            'policy changed' => ['staffing_rules', true], 'policy no-op' => ['staffing_rules', false]];
    }

    #[DataProvider('nestedCommands')]
    public function test_nested_commands_never_publish_receipts_before_or_after_the_outer_boundary(string $action, bool $changed, bool $commit): void
    {
        $body = $this->body($action, $changed);
        $this->commitFixtures();
        $before = $this->state();
        $connection = DB::connection();
        $testManager = $this->app['db.transactions'];
        // The fixture wrapper has physically committed. The testing manager
        // treats a new level-one transaction as its logical wrapper and runs
        // callbacks too early; this proof needs ordinary root-commit semantics.
        $manager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $manager);
        $connection->setTransactionManager($manager);
        try {
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertFalse($connection->getPdo()->inTransaction());
            DB::beginTransaction();
            $this->actingAs($this->actorFor($action))->withSession(['workforce_settings_result' => ['action' => 'old']])
                ->command($action, $body)->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('workforce_settings_result');
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue($connection->getPdo()->inTransaction());
            Queue::assertNotPushed(RefreshWorkforceEligibility::class);
            $commit ? DB::commit() : DB::rollBack();
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertFalse($connection->getPdo()->inTransaction());
            $this->assertNull(session('workforce_settings_result'));
            if (! $commit) {
                Queue::assertNotPushed(RefreshWorkforceEligibility::class);
                $this->assertSame($before, $this->state());
            } elseif ($action === 'staffing_rules' && $changed) {
                $this->assertSame(1, $this->policyIntents()->count());
                Queue::assertPushed(RefreshWorkforceEligibility::class, 1);
            } elseif ($action === 'preferences') {
                $this->assertSame($body['default_tab'], $this->snapshot($action)['default_tab']);
            }
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

    public static function nestedCommands(): array
    {
        $cases = [];
        foreach (['preferences', 'staffing_rules'] as $action) {
            foreach ([true, false] as $changed) {
                foreach ([true, false] as $commit) {
                    $cases[$action.' '.($changed ? 'changed' : 'same').' '.($commit ? 'commit' : 'rollback')] = [$action, $changed, $commit];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('actions')]
    public function test_stale_and_invalid_commands_clear_old_receipts_and_preserve_all_command_stores(string $action): void
    {
        $body = $this->body($action);
        $this->commitFixtures();
        $this->actingAs($this->actorFor($action))->command($action, $body)->assertRedirect();
        $before = $this->state();
        $this->withSession(['workforce_settings_result' => ['action' => 'old']])->command($action, $body, true)
            ->assertUnprocessable()->assertJsonValidationErrors('expected_revision')->assertSessionMissing('workforce_settings_result');
        $invalid = $this->body($action);
        $invalid[$action === 'preferences' ? 'default_tab' : 'reason'] = $action === 'preferences' ? 'foreign-workflow' : '';
        $this->withSession(['workforce_settings_result' => ['action' => 'old']])->command($action, $invalid, true)
            ->assertUnprocessable()->assertJsonValidationErrors($action === 'preferences' ? 'default_tab' : 'reason')->assertSessionMissing('workforce_settings_result');
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('revocations')]
    public function test_committed_account_or_exact_grant_revocation_survives_an_earlier_rr_snapshot(string $action, string $change): void
    {
        $actor = $this->actorFor($action);
        $body = $this->body($action);
        $roleId = $actor->roles()->sole()->id;
        $permissionId = $change === 'account' ? null : Permission::where('key', $change)->sole()->id;
        $this->assertTrue($actor->canDo('rostering.viewAny'));
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        $evidence = fn (Connection $connection) => $change === 'account'
            ? $connection->table('users')->where('id', $actor->id)->value('approved_at')
            : $connection->table('role_permission')->where('role_id', $roleId)->where('permission_id', $permissionId)->count();
        $snapshot = $evidence(DB::connection());
        $before = $this->state();
        $fired = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($writer, $actor, $change, $roleId, $permissionId, $evidence, $snapshot, &$fired): void {
            if ($fired || ! str_contains($sql, 'hr_payroll_run_mutexes')) {
                return;
            }
            $fired = true;
            $this->assertSame(0, $writer->transactionLevel());
            $this->assertFalse($writer->getPdo()->inTransaction());
            $change === 'account' ? $writer->table('users')->where('id', $actor->id)->update(['approved_at' => null])
                : $writer->table('role_permission')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
            $this->assertNotEquals($snapshot, $evidence($writer));
            $this->assertSame($snapshot, $evidence(DB::connection()), 'The ordinary main snapshot remains stale.');
        });
        try {
            $this->actingAs($actor)->withSession(['workforce_settings_result' => ['action' => 'old']])->command($action, $body, true)
                ->assertForbidden()->assertSessionMissing('workforce_settings_result');
            $this->assertTrue($fired);
            $this->assertSame($before, $this->state());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('settings_evidence_writer');
        }
        $this->assertNotEquals($snapshot, $evidence(DB::connection()));
        $this->assertSame($before, $this->state());
    }

    public static function revocations(): array
    {
        return ['preferences approval' => ['preferences', 'account'], 'preferences roster grant' => ['preferences', 'rostering.viewAny'],
            'policy approval' => ['staffing_rules', 'account'], 'policy roster grant' => ['staffing_rules', 'rostering.viewAny'],
            'policy exact HR grant' => ['staffing_rules', 'hr.settings.manage']];
    }

    #[DataProvider('actions')]
    public function test_model_veto_is_not_a_persisted_value_or_successful_command(string $action): void
    {
        $body = $this->body($action);
        $this->commitFixtures();
        $before = $this->state();
        $model = $action === 'preferences' ? UserUiPreference::class : AppSetting::class;
        $this->withModelListener($model, 'saving', fn () => false, function () use ($action, $body): void {
            $this->actingAs($this->actorFor($action))->withSession(['workforce_settings_result' => ['action' => 'old']])->command($action, $body, true)
                ->assertUnprocessable()->assertJsonValidationErrors($action === 'preferences' ? 'preferences' : 'staffing_rules')
                ->assertSessionMissing('workforce_settings_result');
        });
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('actions')]
    public function test_mandatory_audit_failure_rolls_back_values_revisions_and_work(string $action): void
    {
        $body = $this->body($action);
        $this->commitFixtures();
        $before = $this->state();
        $this->withoutExceptionHandling();
        $this->withModelListener(AuditLog::class, 'creating', fn () => throw new RuntimeException('Controlled settings audit failure.'), function () use ($action, $body): void {
            try {
                $this->actingAs($this->actorFor($action))->withSession(['workforce_settings_result' => ['action' => 'old']])->command($action, $body);
                $this->fail('The mandatory audit failure must surface.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Controlled settings audit failure.', $exception->getMessage());
            }
        });
        $this->assertNull(session('workforce_settings_result'));
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('stageFailures')]
    public function test_durable_stage_failure_or_filled_but_unsaved_intent_cannot_certify_policy(string $failure): void
    {
        $body = $this->body('staffing_rules');
        $this->commitFixtures();
        $before = $this->state();
        $this->withModelListener(WorkforceEligibilityRecheck::class, 'saving', $failure === 'veto'
            ? fn () => false : fn () => throw new RuntimeException('Controlled settings staging failure.'), function () use ($failure, $body): void {
                $this->actingAs($this->manager)->withSession(['workforce_settings_result' => ['action' => 'old']]);
                if ($failure === 'veto') {
                    $this->command('staffing_rules', $body, true)->assertUnprocessable()->assertJsonValidationErrors('staffing_rules');
                } else {
                    $this->withoutExceptionHandling();
                    try {
                        $this->command('staffing_rules', $body);
                        $this->fail('The actual staging failure must surface.');
                    } catch (RuntimeException $exception) {
                        $this->assertSame('Controlled settings staging failure.', $exception->getMessage());
                    }
                }
            });
        $this->assertNull(session('workforce_settings_result'));
        $this->assertSame($before, $this->state());
    }

    public static function stageFailures(): array
    {
        return ['filled model veto' => ['veto'], 'writer exception' => ['exception']];
    }

    public function test_personal_command_cannot_select_another_owner_or_change_global_policy(): void
    {
        $otherBefore = app(WorkforcePreferences::class)->for($this->manager);
        $policyBefore = app(HrFatiguePolicySettings::class)->snapshot();
        $body = [...$this->body('preferences'), 'user_id' => $this->manager->id,
            'values' => ['max_hours_per_day' => 1], 'reason' => 'Not a policy command.'];
        $this->commitFixtures();
        $this->actingAs($this->personal)->command('preferences', $body)->assertRedirect()->assertSessionHas('workforce_settings_result.actor_id', $this->personal->id);
        $this->assertSame($otherBefore, app(WorkforcePreferences::class)->for($this->manager));
        $this->assertSame($policyBefore, app(HrFatiguePolicySettings::class)->snapshot());
        $this->assertSame(0, $this->policyIntents()->count());
        $this->assertDatabaseMissing('user_ui_preferences', ['user_id' => $this->manager->id, 'key' => WorkforcePreferences::KEY]);
    }

    public function test_a_previous_requesters_receipt_is_not_shared_with_a_different_approved_account(): void
    {
        $body = $this->body('preferences');
        $this->commitFixtures();
        $this->actingAs($this->personal)->command('preferences', $body)->assertRedirect()
            ->assertSessionHas('workforce_settings_result.actor_id', $this->personal->id);
        $before = $this->state();
        $this->actingAs($this->manager)->get(route('operations.workforce.settings'))->assertInertia(fn (Assert $page) => $page
            ->where('flash.workforce_settings_result', null)->where('preferences.default_tab', 'shifts')->where('preferences.roster_view', 'grid'));
        $this->assertSame($before, $this->state());
        $this->assertSame('calendar', app(WorkforcePreferences::class)->for($this->personal)['default_tab']);
    }

    public function test_same_second_policy_restore_and_noop_preserve_version_and_staging_semantics(): void
    {
        $firstBody = $this->body('staffing_rules');
        $initial = $this->snapshot('staffing_rules');
        $this->commitFixtures();
        $this->actingAs($this->manager)->command('staffing_rules', $firstBody)->assertRedirect();
        $first = $this->snapshot('staffing_rules');
        $restore = ['expected_revision' => $first['revision'], 'values' => $initial['values'], 'reason' => 'Restore reviewed limits.'];
        $this->command('staffing_rules', $restore)->assertRedirect();
        $restored = $this->snapshot('staffing_rules');
        $this->assertSame($initial['values'], $restored['values']);
        $this->assertSame(2, $restored['version']);
        $this->assertNotSame($initial['revision'], $restored['revision']);
        $this->assertNotSame($first['revision'], $restored['revision']);
        $this->assertSame(2, $this->policyIntents()->sole()->source_version);
        $before = $this->state();
        Queue::fake([RefreshWorkforceEligibility::class]);
        $same = ['expected_revision' => $restored['revision'], 'values' => $restored['values'], 'reason' => 'No further change.'];
        $response = $this->command('staffing_rules', $same)->assertRedirect()->assertSessionHas('success', $this->success('staffing_rules', false));
        $this->assertReceipt($response, 'staffing_rules', $same, $restored, $restored, false);
        $this->assertSame($before, $this->state());
        Queue::assertNotPushed(RefreshWorkforceEligibility::class);
        $this->command('staffing_rules', $firstBody, true)->assertUnprocessable()->assertJsonValidationErrors('expected_revision')->assertSessionMissing('workforce_settings_result');
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('presentationFailures')]
    public function test_postcommit_projection_or_root_check_and_logger_failure_cannot_reverse_the_command(string $action, string $failure): void
    {
        $body = $this->body($action);
        $this->commitFixtures();
        $this->actingAs($this->actorFor($action))->command($action, $body)->assertRedirect();
        $outcome = session('workforce_settings_result');
        $this->assertIsArray($outcome);
        session()->forget('workforce_settings_result');
        $before = $this->state();
        $manager = DB::getFacadeRoot();
        Log::partialMock()->shouldReceive('warning')->once()->andThrow(new RuntimeException('Controlled log failure.'));
        if ($failure === 'root check') {
            DB::swap(new class
            {
                public function connection(): never
                {
                    throw new RuntimeException('Private connection detail must not escape.');
                }
            });
        } else {
            $outcome['values'] = null;
        }
        try {
            $response = (new ReflectionMethod(WorkforceSettingsController::class, 'committedResult'))->invoke(
                app(WorkforceSettingsController::class), redirect('/settings-review')->with('success', $this->success($action, true)), true, $action, $outcome,
            );
            $this->assertSame(url('/settings-review'), $response->getTargetUrl());
            $this->assertSame($this->success($action, true), session('success'));
            $this->assertNull(session('workforce_settings_result'));
        } finally {
            DB::swap($manager);
        }
        $this->assertSame($before, $this->state());
    }

    public static function presentationFailures(): array
    {
        return ['personal projection' => ['preferences', 'projection'], 'policy projection' => ['staffing_rules', 'projection'],
            'personal root check' => ['preferences', 'root check'], 'policy root check' => ['staffing_rules', 'root check']];
    }

    public function test_actual_pdo_transaction_without_laravel_level_is_not_a_root_commit_receipt(): void
    {
        $body = $this->body('preferences');
        $this->commitFixtures();
        $this->actingAs($this->personal)->command('preferences', $body)->assertRedirect();
        $outcome = session('workforce_settings_result');
        session()->forget('workforce_settings_result');
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue($pdo->inTransaction());
            (new ReflectionMethod(WorkforceSettingsController::class, 'committedResult'))->invoke(
                app(WorkforceSettingsController::class), redirect('/settings-review'), true, 'preferences', $outcome,
            );
            $this->assertNull(session('workforce_settings_result'));
        } finally {
            $pdo->rollBack();
        }
    }

    public static function actions(): array
    {
        return ['personal' => ['preferences'], 'policy' => ['staffing_rules']];
    }

    private function actor(array $keys): User
    {
        $actor = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $role = Role::create(['name' => 'settings-receipt-'.Str::uuid(), 'label' => 'Settings receipt fixture', 'type' => 'custom', 'level' => 10]);
        $role->permissions()->sync(collect($keys)->map(fn ($key) => Permission::firstOrCreate(['key' => $key],
            ['description' => $key, 'group' => 'Workforce', 'module' => 'operations'])->id));
        $actor->roles()->attach($role);

        return $actor->fresh();
    }

    private function actorFor(string $action): User
    {
        return $action === 'preferences' ? $this->personal : $this->manager;
    }

    private function snapshot(string $action): array
    {
        return $action === 'preferences' ? app(WorkforcePreferences::class)->for($this->personal)
            : app(HrFatiguePolicySettings::class)->snapshot();
    }

    private function body(string $action, bool $changed = true): array
    {
        $before = $this->snapshot($action);

        return $action === 'preferences'
            ? ['expected_revision' => $before['revision'], 'default_tab' => $changed ? 'calendar' : $before['default_tab'], 'roster_view' => $changed ? 'list' : $before['roster_view']]
            : ['expected_revision' => $before['revision'], 'values' => [...$before['values'], 'max_hours_per_day' => $changed ? 8.5 : $before['values']['max_hours_per_day']],
                'reason' => '  Reviewed staffing limits for the next period.  '];
    }

    private function command(string $action, array $body, bool $json = false)
    {
        return $this->{$json ? 'patchJson' : 'patch'}(route($action === 'preferences'
            ? 'operations.workforce.settings.update' : 'operations.workforce.settings.staffing-rules.update'), $body);
    }

    private function success(string $action, bool $changed): string
    {
        return $action === 'preferences' ? 'Your workforce preferences were saved.'
            : ($changed ? 'Staffing rules were saved. Existing assignments are being checked again.' : 'Staffing rules already match the saved values.');
    }

    private function assertReceipt($response, string $action, array $body, array $before, array $after, bool $changed): array
    {
        $expected = ['action' => $action, 'actor_id' => (int) $this->actorFor($action)->id,
            'expected_revision' => $body['expected_revision'], 'prior_revision' => $before['revision'], 'revision' => $after['revision'],
            'values' => $action === 'preferences' ? ['default_tab' => $after['default_tab'], 'roster_view' => $after['roster_view']] : $after['values'], 'changed' => $changed];
        if ($action === 'staffing_rules') {
            $intent = $changed ? $this->policyIntents()->sole() : null;
            $expected['refresh'] = ['status' => $changed ? 'staged' : 'not_requested',
                'recheck_id' => $intent?->id, 'source_version' => $intent?->source_version];
        }
        $response->assertSessionHas('workforce_settings_result', $expected);
        $this->assertSame($expected, session('workforce_settings_result'));
        $this->assertArrayNotHasKey('reason', $expected);
        $this->assertArrayNotHasKey('defaults', $expected);

        return $expected;
    }

    private function policyIntents()
    {
        return WorkforceEligibilityRecheck::where('source_type', 'hr_fatigue_policy');
    }

    private function state(): array
    {
        return collect(['user_ui_preferences', 'app_settings', 'audit_logs', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function commitFixtures(): void
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame(static::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), getmypid()));
        $this->assertSame($connection->getDatabaseName(), $connection->selectOne('SELECT DATABASE() AS selected_database')->selected_database);
        $this->assertSame(1, $connection->transactionLevel());
        DB::commit();
        $this->fixturesCommitted = true;
        Queue::fake([RefreshWorkforceEligibility::class]);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    private function writer(): Connection
    {
        config(['database.connections.settings_evidence_writer' => DB::connection()->getConfig()]);
        DB::purge('settings_evidence_writer');
        $writer = DB::connection('settings_evidence_writer');
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        $this->assertSame($writer->getDatabaseName(), $writer->selectOne('SELECT DATABASE() AS selected_database')->selected_database);
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($writer->getDatabaseName(), getmypid()));
        $this->assertSame(0, $writer->transactionLevel());
        $this->assertFalse($writer->getPdo()->inTransaction());

        return $writer;
    }

    private function withModelListener(string $model, string $event, callable $listener, callable $command): void
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
