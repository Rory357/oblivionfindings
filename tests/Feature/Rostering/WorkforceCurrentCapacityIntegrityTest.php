<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionApplier;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleSource;
use App\Models\Client;
use App\Models\CoverageReservation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\ShiftSeries;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\User;
use App\Services\CoverageReservationService;
use App\Services\ShiftCoverageService;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Existing capacity/hold policy; one organisation, approved Sites and canonical duty ownership. */
class WorkforceCurrentCapacityIntegrityTest extends TestCase
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
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland', 'features.rostering.auto_schedule' => true]);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake();
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null, 'status' => 'active']);
        $this->actor = $this->person(['rostering.autoSchedule', 'shifts.viewAny', 'shifts.manageAny', 'shifts.overrideEligibility']);
        $this->worker = $this->person();
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('current_capacity_writer');
            if ($this->committed && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
            Carbon::setTestNow();
        } finally {
            parent::tearDown();
        }
    }

    public static function overstaffingPolicies(): array
    {
        return ['disabled denies the second duty' => [false], 'enabled permits the second duty' => [true]];
    }

    #[DataProvider('overstaffingPolicies')]
    public function test_the_same_writer_and_coverage_memo_use_capacity_after_the_first_distinct_worker_assignment(bool $allowed): void
    {
        $this->rule(['allow_overstaffing' => $allowed]);
        $first = $this->duty();
        $second = $this->duty();
        $other = $this->person();
        $coverage = $this->reuseCoverage();
        $this->assertSame('under', $coverage->coverageStatusForShift($first)['coverage_state']);
        $lifecycle = app(ShiftLifecycleService::class);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($first, $second, $other, $lifecycle, $allowed): void {
            $this->assign($lifecycle, $first, $this->worker);
            $this->assertSame($this->worker->id, $first->fresh()->user_id);
            $before = $this->state();
            $queue = $this->queueState();
            if ($allowed) {
                $this->assign($lifecycle, $second, $other);
                $this->assertSame($other->id, $second->fresh()->user_id);
                $this->assertSame('scheduled', $second->fresh()->status);
                $this->assertSame(2, Shift::whereIn('id', [$first->id, $second->id])->whereNotNull('user_id')->count());
            } else {
                $this->assertOverfill(fn () => $this->assign($lifecycle, $second, $other));
                $this->assertSame($before, $this->state());
                $this->assertSame($queue, $this->queueState());
                $this->assertNull($second->fresh()->user_id);
                $this->assertSame('draft', $second->fresh()->status);
            }
            $this->assertTrue(DB::table('timeline_events')->where('shift_id', $first->id)->exists());
        });
    }

    public function test_a_second_current_capacity_denial_rolls_back_the_whole_accepted_distinct_worker_batch(): void
    {
        $this->rule();
        $targets = [$this->duty(), $this->duty()];
        $workers = [$this->worker, $this->person()];
        $run = RosterSuggestionRun::factory()->create(['site_id' => $this->site->id, 'requested_by' => $this->actor->id,
            'week_start' => '2026-10-12', 'week_end' => '2026-10-19', 'expires_at' => now()->addDay()]);
        foreach ($targets as $index => $target) {
            RosterSuggestion::factory()->create(['roster_suggestion_run_id' => $run->id, 'shift_id' => $target->id,
                'candidate_user_id' => $workers[$index]->id, 'rank' => 1, 'status' => RosterSuggestion::STATUS_ACCEPTED,
                'accepted_by' => $this->actor->id, 'accepted_at' => now()]);
        }
        $coverage = $this->reuseCoverage();
        $this->assertSame('under', $coverage->coverageStatusForShift($targets[0])['coverage_state']);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($run, $targets): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->assertOverfill(fn () => app(RosterSuggestionApplier::class)->applyAccepted($run, $this->actor));
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
            foreach ($targets as $target) {
                $this->assertNull($target->fresh()->user_id);
                $this->assertSame('draft', $target->fresh()->status);
            }
        });
    }

    public static function committedSupplyChanges(): array
    {
        return ['added assignee fills capacity' => ['add', false], 'cancellation removes supply' => ['cancel', true],
            'moved duty leaves the window' => ['move', true], 'cleared assignee leaves an open duty' => ['clear', true]];
    }

    #[DataProvider('committedSupplyChanges')]
    public function test_assignment_uses_committed_supply_despite_primed_rr_rows_and_advisory_memo(string $change, bool $allowed): void
    {
        $this->rule();
        $other = $this->person();
        $supply = $this->duty($change === 'add' ? [] : ['user_id' => $other->id, 'status' => 'scheduled']);
        $target = $this->duty();
        $coverage = $this->reuseCoverage();
        $lifecycle = app(ShiftLifecycleService::class);
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($change, $allowed, $other, $supply, $target, $coverage, $lifecycle, $writer): void {
            DB::beginTransaction();
            $old = $this->state();
            $hint = $coverage->coverageStatusForShift($target);
            $this->assertSame($change === 'add' ? 'under' : 'exact', $hint['coverage_state']);
            $values = match ($change) {
                'add' => ['user_id' => $other->id, 'status' => 'scheduled'],
                'cancel' => ['status' => 'cancelled'],
                'clear' => ['user_id' => null],
                'move' => ['starts_at' => $this->instant('13:00')->format('Y-m-d H:i:s'), 'ends_at' => $this->instant('17:00')->format('Y-m-d H:i:s')],
            };
            $writer->transaction(fn () => $writer->table('shifts')->where('id', $supply->id)->update($values));
            $this->assertSame($old, $this->state(), 'The ordinary RR view must still contain the pre-commit duty.');
            $current = $this->state($writer);
            $this->assertNotSame($old, $current);
            $queue = $this->queueState();
            if ($allowed) {
                $this->assign($lifecycle, $target, $this->worker);
            } else {
                $this->assertOverfill(fn () => $this->assign($lifecycle, $target, $this->worker));
            }
            $this->assertSame($queue, $this->queueState(), 'Callbacks stay deferred in this real outer transaction.');
            DB::commit();
            if ($allowed) {
                $this->assertSame($this->worker->id, $target->fresh()->user_id);
                $this->assertSame($current['site_coverage_requirements'], $this->raw('site_coverage_requirements', $writer));
                $this->assertSame(collect($current['shifts'])->firstWhere('id', $supply->id), (array) $writer->table('shifts')->where('id', $supply->id)->first());
            } else {
                $this->assertSame($current, $this->state($writer));
                $this->assertSame($queue, $this->queueState());
            }
        });
    }

    public static function committedDemandChanges(): array
    {
        return ['raised minimum creates capacity' => [1, 2, true], 'lowered minimum removes capacity' => [2, 1, false]];
    }

    #[DataProvider('committedDemandChanges')]
    public function test_current_assignment_consumes_the_committed_rule_instead_of_a_primed_demand_snapshot(int $oldMinimum, int $newMinimum, bool $allowed): void
    {
        $rule = $this->rule(['minimum_staff' => $oldMinimum]);
        $supply = $this->duty(['user_id' => $this->person()->id, 'status' => 'scheduled']);
        $target = $this->duty();
        $coverage = $this->reuseCoverage();
        $lifecycle = app(ShiftLifecycleService::class);
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($rule, $oldMinimum, $newMinimum, $allowed, $supply, $target, $coverage, $lifecycle, $writer): void {
            DB::beginTransaction();
            $old = $this->state();
            $this->assertSame($oldMinimum, $coverage->coverageStatusForShift($target)['required_staff']);
            $writer->transaction(fn () => $writer->table('site_coverage_requirements')->where('id', $rule->id)->update(['minimum_staff' => $newMinimum]));
            $this->assertSame($old, $this->state());
            $this->assertSame($oldMinimum, $rule->fresh()->minimum_staff);
            $current = $this->state($writer);
            $queue = $this->queueState();
            if ($allowed) {
                $this->assign($lifecycle, $target, $this->worker);
            } else {
                $this->assertOverfill(fn () => $this->assign($lifecycle, $target, $this->worker));
            }
            $this->assertSame($queue, $this->queueState());
            DB::commit();
            $this->assertSame($newMinimum, $rule->fresh()->minimum_staff);
            $this->assertSame(collect($current['shifts'])->firstWhere('id', $supply->id), (array) $writer->table('shifts')->where('id', $supply->id)->first());
            if ($allowed) {
                $this->assertSame($this->worker->id, $target->fresh()->user_id);
            } else {
                $this->assertSame($current, $this->state($writer));
                $this->assertSame($queue, $this->queueState());
            }
        });
    }

    public static function reservationEntries(): array
    {
        return ['QuickFill' => ['quick'], 'automatic planning hold' => ['payload'], 'assignment hold' => ['assignment']];
    }

    #[DataProvider('reservationEntries')]
    public function test_every_reservation_entry_counts_a_committed_foreign_hold_hidden_by_its_rr_snapshot(string $entry): void
    {
        $rule = $this->rule(['minimum_staff' => $entry === 'assignment' ? 2 : 1]);
        $target = $entry === 'assignment' ? $this->duty() : null;
        $other = $this->person();
        $coverage = $this->reuseCoverage();
        $reservations = app(CoverageReservationService::class);
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($entry, $rule, $target, $other, $coverage, $reservations, $writer): void {
            DB::beginTransaction();
            $old = $this->state();
            $this->assertSame(1, $this->window($coverage)['unfilled_after_open_shifts']);
            $hold = $writer->transaction(fn () => CoverageReservation::on($writer->getName())->create($this->holdValues($rule, $other)));
            $this->assertSame($old, $this->state());
            $current = $this->state($writer);
            $this->assertNotSame($old, $current);
            $queue = $this->queueState();
            $this->assertCoverageDenied(fn () => $this->reserve($reservations, $entry, $rule, $target));
            $this->assertSame($queue, $this->queueState());
            DB::commit();
            $this->assertSame($current, $this->state($writer));
            $this->assertSame(1, CoverageReservation::where('site_id', $this->site->id)->count());
            $this->assertSame($other->id, $hold->fresh()->reserved_by_user_id);
            $this->assertSame(CoverageReservationService::STATUS_ACTIVE, $hold->fresh()->status);
        });
    }

    public static function freedHolds(): array
    {
        return ['QuickFill current release' => ['quick', 'release'], 'automatic current release' => ['payload', 'release'],
            'QuickFill current expiry' => ['quick', 'expire'], 'automatic current expiry' => ['payload', 'expire']];
    }

    #[DataProvider('freedHolds')]
    public function test_current_release_or_expiry_frees_the_recorded_slot_without_reusing_the_old_hold(string $entry, string $change): void
    {
        $rule = $this->rule();
        $hold = CoverageReservation::create($this->holdValues($rule, $this->person()));
        $coverage = $this->reuseCoverage();
        $reservations = app(CoverageReservationService::class);
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($entry, $change, $rule, $hold, $coverage, $reservations, $writer): void {
            DB::beginTransaction();
            $old = $this->state();
            $this->assertTrue(collect($this->window($coverage)['coverage_slots'])->contains(fn ($slot) => $slot['status'] === 'reserved'));
            $writer->transaction(fn () => $writer->table('coverage_reservations')->where('id', $hold->id)->update(
                $change === 'release' ? ['status' => CoverageReservationService::STATUS_RELEASED] : ['expires_at' => now()->subSecond()->format('Y-m-d H:i:s')]));
            $this->assertSame($old, $this->state());
            $currentHold = (array) $writer->table('coverage_reservations')->where('id', $hold->id)->first();
            $queue = $this->queueState();
            $new = $this->reserve($reservations, $entry, $rule);
            $this->assertNotNull($new);
            $this->assertNotSame($hold->id, $new->id);
            $this->assertSame($this->actor->id, $new->reserved_by_user_id);
            $this->assertSame($rule->id, $new->coverage_requirement_id);
            $this->assertHoldWindow($new);
            $this->assertSame($queue, $this->queueState());
            DB::commit();
            $this->assertSame([...$currentHold, 'status' => $change === 'release' ? CoverageReservationService::STATUS_RELEASED : CoverageReservationService::STATUS_EXPIRED],
                (array) $writer->table('coverage_reservations')->where('id', $hold->id)->first());
            $this->assertSame(1, CoverageReservation::where('site_id', $this->site->id)->where('status', CoverageReservationService::STATUS_ACTIVE)->count());
            $this->assertSame(2, CoverageReservation::where('site_id', $this->site->id)->count());
        });
    }

    public static function dedupEntries(): array
    {
        return ['current QuickFill owner' => ['quick', 'quick_fill'], 'current automatic owner' => ['payload', 'current_capacity_fixture']];
    }

    #[DataProvider('dedupEntries')]
    public function test_current_deduplication_returns_the_committed_owned_token_and_merged_metadata_without_a_duplicate(string $entry, string $reason): void
    {
        $rule = $this->rule();
        $coverage = $this->reuseCoverage();
        $reservations = app(CoverageReservationService::class);
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($entry, $reason, $rule, $coverage, $reservations, $writer): void {
            DB::beginTransaction();
            $old = $this->state();
            $this->window($coverage);
            $hold = $writer->transaction(fn () => CoverageReservation::on($writer->getName())->create([
                ...$this->holdValues($rule, $this->actor), 'reason' => $reason, 'meta' => ['retained' => 'recorded fixture'], 'expires_at' => now()->addMinute(),
            ]));
            $this->assertSame($old, $this->state());
            $queue = $this->queueState();
            $actual = $this->reserve($reservations, $entry, $rule);
            $this->assertNotNull($actual, 'The result must come from the current locked command, not a stale fresh() query.');
            $this->assertSame($hold->id, $actual->id);
            $this->assertSame($hold->reservation_token, $actual->reservation_token);
            $this->assertSame('recorded fixture', $actual->meta['retained']);
            if ($entry === 'quick') {
                $this->assertTrue($actual->meta['reviewed']);
            }
            $this->assertTrue($actual->expires_at->equalTo(now()->addMinutes($entry === 'quick' ? 7 : 5)));
            $this->assertHoldWindow($actual);
            $this->assertSame($queue, $this->queueState());
            DB::commit();
            $this->assertSame(1, CoverageReservation::where('site_id', $this->site->id)->count());
            $this->assertSame($this->actor->id, $actual->fresh()->reserved_by_user_id);
        });
    }

    public function test_a_committed_driver_hold_consumes_only_the_existing_driver_shortage(): void
    {
        $this->roleHoldCase('driver', false);
    }

    public function test_another_role_hold_does_not_erase_the_existing_driver_shortage(): void
    {
        $this->roleHoldCase('caregiver', true);
    }

    private function roleHoldCase(string $heldRole, bool $allowed): void
    {
        $rule = $this->rule(['minimum_staff' => 2, 'role_requirements' => ['driver' => 1, 'caregiver' => 2]]);
        $this->duty(['user_id' => $this->person()->id, 'status' => 'scheduled']);
        $other = $this->person();
        $coverage = $this->reuseCoverage();
        $reservations = app(CoverageReservationService::class);
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($heldRole, $allowed, $rule, $other, $coverage, $reservations, $writer): void {
            DB::beginTransaction();
            $old = $this->state();
            $shortage = collect($this->window($coverage)['planned_role_shortages'])->keyBy('key');
            $this->assertSame(1, $shortage['driver']['missing']);
            $writer->transaction(fn () => CoverageReservation::on($writer->getName())->create([...$this->holdValues($rule, $other), 'role_key' => $heldRole]));
            $this->assertSame($old, $this->state());
            $current = $this->state($writer);
            $queue = $this->queueState();
            $action = fn () => $reservations->reserveForCoveragePayload($this->actor, [...$this->payload($rule), 'role_key' => 'driver'], 'current_capacity_fixture');
            if ($allowed) {
                $hold = $action();
                $this->assertNotNull($hold);
                $this->assertSame('driver', $hold->role_key);
                $this->assertHoldWindow($hold);
            } else {
                $this->assertCoverageDenied($action);
            }
            $this->assertSame($queue, $this->queueState());
            DB::commit();
            if ($allowed) {
                $this->assertSame(2, CoverageReservation::where('site_id', $this->site->id)->count());
                foreach ($current['coverage_reservations'] as $prior) {
                    $this->assertSame($prior, (array) $writer->table('coverage_reservations')->where('id', $prior['id'])->first());
                }
            } else {
                $this->assertSame($current, $this->state($writer));
            }
        });
    }

    public static function committedDriverChanges(): array
    {
        return ['revoked driver eligibility opens the role shortage' => [true, false], 'current eligible driver closes the role shortage' => [false, true]];
    }

    #[DataProvider('committedDriverChanges')]
    public function test_current_role_capacity_uses_committed_driver_evidence_instead_of_the_primed_related_row(bool $oldEligible, bool $newEligible): void
    {
        $rule = $this->rule(['role_requirements' => ['driver' => 1]]);
        $supplier = $this->person();
        $this->duty(['user_id' => $supplier->id, 'status' => 'scheduled']);
        $driver = HrDriverEligibility::create(['user_id' => $supplier->id, 'licence_number' => 'CURRENT-CAPACITY-FIXTURE',
            'licence_class' => '1', 'licence_endorsements' => [], 'licence_expires_at' => '2028-10-01',
            'status' => 'eligible', 'can_drive_clients' => $oldEligible, 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        $coverage = $this->reuseCoverage();
        $reservations = app(CoverageReservationService::class);
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($oldEligible, $newEligible, $rule, $driver, $coverage, $reservations, $writer): void {
            DB::beginTransaction();
            $old = $this->state();
            $oldWindow = $this->window($coverage);
            $this->assertSame(! $oldEligible, collect($oldWindow['planned_role_shortages'])->contains(fn ($role) => $role['key'] === 'driver'));
            $writer->transaction(fn () => $writer->table('hr_driver_eligibility')->where('id', $driver->id)->update(['can_drive_clients' => $newEligible]));
            $this->assertSame($oldEligible, $driver->fresh()->can_drive_clients);
            $this->assertSame($old, $this->state(), 'The ordinary related driver record remains in the primed RR view.');
            $current = $this->state($writer);
            $this->assertNotSame($old, $current);
            $queue = $this->queueState();
            $hold = $reservations->reserveForCoveragePayload($this->actor, [...$this->payload($rule), 'role_key' => 'driver'], 'current_capacity_fixture');
            if ($newEligible) {
                $this->assertNull($hold, 'A current eligible assigned driver already fills the recorded role requirement.');
            } else {
                $this->assertNotNull($hold);
                $this->assertSame('driver', $hold->role_key);
                $this->assertSame($this->actor->id, $hold->reserved_by_user_id);
                $this->assertHoldWindow($hold);
            }
            $this->assertSame($queue, $this->queueState());
            DB::commit();
            if ($newEligible) {
                $this->assertSame($current, $this->state($writer));
            } else {
                $this->assertSame(1, CoverageReservation::where('site_id', $this->site->id)->count());
                foreach (array_diff(array_keys($current), ['coverage_reservations']) as $table) {
                    $this->assertSame($current[$table], $this->raw($table, $writer));
                }
            }
            $this->assertSame($oldWindow, $this->window($coverage), 'Current mutations do not overwrite the advisory memo.');
        });
    }

    public function test_current_series_counters_and_capacity_include_an_independently_committed_occurrence(): void
    {
        $this->rule(['minimum_staff' => 2]);
        $series = ShiftSeries::create(['site_id' => $this->site->id, 'client_id' => $this->client->id, 'service_context_id' => null,
            'user_id' => null, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'timezone' => 'Pacific/Auckland',
            'by_weekday' => ['mon'], 'starts_time' => '08:00', 'ends_time' => '12:00', 'status' => 'scheduled', 'coverage_roles' => [], 'created_by' => $this->actor->id]);
        $occurrence = $this->duty(['shift_series_id' => $series->id, 'status' => 'cancelled']);
        $coverage = $this->reuseCoverage();
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($series, $occurrence, $coverage, $writer): void {
            DB::beginTransaction();
            $old = $this->state();
            $oldWindow = $this->window($coverage);
            $this->assertSame(0, $oldWindow['matching_series'][0]['active_occurrences_count']);
            $writer->transaction(fn () => $writer->table('shifts')->where('id', $occurrence->id)->update(['status' => 'scheduled']));
            $this->assertSame($old, $this->state());
            $current = $this->state($writer);
            $queue = $this->queueState();
            $windows = $coverage->buildRangeCoverage($this->instant(), $this->instant('12:00'), $this->site->id, current: true);
            $this->assertCount(1, $windows);
            $actual = $windows[0];
            $matching = collect($actual['matching_series'])->firstWhere('id', $series->id);
            $this->assertNotNull($matching);
            $this->assertSame(1, $matching['active_occurrences_count']);
            $this->assertSame(1, $matching['open_occurrences_count']);
            $this->assertTrue(Carbon::parse($matching['next_starts_at'])->equalTo($this->instant()));
            $this->assertSame(1, $actual['open_shifts']);
            $this->assertSame(1, $actual['unfilled_after_open_shifts']);
            $this->assertSame([$occurrence->id], $actual['open_shift_ids']);
            $this->assertSame($queue, $this->queueState());
            DB::commit();
            $this->assertSame($current, $this->state($writer));
            $this->assertSame($oldWindow, $this->window($coverage), 'Default advisory memo remains deliberately unchanged.');
        });
    }

    public static function payloadCompatibility(): array
    {
        return ['missing site returns null' => ['missing'], 'outside a rule returns null' => ['outside'],
            'already planned headcount returns null' => ['filled'], 'legacy null rule token remains owned' => ['null_rule']];
    }

    #[DataProvider('payloadCompatibility')]
    public function test_current_reservation_reads_preserve_supported_noop_and_legacy_token_context(string $case): void
    {
        $rule = $this->rule();
        if ($case === 'filled') {
            $this->duty();
        }
        $reservations = app(CoverageReservationService::class);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($case, $rule, $reservations): void {
            $before = $this->state();
            $queue = $this->queueState();
            if ($case === 'null_rule') {
                $hold = $reservations->createQuickFillReservation($this->actor, $this->site->id, $this->instant(), $this->instant('12:00'));
                $this->assertNull($hold->coverage_requirement_id);
                $this->assertSame($this->actor->id, $hold->reserved_by_user_id);
                $this->assertHoldWindow($hold);
                $actual = $reservations->validateToken($hold->reservation_token, $this->actor, ['site_id' => $this->site->id,
                    'coverage_requirement_id' => null, 'window_starts_at' => $this->instant(), 'window_ends_at' => $this->instant('12:00')]);
                $this->assertSame($hold->id, $actual->id);
                $this->assertSame(1, CoverageReservation::where('site_id', $this->site->id)->count());
            } else {
                $payload = match ($case) {
                    'missing' => ['starts_at' => $this->instant(), 'ends_at' => $this->instant('12:00')],
                    'outside' => [...$this->payload($rule), 'starts_at' => $this->instant('13:00'), 'ends_at' => $this->instant('17:00')],
                    default => $this->payload($rule),
                };
                $this->assertNull($reservations->reserveForCoveragePayload($this->actor, $payload, 'current_capacity_fixture'));
                $this->assertSame($before, $this->state());
            }
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function reportBypassRevocations(): array
    {
        return ['unscoped actor loses the bypass' => [false], 'profile Site access survives bypass revocation' => [true]];
    }

    #[DataProvider('reportBypassRevocations')]
    public function test_reservation_uses_current_reports_authority_without_removing_existing_profile_site_access(bool $profileScoped): void
    {
        $rule = $this->rule();
        $this->actor = $this->person(['reports.viewAny']);
        if (! $profileScoped) {
            HrEmployeeProfile::where('user_id', $this->actor->id)->update(['primary_site_id' => null]);
        }
        $permission = Permission::where('key', 'reports.viewAny')->firstOrFail();
        $reservations = app(CoverageReservationService::class);
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($profileScoped, $rule, $permission, $reservations, $writer): void {
            DB::beginTransaction();
            $this->assertTrue($this->actor->canDo('reports.viewAny'));
            $this->assertSame(1, (int) DB::table('permission_user')->where('user_id', $this->actor->id)
                ->where('permission_id', $permission->id)->value('allowed'));
            $writer->transaction(fn () => $writer->table('permission_user')->where('user_id', $this->actor->id)
                ->where('permission_id', $permission->id)->update(['allowed' => false]));
            $this->assertSame(0, (int) $writer->table('permission_user')->where('user_id', $this->actor->id)
                ->where('permission_id', $permission->id)->value('allowed'));
            $this->assertSame(1, (int) DB::table('permission_user')->where('user_id', $this->actor->id)
                ->where('permission_id', $permission->id)->value('allowed'), 'The ordinary RR grant remains demonstrably stale.');
            $this->assertTrue($this->actor->canDo('reports.viewAny'), 'The same preloaded actor still holds the old grant.');
            $before = $this->state($writer);
            $queue = $this->queueState();
            if ($profileScoped) {
                $hold = $this->reserve($reservations, 'quick', $rule);
                $this->assertNotNull($hold);
                $this->assertSame($this->actor->id, $hold->reserved_by_user_id);
                $this->assertHoldWindow($hold);
            } else {
                try {
                    $this->reserve($reservations, 'quick', $rule);
                    $this->fail('A revoked reports bypass cannot authorize an unscoped actor.');
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }
            }
            $this->assertSame($queue, $this->queueState());
            DB::commit();
            $this->assertSame(0, (int) $writer->table('permission_user')->where('user_id', $this->actor->id)
                ->where('permission_id', $permission->id)->value('allowed'));
            if ($profileScoped) {
                $this->assertSame(1, CoverageReservation::where('site_id', $this->site->id)->count());
                foreach (array_diff(array_keys($before), ['coverage_reservations']) as $table) {
                    $this->assertSame($before[$table], $this->raw($table, $writer));
                }
            } else {
                $this->assertSame($before, $this->state($writer));
            }
        });
    }

    public function test_a_native_held_demand_row_refuses_the_current_read_without_a_partial_hold(): void
    {
        $rule = $this->rule();
        $reservations = app(CoverageReservationService::class);
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($rule, $reservations, $writer): void {
            $before = $this->state();
            $queue = $this->queueState();
            $writer->beginTransaction();
            try {
                $this->assertNotNull($writer->table('site_coverage_requirements')->where('id', $rule->id)->lockForUpdate()->first());
                try {
                    $this->reserve($reservations, 'quick', $rule);
                    $this->fail('A current NOWAIT demand read must not certify an unlocked capacity snapshot.');
                } catch (QueryException $exception) {
                    $this->assertSame(3572, (int) ($exception->errorInfo[1] ?? 0));
                }
                $this->assertSame($before, $this->state());
                $this->assertSame($queue, $this->queueState());
            } finally {
                $writer->rollBack();
            }
        });
    }

    private function rule(array $values = []): SiteCoverageRequirement
    {
        return SiteCoverageRequirement::create(['site_id' => $this->site->id, 'service_context_id' => null,
            'name' => 'Current capacity development fixture', 'coverage_type' => 'custom', 'day_of_week' => 'mon',
            'starts_time' => '08:00', 'ends_time' => '12:00', 'minimum_staff' => 1, 'role_requirements' => [],
            'allow_overstaffing' => false, 'is_active' => true, ...$values]);
    }

    private function person(array $keys = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'current-capacity-'.Str::uuid(), 'label' => 'Capacity fixture', 'level' => 10, 'type' => 'custom']);
        $user->roles()->attach($role);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'workforce', 'module' => 'Operations']);
            $user->permissionOverrides()->attach($permission, ['allowed' => true]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function instant(string $time = '08:00'): Carbon
    {
        return Carbon::parse('2026-10-12 '.$time, 'Pacific/Auckland')->utc();
    }

    private function duty(array $values = []): Shift
    {
        return Shift::factory()->create(['site_id' => $this->site->id, 'client_id' => $this->client->id, 'service_context_id' => null,
            'user_id' => null, 'status' => 'draft', 'created_by' => $this->actor->id, 'starts_at' => $this->instant(), 'ends_at' => $this->instant('12:00'),
            'coverage_roles' => [], 'required_licence_class' => null, 'required_licence_endorsements' => [], 'expected_break_minutes' => null,
            'is_sleepover' => false, 'is_on_call' => false, 'is_lone_worker' => false, ...$values]);
    }

    private function reuseCoverage(): ShiftCoverageService
    {
        $coverage = app(ShiftCoverageService::class);
        $this->instance(ShiftCoverageService::class, $coverage);
        $this->instance(ShiftStaffEligibilityService::class, app(ShiftStaffEligibilityService::class));

        return $coverage;
    }

    private function window(ShiftCoverageService $coverage): array
    {
        $windows = $coverage->buildRangeCoverage($this->instant(), $this->instant('12:00'), $this->site->id);
        $this->assertCount(1, $windows);

        return $windows[0];
    }

    private function assign(ShiftLifecycleService $lifecycle, Shift $target, User $worker): Shift
    {
        return $lifecycle->assign($target, $this->actor, $worker,
            ['override_acknowledged' => true, 'override_reason' => 'Recorded capacity and availability reviewed.'], source: ShiftLifecycleSource::Bulk);
    }

    private function payload(SiteCoverageRequirement $rule): array
    {
        return ['site_id' => $this->site->id, 'coverage_rule_id' => $rule->id, 'starts_at' => $this->instant(), 'ends_at' => $this->instant('12:00')];
    }

    private function holdValues(SiteCoverageRequirement $rule, User $owner): array
    {
        return ['site_id' => $this->site->id, 'coverage_requirement_id' => $rule->id, 'reserved_by_user_id' => $owner->id,
            'reservation_token' => (string) Str::uuid(), 'status' => CoverageReservationService::STATUS_ACTIVE, 'reason' => 'quick_fill',
            'role_key' => null, 'window_starts_at' => $this->instant(), 'window_ends_at' => $this->instant('12:00'),
            'expires_at' => now()->addMinutes(10), 'meta' => ['fixture' => 'recorded capacity']];
    }

    private function reserve(CoverageReservationService $service, string $entry, SiteCoverageRequirement $rule, ?Shift $target = null): ?CoverageReservation
    {
        return match ($entry) {
            'quick' => $service->createQuickFillReservation($this->actor, $this->site->id, $this->instant(), $this->instant('12:00'), $rule->id, null, ['reviewed' => true], 7),
            'payload' => $service->reserveForCoveragePayload($this->actor, $this->payload($rule), 'current_capacity_fixture'),
            default => $service->reserveForAssignment($target, $this->actor, 'current_capacity_fixture'),
        };
    }

    private function assertOverfill(callable $action): void
    {
        try {
            $action();
            $this->fail('The current filled window must refuse another assigned worker.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertStringContainsString('overstaffing is disabled', implode(' ', $exception->errors()['user_id']));
        }
    }

    private function assertCoverageDenied(callable $action): void
    {
        try {
            $action();
            $this->fail('An independently committed active hold must consume the same recorded capacity.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('coverage', $exception->errors());
        }
    }

    private function assertHoldWindow(CoverageReservation $hold): void
    {
        $this->assertTrue($hold->window_starts_at->equalTo($this->instant()));
        $this->assertTrue($hold->window_ends_at->equalTo($this->instant('12:00')));
        $this->assertSame('2026-10-11 19:00:00', $hold->getRawOriginal('window_starts_at'));
        $this->assertSame('2026-10-11 23:00:00', $hold->getRawOriginal('window_ends_at'));
    }

    private function raw(string $table, ?Connection $connection = null): array
    {
        return ($connection ?? DB::connection())->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function state(?Connection $connection = null): array
    {
        $tables = ['shifts', 'shift_series', 'hr_driver_eligibility', 'site_coverage_requirements', 'coverage_reservations', 'roster_suggestion_runs', 'roster_suggestions',
            'shift_eligibility_overrides', 'timeline_events', 'audit_logs', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations'];

        return array_combine($tables, array_map(fn ($table) => $this->raw($table, $connection), $tables));
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
        $name = 'current_capacity_writer';
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
}
