<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Notifications\LeaveApprovedNotification;
use App\Domain\Hr\Notifications\LeaveDeclinedNotification;
use App\Domain\Hr\Services\HrWebhookService;
use App\Domain\Hr\Services\LeaveReviewReceipt;
use App\Domain\Hr\Services\LeaveService;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Inactive source candidate: requires both separately reviewed checked leave writers. */
class WorkforceLeaveReviewReceiptIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    private User $worker;

    private Site $site;

    private HrLeaveRequest $leave;

    private HrLeaveBalance $balance;

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
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->reviewer = $this->person($this->site, ['hr.leave.viewAny', 'hr.leave.approve']);
        $this->worker = $this->person($this->site, []);
        $this->balance = HrLeaveBalance::query()->create(['user_id' => $this->worker->id, 'leave_type' => 'annual',
            'year' => 2026, 'balance_hours' => 200, 'accrued_hours' => 200, 'used_hours' => 12, 'pending_hours' => 24,
            'source' => 'system', 'last_synced_at' => now(), 'updated_by' => $this->worker->id])->fresh();
        $this->leave = HrLeaveRequest::query()->create(['user_id' => $this->worker->id, 'leave_type' => 'annual',
            'period' => 'full_day', 'starts_at' => '2026-10-13 11:00:00', 'ends_at' => '2026-10-14 10:59:59',
            'hours_requested' => 8, 'status' => 'pending', 'submitted_at' => now(), 'approval_due_at' => now()->addDay(),
            'reason' => 'Private source reason receipt must omit', 'created_by' => $this->worker->id])->fresh();
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('leave_receipt_observer');
            if ($this->committed && DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction()) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    #[DataProvider('actions')]
    public function test_root_http_decision_confirms_only_actual_persisted_request_and_projection(string $action): void
    {
        $this->commitFixtures();
        $this->production(function () use ($action): void {
            $before = $this->state();
            $queue = $this->queue();
            $uuid = (string) Str::uuid();
            $notes = $action === 'approve' ? null : 'Private required decline reason';
            $this->expectWebhook($action, $notes);
            $response = $this->postDecision($action, ['request_id' => $uuid, 'review_notes' => $notes]);
            $response->assertRedirect('/operations/rostering?tab=time-off')
                ->assertSessionHas('success', $action === 'approve' ? 'Leave request approved.' : 'Leave request declined.')
                ->assertSessionMissing('error');
            $receipt = session('leave_review_result');
            $this->assertConfirmed($receipt, $action, $uuid, $notes);
            $this->assertCommittedState($before, $action, $notes);
            $this->assertDispatchSuffix($queue, $action);
            $snapshot = $this->state($this->observer());
            $queued = $this->queue();
            $this->assertSame($receipt, LeaveReviewReceipt::forRequester($this->projectionRequest($this->reviewer)));
            $this->assertSame($snapshot, $this->state($this->observer()));
            $this->assertSame($queued, $this->queue());
        });
    }

    #[DataProvider('actions')]
    public function test_legacy_request_keeps_success_and_webhook_without_claiming_a_modern_receipt(string $action): void
    {
        $this->commitFixtures();
        $this->production(function () use ($action): void {
            $before = $this->state();
            $queue = $this->queue();
            $notes = 'Legacy review note';
            $this->expectWebhook($action, $notes);
            $this->actingAs($this->reviewer)->withSession(['leave_review_result' => $this->staleReceipt()])
                ->from('/operations/rostering?tab=time-off')->post(route('hr.leave.'.$action, $this->leave), ['review_notes' => $notes])
                ->assertRedirect('/operations/rostering?tab=time-off')
                ->assertSessionHas('success', $action === 'approve' ? 'Leave request approved.' : 'Leave request declined.')
                ->assertSessionMissing('leave_review_result');
            $this->assertCommittedState($before, $action, $notes);
            $this->assertDispatchSuffix($queue, $action);
        });
    }

    #[DataProvider('nestedOutcomes')]
    public function test_nested_http_return_never_confirms_even_when_the_outer_owner_later_commits(string $action, bool $commit): void
    {
        $this->commitFixtures();
        $this->production(function () use ($action, $commit): void {
            $before = $this->state($this->observer());
            $queue = $this->queue();
            $uuid = (string) Str::uuid();
            $notes = 'Private nested review note';
            $this->expectWebhook($action, $notes);
            DB::beginTransaction();
            $this->postDecision($action, ['request_id' => $uuid, 'review_notes' => $notes])->assertRedirect();
            $receipt = session('leave_review_result');
            $this->assertSame($this->unknown($action, $uuid), $receipt);
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
            $commit ? DB::commit() : DB::rollBack();
            $this->assertSame($receipt, session('leave_review_result'));
            if ($commit) {
                $this->assertCommittedState($before, $action, $notes);
                $this->assertDispatchSuffix($queue, $action);
            } else {
                $this->assertSame($before, $this->state($this->observer()));
                $this->assertSame($queue, $this->queue());
            }
        });
    }

    #[DataProvider('readbackFaults')]
    public function test_actual_committed_approve_remains_unknown_when_optional_readback_cannot_confirm(string $fault): void
    {
        $helper = new class($fault) extends LeaveReviewReceipt
        {
            public function __construct(private string $fault) {}

            protected function readRequest(Connection $connection, int $id): ?HrLeaveRequest
            {
                if ($this->fault === 'request read failure') {
                    throw new \RuntimeException('Private read failure');
                }
                $row = parent::readRequest($connection, $id);
                if ($this->fault === 'request note mismatch') {
                    $row->review_notes = 'A different private note';
                }

                return $row;
            }

            protected function readProjection(Connection $connection, int $id): ?StaffTimeOff
            {
                throw new \RuntimeException('Private projection read failure');
            }
        };
        app()->bind(LeaveReviewReceipt::class, fn () => $helper);
        $this->commitFixtures();
        $this->production(function (): void {
            $before = $this->state();
            $queue = $this->queue();
            $uuid = (string) Str::uuid();
            $notes = 'Private approved note';
            $this->expectWebhook('approve', $notes);
            $this->postDecision('approve', ['request_id' => $uuid, 'review_notes' => $notes])
                ->assertRedirect()->assertSessionHas('success', 'Leave request approved.');
            $this->assertSame($this->unknown('approve', $uuid), session('leave_review_result'));
            $this->assertCommittedState($before, 'approve', $notes);
            $this->assertDispatchSuffix($queue, 'approve');
        });
    }

    public function test_changed_connection_identity_with_the_same_owned_schema_withholds_confirmation(): void
    {
        $this->commitFixtures();
        $this->production(function (): void {
            $default = DB::getDefaultConnection();
            $observer = $this->observer();
            $request = $this->projectionRequest($this->reviewer, ['request_id' => (string) Str::uuid(), 'review_notes' => 'Identity probe']);
            $request->headers->set('X-Leave-Review-Result', 'committed-v1');
            $helper = new LeaveReviewReceipt;
            $this->assertTrue($helper->begin($request, 'approve', $this->leave->id));
            $helper->capture($request, $this->leave, $request->all());
            $result = app(LeaveService::class)->approveRequest($this->leave, $this->reviewer, 'Identity probe');
            $before = $this->state($observer);
            $queue = $this->queue();
            try {
                DB::setDefaultConnection($observer->getName());
                $helper->committed($request, $result);
            } finally {
                DB::setDefaultConnection($default);
            }
            $this->assertSame($this->unknown('approve', $request->input('request_id')), session('leave_review_result'));
            $this->assertSame('approved', $observer->table('hr_leave_requests')->where('id', $this->leave->id)->value('status'));
            $this->assertSame($before, $this->state($observer));
            $this->assertSame($queue, $this->queue());
        });
    }

    #[DataProvider('actions')]
    public function test_later_webhook_error_keeps_the_confirmed_decision_and_existing_error_flash(string $action): void
    {
        $this->commitFixtures();
        $this->production(function () use ($action): void {
            $before = $this->state();
            $queue = $this->queue();
            $uuid = (string) Str::uuid();
            $notes = 'Actual private review note';
            $this->expectWebhook($action, $notes, true);
            $this->postDecision($action, ['request_id' => $uuid, 'review_notes' => $notes])->assertRedirect()
                ->assertSessionHas('error', 'Webhook could not be published.')->assertSessionMissing('success');
            $this->assertConfirmed(session('leave_review_result'), $action, $uuid, $notes);
            $this->assertCommittedState($before, $action, $notes);
            $this->assertDispatchSuffix($queue, $action);
        });
    }

    #[DataProvider('actions')]
    public function test_late_checked_write_veto_rolls_back_and_preserves_controller_error_without_receipt(string $action): void
    {
        $this->commitFixtures();
        $this->production(function () use ($action): void {
            $before = $this->state();
            $queue = $this->queue();
            $original = Model::getEventDispatcher();
            $events = clone $original;
            $events->listen('eloquent.saving: '.AuditLog::class, static fn (AuditLog $row) => in_array($row->action, ['hr.leave.approved', 'hr.leave.declined'], true) ? false : null);
            $this->mock(HrWebhookService::class, fn ($mock) => $mock->shouldNotReceive('publishApplicationEvent'));
            Model::setEventDispatcher($events);
            try {
                $this->postDecision($action, ['request_id' => (string) Str::uuid(), 'review_notes' => 'Actual review note'])
                    ->assertRedirect()->assertSessionHas('error', $action === 'approve' ? 'Leave approval could not be saved.' : 'Leave decline could not be saved.')
                    ->assertSessionMissing('leave_review_result')->assertSessionMissing('success');
            } finally {
                Model::setEventDispatcher($original);
            }
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    #[DataProvider('invalidAttempts')]
    public function test_existing_notes_and_optional_uuid_validation_clear_entered_stale_flash_without_writes(string $action, array $body, string $field): void
    {
        $this->commitFixtures();
        $this->production(function () use ($action, $body, $field): void {
            $before = $this->state();
            $queue = $this->queue();
            $this->mock(HrWebhookService::class, fn ($mock) => $mock->shouldNotReceive('publishApplicationEvent'));
            $this->actingAs($this->reviewer)->withSession(['leave_review_result' => $this->staleReceipt()]);
            $this->postDecision($action, $body)->assertRedirect()->assertSessionHasErrors($field)
                ->assertSessionMissing('leave_review_result')->assertSessionMissing('success');
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    #[DataProvider('entryDenials')]
    public function test_existing_grant_and_site_denials_never_project_a_prior_requester_result(string $denial): void
    {
        $actor = $denial === 'route permission' ? $this->person($this->site, ['hr.leave.viewAny']) : $this->reviewer;
        if ($denial === 'foreign approved Site') {
            $foreign = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
            $this->worker->hrEmployeeProfile->update(['primary_site_id' => $foreign->id]);
        }
        $this->commitFixtures();
        $this->production(function () use ($actor, $denial): void {
            $before = $this->state();
            $queue = $this->queue();
            $prior = $this->staleReceipt();
            $this->mock(HrWebhookService::class, fn ($mock) => $mock->shouldNotReceive('publishApplicationEvent'));
            $this->actingAs($actor)->withSession(['leave_review_result' => $prior])->withHeader('X-Leave-Review-Result', 'committed-v1')
                ->postJson(route('hr.leave.approve', $this->leave), ['request_id' => (string) Str::uuid()])
                ->assertStatus($denial === 'route permission' ? 403 : 404)->assertJsonMissing(['leave_review_result' => $prior]);
            if ($denial === 'route permission') {
                // Route middleware runs before begin(); raw retention is permitted, disclosure is not.
                $this->assertSame($prior, session('leave_review_result'));
            }
            $this->assertNull(LeaveReviewReceipt::forRequester($this->projectionRequest($actor, ['request_id' => (string) Str::uuid()])));
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    public function test_raw_pdo_entry_does_not_publish_a_commit_claim_or_hide_the_existing_transaction_error(): void
    {
        $this->commitFixtures();
        $this->production(function (): void {
            $before = $this->state();
            $queue = $this->queue();
            $pdo = DB::connection()->getPdo();
            $this->withoutExceptionHandling();
            $this->mock(HrWebhookService::class, fn ($mock) => $mock->shouldNotReceive('publishApplicationEvent'));
            $pdo->beginTransaction();
            try {
                $this->assertSame(0, DB::transactionLevel());
                try {
                    $this->postDecision('approve', ['request_id' => (string) Str::uuid()]);
                    $this->fail('The existing writer must not claim ownership of the externally opened PDO transaction.');
                } catch (\PDOException) {
                    $this->assertTrue($pdo->inTransaction());
                    $this->assertNull(session('leave_review_result'));
                }
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    #[DataProvider('projectionDenials')]
    public function test_lazy_scalar_projection_rejects_foreign_stale_or_malformed_results_without_mutation(string $kind): void
    {
        $result = $this->staleReceipt();
        $actor = $this->reviewer;
        $body = [];
        if ($kind === 'different actor') {
            $actor = $this->worker;
        } elseif ($kind === 'different attempt') {
            $body = ['request_id' => (string) Str::uuid()];
        } elseif ($kind === 'malformed UUID') {
            $result['request_id'] = 'not-a-uuid';
        } elseif ($kind === 'private extra field') {
            $result['review_notes'] = 'Private sentinel must not escape';
        } else {
            $result['outcome'] = 'not_confirmed';
        }
        $before = $this->state();
        $queue = $this->queue();
        session()->put('leave_review_result', $result);
        $this->assertNull(LeaveReviewReceipt::forRequester($this->projectionRequest($actor, $body)));
        $this->assertSame($result, session('leave_review_result'));
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public function test_decline_receipt_preserves_a_real_existing_projection_id_and_all_its_rows(): void
    {
        $projection = StaffTimeOff::query()->create(['hr_leave_request_id' => $this->leave->id,
            'user_id' => $this->worker->id, 'type' => 'annual', 'period' => 'full_day',
            'starts_at' => $this->leave->starts_at, 'ends_at' => $this->leave->ends_at,
            'label' => 'Retained legacy projection', 'notes' => 'Private prior review', 'created_by' => $this->reviewer->id])->fresh();
        $this->leave->update(['time_off_id' => $projection->id]);
        $this->leave = $this->leave->fresh();
        $this->commitFixtures();
        $this->production(function () use ($projection): void {
            $before = $this->state();
            $queue = $this->queue();
            $uuid = (string) Str::uuid();
            $notes = 'Required decline reason';
            $this->expectWebhook('decline', $notes);
            $this->postDecision('decline', ['request_id' => $uuid, 'review_notes' => $notes])->assertRedirect();
            $this->assertConfirmed(session('leave_review_result'), 'decline', $uuid, $notes);
            $this->assertSame($projection->id, session('leave_review_result.saved.time_off_id'));
            $this->assertSame($before['staff_time_offs'], $this->state($this->observer())['staff_time_offs']);
            $this->assertCommittedState($before, 'decline', $notes);
            $this->assertDispatchSuffix($queue, 'decline');
        });
    }

    #[DataProvider('reviewActionsWithoutRead')]
    public function test_review_grant_without_leave_read_denies_the_real_decision_before_any_domain_save(string $grant, string $action): void
    {
        $actor = $this->person($this->site, [$grant]);
        $this->assertTrue($actor->canDo($grant));
        $this->assertFalse($actor->canDo('hr.leave.viewAny'));
        $this->assertFalse($actor->canDo($grant === 'hr.leave.approve' ? 'hr.leave.manage' : 'hr.leave.approve'));
        $this->assertSame($this->site->id, $actor->hrEmployeeProfile->primary_site_id);
        $this->assertTrue($actor->hrEmployeeProfile->is_active);
        $this->commitFixtures();
        $this->production(function () use ($actor, $action): void {
            $observer = $this->observer();
            $before = $this->state($observer);
            $queue = $this->queue();
            $this->assertSame('pending', $observer->table('hr_leave_requests')->where('id', $this->leave->id)->value('status'));
            $uuid = (string) Str::uuid();
            $prior = [...$this->staleReceipt(), 'actor_id' => $actor->id];
            $this->mock(HrWebhookService::class, fn ($mock) => $mock->shouldNotReceive('publishApplicationEvent'));
            $attemptedSaves = [];
            $original = Model::getEventDispatcher();
            $events = clone $original;
            foreach ([HrLeaveRequest::class, HrLeaveBalance::class, \App\Domain\Hr\Models\HrLeaveBalanceLedger::class,
                StaffTimeOff::class, \App\Models\Shift::class, \App\Models\ShiftReplacementRequest::class,
                \App\Models\ShiftOpenPosition::class, \App\Models\WorkforceAvailabilityCoverageAction::class,
                \App\Models\WorkforceEligibilityRecheck::class, \App\Models\WorkforceEligibilityObservation::class,
                AuditLog::class] as $class) {
                $events->listen('eloquent.saving: '.$class, static function (Model $row) use (&$attemptedSaves): void {
                    $attemptedSaves[] = $row->getTable();
                });
            }
            Model::setEventDispatcher($events);
            try {
                $this->actingAs($actor)->withSession(['leave_review_result' => $prior])
                    ->withHeader('X-Leave-Review-Result', 'committed-v1')
                    ->from('/operations/rostering?week=2026-10-12&tab=time-off')
                    ->postJson(route('hr.leave.'.$action, $this->leave), [
                        'request_id' => $uuid,
                        'review_notes' => 'Valid review note for a request that must be denied.',
                    ])->assertForbidden()->assertJsonMissingPath('leave_review_result')->assertSessionMissing('success');
            } finally {
                Model::setEventDispatcher($original);
            }
            $this->assertSame([], $attemptedSaves);
            $this->assertSame($before, $this->state($observer));
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queue());
            $this->assertSame($prior, session('leave_review_result'));
            $this->assertNull(LeaveReviewReceipt::forRequester($this->projectionRequest($actor, ['request_id' => $uuid])));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        });
    }

    public static function reviewActionsWithoutRead(): array
    {
        return [
            'approve-only approval' => ['hr.leave.approve', 'approve'],
            'approve-only decline' => ['hr.leave.approve', 'decline'],
            'manage-only approval' => ['hr.leave.manage', 'approve'],
            'manage-only decline' => ['hr.leave.manage', 'decline'],
        ];
    }

    public static function actions(): array
    {
        return ['approve' => ['approve'], 'decline' => ['decline']];
    }

    public static function nestedOutcomes(): array
    {
        return ['approve outer commit' => ['approve', true], 'approve outer rollback' => ['approve', false],
            'decline outer commit' => ['decline', true], 'decline outer rollback' => ['decline', false]];
    }

    public static function readbackFaults(): array
    {
        return ['request read failure' => ['request read failure'], 'request note mismatch' => ['request note mismatch'],
            'projection read failure' => ['projection read failure']];
    }

    public static function invalidAttempts(): array
    {
        return ['approve UUID required' => ['approve', [], 'request_id'],
            'decline malformed UUID' => ['decline', ['request_id' => 'bad', 'review_notes' => 'Reason'], 'request_id'],
            'decline reason required' => ['decline', ['request_id' => '11111111-1111-4111-8111-111111111111'], 'review_notes'],
            'approval note max retained' => ['approve', ['request_id' => '11111111-1111-4111-8111-111111111111', 'review_notes' => str_repeat('a', 2001)], 'review_notes']];
    }

    public static function entryDenials(): array
    {
        return ['route permission' => ['route permission'], 'foreign approved Site' => ['foreign approved Site']];
    }

    public static function projectionDenials(): array
    {
        return ['different actor' => ['different actor'], 'different attempt' => ['different attempt'],
            'malformed UUID' => ['malformed UUID'], 'private extra field' => ['private extra field'],
            'unknown cannot carry saved' => ['unknown cannot carry saved']];
    }

    private function postDecision(string $action, array $body)
    {
        return $this->actingAs($this->reviewer)->withHeader('X-Leave-Review-Result', 'committed-v1')
            ->from('/operations/rostering?tab=time-off')->post(route('hr.leave.'.$action, $this->leave), $body);
    }

    private function expectWebhook(string $action, ?string $notes, bool $fail = false): void
    {
        $payload = ['leave_request_id' => $this->leave->id, 'user_id' => $this->worker->id,
            'reviewed_by' => $this->reviewer->id, 'leave_type' => 'annual'];
        $payload = $action === 'approve' ? [...$payload, 'starts_at' => $this->leave->starts_at->toDateString(),
            'ends_at' => $this->leave->ends_at->toDateString(), 'hours_requested' => 8.0] : [...$payload, 'review_notes' => $notes];
        $this->mock(HrWebhookService::class, function ($mock) use ($action, $payload, $fail): void {
            $expectation = $mock->shouldReceive('publishApplicationEvent')->once()
                ->with('leave.request.'.($action === 'approve' ? 'approved' : 'declined'), $payload);
            $fail ? $expectation->andThrow(new \LogicException('Webhook could not be published.')) : $expectation->andReturn(0);
        });
    }

    private function assertConfirmed(array $receipt, string $action, string $uuid, ?string $notes): void
    {
        $row = $this->observer()->table('hr_leave_requests')->where('id', $this->leave->id)->sole();
        $this->assertSame([...$this->unknown($action, $uuid), 'outcome' => 'confirmed', 'saved' => [
            'status' => $action === 'approve' ? 'approved' : 'declined', 'reviewed_by' => $this->reviewer->id,
            'reviewed_at' => '2026-10-10T00:10:11.000Z', 'time_off_id' => $row->time_off_id === null ? null : (int) $row->time_off_id],
            'committed_at' => '2026-10-10T00:10:11.000Z'], $receipt);
        $this->assertSame($notes, $row->review_notes);
        $this->assertSame('2026-10-10 00:10:11', $row->reviewed_at);
        $this->assertArrayNotHasKey('values_hash', $receipt);
        $this->assertStringNotContainsString('Private source reason', json_encode($receipt, JSON_THROW_ON_ERROR));
        if ($notes !== null) {
            $this->assertStringNotContainsString($notes, json_encode($receipt, JSON_THROW_ON_ERROR));
        }
    }

    private function assertCommittedState(array $before, string $action, ?string $notes): void
    {
        $db = $this->observer();
        $after = $this->state($db);
        $request = $db->table('hr_leave_requests')->where('id', $this->leave->id)->sole();
        $balance = $db->table('hr_leave_balances')->where('id', $this->balance->id)->sole();
        $this->assertSame($action === 'approve' ? 'approved' : 'declined', $request->status);
        $this->assertSame($this->reviewer->id, (int) $request->reviewed_by);
        $this->assertSame($notes, $request->review_notes);
        $this->assertSame(16.0, (float) $balance->pending_hours);
        $this->assertSame($action === 'approve' ? 20.0 : 12.0, (float) $balance->used_hours);
        $this->assertSame(200.0, (float) $balance->balance_hours);
        $ledger = $db->table('hr_leave_balance_ledgers')->where('source_type', $this->leave->getMorphClass())
            ->where('source_id', $this->leave->id)->sole();
        $this->assertSame($action === 'approve' ? 'approved' : 'released', $ledger->entry_type);
        $this->assertSame($notes, $ledger->notes);
        $this->assertSame($this->reviewer->id, (int) $ledger->created_by);
        $this->assertSame(24.0, (float) $ledger->pending_hours_before);
        $this->assertSame(16.0, (float) $ledger->pending_hours_after);
        $audit = $db->table('audit_logs')->where('action', 'hr.leave.'.($action === 'approve' ? 'approved' : 'declined'))
            ->where('auditable_id', $this->leave->id)->sole();
        $this->assertSame($this->reviewer->id, (int) $audit->user_id);
        $this->assertStringNotContainsString('Private source reason', $audit->meta);
        if ($action === 'approve') {
            $projection = $db->table('staff_time_offs')->where('id', $request->time_off_id)->sole();
            $this->assertSame($this->leave->id, (int) $projection->hr_leave_request_id);
            $this->assertSame($this->worker->id, (int) $projection->user_id);
            $this->assertSame('annual', $projection->type);
            $this->assertSame('full_day', $projection->period);
            $this->assertSame($this->leave->getRawOriginal('starts_at'), $projection->starts_at);
            $this->assertSame($this->leave->getRawOriginal('ends_at'), $projection->ends_at);
            $this->assertSame($notes, $projection->notes);
            $this->assertCount(count($before['staff_time_offs']) + 1, $after['staff_time_offs']);
        } else {
            $this->assertSame($before['staff_time_offs'], $after['staff_time_offs']);
        }
        foreach ($before as $table => $rows) {
            foreach ($rows as $row) {
                if (($table === 'hr_leave_requests' && (int) $row['id'] === $this->leave->id)
                    || ($table === 'hr_leave_balances' && (int) $row['id'] === $this->balance->id)
                    || ($table === 'workforce_eligibility_rechecks' && $row['source_type'] === 'hr_leave_requests'
                        && (int) $row['source_id'] === $this->leave->id)) {
                    continue;
                }
                $this->assertSame($row, collect($after[$table])->firstWhere('id', $row['id']), $table.' prior row '.$row['id']);
            }
        }
        foreach (['shifts', 'shift_replacement_requests', 'shift_open_positions', 'workforce_availability_coverage_actions',
            'workforce_eligibility_observations', 'notifications'] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
    }

    private function assertDispatchSuffix(array $before, string $action): void
    {
        $after = $this->queue();
        $expected = $before;
        $refresh = Queue::pushed(RefreshWorkforceEligibility::class)->slice(count($before[RefreshWorkforceEligibility::class] ?? []));
        $this->assertCount($action === 'approve' ? 2 : 1, $refresh);
        $sources = [['hr_leave_requests', $this->leave->id]];
        if ($action === 'approve') {
            $sources[] = ['staff_time_offs', (int) $this->observer()->table('hr_leave_requests')->where('id', $this->leave->id)->value('time_off_id')];
        }
        foreach ($sources as [$type, $id]) {
            $intent = $this->observer()->table('workforce_eligibility_rechecks')->where('source_type', $type)->where('source_id', $id)->sole();
            $job = $refresh->first(fn ($job) => $job->recheckId === (int) $intent->id);
            $this->assertNotNull($job);
            $this->assertSame((int) $intent->source_version, $job->sourceVersion);
        }
        $this->assertSame($before[RefreshWorkforceEligibility::class] ?? [],
            array_slice($after[RefreshWorkforceEligibility::class], 0, count($before[RefreshWorkforceEligibility::class] ?? [])));
        $expected[RefreshWorkforceEligibility::class] = $after[RefreshWorkforceEligibility::class];
        $notifications = Queue::pushed(SendQueuedNotifications::class)->slice(count($before[SendQueuedNotifications::class] ?? []));
        $this->assertCount(2, $notifications);
        $channels = [];
        foreach ($notifications as $job) {
            $this->assertInstanceOf($action === 'approve' ? LeaveApprovedNotification::class : LeaveDeclinedNotification::class, $job->notification);
            $this->assertSame([$this->worker->id], $job->notifiables->modelKeys());
            $this->assertSame($this->leave->id, $job->notification->toArray($this->worker)['leave_request_id']);
            $this->assertCount(1, $job->channels);
            $channels[] = $job->channels[0];
        }
        sort($channels);
        $this->assertSame(['database', 'mail'], $channels);
        $this->assertSame($before[SendQueuedNotifications::class] ?? [],
            array_slice($after[SendQueuedNotifications::class], 0, count($before[SendQueuedNotifications::class] ?? [])));
        $expected[SendQueuedNotifications::class] = $after[SendQueuedNotifications::class];
        $this->assertSame($expected, $after);
    }

    private function unknown(string $action, string $uuid): array
    {
        return ['version' => 1, 'scope' => 'leave_review', 'action' => $action, 'actor_id' => $this->reviewer->id,
            'leave_request_id' => $this->leave->id, 'request_id' => $uuid, 'outcome' => 'not_confirmed'];
    }

    private function staleReceipt(): array
    {
        return [...$this->unknown('approve', (string) Str::uuid()), 'outcome' => 'confirmed',
            'saved' => ['status' => 'approved', 'reviewed_by' => $this->reviewer->id,
                'reviewed_at' => '2026-10-09T00:00:00.000Z', 'time_off_id' => 123],
            'committed_at' => '2026-10-09T00:00:00.000Z'];
    }

    private function projectionRequest(User $actor, array $body = []): Request
    {
        $request = Request::create('/operations/rostering', 'GET', $body);
        $request->setUserResolver(fn () => $actor);
        $request->setLaravelSession(session()->driver());

        return $request;
    }

    private function person(Site $site, array $keys): User
    {
        $user = User::factory()->create(['role' => 'custom_leave_delegate', 'approved_at' => now()]);
        $role = Role::query()->create(['name' => 'receipt-proof-'.$user->id, 'label' => 'Scoped leave reviewer', 'type' => 'custom', 'level' => 50]);
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
        return collect(['hr_leave_requests', 'hr_leave_balances', 'hr_leave_balance_ledgers', 'staff_time_offs', 'shifts',
            'shift_replacement_requests', 'shift_open_positions', 'workforce_availability_coverage_actions',
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
        $name = 'leave_receipt_observer';
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
