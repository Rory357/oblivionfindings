<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveBalanceLedger;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Notifications\LeaveDeclinedNotification;
use App\Domain\Hr\Services\HrNotificationService;
use App\Domain\Hr\Services\HrWebhookService;
use App\Domain\Hr\Services\LeaveService;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
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
class WorkforceLeaveDeclineAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    private User $worker;

    private HrLeaveRequest $leave;

    private HrLeaveBalance $balance;

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
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('leave_decline_observer');
            if ($this->committed && DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction()) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public function test_root_decline_commits_exact_balance_ledger_and_scalar_audit_before_real_notification_dispatch(): void
    {
        $this->commitFixtures();
        $this->production(function (): void {
            $before = $this->state();
            $queue = $this->queue();
            $this->observeNotificationCommit();
            $result = $this->decline();
            $this->assertSame('declined', $result->status);
            $this->assertSame($this->reviewer->id, $result->reviewed_by);
            $this->assertCommittedState($before);
            $this->assertDispatchSuffix($queue, true);
        });
    }

    #[DataProvider('outerOutcomes')]
    public function test_nested_decline_waits_for_the_actual_outer_commit_and_rollback_discards_every_effect(bool $commit): void
    {
        $this->commitFixtures();
        $this->production(function () use ($commit): void {
            $before = $this->state();
            $queue = $this->queue();
            if ($commit) {
                $this->observeNotificationCommit();
            } else {
                $this->mock(HrNotificationService::class, fn ($mock) => $mock->shouldNotReceive('notifyLeaveDeclined'));
            }
            DB::beginTransaction();
            $result = $this->decline();
            $this->assertSame('declined', $result->status, 'The returned model is pending the enclosing commit.');
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
    public function test_each_false_save_or_changed_persisted_intent_rolls_back_the_whole_decline(string $class, string $kind): void
    {
        $this->commitFixtures();
        $this->production(function () use ($class, $kind): void {
            $before = $this->state();
            $queue = $this->queue();
            $original = Model::getEventDispatcher();
            $events = clone $original;
            $events->listen('eloquent.saving: '.$class, function (Model $row) use ($kind) {
                if ($row instanceof AuditLog && $row->action !== 'hr.leave.declined') {
                    return null;
                }
                if ($kind === 'veto') {
                    return false;
                }
                if ($row instanceof HrLeaveRequest) {
                    $row->review_notes = 'Observer replaced the requested reason';
                } elseif ($row instanceof HrLeaveBalance) {
                    $row->pending_hours = 999;
                } elseif ($row instanceof HrLeaveBalanceLedger) {
                    $row->hours_delta = -999;
                } else {
                    $row->meta = ['observer_replaced_command_evidence' => true];
                }

                return null;
            });
            Model::setEventDispatcher($events);
            try {
                $this->expectWriteFailure(fn () => $this->decline());
            } finally {
                Model::setEventDispatcher($original);
            }
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    public static function writeFaults(): array
    {
        return ['request veto' => [HrLeaveRequest::class, 'veto'], 'request alteration' => [HrLeaveRequest::class, 'alteration'],
            'balance veto' => [HrLeaveBalance::class, 'veto'], 'balance alteration' => [HrLeaveBalance::class, 'alteration'],
            'ledger veto' => [HrLeaveBalanceLedger::class, 'veto'], 'ledger alteration' => [HrLeaveBalanceLedger::class, 'alteration'],
            'required audit veto' => [AuditLog::class, 'veto'], 'required audit alteration' => [AuditLog::class, 'alteration']];
    }

    #[DataProvider('lateTargets')]
    public function test_a_late_audit_observer_cannot_change_an_earlier_confirmed_decline_write(string $target): void
    {
        $this->commitFixtures();
        $this->production(function () use ($target): void {
            $before = $this->state();
            $queue = $this->queue();
            $original = Model::getEventDispatcher();
            $events = clone $original;
            $events->listen('eloquent.created: '.AuditLog::class, function (AuditLog $audit) use ($target): void {
                if ($audit->action !== 'hr.leave.declined') {
                    return;
                }
                if ($target === 'request') {
                    DB::table('hr_leave_requests')->where('id', $this->leave->id)->update(['status' => 'pending']);
                } elseif ($target === 'balance') {
                    DB::table('hr_leave_balances')->where('id', $this->balance->id)->update(['pending_hours' => 999]);
                } else {
                    DB::table('hr_leave_balance_ledgers')->where('source_type', $this->leave->getMorphClass())
                        ->where('source_id', $this->leave->id)->update(['hours_delta' => -999]);
                }
            });
            Model::setEventDispatcher($events);
            try {
                $this->expectWriteFailure(fn () => $this->decline());
            } finally {
                Model::setEventDispatcher($original);
            }
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    public static function lateTargets(): array
    {
        return ['earlier request' => ['request'], 'earlier balance' => ['balance'], 'earlier ledger' => ['ledger']];
    }

    public function test_post_commit_notification_failure_does_not_roll_back_or_repeat_the_committed_decline(): void
    {
        $this->commitFixtures();
        $this->production(function (): void {
            $before = $this->state();
            $queue = $this->queue();
            $seen = null;
            $this->mock(HrNotificationService::class, function ($mock) use (&$seen): void {
                $mock->shouldReceive('notifyLeaveDeclined')->once()->andReturnUsing(function () use (&$seen): void {
                    $seen = [DB::transactionLevel(), DB::connection()->getPdo()->inTransaction(),
                        $this->observer()->table('hr_leave_requests')->where('id', $this->leave->id)->value('status')];
                    throw new \RuntimeException('Synthetic notification failure after physical commit');
                });
            });
            $this->assertSame('declined', $this->decline()->status);
            $this->assertSame([0, false, 'declined'], $seen);
            $this->assertCommittedState($before);
            $this->assertDispatchSuffix($queue, false);
            $saved = $this->state($this->observer());
            $savedQueue = $this->queue();
            try {
                $this->decline();
                $this->fail('A declined request is not pending.');
            } catch (\LogicException $exception) {
                $this->assertSame("Cannot decline a 'declined' leave request.", $exception->getMessage());
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
                $this->expectWriteFailure(fn () => $this->decline());
            } finally {
                Model::setEventDispatcher($original);
            }
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
            $this->assertSame('declined', $this->decline()->status);
            $balance = $this->observer()->table('hr_leave_balances')->where('user_id', $this->worker->id)->sole();
            $this->assertSame(152.0, (float) $balance->balance_hours);
            $this->assertSame(152.0, (float) $balance->accrued_hours);
            $this->assertSame(0.0, (float) $balance->used_hours);
            $this->assertSame(0.0, (float) $balance->pending_hours);
            $ledger = $this->observer()->table('hr_leave_balance_ledgers')->where('source_id', $this->leave->id)->sole();
            $this->assertSame(-8.0, (float) $ledger->hours_delta);
            $this->assertDispatchSuffix($queue, true);
        });
    }

    public function test_existing_http_decline_preserves_required_reason_redirect_flash_and_webhook_payload(): void
    {
        $this->commitFixtures();
        $this->production(function (): void {
            $before = $this->state();
            $queue = $this->queue();
            $this->mock(HrWebhookService::class, function ($mock): void {
                $mock->shouldReceive('publishApplicationEvent')->once()->with('leave.request.declined', [
                    'leave_request_id' => $this->leave->id, 'user_id' => $this->worker->id,
                    'reviewed_by' => $this->reviewer->id, 'leave_type' => 'annual', 'review_notes' => 'Actual review reason',
                ])->andReturn(0);
            });
            $this->actingAs($this->reviewer)->from('/operations/rostering?tab=time-off')
                ->post(route('hr.leave.decline', $this->leave), [])->assertRedirect()
                ->assertSessionHasErrors('review_notes')->assertSessionMissing('success');
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
            $this->from('/operations/rostering?tab=time-off')->post(route('hr.leave.decline', $this->leave), ['review_notes' => 'Actual review reason'])
                ->assertRedirect('/operations/rostering?tab=time-off')->assertSessionHas('success', 'Leave request declined.');
            $this->assertCommittedState($before);
            $this->assertDispatchSuffix($queue, true);
        });
    }

    public function test_native_audit_object_key_reordering_preserves_the_exact_decline_values(): void
    {
        $this->commitFixtures();
        $this->production(function (): void {
            $before = $this->state();
            $queue = $this->queue();
            $original = Model::getEventDispatcher();
            $events = clone $original;
            $events->listen('eloquent.saving: '.AuditLog::class, static function (AuditLog $row): void {
                if ($row->action === 'hr.leave.declined') {
                    $meta = array_reverse($row->meta, true);
                    $meta['balance_before'] = array_reverse($meta['balance_before'], true);
                    $meta['balance_after'] = array_reverse($meta['balance_after'], true);
                    $row->meta = $meta;
                }
            });
            Model::setEventDispatcher($events);
            try {
                $this->assertSame('declined', $this->decline()->status);
            } finally {
                Model::setEventDispatcher($original);
            }
            $this->assertCommittedState($before);
            $this->assertDispatchSuffix($queue, true);
        });
    }

    #[DataProvider('auditListOutcomes')]
    public function test_audit_object_semantics_do_not_ignore_ordered_nested_list_changes(bool $alterList): void
    {
        $this->commitFixtures();
        $this->production(function () use ($alterList): void {
            $before = $this->state();
            $queue = $this->queue();
            $meta = ['object' => ['z' => null, 'a' => false], 'ordered' => [2, 1]];
            $original = Model::getEventDispatcher();
            $events = clone $original;
            $events->listen('eloquent.saving: '.AuditLog::class, static function (AuditLog $row) use ($alterList): void {
                if ($row->action !== 'leave_decline_order_probe') {
                    return;
                }
                $value = array_reverse($row->meta, true);
                $value['object'] = array_reverse($value['object'], true);
                if ($alterList) {
                    $value['ordered'] = array_reverse($value['ordered']);
                }
                $row->meta = $value;
            });
            Model::setEventDispatcher($events);
            $save = function () use ($meta): array {
                return DB::transaction(fn () => (new \ReflectionMethod(LeaveService::class, 'persistDeclineRow'))
                    ->invoke(app(LeaveService::class), new AuditLog, [
                        'user_id' => $this->reviewer->id, 'action' => 'leave_decline_order_probe',
                        'auditable_type' => $this->leave->getMorphClass(), 'auditable_id' => $this->leave->id,
                        'meta' => $meta, 'client_id' => null, 'ip_address' => null, 'user_agent' => null,
                    ]));
            };
            try {
                if ($alterList) {
                    $this->expectWriteFailure($save);
                } else {
                    [$audit] = $save();
                    $actual = $this->observer()->table('audit_logs')->where('id', $audit->id)->sole();
                    $this->assertSame($this->canonicalMeta($meta), $this->canonicalMeta(json_decode($actual->meta, true, 512, JSON_THROW_ON_ERROR)));
                    $after = $this->state($this->observer());
                    $appended = array_pop($after['audit_logs']);
                    $this->assertSame((int) $audit->id, (int) $appended['id']);
                    $this->assertSame($before, $after);
                }
            } finally {
                Model::setEventDispatcher($original);
            }
            if ($alterList) {
                $this->assertSame($before, $this->state($this->observer()));
            }
            $this->assertSame($queue, $this->queue());
        });
    }

    public static function auditListOutcomes(): array
    {
        return ['object reorder with unchanged list' => [false], 'object reorder with changed list' => [true]];
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
                $this->expectWriteFailure(fn () => $this->decline());
            } finally {
                Model::setEventDispatcher($original);
            }
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
            $this->assertSame('declined', $this->decline()->status);
            $fresh = $this->observer()->table('hr_leave_balances')->where('user_id', $this->worker->id)->where('leave_type', 'annual')->where('year', 2026)->sole();
            $this->assertGreaterThan(0, (int) $fresh->id);
            $this->assertSame(232.0, (float) $fresh->balance_hours);
            $this->assertSame(232.0, (float) $fresh->accrued_hours);
            $this->assertSame(0.0, (float) $fresh->used_hours);
            $this->assertSame(0.0, (float) $fresh->pending_hours);
            $this->assertSame('system', $fresh->source);
            $this->assertSame($this->reviewer->id, (int) $fresh->updated_by);
            $ledger = $this->observer()->table('hr_leave_balance_ledgers')->where('source_id', $this->leave->id)->sole();
            $this->assertSame(-8.0, (float) $ledger->hours_delta);
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

    private function decline(): HrLeaveRequest
    {
        return app(LeaveService::class)->declineRequest($this->leave, $this->reviewer, 'Actual review reason');
    }

    private function assertCommittedState(array $before): void
    {
        $db = $this->observer();
        $request = $db->table('hr_leave_requests')->where('id', $this->leave->id)->sole();
        $balance = $db->table('hr_leave_balances')->where('id', $this->balance->id)->sole();
        $ledger = $db->table('hr_leave_balance_ledgers')->where('source_type', $this->leave->getMorphClass())->where('source_id', $this->leave->id)->sole();
        $audit = $db->table('audit_logs')->where('action', 'hr.leave.declined')->where('auditable_id', $this->leave->id)->sole();
        $this->assertSame('declined', $request->status);
        $this->assertSame($this->reviewer->id, (int) $request->reviewed_by);
        $this->assertSame('2026-10-10 00:10:11', $request->reviewed_at);
        $this->assertSame('Actual review reason', $request->review_notes);
        $this->assertNull($request->approval_due_at);
        $this->assertSame($this->leave->user_id, (int) $request->user_id);
        foreach (['starts_at', 'ends_at', 'hours_requested', 'reason', 'time_off_id', 'period', 'leave_type', 'created_by'] as $field) {
            $this->assertSame($this->leave->getRawOriginal($field), $request->{$field});
        }
        $this->assertSame(200.0, (float) $balance->balance_hours);
        $this->assertSame(200.0, (float) $balance->accrued_hours);
        $this->assertSame(12.0, (float) $balance->used_hours);
        $this->assertSame(16.0, (float) $balance->pending_hours);
        $this->assertSame($this->reviewer->id, (int) $balance->updated_by);
        $this->assertSame('released', $ledger->entry_type);
        $this->assertSame(-8.0, (float) $ledger->hours_delta);
        $this->assertSame(24.0, (float) $ledger->pending_hours_before);
        $this->assertSame(16.0, (float) $ledger->pending_hours_after);
        $this->assertSame(12.0, (float) $ledger->used_hours_before);
        $this->assertSame(12.0, (float) $ledger->used_hours_after);
        $this->assertSame('Actual review reason', $ledger->notes);
        $this->assertSame($this->reviewer->id, (int) $ledger->created_by);
        $meta = json_decode($audit->meta, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($this->canonicalMeta(['balance_id' => $this->balance->id, 'ledger_id' => (int) $ledger->id, 'released_hours' => 8,
            'balance_before' => ['balance_hours' => 200, 'used_hours' => 12, 'pending_hours' => 24],
            'balance_after' => ['balance_hours' => 200, 'used_hours' => 12, 'pending_hours' => 16]]), $this->canonicalMeta($meta));
        $this->assertSame($this->reviewer->id, (int) $audit->user_id);
        $this->assertSame($this->leave->getMorphClass(), $audit->auditable_type);
        $this->assertStringNotContainsString('Actual review reason', $audit->meta);
        $this->assertStringNotContainsString('Private source text', $audit->meta);
        $this->assertNull($audit->ip_address);
        $this->assertNull($audit->user_agent);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hrleaverequest.update', 'auditable_id' => $this->leave->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hrleavebalance.update', 'auditable_id' => $this->balance->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hrleavebalanceledger.create', 'auditable_id' => $ledger->id]);
        $after = $this->state($db);
        foreach (['staff_time_offs', 'shifts', 'shift_tasks', 'shift_replacement_requests', 'shift_open_positions',
            'workforce_availability_coverage_actions', 'coverage_reservations', 'notifications', 'workforce_eligibility_observations'] as $table) {
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
            $mock->shouldReceive('notifyLeaveDeclined')->once()->andReturnUsing(function (HrLeaveRequest $request) use ($real): void {
                $this->notificationCommit = [DB::transactionLevel(), DB::connection()->getPdo()->inTransaction(), $request->id,
                    $this->observer()->table('hr_leave_requests')->where('id', $request->id)->value('status')];
                $real->notifyLeaveDeclined($request);
            });
        });
    }

    private function assertDispatchSuffix(array $before, bool $notification): void
    {
        $after = $this->queue();
        $expected = $before;
        $intent = $this->observer()->table('workforce_eligibility_rechecks')->where('source_type', 'hr_leave_requests')->where('source_id', $this->leave->id)->sole();
        $refresh = Queue::pushed(RefreshWorkforceEligibility::class)->last();
        $this->assertSame((int) $intent->id, $refresh->recheckId);
        $this->assertSame((int) $intent->source_version, $refresh->sourceVersion);
        $newRefresh = array_slice($after[RefreshWorkforceEligibility::class], count($before[RefreshWorkforceEligibility::class] ?? []));
        $this->assertCount(1, $newRefresh);
        $expected[RefreshWorkforceEligibility::class] = [...($before[RefreshWorkforceEligibility::class] ?? []), ...$newRefresh];
        if ($notification) {
            if ($this->observesNotificationCommit) {
                $this->assertSame([0, false, $this->leave->id, 'declined'], $this->notificationCommit);
            }
            $jobs = Queue::pushed(SendQueuedNotifications::class)->slice(count($before[SendQueuedNotifications::class] ?? []));
            $this->assertCount(2, $jobs);
            $channels = [];
            foreach ($jobs as $job) {
                $this->assertInstanceOf(LeaveDeclinedNotification::class, $job->notification);
                $this->assertSame([$this->worker->id], $job->notifiables->modelKeys());
                $this->assertSame($this->leave->id, $job->notification->toArray($this->worker)['leave_request_id']);
                $this->assertSame('declined', $job->notification->toArray($this->worker)['status']);
                $this->assertContains('**Reason:** Actual review reason', $job->notification->toMail($this->worker)->introLines);
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
            $this->fail('A failed or altered write must reject decline.');
        } catch (\LogicException $exception) {
            $this->assertSame('Leave decline could not be saved.', $exception->getMessage());
        }
    }

    private function person(Site $site, array $keys): User
    {
        $user = User::factory()->create(['role' => 'custom_leave_delegate', 'approved_at' => now()]);
        $role = Role::query()->create(['name' => 'decline-proof-'.$user->id, 'label' => 'Scoped leave reviewer', 'type' => 'custom', 'level' => 50]);
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
        $name = 'leave_decline_observer';
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
