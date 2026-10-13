<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveBalanceLedger;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Notifications\LeaveApprovedNotification;
use App\Domain\Hr\Services\HrNotificationService;
use App\Domain\Hr\Services\HrWebhookService;
use App\Domain\Hr\Services\LeaveService;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\Site;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Models\WorkforceAvailabilityCoverageAction;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Inactive candidate: one organisation, physical commits and existing scoped leave review. */
class WorkforceLeaveApprovalAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    private User $worker;

    private HrLeaveRequest $leave;

    private HrLeaveBalance $balance;

    private Shift $shift;

    private bool $committed = false;

    private bool $observesNotificationCommit = false;

    private ?array $notificationCommit = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-10-10T00:10:11.123456Z'));
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->reviewer = $this->person($site, ['hr.leave.viewAny', 'hr.leave.approve']);
        $this->worker = $this->person($site, []);
        $this->balance = HrLeaveBalance::query()->create(['user_id' => $this->worker->id, 'leave_type' => 'annual',
            'year' => 2026, 'balance_hours' => 200, 'accrued_hours' => 200, 'used_hours' => 12, 'pending_hours' => 24,
            'source' => 'system', 'last_synced_at' => now(), 'updated_by' => $this->worker->id])->fresh();
        $this->leave = HrLeaveRequest::query()->create(['user_id' => $this->worker->id, 'leave_type' => 'annual',
            'period' => 'full_day', 'starts_at' => '2026-10-13 11:00:00', 'ends_at' => '2026-10-14 10:59:59',
            'hours_requested' => 8, 'status' => 'pending', 'submitted_at' => now(), 'approval_due_at' => now()->addDay(),
            'reason' => 'Private source text must not enter the new command audit', 'created_by' => $this->worker->id])->fresh();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $context = ServiceContext::factory()->create(['is_active' => true]);
        $this->shift = Shift::factory()->create(['site_id' => $site->id, 'client_id' => $client->id,
            'service_context_id' => $context->id, 'user_id' => $this->worker->id,
            'starts_at' => '2026-10-13 20:00:00', 'ends_at' => '2026-10-14 04:00:00',
            'status' => 'scheduled', 'created_by' => $this->reviewer->id]);
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('leave_approve_observer');
            if ($this->committed && DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction()) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public function test_root_approve_commits_exact_balance_ledger_and_scalar_audit_before_real_notification_dispatch(): void
    {
        $this->commitFixtures();
        $this->production(function (): void {
            $before = $this->state();
            $queue = $this->queue();
            $this->observeNotificationCommit();
            $result = $this->approve();
            $this->assertSame('approved', $result->status);
            $this->assertSame($this->reviewer->id, $result->reviewed_by);
            $this->assertCommittedState($before);
            $this->assertDispatchSuffix($queue, true);
        });
    }

    #[DataProvider('outerOutcomes')]
    public function test_nested_approve_waits_for_the_actual_outer_commit_and_rollback_discards_every_effect(bool $commit): void
    {
        $this->commitFixtures();
        $this->production(function () use ($commit): void {
            $before = $this->state();
            $queue = $this->queue();
            if ($commit) {
                $this->observeNotificationCommit();
            } else {
                $this->mock(HrNotificationService::class, fn ($mock) => $mock->shouldNotReceive('notifyLeaveApproved'));
            }
            DB::beginTransaction();
            $result = $this->approve();
            $this->assertSame('approved', $result->status, 'The returned model is pending the enclosing commit.');
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertSame('pending', $this->observer()->table('hr_leave_requests')->where('id', $this->leave->id)->value('status'));
            $this->assertSame($queue, $this->queue());
            if ($commit) {
                DB::commit();
                $this->assertCommittedState($before);
                $this->assertDispatchSuffix($queue, true);
            } else {
                DB::rollBack();
                $this->assertSame($before, $this->state($this->observer()));
                $this->assertSame($queue, $this->queue());
            }
        });
    }

    public static function outerOutcomes(): array
    {
        return ['outer commit' => [true], 'outer rollback' => [false]];
    }

    #[DataProvider('writeFaults')]
    public function test_each_false_save_or_changed_persisted_intent_rolls_back_the_whole_approve(string $class, string $kind): void
    {
        $this->commitFixtures();
        $this->production(function () use ($class, $kind): void {
            $before = $this->state();
            $queue = $this->queue();
            $original = Model::getEventDispatcher();
            $events = clone $original;
            $events->listen('eloquent.saving: '.$class, function (Model $row) use ($kind) {
                if ($row instanceof AuditLog && $row->action !== 'hr.leave.approved') {
                    return null;
                }
                if ($kind === 'veto') {
                    return false;
                }
                if ($row instanceof HrLeaveRequest) {
                    $row->review_notes = 'Observer replaced the requested review note';
                } elseif ($row instanceof HrLeaveBalance) {
                    $row->pending_hours = 999;
                } elseif ($row instanceof HrLeaveBalanceLedger) {
                    $row->hours_delta = 999;
                } elseif ($row instanceof StaffTimeOff) {
                    $row->user_id = $this->reviewer->id;
                } elseif ($row instanceof ShiftReplacementRequest) {
                    $row->current_staff_id = $this->reviewer->id;
                } elseif ($row instanceof ShiftOpenPosition) {
                    $row->status = 'cancelled';
                } elseif ($row instanceof WorkforceAvailabilityCoverageAction) {
                    $row->owner_user_id = $this->worker->id;
                } else {
                    $row->meta = ['observer_replaced_command_evidence' => true];
                }

                return null;
            });
            Model::setEventDispatcher($events);
            try {
                $this->expectWriteFailure(fn () => $this->approve());
            } finally {
                Model::setEventDispatcher($original);
            }
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    public static function writeFaults(): array
    {
        $cases = [];
        foreach ([HrLeaveRequest::class, HrLeaveBalance::class, StaffTimeOff::class, HrLeaveBalanceLedger::class,
            ShiftReplacementRequest::class, ShiftOpenPosition::class, WorkforceAvailabilityCoverageAction::class, AuditLog::class] as $class) {
            foreach (['veto', 'alteration'] as $kind) {
                $cases[class_basename($class).' '.$kind] = [$class, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('lateTargets')]
    public function test_a_late_audit_observer_cannot_change_an_earlier_confirmed_approve_write(string $target): void
    {
        $this->commitFixtures();
        $this->production(function () use ($target): void {
            $before = $this->state();
            $queue = $this->queue();
            $original = Model::getEventDispatcher();
            $events = clone $original;
            $events->listen('eloquent.created: '.AuditLog::class, function (AuditLog $audit) use ($target): void {
                if ($audit->action !== 'hr.leave.approved') {
                    return;
                }
                [$table, $field, $value] = match ($target) {
                    'request' => ['hr_leave_requests', 'status', 'pending'],
                    'balance' => ['hr_leave_balances', 'used_hours', 999],
                    'time_off' => ['staff_time_offs', 'user_id', $this->reviewer->id],
                    'ledger' => ['hr_leave_balance_ledgers', 'hours_delta', 999],
                    'replacement' => ['shift_replacement_requests', 'current_staff_id', $this->reviewer->id],
                    'position' => ['shift_open_positions', 'status', 'cancelled'],
                    'action' => ['workforce_availability_coverage_actions', 'owner_user_id', $this->worker->id],
                    default => ['shifts', 'user_id', $this->reviewer->id],
                };
                $query = DB::table($table);
                if ($target === 'request') {
                    $query->where('id', $this->leave->id);
                } elseif ($target === 'balance') {
                    $query->where('id', $this->balance->id);
                } elseif ($target === 'time_off') {
                    $query->where('hr_leave_request_id', $this->leave->id);
                } elseif ($target === 'ledger') {
                    $query->where('source_type', $this->leave->getMorphClass())->where('source_id', $this->leave->id);
                } elseif ($target === 'shift') {
                    $query->where('id', $this->shift->id);
                } else {
                    $query->where('shift_id', $this->shift->id);
                }
                $query->update([$field => $value]);
            });
            Model::setEventDispatcher($events);
            try {
                $this->expectWriteFailure(fn () => $this->approve());
            } finally {
                Model::setEventDispatcher($original);
            }
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    public static function lateTargets(): array
    {
        return ['request' => ['request'], 'balance' => ['balance'], 'projection' => ['time_off'], 'ledger' => ['ledger'],
            'replacement' => ['replacement'], 'position' => ['position'], 'coverage action' => ['action'], 'locked Shift source' => ['shift']];
    }

    public function test_post_commit_notification_failure_does_not_roll_back_or_repeat_the_committed_approve(): void
    {
        $this->commitFixtures();
        $this->production(function (): void {
            $before = $this->state();
            $queue = $this->queue();
            $seen = null;
            $this->mock(HrNotificationService::class, function ($mock) use (&$seen): void {
                $mock->shouldReceive('notifyLeaveApproved')->once()->andReturnUsing(function () use (&$seen): void {
                    $seen = [DB::transactionLevel(), DB::connection()->getPdo()->inTransaction(),
                        $this->observer()->table('hr_leave_requests')->where('id', $this->leave->id)->value('status')];
                    throw new \RuntimeException('Synthetic notification failure after physical commit');
                });
            });
            $this->assertSame('approved', $this->approve()->status);
            $this->assertSame([0, false, 'approved'], $seen);
            $this->assertCommittedState($before);
            $this->assertDispatchSuffix($queue, false);
            $saved = $this->state($this->observer());
            $savedQueue = $this->queue();
            try {
                $this->approve();
                $this->fail('A approved request is not pending.');
            } catch (\LogicException $exception) {
                $this->assertSame("Cannot approve a 'approved' leave request.", $exception->getMessage());
            }
            $this->assertSame($saved, $this->state($this->observer()));
            $this->assertSame($savedQueue, $this->queue());
        });
    }

    public function test_missing_balance_keeps_existing_opening_balance_and_zero_pending_clamp(): void
    {
        $this->balance->delete();
        $this->commitFixtures();
        $this->production(function (): void {
            $before = $this->state();
            $queue = $this->queue();
            $original = Model::getEventDispatcher();
            $events = clone $original;
            $events->listen('eloquent.saving: '.HrLeaveBalance::class, static fn () => false);
            Model::setEventDispatcher($events);
            try {
                $this->expectWriteFailure(fn () => $this->approve());
            } finally {
                Model::setEventDispatcher($original);
            }
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
            $this->assertSame('approved', $this->approve()->status);
            $balance = $this->observer()->table('hr_leave_balances')->where('user_id', $this->worker->id)->sole();
            $this->assertSame(152.0, (float) $balance->balance_hours);
            $this->assertSame(152.0, (float) $balance->accrued_hours);
            $this->assertSame(8.0, (float) $balance->used_hours);
            $this->assertSame(0.0, (float) $balance->pending_hours);
            $ledger = $this->observer()->table('hr_leave_balance_ledgers')->where('source_id', $this->leave->id)->sole();
            $this->assertSame(8.0, (float) $ledger->hours_delta);
            $this->assertDispatchSuffix($queue, true);
        });
    }

    public function test_existing_http_approve_preserves_nullable_notes_redirect_flash_and_webhook_payload(): void
    {
        $this->commitFixtures();
        $this->production(function (): void {
            $before = $this->state();
            $queue = $this->queue();
            $this->mock(HrWebhookService::class, function ($mock): void {
                $mock->shouldReceive('publishApplicationEvent')->once()->with('leave.request.approved', [
                    'leave_request_id' => $this->leave->id, 'user_id' => $this->worker->id,
                    'reviewed_by' => $this->reviewer->id, 'leave_type' => 'annual',
                    'starts_at' => $this->leave->starts_at->toDateString(), 'ends_at' => $this->leave->ends_at->toDateString(),
                    'hours_requested' => 8.0,
                ])->andReturn(0);
            });
            $this->actingAs($this->reviewer)->from('/operations/rostering?tab=time-off')
                ->post(route('hr.leave.approve', $this->leave), ['review_notes' => str_repeat('a', 2001)])
                ->assertRedirect()->assertSessionHasErrors('review_notes')->assertSessionMissing('success');
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
            $this->from('/operations/rostering?tab=time-off')->post(route('hr.leave.approve', $this->leave), ['review_notes' => 'Actual review note'])
                ->assertRedirect('/operations/rostering?tab=time-off')->assertSessionHas('success', 'Leave request approved.');
            $this->assertCommittedState($before);
            $this->assertDispatchSuffix($queue, true);
        });
    }

    public function test_native_audit_object_key_reordering_preserves_the_exact_approve_values(): void
    {
        $this->commitFixtures();
        $this->production(function (): void {
            $before = $this->state();
            $queue = $this->queue();
            $original = Model::getEventDispatcher();
            $events = clone $original;
            $events->listen('eloquent.saving: '.AuditLog::class, static function (AuditLog $row): void {
                if ($row->action === 'hr.leave.approved') {
                    $meta = array_reverse($row->meta, true);
                    $meta['balance_before'] = array_reverse($meta['balance_before'], true);
                    $meta['balance_after'] = array_reverse($meta['balance_after'], true);
                    $row->meta = $meta;
                }
            });
            Model::setEventDispatcher($events);
            try {
                $this->assertSame('approved', $this->approve()->status);
            } finally {
                Model::setEventDispatcher($original);
            }
            $this->assertCommittedState($before);
            $this->assertDispatchSuffix($queue, true);
        });
    }

    #[DataProvider('openingFaults')]
    public function test_fresh_balance_creation_cannot_retarget_or_change_its_opening_intent(string $phase, string $field): void
    {
        $this->balance->delete();
        HrLeaveBalance::query()->create(['user_id' => $this->worker->id, 'leave_type' => 'annual', 'year' => 2025,
            'balance_hours' => 180, 'accrued_hours' => 180, 'used_hours' => 20, 'pending_hours' => 10,
            'source' => 'system', 'updated_by' => $this->worker->id]);
        $this->commitFixtures();
        $this->production(function () use ($phase, $field): void {
            $before = $this->state();
            $queue = $this->queue();
            $original = Model::getEventDispatcher();
            $events = clone $original;
            $events->listen('eloquent.'.$phase.': '.HrLeaveBalance::class, function (HrLeaveBalance $row) use ($phase, $field): void {
                if ($field === 'identity') {
                    $row->id = $row->id + 100000;

                    return;
                }
                $attributes = match ($field) {
                    'owner' => ['user_id' => $this->reviewer->id],
                    'type' => ['leave_type' => 'sick'],
                    'year' => ['year' => 2024],
                    default => ['balance_hours' => 999, 'accrued_hours' => 998, 'used_hours' => 7, 'pending_hours' => 5],
                };
                $row->fill($attributes);
                if ($phase === 'created') {
                    DB::table('hr_leave_balances')->where('id', $row->id)->update($attributes);
                }
            });
            Model::setEventDispatcher($events);
            try {
                $this->expectWriteFailure(fn () => $this->approve());
            } finally {
                Model::setEventDispatcher($original);
            }
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
            $this->assertSame('approved', $this->approve()->status);
            $fresh = $this->observer()->table('hr_leave_balances')->where('user_id', $this->worker->id)->where('leave_type', 'annual')->where('year', 2026)->sole();
            $this->assertGreaterThan(0, (int) $fresh->id);
            $this->assertSame(232.0, (float) $fresh->balance_hours);
            $this->assertSame(232.0, (float) $fresh->accrued_hours);
            $this->assertSame(8.0, (float) $fresh->used_hours);
            $this->assertSame(0.0, (float) $fresh->pending_hours);
            $this->assertSame('system', $fresh->source);
            $this->assertSame($this->reviewer->id, (int) $fresh->updated_by);
            $ledger = $this->observer()->table('hr_leave_balance_ledgers')->where('source_id', $this->leave->id)->sole();
            $this->assertSame(8.0, (float) $ledger->hours_delta);
            $this->assertSame(232.0, (float) $ledger->balance_hours_before);
            $this->assertSame(232.0, (float) $ledger->balance_hours_after);
            $this->assertDispatchSuffix($queue, true);
        });
    }

    public static function openingFaults(): array
    {
        return ['creating owner alteration' => ['creating', 'owner'], 'creating type alteration' => ['creating', 'type'],
            'creating year alteration' => ['creating', 'year'], 'creating opening alteration' => ['creating', 'opening'],
            'created opening alteration' => ['created', 'opening'], 'created identity retarget' => ['created', 'identity']];
    }

    #[DataProvider('existingCoverageCases')]
    public function test_existing_roster_owned_cover_records_keep_identity_and_are_verified_after_late_observers(string $mode): void
    {
        $replacement = null;
        if (! in_array($mode, ['standalone', 'link veto'], true)) {
            $replacement = ShiftReplacementRequest::query()->create(['shift_id' => $this->shift->id,
                'current_staff_id' => $this->worker->id, 'requested_by' => $this->worker->id,
                'status' => 'requested', 'reason' => 'Existing roster request', 'notes' => 'Existing private note',
                'required_skills' => [], 'requested_at' => now()])->fresh();
        }
        $position = ShiftOpenPosition::query()->create(['shift_id' => $this->shift->id,
            'replacement_request_id' => $replacement?->id, 'status' => 'open',
            'required_skills' => [], 'coverage_roles' => [], 'notes' => 'Existing roster position'])->fresh();
        $this->commitFixtures();
        $this->production(function () use ($mode, $replacement, $position): void {
            $before = $this->state();
            $queue = $this->queue();
            $original = Model::getEventDispatcher();
            $events = clone $original;
            if ($mode === 'link veto') {
                $events->listen('eloquent.saving: '.ShiftOpenPosition::class, static fn () => false);
            } elseif (str_starts_with($mode, 'late ')) {
                $events->listen('eloquent.created: '.AuditLog::class, function (AuditLog $audit) use ($mode, $replacement, $position): void {
                    if ($audit->action !== 'hr.leave.approved') {
                        return;
                    }
                    if ($mode === 'late replacement') {
                        DB::table('shift_replacement_requests')->where('id', $replacement->id)->update(['current_staff_id' => $this->reviewer->id]);
                    } else {
                        DB::table('shift_open_positions')->where('id', $position->id)->update(['notes' => 'Altered previously read cover record']);
                    }
                });
            }
            if ($mode === 'link veto' || str_starts_with($mode, 'late ')) {
                Model::setEventDispatcher($events);
                try {
                    $this->expectWriteFailure(fn () => $this->approve());
                } finally {
                    Model::setEventDispatcher($original);
                }
                $this->assertSame($before, $this->state($this->observer()));
                $this->assertSame($queue, $this->queue());
            }
            $result = $this->approve();
            $db = $this->observer();
            $this->assertSame('approved', $db->table('hr_leave_requests')->where('id', $result->id)->value('status'));
            $savedPosition = $db->table('shift_open_positions')->where('id', $position->id)->sole();
            $savedReplacement = $db->table('shift_replacement_requests')->where('shift_id', $this->shift->id)->sole();
            $action = $db->table('workforce_availability_coverage_actions')->where('source_type', 'hr_leave_request')->where('source_id', $result->id)->sole();
            $this->assertSame($position->id, (int) $savedPosition->id);
            $this->assertSame('Existing roster position', $savedPosition->notes);
            $this->assertSame((int) $savedReplacement->id, (int) $savedPosition->replacement_request_id);
            $this->assertSame((int) $savedReplacement->id, (int) $action->replacement_request_id);
            $this->assertSame(0, (int) $action->manages_replacement, 'Pre-existing roster ownership is preserved.');
            if ($replacement) {
                $this->assertSame($replacement->id, (int) $savedReplacement->id);
                $this->assertSame($this->worker->id, (int) $savedReplacement->requested_by);
                $this->assertSame('Existing roster request', $savedReplacement->reason);
                $this->assertSame('Existing private note', $savedReplacement->notes);
            }
            $this->assertCount(1, $db->table('shift_open_positions')->where('shift_id', $this->shift->id)->get());
            $this->assertCount(1, $db->table('shift_replacement_requests')->where('shift_id', $this->shift->id)->get());
            $this->assertSame($before['shifts'], $this->state($db)['shifts']);
            $this->assertDispatchSuffix($queue, true);
        });
    }

    public static function existingCoverageCases(): array
    {
        return ['standalone roster position' => ['standalone'], 'existing linked roster records' => ['linked'],
            'standalone link false-save rollback' => ['link veto'],
            'late existing replacement source alteration' => ['late replacement'],
            'late existing position alteration' => ['late position']];
    }

    public function test_nullable_review_notes_and_no_overlapping_duty_preserve_existing_approval_behavior(): void
    {
        $this->shift->update(['starts_at' => '2026-10-16 20:00:00', 'ends_at' => '2026-10-17 04:00:00']);
        $this->commitFixtures();
        $this->production(function (): void {
            $before = $this->state();
            $queue = $this->queue();
            $result = app(LeaveService::class)->approveRequest($this->leave, $this->reviewer);
            $db = $this->observer();
            $this->assertSame('approved', $db->table('hr_leave_requests')->where('id', $result->id)->value('status'));
            $this->assertNull($db->table('hr_leave_requests')->where('id', $result->id)->value('review_notes'));
            $this->assertNull($db->table('staff_time_offs')->where('hr_leave_request_id', $result->id)->value('notes'));
            $this->assertNull($db->table('hr_leave_balance_ledgers')->where('source_id', $result->id)->value('notes'));
            $after = $this->state($db);
            foreach (['shifts', 'shift_replacement_requests', 'shift_open_positions', 'workforce_availability_coverage_actions'] as $table) {
                $this->assertSame($before[$table], $after[$table]);
            }
            $this->assertDispatchSuffix($queue, true);
        });
    }

    private function approve(): HrLeaveRequest
    {
        return app(LeaveService::class)->approveRequest($this->leave, $this->reviewer, 'Actual review note');
    }

    private function assertCommittedState(array $before): void
    {
        $db = $this->observer();
        $request = $db->table('hr_leave_requests')->where('id', $this->leave->id)->sole();
        $balance = $db->table('hr_leave_balances')->where('id', $this->balance->id)->sole();
        $ledger = $db->table('hr_leave_balance_ledgers')->where('source_type', $this->leave->getMorphClass())->where('source_id', $this->leave->id)->sole();
        $timeOff = $db->table('staff_time_offs')->where('hr_leave_request_id', $this->leave->id)->sole();
        $audit = $db->table('audit_logs')->where('action', 'hr.leave.approved')->where('auditable_id', $this->leave->id)->sole();
        $replacement = $db->table('shift_replacement_requests')->where('shift_id', $this->shift->id)->sole();
        $position = $db->table('shift_open_positions')->where('shift_id', $this->shift->id)->sole();
        $action = $db->table('workforce_availability_coverage_actions')->where('source_type', 'hr_leave_request')->where('source_id', $this->leave->id)->sole();
        $this->assertSame('approved', $request->status);
        $this->assertSame($this->reviewer->id, (int) $request->reviewed_by);
        $this->assertSame('2026-10-10 00:10:11', $request->reviewed_at);
        $this->assertSame('Actual review note', $request->review_notes);
        $this->assertNull($request->approval_due_at);
        $this->assertSame((int) $timeOff->id, (int) $request->time_off_id);
        foreach (['user_id', 'starts_at', 'ends_at', 'hours_requested', 'reason', 'period', 'leave_type', 'created_by'] as $field) {
            $this->assertSame($this->leave->getRawOriginal($field), $request->{$field});
        }
        $this->assertSame($this->worker->id, (int) $timeOff->user_id);
        $this->assertSame('annual', $timeOff->type);
        $this->assertSame('full_day', $timeOff->period);
        $this->assertSame($request->starts_at, $timeOff->starts_at);
        $this->assertSame($request->ends_at, $timeOff->ends_at);
        $this->assertSame('Actual review note', $timeOff->notes);
        $this->assertSame($this->reviewer->id, (int) $timeOff->created_by);
        $this->assertSame(200.0, (float) $balance->balance_hours);
        $this->assertSame(200.0, (float) $balance->accrued_hours);
        $this->assertSame(20.0, (float) $balance->used_hours);
        $this->assertSame(16.0, (float) $balance->pending_hours);
        $this->assertSame($this->reviewer->id, (int) $balance->updated_by);
        $this->assertSame('approved', $ledger->entry_type);
        $this->assertSame(8.0, (float) $ledger->hours_delta);
        $this->assertSame(24.0, (float) $ledger->pending_hours_before);
        $this->assertSame(16.0, (float) $ledger->pending_hours_after);
        $this->assertSame(12.0, (float) $ledger->used_hours_before);
        $this->assertSame(20.0, (float) $ledger->used_hours_after);
        $this->assertSame('Actual review note', $ledger->notes);
        $this->assertSame($this->reviewer->id, (int) $ledger->created_by);
        $this->assertSame('requested', $replacement->status);
        $this->assertSame($this->worker->id, (int) $replacement->current_staff_id);
        $this->assertSame('open', $position->status);
        $this->assertSame((int) $replacement->id, (int) $position->replacement_request_id);
        $this->assertSame($this->shift->id, (int) $action->shift_id);
        $this->assertSame((int) $replacement->id, (int) $action->replacement_request_id);
        $this->assertSame($this->reviewer->id, (int) $action->owner_user_id);
        $this->assertSame('replacement', $action->action_kind);
        $this->assertSame('open', $action->status);
        $this->assertSame(1, (int) $action->manages_replacement);
        $meta = json_decode($audit->meta, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($this->canonicalMeta(['balance_id' => $this->balance->id, 'ledger_id' => (int) $ledger->id,
            'time_off_id' => (int) $timeOff->id, 'approved_hours' => 8,
            'balance_before' => ['balance_hours' => 200, 'used_hours' => 12, 'pending_hours' => 24],
            'balance_after' => ['balance_hours' => 200, 'used_hours' => 20, 'pending_hours' => 16]]), $this->canonicalMeta($meta));
        $this->assertSame($this->reviewer->id, (int) $audit->user_id);
        $this->assertSame($this->leave->getMorphClass(), $audit->auditable_type);
        $this->assertStringNotContainsString('Actual review note', $audit->meta);
        $this->assertStringNotContainsString('Private source text', $audit->meta);
        $this->assertNull($audit->ip_address);
        $this->assertNull($audit->user_agent);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hrleaverequest.update', 'auditable_id' => $this->leave->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hrleavebalance.update', 'auditable_id' => $this->balance->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hrleavebalanceledger.create', 'auditable_id' => $ledger->id]);
        $after = $this->state($db);
        foreach (['shifts', 'shift_tasks', 'coverage_reservations', 'notifications', 'workforce_eligibility_observations'] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
    }

    private function canonicalMeta(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalMeta($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private function observeNotificationCommit(): void
    {
        $this->observesNotificationCommit = true;
        $real = app(HrNotificationService::class);
        $this->mock(HrNotificationService::class, function ($mock) use ($real): void {
            $mock->shouldReceive('notifyLeaveApproved')->once()->andReturnUsing(function (HrLeaveRequest $request) use ($real): void {
                $this->notificationCommit = [DB::transactionLevel(), DB::connection()->getPdo()->inTransaction(), $request->id,
                    $this->observer()->table('hr_leave_requests')->where('id', $request->id)->value('status')];
                $real->notifyLeaveApproved($request);
            });
        });
    }

    private function assertDispatchSuffix(array $before, bool $notification): void
    {
        $after = $this->queue();
        $expected = $before;
        $newRefresh = array_slice($after[RefreshWorkforceEligibility::class], count($before[RefreshWorkforceEligibility::class] ?? []));
        $this->assertCount(2, $newRefresh);
        $timeOffId = (int) $this->observer()->table('staff_time_offs')->where('hr_leave_request_id', $this->leave->id)->value('id');
        $jobs = Queue::pushed(RefreshWorkforceEligibility::class)->slice(count($before[RefreshWorkforceEligibility::class] ?? []));
        $this->assertCount(2, $jobs);
        foreach ([['hr_leave_requests', $this->leave->id], ['staff_time_offs', $timeOffId]] as [$type, $id]) {
            $intent = $this->observer()->table('workforce_eligibility_rechecks')->where('source_type', $type)->where('source_id', $id)->sole();
            $job = $jobs->first(fn ($job) => $job->recheckId === (int) $intent->id);
            $this->assertNotNull($job);
            $this->assertSame((int) $intent->source_version, $job->sourceVersion);
        }
        $expected[RefreshWorkforceEligibility::class] = [...($before[RefreshWorkforceEligibility::class] ?? []), ...$newRefresh];
        if ($notification) {
            if ($this->observesNotificationCommit) {
                $this->assertSame([0, false, $this->leave->id, 'approved'], $this->notificationCommit);
            }
            $jobs = Queue::pushed(SendQueuedNotifications::class)->slice(count($before[SendQueuedNotifications::class] ?? []));
            $this->assertCount(2, $jobs);
            $channels = [];
            foreach ($jobs as $job) {
                $this->assertInstanceOf(LeaveApprovedNotification::class, $job->notification);
                $this->assertSame([$this->worker->id], $job->notifiables->modelKeys());
                $this->assertSame($this->leave->id, $job->notification->toArray($this->worker)['leave_request_id']);
                $this->assertSame('approved', $job->notification->toArray($this->worker)['status']);
                $this->assertContains('Your leave request has been approved.', $job->notification->toMail($this->worker)->introLines);
                $this->assertCount(1, $job->channels);
                $channels[] = $job->channels[0];
            }
            sort($channels);
            $this->assertSame(['database', 'mail'], $channels);
            $expected[SendQueuedNotifications::class] = $after[SendQueuedNotifications::class];
        }
        $this->assertSame($expected, $after);
    }

    private function expectWriteFailure(callable $command): void
    {
        try {
            $command();
            $this->fail('A failed or altered write must reject approve.');
        } catch (\LogicException $exception) {
            $this->assertSame('Leave approval could not be saved.', $exception->getMessage());
        }
    }

    private function person(Site $site, array $keys): User
    {
        $user = User::factory()->create(['role' => 'custom_leave_delegate', 'approved_at' => now()]);
        $role = Role::query()->create(['name' => 'approve-proof-'.$user->id, 'label' => 'Scoped leave reviewer', 'type' => 'custom', 'level' => 50]);
        foreach ($keys as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'HR', 'module' => 'HR']);
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => '2025-01-01', 'end_date' => null, 'is_active' => true, 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function state(?Connection $db = null): array
    {
        return collect(['hr_leave_requests', 'hr_leave_balances', 'hr_leave_balance_ledgers', 'staff_time_offs', 'shifts', 'shift_tasks',
            'shift_replacement_requests', 'shift_open_positions', 'workforce_availability_coverage_actions', 'coverage_reservations',
            'workforce_eligibility_rechecks', 'workforce_eligibility_observations', 'audit_logs', 'notifications'])
            ->mapWithKeys(fn (string $table) => [$table => ($db ?? DB::connection())->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function queue(): array
    {
        return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
    }

    private function commitFixtures(): void
    {
        $db = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $db->getDriverName());
        $this->assertSame(static::$isolatedMysqlDatabase, $db->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($db->getDatabaseName(), getmypid()));
        $this->assertSame($db->getDatabaseName(), $db->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertSame(1, $db->transactionLevel());
        DB::commit();
        $this->committed = true;
        $this->assertSame(0, $db->transactionLevel());
        $this->assertFalse($db->getPdo()->inTransaction());
    }

    private function observer(): Connection
    {
        $name = 'leave_approve_observer';
        config(['database.connections.'.$name => [...DB::connection()->getConfig(), 'name' => $name]]);
        $db = DB::connection($name);
        $this->assertSame($name, $db->getName());
        $this->assertSame(DB::connection()->getDatabaseName(), $db->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($db->getDatabaseName(), getmypid()));
        $this->assertSame($db->getDatabaseName(), $db->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertNotSame(DB::connection()->getPdo(), $db->getPdo());
        $this->assertSame(0, $db->transactionLevel());
        $this->assertFalse($db->getPdo()->inTransaction());

        return $db;
    }

    private function production(callable $proof): void
    {
        $db = DB::connection();
        $testing = app('db.transactions');
        $manager = new DatabaseTransactionsManager;
        $this->assertSame(0, $db->transactionLevel());
        $this->assertFalse($db->getPdo()->inTransaction());
        app()->instance('db.transactions', $manager);
        $db->setTransactionManager($manager);
        try {
            $proof();
        } finally {
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            $this->assertFalse($db->getPdo()->inTransaction());
            app()->instance('db.transactions', $testing);
            $db->setTransactionManager($testing);
        }
    }
}
