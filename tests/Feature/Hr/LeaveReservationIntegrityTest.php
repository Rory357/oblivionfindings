<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveBalanceLedger;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPublicHoliday;
use App\Domain\Hr\Notifications\LeaveBalanceAdjustedNotification;
use App\Domain\Hr\Notifications\LeaveRequestNotification;
use App\Domain\Hr\Services\LeaveService;
use App\Domain\Hr\Services\PublicHolidayCalendar;
use App\Domain\Hr\Services\WorkforceAvailabilityCoverageService;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\LeaveReservationProcess;
use Tests\TestCase;

class LeaveReservationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $worker;

    private User $manager;

    private bool $committed = false;

    private array $children = [];

    private array $prefixes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05T00:00:00Z'));
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        Notification::fake();
        Queue::fake([RefreshWorkforceEligibility::class]);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->worker = $this->person([]);
        $this->manager = $this->person(['hr.leave.viewAny', 'hr.leave.manage', 'staff.availability.updateAny']);
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            $this->assertFalse($process->isRunning());
        }
        foreach ($this->prefixes as $prefix) {
            foreach (['-ready.json', '-ready.writing', '-release'] as $suffix) {
                if (is_file($prefix.$suffix)) {
                    unlink($prefix.$suffix);
                }
            }
        }
        if ($this->committed && DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('concurrentRanges')]
    public function test_real_concurrent_requests_preserve_overlap_and_additive_reservations(string $secondStart, string $secondEnd, string $secondType, bool $overlapping, string $firstStart = '2026-10-14', string $firstEnd = '2026-10-14'): void
    {
        $this->commitFixture();
        $first = $this->child('self', $this->worker, $this->worker, $this->payload(['starts_at' => $firstStart, 'ends_at' => $firstEnd]), 'reserved');
        $this->waitReady($first); // Actual first reservation still owns the application mutex.
        $second = $this->child('self', $this->worker, $this->worker,
            $this->payload(['starts_at' => $secondStart, 'ends_at' => $secondEnd, 'leave_type' => $secondType]), true);
        $secondReady = $this->waitReady($second);
        $this->release($second);
        $this->waitForNativeMutexWait($second, $secondReady['connection_id']);
        $this->release($first);
        $results = [$this->finish($first), $this->finish($second)];
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame($overlapping ? [201, 422] : [201, 201], $statuses);
        $this->assertSame($overlapping ? 1 : 2, HrLeaveRequest::count());
        $this->assertSame($overlapping ? 8.0 : 16.0, (float) HrLeaveBalance::sum('pending_hours'));
        $ledger = HrLeaveBalanceLedger::orderBy('id')->get();
        $this->assertCount($overlapping ? 1 : 2, $ledger);
        $this->assertSame(0.0, (float) $ledger->first()->pending_hours_before);
        $this->assertSame(8.0, (float) $ledger->first()->pending_hours_after);
        if (! $overlapping) {
            $this->assertSame(1, HrLeaveBalance::count(), 'First-balance creation must remain unique.');
            $this->assertSame(8.0, (float) $ledger->last()->pending_hours_before);
            $this->assertSame(16.0, (float) $ledger->last()->pending_hours_after);
        }
        $this->assertSame(0, DB::table('staff_time_offs')->count());
    }

    public static function concurrentRanges(): array
    {
        return ['nonoverlap' => ['2026-10-15', '2026-10-15', 'annual', false],
            'same date/type' => ['2026-10-14', '2026-10-14', 'annual', true],
            'cross type overlap' => ['2026-10-14', '2026-10-14', 'sick', true],
            'cross type and start-year overlap' => ['2027-01-01', '2027-01-02', 'sick', true, '2026-12-31', '2027-01-02']];
    }

    #[DataProvider('committedEntryRevocations')]
    public function test_a_real_wait_cannot_preserve_revoked_entry_authority(string $entry, string $change, bool $ownManaged): void
    {
        $actor = $entry === 'self' ? $this->worker : $this->manager;
        $subject = $ownManaged ? $actor : $this->worker;
        $actor->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $this->commitFixture();
        DB::beginTransaction();
        DB::table('hr_payroll_run_mutexes')->where('key', 'application')->lockForUpdate()->firstOrFail();
        $child = $this->child($entry, $actor, $subject, $this->payload(), true);
        $this->waitReady($child);
        if ($change === 'approval') {
            DB::table('users')->where('id', $actor->id)->update(['approved_at' => null]);
        } elseif ($change === 'subject inactive') {
            DB::table('hr_employee_profiles')->where('user_id', $subject->id)->update(['is_active' => false]);
        } elseif ($change === 'subject Site removed') {
            DB::table('hr_employee_profiles')->where('user_id', $subject->id)->update(['primary_site_id' => null, 'secondary_site_ids' => '[]']);
        } elseif ($change === 'Site archived') {
            DB::table('sites')->where('id', $this->site->id)->update(['archived' => true, 'archived_at' => now()]);
        } else {
            $permission = Permission::where('key', $change)->sole();
            $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        }
        DB::commit();
        $this->release($child);
        $result = $this->finish($child);
        $this->assertSame(in_array($change, ['subject inactive', 'subject Site removed', 'Site archived'], true) ? 404 : 403, $result['status']);
        $this->assertSame(0, HrLeaveRequest::count());
        $this->assertSame(0, HrLeaveBalance::count());
        $this->assertSame(0, HrLeaveBalanceLedger::count());
        $this->assertSame(0, DB::table('staff_time_offs')->count());
        $this->assertTrue($actor->isApproved(), 'The actual command began with cached approved evidence.');
    }

    public static function committedEntryRevocations(): array
    {
        return ['self approval' => ['self', 'approval', false],
            'managed approval' => ['managed', 'approval', false],
            'own managed cannot fall back' => ['managed', 'hr.leave.manage', true],
            'managed route read intersection' => ['managed', 'hr.leave.viewAny', false],
            'roster exact grant' => ['roster', 'staff.availability.updateAny', false],
            'managed inactive subject' => ['managed', 'subject inactive', false],
            'managed subject Site removal' => ['managed', 'subject Site removed', false],
            'managed Site archival' => ['managed', 'Site archived', false]];
    }

    #[DataProvider('calculationChanges')]
    public function test_current_calculation_evidence_after_reservation_rejects_a_committed_change(string $change): void
    {
        $holiday = HrPublicHoliday::create(['name' => 'Synthetic regional evidence', 'date' => '2026-10-14',
            'year' => 2026, 'is_national' => false, 'region' => 'Auckland']);
        $this->commitFixture();
        DB::beginTransaction();
        $this->assertSame(40, (int) DB::table('hr_employee_profiles')->where('user_id', $this->worker->id)->value('hours_per_week'));
        $this->assertFalse(app(PublicHolidayCalendar::class)->isPublicHoliday(Carbon::parse('2026-10-14')));
        $before = $this->state();
        $writer = $this->writer();
        $listener = 'eloquent.created: '.HrLeaveBalanceLedger::class;
        Event::listen($listener, function ($ledger) use ($change, $holiday, $writer): void {
            if ($ledger->entry_type !== 'reserved') {
                return;
            }
            if ($change === 'weekly hours') {
                $writer->table('hr_employee_profiles')->where('user_id', $this->worker->id)->update(['hours_per_week' => 30]);
                $this->assertSame(40, (int) DB::table('hr_employee_profiles')->where('user_id', $this->worker->id)->value('hours_per_week'));
            } else {
                $values = match ($change) {
                    'national holiday' => ['is_national' => true], 'null-region holiday' => ['region' => null], default => ['region' => ''],
                };
                $writer->table('hr_public_holidays')->where('id', $holiday->id)->update($values);
                $this->assertFalse(app(PublicHolidayCalendar::class)->isPublicHoliday(Carbon::parse('2026-10-14')));
            }
            $this->assertSame(0, $writer->transactionLevel());
        });
        try {
            $this->denied(fn () => app(LeaveService::class)->submitRequest($this->worker, $this->payload(['hours_requested' => null])), 422);
            $this->assertSame($before, $this->state());
            Notification::assertNothingSent();
        } finally {
            Event::forget($listener);
            DB::rollBack();
            DB::purge('leave-committed-writer');
        }
    }

    public static function calculationChanges(): array
    {
        return [['weekly hours'], ['national holiday'], ['null-region holiday'], ['blank-region holiday']];
    }

    public function test_current_carryover_does_not_resurrect_a_primed_previous_balance_snapshot(): void
    {
        HrLeaveBalance::create(['user_id' => $this->worker->id, 'leave_type' => 'annual', 'year' => 2025,
            'balance_hours' => 100, 'accrued_hours' => 100, 'used_hours' => 0, 'pending_hours' => 0,
            'source' => 'system', 'updated_by' => $this->worker->id]);
        $this->commitFixture();
        DB::beginTransaction();
        $this->assertSame(0.0, (float) HrLeaveBalance::where('year', 2025)->value('pending_hours'));
        $writer = $this->writer();
        try {
            $writer->table('hr_leave_balances')->where('user_id', $this->worker->id)->where('year', 2025)->update(['pending_hours' => 90]);
            $this->assertSame(0.0, (float) HrLeaveBalance::where('year', 2025)->value('pending_hours'));
            app(LeaveService::class)->submitRequest($this->worker, $this->payload());
            $current = HrLeaveBalance::where('year', 2026)->sole();
            $this->assertSame((float) config('hr.leave.default_entitlements.annual') + 10.0, (float) $current->balance_hours);
            $this->assertSame(8.0, (float) $current->pending_hours);
        } finally {
            DB::rollBack();
            DB::purge('leave-committed-writer');
        }
    }

    #[DataProvider('nativeHeldEvidence')]
    public function test_actual_held_overlap_or_role_evidence_returns_recoverable_nowait_without_projection(string $held): void
    {
        $leave = app(LeaveService::class)->submitRequest($this->worker, $this->payload());
        $roleId = $this->manager->roles()->sole()->id;
        $this->commitFixture();
        $before = $this->state();
        DB::beginTransaction();
        if ($held === 'overlap') {
            HrLeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();
        } else {
            Role::whereKey($roleId)->lockForUpdate()->firstOrFail();
        }
        try {
            $child = $this->child('managed', $this->manager, $this->worker,
                $this->payload(['starts_at' => $held === 'overlap' ? '2026-10-14' : '2026-10-15', 'ends_at' => $held === 'overlap' ? '2026-10-14' : '2026-10-15']), false);
            $result = $this->finish($child);
            $this->assertSame(422, $result['status']);
            $this->assertSame(['Leave access is being updated. Please try again.'], $result['errors']['leave_request']);
            DB::rollBack();
            $this->assertSame($before, $this->state());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public static function nativeHeldEvidence(): array
    {
        return [['overlap'], ['actor role']];
    }

    #[DataProvider('entryKinds')]
    public function test_only_actual_entry_actor_owns_reservations_and_exact_positive_semantics(string $entry): void
    {
        $actor = $entry === 'self' ? $this->worker : $this->manager;
        $data = $this->payload(['created_by' => 999999]);
        $leave = $entry === 'self'
            ? app(LeaveService::class)->submitRequest($this->worker, $data)
            : app(LeaveService::class)->submitRequest($this->worker, $data, $actor);
        $this->assertSame($actor->id, $leave->created_by);
        $this->assertSame($actor->id, HrLeaveBalance::sole()->updated_by);
        $this->assertSame($actor->id, HrLeaveBalanceLedger::sole()->created_by);
        $this->assertSame(8.0, (float) $leave->hours_requested);
        $this->assertSame('pending', $leave->status);
    }

    public static function entryKinds(): array
    {
        return [['self'], ['managed']];
    }

    public function test_roster_final_entry_recheck_follows_native_cover_and_rolls_back_every_projection(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        Shift::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id,
            'user_id' => $this->worker->id, 'starts_at' => '2026-10-13 20:00:00', 'ends_at' => '2026-10-13 23:00:00', 'status' => 'scheduled']);
        $before = $this->state();
        $real = app(WorkforceAvailabilityCoverageService::class);
        $this->mock(WorkforceAvailabilityCoverageService::class, function ($mock) use ($real): void {
            $mock->shouldReceive('syncApprovedLeave')->once()->andReturnUsing(function ($request, $actor) use ($real) {
                $actions = $real->syncApprovedLeave($request, $actor);
                $this->assertCount(1, $actions);
                $permission = Permission::where('key', 'staff.availability.updateAny')->sole();
                $this->manager->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);

                return $actions;
            });
        });
        $this->denied(fn () => app(LeaveService::class)->createRosterLeave($this->worker, $this->payload(), $this->manager), 403);
        $this->assertSame($before, $this->state());
        Notification::assertNothingSent();
    }

    #[DataProvider('notificationCommands')]
    public function test_outer_commit_and_rollback_bound_existing_notices_and_full_recipient_routing(string $command, bool $commit): void
    {
        $this->commitFixture();
        $before = $this->state();
        DB::beginTransaction();
        if ($command === 'adjust') {
            app(LeaveService::class)->adjustBalance($this->worker, 'annual', 2026, 'credit', 3, 'Existing adjustment reason', $this->manager);
        } else {
            app(LeaveService::class)->submitRequest($this->worker, $this->payload());
        }
        Notification::assertNothingSent();
        $commit ? DB::commit() : DB::rollBack();
        if (! $commit) {
            Notification::assertNothingSent();
            $this->assertSame($before, $this->state());
        } elseif ($command === 'adjust') {
            Notification::assertSentToTimes($this->worker, LeaveBalanceAdjustedNotification::class, 1);
            Notification::assertSentTo($this->worker, LeaveBalanceAdjustedNotification::class, function ($notice, $channels, $recipient): bool {
                return $recipient->name === $this->worker->name && $recipient->email === $this->worker->email
                    && $recipient->routeNotificationFor('mail') === $this->worker->email
                    && $notice->toArray($recipient)['hours_delta'] === 3.0;
            });
        } else {
            Notification::assertSentToTimes($this->manager, LeaveRequestNotification::class, 1);
            Notification::assertSentTo($this->manager, LeaveRequestNotification::class, function ($notice, $channels, $recipient): bool {
                return $recipient->email === $this->manager->email && $notice->toArray($recipient)['user_name'] === $this->worker->name;
            });
        }
    }

    public static function notificationCommands(): array
    {
        return [['adjust', true], ['adjust', false], ['submit', true], ['submit', false]];
    }

    #[DataProvider('rollbackCommands')]
    public function test_mandatory_ledger_failure_rolls_back_the_complete_native_command(string $command): void
    {
        $before = $this->state();
        $listener = 'eloquent.creating: '.HrLeaveBalanceLedger::class;
        Event::listen($listener, fn () => throw new RuntimeException('Synthetic mandatory ledger failure'));
        try {
            try {
                $command === 'submit'
                    ? app(LeaveService::class)->submitRequest($this->worker, $this->payload())
                    : app(LeaveService::class)->adjustBalance($this->worker, 'annual', 2026, 'credit', 3, null, $this->manager);
                $this->fail('Mandatory persistence must fail the command.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic mandatory ledger failure', $exception->getMessage());
            }
        } finally {
            Event::forget($listener);
        }
        $this->assertSame($before, $this->state());
        Notification::assertNothingSent();
    }

    public static function rollbackCommands(): array
    {
        return [['submit'], ['adjust']];
    }

    #[DataProvider('calendarParity')]
    public function test_final_calendar_preserves_exact_null_blank_national_and_regional_semantics(?string $region, bool $national, float $expected): void
    {
        HrPublicHoliday::create(['name' => 'Synthetic calendar parity', 'date' => '2026-10-14',
            'year' => 2026, 'region' => $region, 'is_national' => $national]);
        $leave = app(LeaveService::class)->submitRequest($this->worker,
            $this->payload(['starts_at' => '2026-10-12', 'hours_requested' => null]));
        $this->assertSame($expected, (float) $leave->hours_requested);
        $this->assertSame($expected, (float) HrLeaveBalance::sole()->pending_hours);
    }

    public static function calendarParity(): array
    {
        return ['national regional label' => ['Auckland', true, 16.0], 'null region' => [null, false, 16.0],
            'exact blank region' => ['', false, 16.0], 'whitespace remains regional' => [' ', false, 24.0],
            'known region remains unmatched for null request region' => ['Auckland', false, 24.0]];
    }

    public function test_explicit_positive_hours_preserve_caller_semantics_after_current_profile_hours_change(): void
    {
        $listener = 'eloquent.created: '.HrLeaveBalanceLedger::class;
        Event::listen($listener, function ($ledger): void {
            if ($ledger->entry_type === 'reserved') {
                DB::table('hr_employee_profiles')->where('user_id', $this->worker->id)->update(['hours_per_week' => 30]);
            }
        });
        try {
            $leave = app(LeaveService::class)->submitRequest($this->worker, $this->payload(['hours_requested' => 23.5]));
            $this->assertSame(23.5, (float) $leave->hours_requested);
            $this->assertSame(23.5, (float) HrLeaveBalance::sole()->pending_hours);
        } finally {
            Event::forget($listener);
        }
    }

    public function test_adjustment_late_exact_authority_denial_rolls_back_balance_and_notice(): void
    {
        $before = $this->state();
        $listener = 'eloquent.created: '.HrLeaveBalanceLedger::class;
        Event::listen($listener, function (): void {
            $permission = Permission::where('key', 'hr.leave.manage')->sole();
            $this->manager->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        });
        try {
            $this->denied(fn () => app(LeaveService::class)->adjustBalance($this->worker, 'annual', 2026, 'credit', 3, null, $this->manager), 403);
        } finally {
            Event::forget($listener);
        }
        $this->assertSame($before, $this->state());
        Notification::assertNothingSent();
    }

    public function test_automatic_hours_drift_after_native_roster_cover_rolls_back_all_projections_and_notices(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        Shift::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id, 'user_id' => $this->worker->id,
            'starts_at' => '2026-10-13 20:00:00', 'ends_at' => '2026-10-13 23:00:00', 'status' => 'scheduled']);
        $this->commitFixture();
        DB::beginTransaction();
        $this->assertSame(40, (int) DB::table('hr_employee_profiles')->where('user_id', $this->worker->id)->value('hours_per_week'));
        $before = $this->state();
        $writer = $this->writer();
        $real = app(WorkforceAvailabilityCoverageService::class);
        $this->mock(WorkforceAvailabilityCoverageService::class, function ($mock) use ($real, $writer): void {
            $mock->shouldReceive('syncApprovedLeave')->once()->andReturnUsing(function ($request, $actor) use ($real, $writer) {
                $actions = $real->syncApprovedLeave($request, $actor);
                $this->assertCount(1, $actions);
                $writer->table('hr_employee_profiles')->where('user_id', $this->worker->id)->update(['hours_per_week' => 30]);
                $this->assertSame(40, (int) DB::table('hr_employee_profiles')->where('user_id', $this->worker->id)->value('hours_per_week'));

                return $actions;
            });
        });
        try {
            $this->denied(fn () => app(LeaveService::class)->createRosterLeave($this->worker, $this->payload(), $this->manager), 422);
            $this->assertSame($before, $this->state());
            Notification::assertNothingSent();
        } finally {
            DB::rollBack();
            DB::purge('leave-committed-writer');
        }
    }

    public function test_existing_inclusive_overlap_boundary_is_not_changed_to_half_open(): void
    {
        HrLeaveRequest::create(['user_id' => $this->worker->id, 'leave_type' => 'sick', 'period' => 'full_day',
            'starts_at' => Carbon::parse('2026-10-13', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-10-14', 'Pacific/Auckland')->utc(),
            'hours_requested' => 8, 'status' => 'pending', 'submitted_at' => now(), 'created_by' => $this->worker->id]);
        $before = $this->state();
        try {
            app(LeaveService::class)->submitRequest($this->worker, $this->payload());
            $this->fail('The existing inclusive boundary must still reject overlap.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Leave request overlaps with an existing pending or approved leave request.', $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
        Notification::assertNothingSent();
    }

    public function test_roster_self_exact_grant_remains_pending_without_h_r_review_authority(): void
    {
        $permission = Permission::firstOrCreate(['key' => 'staff.availability.updateSelf'],
            ['description' => 'Synthetic own availability', 'group' => 'Operations', 'module' => 'operations']);
        $this->worker->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $actor = $this->worker->fresh();
        $this->assertFalse($actor->canDo('hr.leave.approve'));
        $this->assertFalse($actor->canDo('hr.leave.manage'));
        $leave = app(LeaveService::class)->createRosterLeave($actor, $this->payload(), $actor);
        $this->assertSame('pending', $leave->status);
        $this->assertSame($actor->id, $leave->created_by);
        $this->assertSame(8.0, (float) HrLeaveBalance::sole()->pending_hours);
        $this->assertSame(0, DB::table('staff_time_offs')->count());
    }

    private function person(array $permissions): User
    {
        $person = User::factory()->create(['role' => 'custom_leave_worker', 'approved_at' => now()]);
        $role = Role::create(['name' => 'reservation-'.Str::uuid(), 'label' => 'Synthetic Leave role', 'type' => 'custom', 'level' => 50]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'HR', 'module' => 'HR']);
            $role->permissions()->attach($permission);
        }
        $person->roles()->attach($role);
        HrEmployeeProfile::factory()->create(['user_id' => $person->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null,
            'hours_per_week' => 40, 'created_by' => $person->id, 'updated_by' => $person->id]);

        return $person;
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['leave_type' => 'annual', 'starts_at' => '2026-10-14', 'ends_at' => '2026-10-14', 'hours_requested' => 8], $changes);
    }

    private function commitFixture(): void
    {
        $this->assertFalse($this->committed);
        $this->assertSame(1, DB::transactionLevel());
        $this->assertMatchesRegularExpression('/^oblivion_workforce_[A-Za-z0-9_]+_'.getmypid().'$/D', DB::connection()->getDatabaseName());
        $cleanup = CommittedFixtureCleanup::capture();
        $this->beforeApplicationDestroyed(fn () => $cleanup->restore());
        DB::commit();
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $manager = new DatabaseTransactionsManager;
        app()->instance('db.transactions', $manager);
        DB::connection()->setTransactionManager($manager);
        $this->committed = true;
    }

    private function writer()
    {
        config(['database.connections.leave-committed-writer' => DB::connection()->getConfig()]);
        $writer = DB::connection('leave-committed-writer');
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->selectOne('SELECT DATABASE() AS selected_database')->selected_database);
        $this->assertSame(0, $writer->transactionLevel());
        $this->assertFalse($writer->getPdo()->inTransaction());

        return $writer;
    }

    private function child(string $entry, User $actor, User $subject, array $data, bool|string $pause): array
    {
        $prefix = base_path('test-results/leave-reservation-'.Str::uuid());
        $process = LeaveReservationProcess::make(['entry' => $entry, 'actor_id' => $actor->id, 'subject_id' => $subject->id,
            'data' => $data, 'pause_phase' => $pause === true ? 'before_mutex' : ($pause ?: null)], $prefix);
        $this->children[] = $process;
        $this->prefixes[] = $prefix;
        $process->start();

        return [$process, $prefix];
    }

    private function waitReady(array $child): array
    {
        [$process, $prefix] = $child;
        $deadline = microtime(true) + 35;
        while (! is_file($prefix.'-ready.json') && $process->isRunning() && microtime(true) < $deadline) {
            $process->checkTimeout();
            usleep(10000);
        }
        $this->assertFileExists($prefix.'-ready.json', $process->getErrorOutput());
        $value = json_decode(file_get_contents($prefix.'-ready.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotSame(getmypid(), $value['pid']);
        $this->assertGreaterThan(0, $value['transaction_level']);

        return $value;
    }

    private function waitForNativeMutexWait(array $child, int $connectionId): void
    {
        $deadline = microtime(true) + 10;
        while ($child[0]->isRunning() && microtime(true) < $deadline) {
            foreach (DB::select('SHOW PROCESSLIST') as $process) {
                if ((int) $process->Id === $connectionId && str_contains((string) $process->Info, 'hr_payroll_run_mutexes')
                    && str_contains(strtolower((string) $process->Info), 'for update')) {
                    $this->assertTrue(true, 'The second actual writer is executing the native mutex SELECT while the first holds it.');

                    return;
                }
            }
            usleep(10000);
        }
        $this->fail('The second actual writer must reach the native mutex wait: '.$child[0]->getErrorOutput());
    }

    private function release(array $child): void
    {
        file_put_contents($child[1].'-release', 'release');
    }

    private function finish(array $child): array
    {
        $child[0]->wait();
        $this->assertTrue($child[0]->isSuccessful(), $child[0]->getErrorOutput());

        return json_decode($child[0]->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function state(): array
    {
        return collect(['hr_leave_requests', 'hr_leave_balances', 'hr_leave_balance_ledgers', 'staff_time_offs',
            'shifts', 'shift_replacement_requests', 'shift_open_positions', 'workforce_availability_coverage_actions', 'audit_logs'])
            ->mapWithKeys(fn ($table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()])->all();
    }

    private function denied(callable $command, int $status): void
    {
        try {
            $command();
            $this->fail('Current evidence must deny and roll back this command.');
        } catch (ValidationException $exception) {
            $this->assertSame($status, $exception->status);
            $this->assertArrayHasKey('leave_request', $exception->errors());
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        }
    }
}
