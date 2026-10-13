<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AttendanceTimeEntryProjector;
use App\Domain\Hr\Services\HrFatiguePolicySettings;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionApplier;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleSource;
use App\Domain\Shifts\Planning\ShiftPlanningIntent;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\CoverageReservation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftEligibilityOverride;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\User;
use App\Services\CoverageReservationService;
use App\Services\Eligibility\AssignmentEligibilityDecision;
use App\Services\Eligibility\AssignmentEligibilityGateway;
use App\Services\Eligibility\ProposedShiftWorkloadService;
use App\Services\Eligibility\Rules\FatigueRule;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Real care-duty writers, existing grants and approved Sites; no new policy. */
class WorkforceAcceptedWorkloadIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $actor;

    private User $worker;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 04:00:00', 'UTC'));
        config(['app.worker_timezone' => 'Pacific/Auckland', 'features.rostering.auto_schedule' => true,
            'hr.fatigue.max_hours_per_day' => 12, 'hr.fatigue.max_hours_per_week' => 50,
            'hr.fatigue.warning_threshold_weekly' => 40, 'hr.fatigue.min_rest_between_shifts_hours' => 10,
            'hr.fatigue.max_consecutive_days' => 7]);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake();
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null, 'status' => 'active']);
        $this->actor = $this->staff(['rostering.autoSchedule', 'shifts.viewAny', 'shifts.create', 'shifts.update', 'shifts.manageAny', 'shifts.overrideEligibility']);
        $this->worker = $this->staff([]);
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('accepted_workload_writer');
            if ($this->committed && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
            Carbon::setTestNow();
        } finally {
            parent::tearDown();
        }
    }

    public static function cumulativeLimits(): array
    {
        return ['weekly 40 plus 12' => ['weekly'], 'daily 14 despite ten hours rest' => ['daily']];
    }

    #[DataProvider('cumulativeLimits')]
    public function test_individually_allowed_choices_cannot_partially_save_a_cumulative_hard_block(string $limit): void
    {
        if ($limit === 'weekly') {
            $this->existingWeek(5);
            $targets = $this->weekend();
        } else {
            $targets = [$this->duty('2026-10-12 00:00', '2026-10-12 08:00'), $this->duty('2026-10-12 18:00', '2026-10-13 02:00')];
        }
        foreach ($targets as $target) {
            $this->assertTrue(app(ShiftStaffEligibilityService::class)->evaluate($target, $this->worker)->is_allowed);
        }
        [$run] = $this->accepted($targets);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($run): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->assertSame(['applied' => 0, 'stale' => 2, 'failed' => 0], app(RosterSuggestionApplier::class)->applyAccepted($run, $this->actor));
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_each_accepted_choice_records_the_complete_seven_day_warning_and_current_actor(): void
    {
        for ($day = 12; $day <= 16; $day++) {
            $this->duty("2026-10-{$day} 09:00", "2026-10-{$day} 10:00", ['user_id' => $this->worker->id, 'status' => 'scheduled']);
        }
        $targets = [$this->duty('2026-10-17 09:00', '2026-10-17 10:00'), $this->duty('2026-10-18 09:00', '2026-10-18 10:00')];
        foreach ($targets as $target) {
            $result = app(ShiftStaffEligibilityService::class)->evaluate($target, $this->worker);
            $this->assertTrue($result->is_allowed);
            $this->assertNotContains('fatigue_consecutive', array_column($result->overrideable_warnings, 'rule'));
        }
        [$run, $suggestions] = $this->accepted($targets);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($run, $targets, $suggestions): void {
            $this->assertSame(['applied' => 2, 'stale' => 0, 'failed' => 0], app(RosterSuggestionApplier::class)->applyAccepted($run, $this->actor));
            $this->assertApplied($targets, $suggestions, ['fatigue_consecutive']);
        });
    }

    public function test_only_accepted_choices_contribute_and_the_read_response_identifies_the_worker_zone(): void
    {
        $targets = [$this->duty('2026-10-12 09:00', '2026-10-12 13:00'), $this->duty('2026-10-13 09:00', '2026-10-13 13:00')];
        [$run, $suggestions] = $this->accepted($targets);
        // Including this unaccepted nine-hour choice would create a daily hard block.
        $alternativeShift = $this->duty('2026-10-12 13:00', '2026-10-12 22:00');
        $alternative = RosterSuggestion::factory()->create(['roster_suggestion_run_id' => $run->id,
            'shift_id' => $alternativeShift->id, 'candidate_user_id' => $this->worker->id, 'rank' => 1, 'status' => RosterSuggestion::STATUS_SUGGESTED]);
        $untouched = $alternative->fresh()->getRawOriginal();
        $untouchedShift = $alternativeShift->fresh()->getRawOriginal();
        $this->actingAs($this->actor)->get(route('operations.rostering.suggestions.show', $run))->assertOk()
            ->assertInertia(fn ($page) => $page->where('worker_timezone', 'Pacific/Auckland')->where('run.id', $run->id));
        $this->commitFixtures();
        $this->withProductionManager(function () use ($run, $targets, $suggestions, $alternative, $untouched, $alternativeShift, $untouchedShift): void {
            $this->assertSame(['applied' => 2, 'stale' => 0, 'failed' => 0], app(RosterSuggestionApplier::class)->applyAccepted($run, $this->actor));
            $this->assertApplied($targets, $suggestions, ['availability']);
            $this->assertSame($untouched, $alternative->fresh()->getRawOriginal());
            $this->assertSame($untouchedShift, $alternativeShift->fresh()->getRawOriginal());
        });
    }

    public function test_late_override_denial_rolls_back_every_prior_assignment_and_its_history(): void
    {
        $targets = [$this->duty('2026-10-12 09:00', '2026-10-12 13:00'), $this->duty('2026-10-13 09:00', '2026-10-13 13:00')];
        [$run] = $this->accepted($targets);
        $permission = Permission::where('key', 'shifts.overrideEligibility')->firstOrFail();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($run, $targets, $permission): void {
            $before = $this->state();
            $queue = $this->queueState();
            $pivot = DB::table('permission_user')->where('user_id', $this->actor->id)->where('permission_id', $permission->id)->first();
            $this->assertNotNull($pivot);
            $fired = false;
            $this->withModelEvents(function () use ($run, $targets, $permission, &$fired): void {
                Shift::updated(function (Shift $row) use ($targets, $permission, &$fired): void {
                    if (! $fired && $row->id === $targets[0]->id && $row->user_id === $this->worker->id) {
                        $fired = true;
                        DB::table('permission_user')->where('user_id', $this->actor->id)->where('permission_id', $permission->id)->update(['allowed' => false]);
                    }
                });
                $this->assertThrowsStatus(fn () => app(RosterSuggestionApplier::class)->applyAccepted($run, $this->actor), 403);
            });
            $this->assertTrue($fired);
            $this->assertSame((array) $pivot, (array) DB::table('permission_user')->where('user_id', $this->actor->id)->where('permission_id', $permission->id)->first());
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_the_same_single_writer_refreshes_its_memo_after_its_first_actual_assignment(): void
    {
        $this->existingWeek(5);
        [$first, $second] = $this->weekend();
        $this->primeEvaluator($first);
        $lifecycle = app(ShiftLifecycleService::class);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($lifecycle, $first, $second): void {
            $this->assign($lifecycle, $first);
            $this->assertSame($this->worker->id, $first->fresh()->user_id);
            $before = $this->state();
            $queue = $this->queueState();
            try {
                $this->assign($lifecycle, $second);
                $this->fail('The second assignment must observe the first six added hours.');
            } catch (ValidationException $exception) {
                $this->assertSame(422, $exception->status);
                $this->assertStringContainsString('weekly maximum', implode(' ', $exception->errors()['user_id']));
            }
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
            $this->assertCount(1, ShiftEligibilityOverride::whereIn('shift_id', [$first->id, $second->id])->get());
        });
    }

    public static function unavailableEntries(): array
    {
        return ['single current writer' => [false], 'accepted batch' => [true]];
    }

    #[DataProvider('unavailableEntries')]
    public function test_unavailable_current_workload_is_private_and_writes_nothing(bool $batch): void
    {
        $targets = $batch ? $this->weekend() : [$this->duty('2026-10-12 09:00', '2026-10-12 13:00')];
        [$run] = $this->accepted($targets);
        $this->instance(ProposedShiftWorkloadService::class, new class
        {
            public function prepareCurrent(...$arguments): never
            {
                throw new \RuntimeException('Private unavailable duty source fixture.');
            }
        });
        $this->commitFixtures();
        $this->withProductionManager(function () use ($run, $targets, $batch): void {
            $before = $this->state();
            $queue = $this->queueState();
            if ($batch) {
                $this->assertSame(['applied' => 0, 'stale' => 0, 'failed' => 2], app(RosterSuggestionApplier::class)->applyAccepted($run, $this->actor));
            } else {
                try {
                    $this->assign(app(ShiftLifecycleService::class), $targets[0]);
                    $this->fail('Unavailable workload cannot assign.');
                } catch (ValidationException $exception) {
                    $this->assertSame(503, $exception->status);
                    $this->assertSame(['user_id' => [AssignmentEligibilityDecision::UNAVAILABLE_MESSAGE]], $exception->errors());
                }
            }
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function committedDutyDrift(): array
    {
        return ['add eight hours' => ['add', false], 'extend four hours' => ['change', false],
            'cancel eight hours' => ['cancel', true], 'reassign eight hours away' => ['reassign', true]];
    }

    #[DataProvider('committedDutyDrift')]
    public function test_batch_uses_committed_current_duties_over_a_primed_rr_snapshot_and_the_same_fatigue_memo(string $change, bool $allowed): void
    {
        $duties = $this->existingWeek($change === 'add' ? 4 : 5, $change === 'change' ? 4 : 8);
        $targets = $this->weekend();
        [$run, $suggestions] = $this->accepted($targets);
        $otherWorker = $change === 'reassign' ? $this->staff([]) : null;
        $newDuty = $change === 'add' ? $this->duty('2026-10-16 09:00', '2026-10-16 17:00') : null;
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($change, $allowed, $duties, $targets, $run, $suggestions, $otherWorker, $newDuty, $writer): void {
            DB::beginTransaction();
            $oldRows = $this->raw('shifts');
            $this->primeEvaluator($targets[0]);
            $queue = $this->queueState();
            $changed = $newDuty ?? $duties->last();
            $values = match ($change) {
                'add' => ['user_id' => $this->worker->id, 'status' => 'scheduled'],
                'change' => ['ends_at' => Carbon::parse('2026-10-16 17:00', 'Pacific/Auckland')->utc()->format('Y-m-d H:i:s')],
                'cancel' => ['status' => 'cancelled'],
                'reassign' => ['user_id' => $otherWorker->id],
            };
            $writer->transaction(fn () => $writer->table('shifts')->where('id', $changed->id)->update($values));
            $this->assertSame(0, $writer->transactionLevel());
            $this->assertFalse($writer->getPdo()->inTransaction());
            $this->assertSame($oldRows, $this->raw('shifts'), 'Ordinary RR must still expose the primed duty rows.');
            $this->assertNotSame($oldRows, $this->raw('shifts', $writer));
            $currentBefore = $this->state($writer);
            $result = app(RosterSuggestionApplier::class)->applyAccepted($run, $this->actor);
            $this->assertSame(['applied' => $allowed ? 2 : 0, 'stale' => $allowed ? 0 : 2, 'failed' => 0], $result);
            $this->assertSame($queue, $this->queueState(), 'Callbacks must remain deferred until this actual outer root.');
            DB::commit();
            if ($allowed) {
                $this->assertApplied($targets, $suggestions, ['fatigue_weekly']);
                foreach ($duties as $duty) {
                    $expected = collect($currentBefore['shifts'])->firstWhere('id', $duty->id);
                    $this->assertSame($expected, (array) $writer->table('shifts')->where('id', $duty->id)->first());
                }
            } else {
                $this->assertSame($currentBefore, $this->state($writer));
                $this->assertSame($queue, $this->queueState());
            }
        });
    }

    public function test_one_current_policy_snapshot_governs_the_whole_accepted_batch_before_callbacks(): void
    {
        $values = app(HrFatiguePolicySettings::class)->defaults();
        $policy = AppSetting::create(['key' => HrFatiguePolicySettings::KEY, 'value' => ['version' => 1, 'values' => [...$values, 'warning_threshold_weekly' => 20.0]]]);
        $targets = [$this->duty('2026-10-12 09:00', '2026-10-12 13:00'), $this->duty('2026-10-13 09:00', '2026-10-13 13:00')];
        [$run, $suggestions] = $this->accepted($targets);
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($policy, $values, $targets, $run, $suggestions, $writer): void {
            DB::beginTransaction();
            $this->assertSame(20, (int) AppSetting::findOrFail($policy->id)->value['values']['warning_threshold_weekly']);
            $writer->transaction(fn () => $writer->table('app_settings')->where('id', $policy->id)->update([
                'value' => json_encode(['version' => 2, 'values' => [...$values, 'warning_threshold_weekly' => 3.0]], JSON_THROW_ON_ERROR),
            ]));
            $this->assertSame(20, (int) AppSetting::findOrFail($policy->id)->value['values']['warning_threshold_weekly']);
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->assertSame(['applied' => 2, 'stale' => 0, 'failed' => 0], app(RosterSuggestionApplier::class)->applyAccepted($run, $this->actor));
                $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from `app_settings`') && in_array(HrFatiguePolicySettings::KEY, $query['bindings'], true));
                $this->assertCount(1, $queries, 'One current policy must govern preflight and both canonical writes.');
            } finally {
                DB::disableQueryLog();
            }
            foreach ($targets as $target) {
                $override = ShiftEligibilityOverride::where('shift_id', $target->id)->firstOrFail();
                $this->assertContains('fatigue_weekly', $override->rules_overridden);
                $warnings = collect($override->acknowledged_warnings)->keyBy('rule');
                $this->assertStringContainsString('3h weekly warning threshold', $warnings['fatigue_weekly']['message']);
            }
            DB::commit();
            $this->assertApplied($targets, $suggestions, ['fatigue_weekly']);
        });
    }

    public static function lateVetoes(): array
    {
        return ['Shift veto' => ['shift', false], 'Shift altered save' => ['shift', true],
            'suggestion veto' => ['suggestion', false], 'suggestion altered save' => ['suggestion', true]];
    }

    #[DataProvider('lateVetoes')]
    public function test_late_observer_refusal_rolls_back_prior_writes_and_deferred_dispatch(string $model, bool $alter): void
    {
        $targets = [$this->duty('2026-10-12 09:00', '2026-10-12 13:00'), $this->duty('2026-10-13 09:00', '2026-10-13 13:00')];
        [$run, $suggestions] = $this->accepted($targets);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($model, $alter, $targets, $run, $suggestions): void {
            $before = $this->state();
            $queue = $this->queueState();
            $fired = false;
            $this->withModelEvents(function () use ($model, $alter, $targets, $run, $suggestions, &$fired): void {
                $class = $model === 'shift' ? Shift::class : RosterSuggestion::class;
                $class::saving(function ($row) use ($model, $alter, $targets, $suggestions, &$fired) {
                    $id = $model === 'shift' ? $targets[1]->id : $suggestions[1]->id;
                    $ready = $model === 'shift' ? $row->user_id === $this->worker->id : $row->status === RosterSuggestion::STATUS_APPLIED;
                    if ($row->id !== $id || ! $ready) {
                        return null;
                    }
                    $fired = true;
                    if (! $alter) {
                        return false;
                    }
                    $model === 'shift' ? $row->user_id = $this->actor->id : $row->applied_by = $this->worker->id;

                    return null;
                });
                $refused = false;
                try {
                    $result = app(RosterSuggestionApplier::class)->applyAccepted($run, $this->actor);
                    $refused = $result['applied'] === 0 && $result['failed'] > 0;
                } catch (ValidationException|HttpException|\RuntimeException) {
                    $refused = true;
                }
                $this->assertTrue($refused, 'Persisted mismatch must refuse the complete batch.');
            });
            $this->assertTrue($fired, 'The second actual write must reach its observer.');
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function preparedMismatches(): array
    {
        return ['wrong worker' => ['worker'], 'wrong window' => ['window'], 'wrong source Client' => ['client']];
    }

    #[DataProvider('preparedMismatches')]
    public function test_opt_in_gateway_rejects_a_prepared_value_for_a_different_current_candidate(string $change): void
    {
        $target = $this->duty('2026-10-12 09:00', '2026-10-12 13:00');
        $other = $change === 'worker' ? $this->staff([]) : $this->worker;
        $client = $change === 'client' ? Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null]) : $this->client;
        $this->commitFixtures();
        $this->withProductionManager(function () use ($target, $other, $client, $change): void {
            DB::transaction(function () use ($target, $other, $client, $change): void {
                app(AttendanceTimeEntryProjector::class)->lockApplicationPayrollMutex();
                $candidate = clone $target;
                $candidate->user_id = $this->worker->id;
                $workload = app(ProposedShiftWorkloadService::class)->prepareCurrent(collect([$candidate]), [$target->id], $this->actor)->get($this->worker->id);
                $this->assertNotNull($workload);
                $before = $this->state();
                $queue = $this->queueState();
                $changed = clone $candidate;
                if ($change === 'worker') {
                    $changed->user_id = $other->id;
                } elseif ($change === 'window') {
                    $changed->ends_at = $changed->ends_at->copy()->addMinute();
                } else {
                    $changed->client_id = $client->id;
                    $changed->setRelation('client', $client);
                }
                $decision = app(AssignmentEligibilityGateway::class)->decide($changed, $other, workload: $workload);
                try {
                    $decision->assertMayAssign('user_id', 'Cannot assign.');
                    $this->fail('Prepared provenance mismatch must fail closed.');
                } catch (ValidationException $exception) {
                    $this->assertSame(503, $exception->status);
                    $this->assertSame(['user_id' => [AssignmentEligibilityDecision::UNAVAILABLE_MESSAGE]], $exception->errors());
                }
                $this->assertSame($before, $this->state());
                $this->assertSame($queue, $this->queueState());
            });
        });
    }

    public function test_real_create_warning_and_update_retry_keep_receipts_and_owned_hold_cleanup(): void
    {
        $this->existingWeek(5);
        $body = $this->body('2026-10-17 09:00', '2026-10-17 15:00');
        $original = $this->duty('2026-10-18 08:00', '2026-10-18 09:00', ['user_id' => $this->worker->id, 'status' => 'scheduled']);
        $update = $this->body('2026-10-18 09:00', '2026-10-18 15:00');
        // A supplied matching hold isolates writer workload from capacity policy.
        $rule = SiteCoverageRequirement::create(['site_id' => $this->site->id, 'service_context_id' => null,
            'preferred_client_id' => $this->client->id, 'name' => 'Accepted workload retry', 'coverage_type' => 'custom',
            'day_of_week' => 'sun', 'starts_time' => '09:00', 'ends_time' => '15:00', 'minimum_staff' => 1,
            'role_requirements' => [], 'allow_overstaffing' => true, 'shift_type' => 'standard', 'is_active' => true]);
        $hold = CoverageReservation::create(['site_id' => $this->site->id, 'coverage_requirement_id' => $rule->id,
            'reserved_by_user_id' => $this->actor->id, 'reservation_token' => (string) Str::uuid(),
            'status' => CoverageReservationService::STATUS_ACTIVE, 'reason' => 'shift_update',
            'window_starts_at' => $update['starts_at'], 'window_ends_at' => $update['ends_at'], 'expires_at' => now()->addMinutes(15)]);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body, $original, $update, $hold): void {
            $this->actingAs($this->actor)->withHeader('X-Shift-Result', 'committed-v1')->post(route('operations.shifts.store'), $body)
                ->assertRedirect()->assertSessionHasNoErrors();
            $receipt = session('shift_result');
            $this->assertSame('create', $receipt['action']);
            $this->assertSame($this->actor->id, $receipt['actor_id']);
            $this->assertSame('saved', $receipt['outcome']);
            $this->assertSame($this->worker->id, $receipt['user_id']);
            $this->assertSame(ShiftPlanningIntent::hash(ShiftPlanningIntent::normalize($body)), $receipt['values_hash']);
            $this->assertTrue(collect($receipt['assignment_warnings'])->contains(fn ($warning) => str_contains($warning, 'weekly warning threshold')));
            $created = Shift::findOrFail($receipt['shift_id']);
            $this->assertSame(0, ShiftEligibilityOverride::where('shift_id', $created->id)->count());
            // Fixture-only cancellation isolates the update warning from a hard weekly block.
            DB::table('shifts')->where('id', $created->id)->update(['status' => 'cancelled']);
            $before = $this->state();
            $queue = $this->queueState();
            $this->put(route('operations.shifts.update', $original), [...$update, 'coverage_reservation_token' => $hold->reservation_token])
                ->assertRedirect()->assertSessionHasNoErrors();
            $retry = session('shift_result');
            $this->assertSame(['action' => 'update', 'actor_id' => $this->actor->id, 'shift_id' => $original->id, 'scope' => 'single',
                'source' => ShiftPlanningIntent::source($original), 'outcome' => 'not_saved', 'changed' => false,
                'reason' => 'eligibility_warning', 'values_hash' => ShiftPlanningIntent::hash(ShiftPlanningIntent::normalize($update, $original))], $retry);
            $this->assertSame(CoverageReservationService::STATUS_RELEASED, $hold->fresh()->status);
            $this->assertNull($hold->fresh()->shift_id);
            $this->assertSame($queue, $this->queueState());
            foreach ($before as $table => $rows) {
                if (! in_array($table, ['coverage_reservations', 'audit_logs'], true)) {
                    $this->assertSame($rows, $this->state()[$table], $table);
                }
            }
        });
    }

    public static function committedCohortDrift(): array
    {
        return ['run expires' => ['expiry'], 'one accepted choice withdrawn' => ['withdraw']];
    }

    #[DataProvider('committedCohortDrift')]
    public function test_current_run_and_accepted_membership_replace_stale_eager_metadata(string $change): void
    {
        $targets = [$this->duty('2026-10-12 09:00', '2026-10-12 13:00'), $this->duty('2026-10-13 09:00', '2026-10-13 13:00')];
        [$run, $suggestions] = $this->accepted($targets);
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($change, $run, $suggestions, $targets, $writer): void {
            DB::beginTransaction();
            $run->load('suggestions.shift', 'suggestions.candidate');
            $old = $this->state();
            $writer->transaction(function () use ($change, $run, $suggestions, $writer): void {
                if ($change === 'expiry') {
                    $writer->table('roster_suggestion_runs')->where('id', $run->id)->update(['expires_at' => now()->subMinute()->format('Y-m-d H:i:s')]);
                } else {
                    $writer->table('roster_suggestions')->where('id', $suggestions[0]->id)->update(['status' => RosterSuggestion::STATUS_SUGGESTED]);
                }
            });
            $current = $this->state($writer);
            $this->assertNotSame($old, $current);
            $this->assertSame($old, $this->state(), 'Ordinary RR and loaded run metadata must still be stale.');
            $queue = $this->queueState();
            $result = app(RosterSuggestionApplier::class)->applyAccepted($run, $this->actor);
            $this->assertSame($change === 'expiry' ? ['applied' => 0, 'stale' => 2, 'failed' => 0] : ['applied' => 1, 'stale' => 0, 'failed' => 0], $result);
            $this->assertSame($queue, $this->queueState());
            DB::commit();
            if ($change === 'expiry') {
                $this->assertSame($current, $this->state($writer));
                $this->assertSame($queue, $this->queueState());
            } else {
                $this->assertApplied([$targets[1]], collect([$suggestions[1]]), ['availability']);
                $this->assertSame(collect($current['shifts'])->firstWhere('id', $targets[0]->id), (array) $writer->table('shifts')->where('id', $targets[0]->id)->first());
                $this->assertSame(collect($current['roster_suggestions'])->firstWhere('id', $suggestions[0]->id), (array) $writer->table('roster_suggestions')->where('id', $suggestions[0]->id)->first());
            }
        });
    }

    public function test_committed_source_tuple_retarget_cannot_apply_using_primed_old_client_and_context_hints(): void
    {
        $targets = [$this->duty('2026-10-12 09:00', '2026-10-12 13:00'), $this->duty('2026-10-13 09:00', '2026-10-13 13:00')];
        [$run] = $this->accepted($targets);
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['site_id' => $site->id, 'is_active' => true, 'type' => 'residential']);
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        HrEmployeeProfile::whereIn('user_id', [$this->actor->id, $this->worker->id])->update(['secondary_site_ids' => json_encode([$site->id], JSON_THROW_ON_ERROR)]);
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($run, $targets, $site, $context, $client, $writer): void {
            DB::beginTransaction();
            $old = $this->state();
            $writer->transaction(fn () => $writer->table('shifts')->where('id', $targets[1]->id)->update([
                'client_id' => $client->id, 'site_id' => $site->id, 'service_context_id' => $context->id,
            ]));
            $current = $this->state($writer);
            $this->assertSame($old, $this->state());
            $this->assertNotSame($old, $current);
            $queue = $this->queueState();
            $this->assertThrowsStatus(fn () => app(RosterSuggestionApplier::class)->applyAccepted($run, $this->actor), 409);
            $this->assertSame($queue, $this->queueState());
            DB::commit();
            $this->assertSame($current, $this->state($writer));
            $this->assertSame($queue, $this->queueState());
        });
    }

    private function staff(array $keys): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'accepted-workload-'.Str::uuid(), 'label' => 'Workload fixture', 'level' => 10, 'type' => 'custom']);
        $user->roles()->attach($role);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'workforce', 'module' => 'Operations']);
            $user->permissionOverrides()->attach($permission, ['allowed' => true]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null,
            'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function duty(string $start, string $end, array $attributes = []): Shift
    {
        return Shift::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => null, 'user_id' => null, 'status' => 'draft', 'expected_break_minutes' => null,
            'coverage_roles' => [], 'required_licence_class' => null, 'required_licence_endorsements' => [],
            'is_sleepover' => false, 'is_on_call' => false, 'is_lone_worker' => false,
            'starts_at' => Carbon::parse($start, 'Pacific/Auckland')->utc(), 'ends_at' => Carbon::parse($end, 'Pacific/Auckland')->utc(),
            'created_by' => $this->actor->id, ...$attributes]);
    }

    private function existingWeek(int $days, int $lastHours = 8): Collection
    {
        return collect(range(12, 11 + $days))->map(fn ($day) => $this->duty("2026-10-{$day} 09:00", "2026-10-{$day} ".($day === 11 + $days ? 9 + $lastHours : 17).':00',
            ['user_id' => $this->worker->id, 'status' => 'scheduled']));
    }

    private function weekend(): array
    {
        return [$this->duty('2026-10-17 09:00', '2026-10-17 15:00'), $this->duty('2026-10-18 09:00', '2026-10-18 15:00')];
    }

    private function accepted(array $targets): array
    {
        $run = RosterSuggestionRun::factory()->create(['site_id' => $this->site->id, 'requested_by' => $this->actor->id,
            'week_start' => '2026-10-12', 'week_end' => '2026-10-19', 'expires_at' => now()->addDay()]);
        $suggestions = collect($targets)->map(fn (Shift $target) => RosterSuggestion::factory()->create([
            'roster_suggestion_run_id' => $run->id, 'shift_id' => $target->id, 'candidate_user_id' => $this->worker->id,
            'rank' => 1, 'status' => RosterSuggestion::STATUS_ACCEPTED, 'accepted_by' => $this->actor->id, 'accepted_at' => now()]));

        return [$run, $suggestions];
    }

    private function assign(ShiftLifecycleService $lifecycle, Shift $target): Shift
    {
        return $lifecycle->assign($target, $this->actor, $this->worker,
            ['override_acknowledged' => true, 'override_reason' => 'Reviewed current workload and availability.'], source: ShiftLifecycleSource::Bulk);
    }

    private function primeEvaluator(Shift $candidate): void
    {
        $rule = app(FatigueRule::class);
        $this->instance(FatigueRule::class, $rule);
        $eligibility = app(ShiftStaffEligibilityService::class);
        $this->instance(ShiftStaffEligibilityService::class, $eligibility);
        $rule->evaluateAll($candidate, $this->worker);
        $eligibility->evaluate($candidate, $this->worker);
    }

    private function assertApplied(array $targets, Collection $suggestions, array $rules): void
    {
        foreach ($targets as $target) {
            $this->assertSame($this->worker->id, $target->fresh()->user_id);
            $this->assertSame('scheduled', $target->fresh()->status);
            $override = ShiftEligibilityOverride::where('shift_id', $target->id)->firstOrFail();
            $this->assertSame($this->worker->id, $override->user_id);
            $this->assertSame($this->actor->id, $override->overridden_by);
            $this->assertSame('Applied roster suggestion after current eligibility recheck.', $override->override_reason);
            foreach ($rules as $rule) {
                $this->assertContains($rule, $override->rules_overridden);
            }
            $this->assertTrue(DB::table('timeline_events')->where('shift_id', $target->id)->exists());
        }
        foreach ($suggestions as $suggestion) {
            $stored = $suggestion->fresh();
            $this->assertSame(RosterSuggestion::STATUS_APPLIED, $stored->status);
            $this->assertSame($this->actor->id, $stored->accepted_by);
            $this->assertSame($this->actor->id, $stored->applied_by);
            $this->assertNotNull($stored->accepted_at);
            $this->assertNotNull($stored->applied_at);
        }
    }

    private function body(string $start, string $end): array
    {
        return ['client_id' => $this->client->id, 'service_context_id' => null, 'user_id' => $this->worker->id,
            'starts_at' => Carbon::parse($start, 'Pacific/Auckland')->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => Carbon::parse($end, 'Pacific/Auckland')->utc()->format('Y-m-d\TH:i:s\Z'),
            'status' => 'scheduled', 'shift_type' => 'standard', 'expected_break_minutes' => null,
            'is_sleepover' => false, 'is_on_call' => false, 'is_lone_worker' => false, 'coverage_roles' => []];
    }

    public static function planningDutyDrift(): array
    {
        return ['committed addition warns' => [true], 'committed cancellation removes fatigue warning' => [false]];
    }

    #[DataProvider('planningDutyDrift')]
    public function test_single_planning_create_reads_current_duties_despite_the_reused_evaluator_memo(bool $add): void
    {
        $duties = $this->existingWeek($add ? 4 : 5);
        $new = $add ? $this->duty('2026-10-16 09:00', '2026-10-16 17:00') : null;
        $body = $this->body('2026-10-17 09:00', '2026-10-17 15:00');
        $candidate = new Shift([...$body, 'site_id' => $this->site->id]);
        $this->primeEvaluator($candidate);
        $this->commitFixtures();
        $writer = $this->writer();
        $writer->transaction(fn () => $writer->table('shifts')->where('id', ($new ?? $duties->last())->id)->update(
            $add ? ['user_id' => $this->worker->id, 'status' => 'scheduled'] : ['status' => 'cancelled']));
        $prior = $this->raw('shifts', $writer);
        $this->withProductionManager(function () use ($body, $add, $prior, $writer): void {
            $this->actingAs($this->actor)->withHeader('X-Shift-Result', 'committed-v1')->post(route('operations.shifts.store'), $body)
                ->assertRedirect()->assertSessionHasNoErrors();
            $receipt = session('shift_result');
            $this->assertSame('create', $receipt['action']);
            $this->assertSame('saved', $receipt['outcome']);
            $this->assertSame($this->actor->id, $receipt['actor_id']);
            $this->assertSame($this->worker->id, $receipt['user_id']);
            $this->assertSame(ShiftPlanningIntent::hash(ShiftPlanningIntent::normalize($body)), $receipt['values_hash']);
            $fatigue = collect($receipt['assignment_warnings'])->filter(fn ($warning) => str_contains($warning, 'weekly warning threshold'));
            $this->assertCount($add ? 1 : 0, $fatigue);
            $this->assertSame('scheduled', Shift::findOrFail($receipt['shift_id'])->status);
            foreach ($prior as $row) {
                $this->assertSame($row, (array) $writer->table('shifts')->where('id', $row['id'])->first());
            }
        });
    }

    public function test_batch_waits_at_the_context_before_locking_competing_client_user_profile_or_site_rows(): void
    {
        $context = ServiceContext::factory()->create(['site_id' => $this->site->id, 'is_active' => true, 'type' => 'residential']);
        $otherClient = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $targets = [$this->duty('2026-10-12 09:00', '2026-10-12 13:00', ['service_context_id' => $context->id]),
            $this->duty('2026-10-13 09:00', '2026-10-13 13:00', ['client_id' => $otherClient->id, 'service_context_id' => $context->id])];
        [$run, $suggestions] = $this->accepted($targets);
        $this->commitFixtures();
        $prefix = base_path('test-results/accepted-workload-'.Str::uuid());
        $process = $this->contextProcess($run, $prefix);
        $probe = $this->writer();
        try {
            DB::beginTransaction();
            $this->assertNotNull(ServiceContext::whereKey($context->id)->lockForUpdate()->first());
            $this->assertFileDoesNotExist($prefix.'-ready.json');
            $process->start();
            $deadline = microtime(true) + 20;
            while (! is_file($prefix.'-ready.json') && microtime(true) < $deadline && $process->isRunning()) {
                usleep(10000);
            }
            $this->assertFileExists($prefix.'-ready.json', $process->getErrorOutput());
            $ready = json_decode(file_get_contents($prefix.'-ready.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(DB::connection()->getDatabaseName(), $ready['database']);
            $this->assertContextProcessIdentity($process, $ready, $prefix);
            $atMutex = false;
            while (! $atMutex && microtime(true) < $deadline && $process->isRunning()) {
                try {
                    $probe->transaction(fn () => $probe->table('hr_payroll_run_mutexes')->where('key', 'application')->lock('for update nowait')->first());
                } catch (QueryException $exception) {
                    $this->assertSame(3572, (int) ($exception->errorInfo[1] ?? 0));
                    $atMutex = true;
                }
                if (! $atMutex) {
                    // The independent probe has released its mutex; our Context remains held.
                    usleep(10000);
                }
            }
            $this->assertTrue($atMutex, 'The child must hold its application mutex while waiting at Context.');
            $this->assertTrue($process->isRunning());
            foreach ([$this->client->id, $otherClient->id] as $id) {
                $this->assertNotNull(Client::whereKey($id)->lock('for update nowait')->first());
            }
            foreach ([$this->actor->id, $this->worker->id] as $id) {
                $this->assertNotNull(User::whereKey($id)->lock('for update nowait')->first());
                $this->assertNotNull(HrEmployeeProfile::where('user_id', $id)->lock('for update nowait')->first());
            }
            $this->assertNotNull(Site::whereKey($this->site->id)->lock('for update nowait')->first());
            DB::commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['applied' => 2, 'stale' => 0, 'failed' => 0], $result['result']);
            $this->assertSame(0, $result['level']);
            $this->assertFalse($result['pdo_transaction']);
            $this->assertApplied($targets, $suggestions, ['availability']);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(1);
            }
            foreach (['-ready.json', '-absent-config.php', '-absent-routes.php', '-services.php', '-packages.php'] as $suffix) {
                if (is_file($prefix.$suffix)) {
                    $this->assertTrue(unlink($prefix.$suffix));
                }
            }
        }
    }

    private function assertContextProcessIdentity(Process $process, array $ready, string $prefix): void
    {
        $this->assertTrue($process->isRunning());
        $wrapperPid = $process->getPid();
        $this->assertIsInt($wrapperPid);
        $this->assertIsInt($ready['pid']);
        $this->assertGreaterThan(0, $ready['pid']);
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertSame($wrapperPid, $ready['pid']);

            return;
        }

        // Symfony owns cmd.exe on Windows; the ready receipt comes from its PHP child.
        $query = <<<'POWERSHELL'
$records = foreach ($lookup in @(%d, %d)) {
    $record = Get-CimInstance -ClassName Win32_Process -Filter "ProcessId = $lookup" -ErrorAction Stop
    if ($null -eq $record) { throw 'The exact owned process identity is absent.' }
    [ordered]@{ pid = [int]$record.ProcessId; parent = [int]$record.ParentProcessId;
        birth = $record.CreationDate.ToUniversalTime().ToString('o');
        executable = $record.ExecutablePath; command = $record.CommandLine }
}
ConvertTo-Json -InputObject @($records) -Compress
POWERSHELL;
        $identity = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-WindowStyle', 'Hidden', '-Command',
            sprintf($query, $wrapperPid, $ready['pid'])], base_path());
        $identity->setTimeout(10);
        $identity->mustRun();
        [$wrapper, $native] = json_decode($identity->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($wrapperPid, $wrapper['pid']);
        $this->assertSame($ready['pid'], $native['pid']);
        $this->assertSame($wrapperPid, $native['parent']);
        $this->assertSame('cmd.exe', strtolower(basename(str_replace('\\', '/', $wrapper['executable']))));
        $this->assertNotFalse(realpath($native['executable']));
        $normalize = static fn (string $path): string => strtolower(str_replace('\\', '/', $path));
        $this->assertSame($normalize(realpath(PHP_BINARY)), $normalize(realpath($native['executable'])));
        $this->assertTrue(Carbon::parse($native['birth'])->gte(Carbon::parse($wrapper['birth'])));
        $this->assertMatchesRegularExpression('/\A"?'.preg_quote($native['executable'], '/').'"?\s+-r\s/iu', $native['command']);
        $this->assertStringContainsString(basename($prefix), $native['command']);
        $this->assertStringContainsString('RosterSuggestionApplier::class', $native['command']);
        $this->assertTrue($process->isRunning());
        $this->assertSame($wrapperPid, $process->getPid());
    }

    private function contextProcess(RosterSuggestionRun $run, string $prefix): Process
    {
        $config = DB::connection()->getConfig();
        $database = DB::connection()->getDatabaseName();
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($database, getmypid()));
        $this->assertSame(base_path('test-results'), dirname($prefix));
        $this->assertMatchesRegularExpression('/^accepted-workload-[a-f0-9-]+$/D', basename($prefix));
        $cache = ['APP_CONFIG_CACHE' => '-absent-config.php', 'APP_ROUTES_CACHE' => '-absent-routes.php',
            'APP_SERVICES_CACHE' => '-services.php', 'APP_PACKAGES_CACHE' => '-packages.php'];
        $paths = [];
        foreach ($cache as $key => $suffix) {
            $this->assertFileDoesNotExist($prefix.$suffix);
            $paths[$key] = 'test-results/'.basename($prefix).$suffix;
        }
        $code = <<<'PHP'
$op = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$base = $op['base'];
$normalize = static fn ($path) => strtr($path, [chr(92) => '/']);
if (getenv('APP_ENV') !== 'testing' || realpath(getcwd()) !== realpath($base)
    || getenv('APP_BASE_PATH') !== $base || getenv('LARAVEL_STORAGE_PATH') !== $op['storage'] || realpath($op['storage']) !== realpath($base.'/storage')
    || getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== $op['database']
    || getenv('DB_URL') !== '' || getenv('DB_SOCKET') !== ''
    || $normalize(dirname($op['prefix'])) !== $normalize($base.'/test-results')
    || !preg_match('/^accepted-workload-[a-f0-9-]+$/D', basename($op['prefix']))) {
    throw new RuntimeException('Owned child root/environment rejected.');
}
require $base.'/vendor/autoload.php';
Tests\Support\WorkforceEligibilityRuntimeProcess::assertOwnedConnection($op['database'], $op['owner'], getenv('DB_HOST'), getenv('DB_SOCKET'));
Tests\Support\WorkforceEligibilityRuntimeProcess::assertEnvironmentFiles($base, $op['database'], $op['owner'], getenv('CI'), getenv('GITHUB_ACTIONS'));
foreach ([App\Domain\Rostering\AutoSchedule\RosterSuggestionApplier::class, Tests\TestCase::class] as $class) {
    $file = (new ReflectionClass($class))->getFileName();
    if (!$file || !str_starts_with($normalize(realpath($file)), $normalize(realpath($base)).'/')) {
        throw new RuntimeException('Child autoload crossed its reviewed checkout.');
    }
}
Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse($op['clock'])->utc());
Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::parse($op['clock'])->utc());
$app = require $base.'/bootstrap/app.php';
$getters = ['APP_CONFIG_CACHE' => 'getCachedConfigPath', 'APP_ROUTES_CACHE' => 'getCachedRoutesPath',
    'APP_SERVICES_CACHE' => 'getCachedServicesPath', 'APP_PACKAGES_CACHE' => 'getCachedPackagesPath'];
if (realpath($app->basePath()) !== realpath($base) || realpath($app->storagePath()) !== realpath($base.'/storage')) {
    throw new RuntimeException('Child actual base/storage mismatch.');
}
foreach ($getters as $key => $getter) {
    $expected = $base.DIRECTORY_SEPARATOR.$op['paths'][$key];
    if (getenv($key) !== $op['paths'][$key] || $normalize($app->$getter()) !== $normalize($expected)
        || is_file($expected)) {
        throw new RuntimeException('Child actual metadata cache mismatch.');
    }
}
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== $op['database']
    || $db->selectOne('SELECT DATABASE() AS db')->db !== $op['database'] || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()
    || config('queue.default') !== 'sync' || config('cache.default') !== 'array' || config('session.driver') !== 'array'
    || config('mail.default') !== 'array' || !in_array(config('broadcasting.default'), [null,'null'], true)
    || config('app.worker_timezone') !== 'Pacific/Auckland') {
    throw new RuntimeException('Owned child database/transports/root mismatch.');
}
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Notification::fake();
$actor = App\Models\User::findOrFail($op['actor']);
$run = App\Models\RosterSuggestionRun::findOrFail($op['run']);
Illuminate\Support\Facades\Auth::setUser($actor);
config(['features.rostering.auto_schedule' => true]);
$ready = json_encode(['pid' => getmypid(), 'database' => $db->getDatabaseName()], JSON_THROW_ON_ERROR);
if (file_put_contents($op['prefix'].'-ready.json', $ready, LOCK_EX) !== strlen($ready)) {
    throw new RuntimeException('Owned child readiness could not be saved.');
}
$result = $app->make(App\Domain\Rostering\AutoSchedule\RosterSuggestionApplier::class)->applyAccepted($run, $actor);
echo json_encode(['result' => $result, 'level' => $db->transactionLevel(), 'pdo_transaction' => $db->getPdo()->inTransaction()], JSON_THROW_ON_ERROR);
PHP;
        $operation = ['base' => base_path(), 'storage' => storage_path(), 'prefix' => $prefix, 'paths' => $paths, 'database' => $database,
            'owner' => getmypid(), 'actor' => $this->actor->id, 'run' => $run->id, 'clock' => now()->toIso8601String()];
        $process = new Process([PHP_BINARY, '-r', $code, '--', json_encode($operation, JSON_THROW_ON_ERROR)], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_BASE_PATH' => base_path(), 'LARAVEL_STORAGE_PATH' => storage_path(),
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_SOCKET' => '', 'DB_DATABASE' => $database,
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
            'DB_TIMEZONE' => '+00:00', 'DB_EMULATE_PREPARES' => 'true', 'APP_WORKER_TIMEZONE' => 'Pacific/Auckland',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
            'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'error', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', ...$paths]);
        $process->setTimeout(45);

        return $process;
    }

    private function raw(string $table, ?Connection $connection = null): array
    {
        return ($connection ?? DB::connection())->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function state(?Connection $connection = null): array
    {
        $tables = ['shifts', 'roster_suggestion_runs', 'roster_suggestions', 'shift_eligibility_overrides', 'timeline_events',
            'coverage_reservations', 'shift_tasks', 'audit_logs', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations'];

        return collect($tables)->mapWithKeys(fn ($table) => [$table => $this->raw($table, $connection)])->all();
    }

    private function queueState(): array
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
        $this->assertSame($connection->getDatabaseName(), $connection->selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(1, $connection->transactionLevel());
        DB::commit();
        $this->committed = true;
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    private function writer(): Connection
    {
        $name = 'accepted_workload_writer';
        config(['database.connections.'.$name => array_replace(DB::connection()->getConfig(), ['name' => $name])]);
        DB::purge($name);
        $writer = DB::connection($name);
        $this->assertSame($name, $writer->getName());
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($writer->getDatabaseName(), getmypid()));
        $this->assertSame($writer->getDatabaseName(), $writer->selectOne('SELECT DATABASE() AS db')->db);
        $this->assertNotSame(DB::connection()->getPdo(), $writer->getPdo());
        $this->assertSame(0, $writer->transactionLevel());
        $this->assertFalse($writer->getPdo()->inTransaction());

        return $writer;
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

    private function withModelEvents(callable $proof): void
    {
        $events = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $events);
        try {
            $proof();
        } finally {
            Model::setEventDispatcher($events);
        }
    }

    private function assertThrowsStatus(callable $command, int $status): void
    {
        try {
            $command();
            $this->fail('Expected current command denial.');
        } catch (ValidationException $exception) {
            $this->assertSame($status, $exception->status);
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
