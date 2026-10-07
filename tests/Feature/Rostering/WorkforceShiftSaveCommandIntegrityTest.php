<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Shifts\Planning\ShiftPlanningCommand;
use App\Domain\Shifts\Planning\ShiftPlanningReceipt;
use App\Models\Client;
use App\Models\CoverageReservation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftEligibilityOverride;
use App\Models\ShiftHandover;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\ShiftSeries;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CoverageReservationService;
use App\Services\Eligibility\AssignmentEligibilityDecision;
use App\Services\Eligibility\AssignmentEligibilityGateway;
use App\Services\Eligibility\EligibilityResult;
use App\Services\NotificationService;
use App\Services\ShiftCoverageService;
use App\Services\ShiftReplacementService;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Proposed acceptance only: requires the reviewed Planning/controller packet to be applied first. */
class WorkforceShiftSaveCommandIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private ServiceContext $context;

    private Client $client;

    private User $actor;

    private User $worker;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 04:00:00', 'UTC'));
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake();
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->context = ServiceContext::factory()->create(['site_id' => null, 'is_active' => true, 'type' => 'residential']);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active']);
        $this->actor = $this->person(['shifts.viewAny', 'shifts.create', 'shifts.update', 'shifts.manageAny', 'shifts.overrideEligibility']);
        $this->worker = $this->person([]);
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('shift_save_writer');
            if ($this->committed && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
            Carbon::setTestNow();
        } finally {
            parent::tearDown();
        }
    }

    public static function actions(): array
    {
        return ['create' => ['create'], 'update' => ['update']];
    }

    #[DataProvider('actions')]
    public function test_actual_root_http_save_preserves_every_field_and_reports_exact_persisted_source(string $action): void
    {
        $row = $action === 'update' ? $this->shift() : null;
        $source = $row ? $this->source($row) : null;
        $manual = $row ? $this->task($row, ['label' => 'Original manual task']) : null;
        $body = $this->body(['shift_type' => 'travel', 'is_sleepover' => true, 'is_on_call' => true,
            'is_lone_worker' => true, 'expected_break_minutes' => 37, 'coverage_roles' => ['med_competent', 'driver'],
            'required_licence_class' => '2', 'required_licence_endorsements' => ['P', 'F'],
            'location' => " \u{00A0}Location / ā\u{00A0} ", 'notes' => " \u{00A0}Line ā\u{2028}two\u{00A0} ",
            'tasks' => [['id' => $manual?->id, 'label' => " \u{00A0}Task ā\u{00A0} ", 'scheduled_time' => '08:37']],
            'return_to' => '/operations/shifts?view=calendar&site='.$this->site->id]);
        if ($action === 'create') {
            unset($body['tasks'][0]['id']);
        }
        $this->decision('pass');
        $this->commitFixtures();
        $this->withSession(['shift_result' => ['actor_id' => $this->actor->id, 'action' => 'old']]);
        $this->requestSave($action, $body, $row)->assertRedirect(url($body['return_to']));
        $receipt = session('shift_result');
        $this->assertIsArray($receipt);
        $saved = Shift::findOrFail($receipt['shift_id']);
        $this->assertSame($action, $receipt['action']);
        $this->assertSame($this->actor->id, $receipt['actor_id']);
        $this->assertSame('single', $receipt['scope']);
        $this->assertSame($source, $receipt['source']);
        $this->assertSame('saved', $receipt['outcome']);
        $this->assertTrue($receipt['changed']);
        $this->assertSame($this->client->id, $saved->client_id);
        $this->assertSame($this->site->id, $saved->site_id);
        $this->assertSame($this->worker->id, $saved->user_id);
        $this->assertSame($this->context->id, $saved->service_context_id);
        $this->assertSame($this->actor->id, $saved->created_by);
        $this->assertSame('2026-10-11T19:00:07.000000Z', $saved->starts_at->toISOString());
        $this->assertSame('2026-10-11T23:00:12.000000Z', $saved->ends_at->toISOString());
        $this->assertSame('Location / ā', $saved->location);
        $this->assertSame("Line ā\u{2028}two", $saved->notes);
        $this->assertSame('travel', $saved->shift_type);
        $this->assertTrue($saved->is_sleepover);
        $this->assertTrue($saved->is_on_call);
        $this->assertTrue($saved->is_lone_worker);
        $this->assertSame(37, $saved->expected_break_minutes);
        $this->assertSame(['med_competent', 'driver'], $saved->coverage_roles);
        $this->assertSame('2', $saved->required_licence_class);
        $this->assertSame(['P', 'F'], $saved->required_licence_endorsements);
        $task = ShiftTask::where('shift_id', $saved->id)->sole();
        $this->assertSame('Task ā', $task->label);
        $this->assertSame('08:37', substr($task->scheduled_time, 0, 5));
        if ($manual) {
            $this->assertSame($manual->id, $task->id);
        }
        $this->assertSame($this->expectedHash($body, $row), $receipt['values_hash']);
        $this->assertArrayNotHasKey('notes', $receipt);
        $this->assertArrayNotHasKey('tasks', $receipt);
        $this->assertSame('2026-10-11T19:00:07.000Z', $receipt['starts_at']);
        $this->assertSame('scheduled', $receipt['status']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public static function statuses(): array
    {
        return ['assigned default' => [true, null, 'scheduled'], 'open default' => [false, null, 'draft'],
            'assigned explicit draft' => [true, 'draft', 'draft'], 'open scheduled downgrade' => [false, 'scheduled', 'draft']];
    }

    #[DataProvider('statuses')]
    public function test_existing_create_assignment_and_planning_status_semantics_remain(bool $assigned, ?string $requested, string $expected): void
    {
        $body = $this->body(['user_id' => $assigned ? $this->worker->id : null]);
        unset($body['status']);
        if ($requested !== null) {
            $body['status'] = $requested;
        }
        $this->commitFixtures();
        $this->requestSave('create', $body)->assertRedirect();
        $receipt = session('shift_result');
        $this->assertSame($expected, $receipt['status']);
        $this->assertSame($assigned ? $this->worker->id : null, $receipt['user_id']);
        $this->assertSame($expected, Shift::findOrFail($receipt['shift_id'])->status);
    }

    public static function types(): array
    {
        return ['standard' => ['standard', false, false], 'sleepover' => ['sleepover', true, false],
            'on call' => ['on_call', false, true], 'split' => ['split', false, false], 'travel' => ['travel', false, false]];
    }

    #[DataProvider('types')]
    public function test_supported_shift_type_normalization_does_not_change_planning_policy(string $type, bool $sleepover, bool $onCall): void
    {
        $this->commitFixtures();
        $this->requestSave('create', $this->body(['user_id' => null, 'shift_type' => $type, 'is_sleepover' => false, 'is_on_call' => false]))->assertRedirect();
        $row = Shift::findOrFail(session('shift_result.shift_id'));
        $this->assertSame($type, $row->shift_type);
        $this->assertSame($sleepover, $row->is_sleepover);
        $this->assertSame($onCall, $row->is_on_call);
    }

    public function test_reports_site_bypass_scheduler_does_not_gain_an_actor_profile_requirement(): void
    {
        $scheduler = $this->person(['shifts.create', 'reports.viewAny'], false);
        $this->commitFixtures();
        $this->actingAs($scheduler)->post(route('operations.shifts.store'), $this->body())->assertRedirect();
        $this->assertSame($scheduler->id, session('shift_result.actor_id'));
        $this->assertSame($this->worker->id, Shift::findOrFail(session('shift_result.shift_id'))->user_id);
        $this->assertNull($scheduler->hrEmployeeProfile);
    }

    public static function nested(): array
    {
        return ['create commit' => ['create', true], 'create rollback' => ['create', false],
            'update commit' => ['update', true], 'update rollback' => ['update', false]];
    }

    #[DataProvider('nested')]
    public function test_nested_save_never_publishes_receipt_and_notices_wait_for_actual_outer_commit(string $action, bool $commit): void
    {
        $row = $action === 'update' ? $this->shift() : null;
        $body = $row ? array_replace($this->unchangedBody($row), ['location' => 'Outer pending change'])
            : $this->body(['location' => 'Outer pending change']);
        $this->commitFixtures();
        $baseline = $this->state();
        $queue = $this->queueState();
        $notices = 0;
        $this->mock(NotificationService::class, function ($mock) use (&$notices): void {
            $mock->shouldReceive('notifyCrud')->andReturnUsing(function () use (&$notices): void {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertFalse(DB::connection()->getPdo()->inTransaction());
                $notices++;
            });
        });
        $this->withProductionManager(function () use ($action, $row, $body, $commit, $baseline, $queue, &$notices): void {
            DB::beginTransaction();
            $this->requestSave($action, $body, $row)->assertRedirect()->assertSessionHasNoErrors();
            $saved = $row ? $row->fresh() : Shift::query()->orderByDesc('id')->firstOrFail();
            $this->assertSame('Outer pending change', $saved->location);
            $this->assertNull(session('shift_result'));
            $this->assertSame(0, $notices);
            $this->assertSame($queue, $this->queueState());
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $commit ? DB::commit() : DB::rollBack();
            $this->assertNull(session('shift_result'));
            $this->assertSame($commit ? 1 : 0, $notices);
            if (! $commit) {
                $this->assertSame($baseline, $this->state());
                $this->assertSame($queue, $this->queueState());
            }
        });
    }

    public function test_no_op_has_unchanged_receipt_and_no_graph_job_or_notice_delta(): void
    {
        $row = $this->shift(['status' => 'scheduled']);
        $body = $this->unchangedBody($row);
        $this->commitFixtures();
        $baseline = $this->state();
        $queue = $this->queueState();
        $this->mock(NotificationService::class, fn ($mock) => $mock->shouldNotReceive('notifyCrud'));
        $this->requestSave('update', $body, $row)->assertRedirect();
        $this->assertSame('unchanged', session('shift_result.outcome'));
        $this->assertFalse(session('shift_result.changed'));
        $this->assertSame($this->expectedHash($body, $row), session('shift_result.values_hash'));
        $this->assertSame($baseline, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public static function warningHolds(): array
    {
        return ['supplied warning' => ['supplied', false, 'eligibility_warning'],
            'supplied reason' => ['supplied', true, 'override_reason_required'],
            'automatic warning' => ['automatic', false, 'eligibility_warning'],
            'automatic reason' => ['automatic', true, 'override_reason_required']];
    }

    #[DataProvider('warningHolds')]
    public function test_typed_warning_has_original_source_and_releases_existing_or_new_hold_without_shift_task_override_write(string $hold, bool $ack, string $reason): void
    {
        $row = $this->shift();
        $task = $this->task($row);
        $source = $this->source($row);
        $body = $this->body(['override_acknowledged' => $ack, 'override_reason' => '  ', 'location' => 'Unapplied location',
            'starts_at' => '2026-10-11T19:00:00Z', 'ends_at' => '2026-10-11T23:00:00Z',
            'tasks' => [['id' => $task->id, 'label' => 'Unapplied task', 'scheduled_time' => '08:40']]]);
        // The unchanged allocator expands rules in the normalized payload's UTC timezone.
        // Arrange a real matching gap; worker-local rule interpretation is separate.
        if ($hold === 'automatic') {
            $body['starts_at'] = '2026-10-12T08:00:00Z';
            $body['ends_at'] = '2026-10-12T12:00:00Z';
        }
        $rule = $this->coverageRule();
        $body['coverage_rule_id'] = $rule->id;
        $reservation = $hold === 'supplied' ? $this->hold($body, $rule) : null;
        if ($reservation) {
            $body['coverage_reservation_token'] = $reservation->reservation_token;
        }
        $this->decision('warning');
        $this->commitFixtures();
        if ($hold === 'automatic') {
            $window = app(ShiftCoverageService::class)->findCoverageWindow(
                $this->site->id, Carbon::parse($body['starts_at'])->utc(), Carbon::parse($body['ends_at'])->utc(), $rule->id,
            );
            $this->assertNotNull($window);
            $this->assertSame($rule->id, (int) $window['rule_id']);
            $this->assertSame(1, (int) $window['unfilled_after_open_shifts']);
            $this->assertSame(0, CoverageReservation::where('coverage_requirement_id', $rule->id)->count());
        }
        $before = $this->state();
        $queue = $this->queueState();
        $this->requestSave('update', $body, $row)->assertRedirect();
        $receipt = session('shift_result');
        $this->assertSame(['action' => 'update', 'actor_id' => $this->actor->id, 'shift_id' => $row->id, 'scope' => 'single',
            'source' => $source, 'outcome' => 'not_saved', 'changed' => false, 'reason' => $reason,
            'values_hash' => $this->expectedHash($body, $row)], $receipt);
        $this->assertSame(['Current fixture warning'], session('assignment_warnings'));
        $released = $reservation ? $reservation->fresh() : CoverageReservation::where('coverage_requirement_id', $rule->id)->sole();
        $this->assertSame(CoverageReservationService::STATUS_RELEASED, $released->status);
        $this->assertNull($released->shift_id);
        $this->assertSame($this->actor->id, $released->reserved_by_user_id);
        $this->assertTrue($released->expires_at->equalTo(now()));
        $this->assertSame($before['shifts'], $this->state()['shifts']);
        $this->assertSame($before['shift_tasks'], $this->state()['shift_tasks']);
        $this->assertSame($before['shift_eligibility_overrides'], $this->state()['shift_eligibility_overrides']);
        $this->assertSame($queue, $this->queueState());
        foreach ($before as $table => $rows) {
            if (! in_array($table, ['coverage_reservations', 'audit_logs'], true)) {
                $this->assertSame($rows, $this->state()[$table], $table);
            }
        }
    }

    public static function warningResponseContracts(): array
    {
        return [
            'typed root warning' => ['committed-v1', 'root', false],
            'typed root reason' => ['committed-v1', 'root', true],
            'legacy missing header warning' => [null, 'root', false],
            'legacy missing header reason' => [null, 'root', true],
            'legacy malformed header warning' => ['committed-v0', 'root', false],
            'legacy malformed header reason' => ['committed-v0', 'root', true],
            'typed nested commit warning' => ['committed-v1', 'nested_commit', false],
            'typed nested commit reason' => ['committed-v1', 'nested_commit', true],
            'typed nested rollback warning' => ['committed-v1', 'nested_rollback', false],
            'typed nested rollback reason' => ['committed-v1', 'nested_rollback', true],
            'typed projection fault warning' => ['committed-v1', 'projection_fault', false],
            'typed projection fault reason' => ['committed-v1', 'projection_fault', true],
        ];
    }

    #[DataProvider('warningResponseContracts')]
    public function test_warning_response_negotiation_preserves_legacy_errors_unless_this_root_rejection_is_confirmed(?string $header, string $boundary, bool $ack): void
    {
        $row = $this->shift();
        $task = $this->task($row);
        $source = $this->source($row);
        $rule = $this->coverageRule();
        $body = $this->body(['coverage_rule_id' => $rule->id, 'override_acknowledged' => $ack, 'override_reason' => '  ',
            'location' => 'Unapplied compatibility location', 'tasks' => [['id' => $task->id, 'label' => 'Unapplied compatibility task', 'scheduled_time' => '08:40']]]);
        $hold = $this->hold($body, $rule);
        $body['coverage_reservation_token'] = $hold->reservation_token;
        $this->decision('warning');
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queueState();
        if ($boundary === 'projection_fault') {
            $this->app->instance(ShiftPlanningReceipt::class, new class extends ShiftPlanningReceipt
            {
                private int $calls = 0;

                protected function isPhysicalRoot(): bool
                {
                    if (++$this->calls === 2) {
                        throw new \RuntimeException('sanitized compatibility projection failure');
                    }

                    return parent::isPhysicalRoot();
                }
            });
        }
        $this->withProductionManager(function () use ($header, $boundary, $ack, $row, $source, $body, $hold, $before, $queue): void {
            $nested = in_array($boundary, ['nested_commit', 'nested_rollback'], true);
            if ($nested) {
                DB::beginTransaction();
            }
            $this->actingAs($this->actor)->from('/operations/shifts')->withHeader('X-Inertia', 'true');
            if ($header !== null) {
                $this->withHeader('X-Shift-Result', $header);
            } else {
                $this->withoutHeader('X-Shift-Result');
            }
            $response = $this->put(route('operations.shifts.update', $row), $body)
                ->assertStatus(303)->assertRedirect('/operations/shifts')
                ->assertSessionHasInput('location', 'Unapplied compatibility location')
                ->assertSessionHasInput('tasks.0.label', 'Unapplied compatibility task')
                ->assertSessionHas('assignment_warnings', ['Current fixture warning'])
                ->assertSessionMissing('success');
            $reason = $ack ? 'override_reason_required' : 'eligibility_warning';
            $errorKey = $ack ? 'override_reason' : 'user_id';
            $message = $ack ? 'A reason is required when overriding eligibility warnings.'
                : 'Review and acknowledge the eligibility warnings before saving this shift. Current fixture warning';
            if ($header === 'committed-v1' && $boundary === 'root') {
                $response->assertSessionHasNoErrors();
            } else {
                $response->assertSessionHasErrors([$errorKey => $message]);
            }
            $feedback = session('eligibility_result');
            $this->assertSame(['Current fixture warning'], $feedback['warning_reasons']);
            $receipt = session('shift_result');
            if ($boundary === 'root') {
                $this->assertSame(['action' => 'update', 'actor_id' => $this->actor->id, 'shift_id' => $row->id, 'scope' => 'single',
                    'source' => $source, 'outcome' => 'not_saved', 'changed' => false, 'reason' => $reason,
                    'values_hash' => $this->expectedHash($body, $row)], $receipt);
            } else {
                $this->assertNull($receipt);
            }
            $this->assertSame(CoverageReservationService::STATUS_RELEASED, $hold->fresh()->status);
            $this->assertSame($queue, $this->queueState());
            foreach ($before as $table => $rows) {
                if (! in_array($table, ['coverage_reservations', 'audit_logs'], true)) {
                    $this->assertSame($rows, $this->state()[$table], $table);
                }
            }
            if ($nested) {
                $this->assertSame(1, DB::transactionLevel());
                $this->assertTrue(DB::connection()->getPdo()->inTransaction());
                $boundary === 'nested_commit' ? DB::commit() : DB::rollBack();
                $this->assertNull(session('shift_result'));
                $this->assertSame($message, session('errors')->first($errorKey));
                $this->assertSame($boundary === 'nested_commit' ? CoverageReservationService::STATUS_RELEASED : CoverageReservationService::STATUS_ACTIVE, $hold->fresh()->status);
                if ($boundary === 'nested_rollback') {
                    $this->assertSame($before, $this->state());
                }
            }
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_create_warning_remains_an_allowed_save_without_override_requirement(): void
    {
        $actor = $this->person(['shifts.create']);
        $this->decision('warning');
        $this->commitFixtures();
        $this->actingAs($actor)->post(route('operations.shifts.store'), $this->body())->assertRedirect();
        $this->assertSame('saved', session('shift_result.outcome'));
        $this->assertSame($actor->id, session('shift_result.actor_id'));
        $this->assertSame(['Current fixture warning'], session('assignment_warnings'));
        $this->assertSame(0, ShiftEligibilityOverride::count());
    }

    public function test_governed_warning_retry_records_only_current_actor_reason_and_warning_evidence(): void
    {
        $row = $this->shift();
        $this->decision('warning');
        $this->commitFixtures();
        $body = $this->body(['override_acknowledged' => true, 'override_reason' => " \u{00A0}Current reason ā\u{00A0} "]);
        $this->requestSave('update', $body, $row)->assertRedirect();
        $override = ShiftEligibilityOverride::where('shift_id', $row->id)->sole();
        $this->assertSame($this->actor->id, $override->overridden_by);
        $this->assertSame($this->worker->id, $override->user_id);
        $this->assertSame('Current reason ā', $override->override_reason);
        $this->assertSame(['fatigue_weekly'], $override->rules_overridden);
        $this->assertSame([['rule' => 'fatigue_weekly', 'message' => 'Current fixture warning', 'overrideable' => true]], $override->acknowledged_warnings);
        $this->assertSame('saved', session('shift_result.outcome'));
    }

    public static function blocks(): array
    {
        return ['create block' => ['create', 'block', 422], 'create unavailable' => ['create', 'unavailable', 503],
            'update block' => ['update', 'block', 422], 'update unavailable' => ['update', 'unavailable', 503]];
    }

    #[DataProvider('blocks')]
    public function test_block_or_unavailable_has_no_saved_receipt_or_command_graph_delta(string $action, string $decision, int $status): void
    {
        $row = $action === 'update' ? $this->shift() : null;
        $this->decision($decision);
        $this->commitFixtures();
        $baseline = $this->state();
        $queue = $this->queueState();
        $response = $this->requestSave($action, $this->body(), $row, true)->assertStatus($status)->assertJsonValidationErrors('user_id');
        if ($decision === 'unavailable') {
            $response->assertJsonPath('errors.user_id.0', AssignmentEligibilityDecision::UNAVAILABLE_MESSAGE);
        }
        $this->assertNull(session('shift_result'));
        $this->assertSame($baseline, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public static function revocations(): array
    {
        return ['create approval' => ['create', 'approval', 403], 'create exact grant' => ['create', 'grant', 403],
            'create actor Site membership' => ['create', 'actor_site', 403], 'create archived Site' => ['create', 'site', 403],
            'create worker inactive' => ['create', 'worker_inactive', 403], 'create worker wrong Site' => ['create', 'worker_site', 422],
            'create worker approval' => ['create', 'worker_approval', 403], 'create portal recipient' => ['create', 'worker_portal', 403],
            'create Client retarget' => ['create', 'client_site', 403],
            'update approval' => ['update', 'approval', 403], 'update exact grant' => ['update', 'grant', 403],
            'update manageAny' => ['update', 'manage', 403], 'update actor Site membership' => ['update', 'actor_site', 403],
            'update archived Site' => ['update', 'site', 403], 'update original recipient inactive' => ['update', 'worker_inactive', 403],
            'update Client tuple contradiction' => ['update', 'client_site', 404],
            'update owner retarget' => ['update', 'source_owner', 409], 'update Client retarget' => ['update', 'source_client', 409],
            'update Site retarget' => ['update', 'source_site', 409], 'update live state' => ['update', 'source_status', 409]];
    }

    #[DataProvider('revocations')]
    public function test_primed_repeatable_read_cannot_authorize_after_independently_committed_current_evidence_change(string $action, string $change, int $status): void
    {
        $row = $action === 'update' ? $this->shift() : null;
        $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $otherClient = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
        $otherWorker = $this->person([]);
        if ($change === 'worker_site') {
            $this->actor->roles()->firstOrFail()->permissions()->attach(Permission::firstOrCreate(['key' => 'reports.viewAny'], ['description' => 'Report Site bypass', 'group' => 'reports', 'module' => 'Operations'])->id);
            $this->actor = $this->actor->fresh();
        }
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        try {
            $staleActor = User::findOrFail($this->actor->id)->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
            $this->assertTrue($staleActor->canDo('shifts.'.$action));
            $this->assertTrue($staleActor->canDo('shifts.manageAny'));
            $staleShift = $row ? Shift::findOrFail($row->id) : null;
            // Establish the actual old ordinary RR view, including every negative/query participant.
            $oldSite = Site::findOrFail($this->site->id)->getRawOriginal();
            $oldClient = Client::findOrFail($this->client->id)->getRawOriginal();
            $oldWorker = User::findOrFail($this->worker->id)->load('hrEmployeeProfile')->getRawOriginal();
            $oldProfile = HrEmployeeProfile::where('user_id', $this->worker->id)->sole()->getRawOriginal();
            $actorProfile = HrEmployeeProfile::where('user_id', $this->actor->id)->sole();
            $pivot = $this->actor->roles()->firstOrFail()->permissions()->getTable();
            $writer->transaction(function () use ($writer, $action, $change, $row, $otherSite, $otherClient, $otherWorker, $actorProfile, $pivot): void {
                match ($change) {
                    'approval' => $writer->table('users')->where('id', $this->actor->id)->update(['approved_at' => null]),
                    'grant', 'manage' => $writer->table($pivot)->where('role_id', $this->actor->roles()->firstOrFail()->id)
                        ->where('permission_id', Permission::where('key', $change === 'manage' ? 'shifts.manageAny' : 'shifts.'.$action)->value('id'))->delete(),
                    'actor_site' => $writer->table($actorProfile->getTable())->where('user_id', $this->actor->id)->update(['primary_site_id' => $otherSite->id, 'secondary_site_ids' => '[]']),
                    'site' => $writer->table('sites')->where('id', $this->site->id)->update(['archived' => true, 'archived_at' => now()]),
                    'worker_inactive' => $writer->table((new HrEmployeeProfile)->getTable())->where('user_id', $this->worker->id)->update(['is_active' => false]),
                    'worker_site' => $writer->table((new HrEmployeeProfile)->getTable())->where('user_id', $this->worker->id)->update(['primary_site_id' => $otherSite->id, 'secondary_site_ids' => '[]']),
                    'worker_approval' => $writer->table('users')->where('id', $this->worker->id)->update(['approved_at' => null]),
                    'worker_portal' => $writer->table('users')->where('id', $this->worker->id)->update(['role' => 'client']),
                    'client_site' => $writer->table('clients')->where('id', $this->client->id)->update(['site_id' => $otherSite->id]),
                    'source_owner' => $writer->table('shifts')->where('id', $row->id)->update(['user_id' => $otherWorker->id]),
                    'source_client' => $writer->table('shifts')->where('id', $row->id)->update(['client_id' => $otherClient->id]),
                    'source_site' => $writer->table('shifts')->where('id', $row->id)->update(['site_id' => $otherSite->id]),
                    'source_status' => $writer->table('shifts')->where('id', $row->id)->update(['status' => 'in_progress']),
                };
            });
            $this->assertFalse($writer->getPdo()->inTransaction());
            $this->assertSame($oldSite, Site::findOrFail($this->site->id)->getRawOriginal());
            $this->assertSame($oldClient, Client::findOrFail($this->client->id)->getRawOriginal());
            $this->assertSame($oldWorker, User::findOrFail($this->worker->id)->getRawOriginal());
            $this->assertSame($oldProfile, HrEmployeeProfile::where('user_id', $this->worker->id)->sole()->getRawOriginal());
            if ($staleShift) {
                $this->assertSame($staleShift->getRawOriginal(), Shift::findOrFail($row->id)->getRawOriginal());
            }
            $before = $this->state($writer);
            $queue = $this->queueState();
            $this->assertCommandDenied(fn () => app(ShiftPlanningCommand::class)->save($staleActor, $this->body(), $staleShift), $status);
            $this->assertSame($before, $this->state($writer));
            $this->assertSame($queue, $this->queueState());
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
        } finally {
            DB::rollBack();
        }
    }

    public function test_current_override_denial_after_primed_grant_cannot_save_warning_or_immutable_override(): void
    {
        $row = $this->shift();
        $this->decision('warning');
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        try {
            $stale = User::findOrFail($this->actor->id)->load('roles.permissions', 'permissionOverrides');
            $this->assertTrue($stale->canDo('shifts.overrideEligibility'));
            $writer->table($this->actor->permissionOverrides()->getTable())->insert([
                'user_id' => $this->actor->id, 'permission_id' => Permission::where('key', 'shifts.overrideEligibility')->value('id'),
                'allowed' => false,
            ]);
            $this->assertTrue($stale->canDo('shifts.overrideEligibility'));
            $before = $this->state($writer);
            $this->assertCommandDenied(fn () => app(ShiftPlanningCommand::class)->save($stale,
                $this->body(['override_acknowledged' => true, 'override_reason' => 'Stale permission reason']), $row), 403);
            $this->assertSame($before, $this->state($writer));
        } finally {
            DB::rollBack();
        }
    }

    public function test_current_approved_timesheet_blocks_planning_even_when_ordinary_rr_evidence_was_empty(): void
    {
        $row = $this->shift();
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        try {
            $this->assertFalse($row->approvedTimesheets()->exists());
            $timesheet = Timesheet::factory()->make(['shift_id' => $row->id, 'client_id' => $this->client->id,
                'user_id' => $this->worker->id, 'shift_site_id' => $this->site->id, 'created_by' => $this->worker->id,
                'approved_by' => $this->actor->id, 'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at]);
            $timesheet->setConnection('shift_save_writer');
            $this->assertSame($writer->getPdo(), $timesheet->getConnection()->getPdo());
            $timesheet->saveOrFail();
            // Retained historical approval on a planning Shift is represented through raw storage,
            // rather than asking today's reconciliation workflow to approve an invalid fixture.
            $writer->table('timesheets')->where('id', $timesheet->id)->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->actor->id]);
            $this->assertFalse($row->approvedTimesheets()->exists());
            $before = $this->state($writer);
            $this->assertCommandDenied(fn () => app(ShiftPlanningCommand::class)->save($this->actor, $this->body(), $row), 422);
            $this->assertSame($before, $this->state($writer));
        } finally {
            DB::rollBack();
        }
    }

    public function test_actual_held_site_lock_returns_recoverable_busy_error_with_complete_state_preserved(): void
    {
        $row = $this->shift();
        $this->commitFixtures();
        $writer = $this->writer();
        $writer->beginTransaction();
        try {
            $writer->table('sites')->where('id', $this->site->id)->lockForUpdate()->first();
            $before = $this->state();
            try {
                app(ShiftPlanningCommand::class)->save($this->actor, $this->body(), $row);
                $this->fail('Expected genuine current Site NOWAIT denial.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('shift', $exception->errors());
                $this->assertStringContainsString('busy', $exception->errors()['shift'][0]);
            }
            $this->assertSame($before, $this->state());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            $writer->rollBack();
        }
    }

    public function test_non_nowait_database_failure_is_not_relabelled_as_a_recoverable_busy_command(): void
    {
        $this->commitFixtures();
        $cause = new \PDOException('sanitized injected SQL fault');
        $cause->errorInfo = ['HY000', 1205, 'sanitized injected SQL fault'];
        $exception = new QueryException('mysql', 'select current evidence', [], $cause);
        $this->mock(AuthorizationEvidenceLockService::class, fn ($mock) => $mock->shouldReceive('lockForUserWithoutWaiting')
            ->once()
            ->with($this->actor->id, ['shifts.create', 'shifts.update', 'shifts.manageAny', 'shifts.overrideEligibility', 'reports.viewAny'])
            ->andThrow($exception));
        $before = $this->state();
        try {
            app(ShiftPlanningCommand::class)->save($this->actor, $this->body());
            $this->fail('Expected the unrelated SQL exception.');
        } catch (QueryException $actual) {
            $this->assertSame($exception, $actual);
        }
        $this->assertSame($before, $this->state());
    }

    public function test_manual_task_ids_and_source_owned_tasks_survive_editable_round_trip(): void
    {
        $row = $this->shift();
        $manual = $this->task($row, ['label' => 'Completed manual', 'is_completed' => true,
            'completed_at' => now(), 'completed_by' => $this->worker->id, 'sort_order' => 1]);
        $removable = $this->task($row, ['label' => 'Removed manual', 'sort_order' => 2]);
        $owned = $this->task($row, ['label' => 'Source creation task', 'creation_key' => (string) Str::uuid(), 'sort_order' => 3]);
        $handover = ShiftHandover::factory()->draft()->create(['client_id' => $this->client->id, 'outgoing_shift_id' => $row->id,
            'outgoing_staff_id' => $this->worker->id, 'incoming_staff_id' => $this->worker->id, 'incoming_shift_id' => null]);
        $carried = $this->task($row, ['label' => 'Handover source task', 'source_handover_id' => $handover->id, 'sort_order' => 4]);
        $this->commitFixtures();
        $originalOwned = $owned->fresh()->getRawOriginal();
        $originalCarried = $carried->fresh()->getRawOriginal();
        $originalManual = $manual->fresh()->getRawOriginal();
        $originalShift = $row->fresh()->getRawOriginal();
        $editable = $this->actingAs($this->actor)->getJson(route('operations.shifts.editable', $row))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertSame('Pacific/Auckland', $editable['worker_timezone']);
        $this->assertSame($this->actor->id, $editable['actor_id']);
        $this->assertFalse($editable['is_lone_worker']);
        $this->assertSame($this->source($row), $editable['source']);
        $controls = collect($editable['tasks'])->keyBy('id');
        $this->assertTrue($controls[$manual->id]['can_edit']);
        $this->assertFalse($controls[$owned->id]['can_edit']);
        $this->assertFalse($controls[$carried->id]['can_edit']);
        $body = $this->unchangedBody($row);
        $body['tasks'] = [['id' => $manual->id, 'label' => 'Reworded manual', 'scheduled_time' => '08:14', 'is_completed' => false],
            ['id' => $owned->id, 'label' => 'Forged creation rewrite', 'scheduled_time' => '10:00'],
            ['id' => $carried->id, 'label' => 'Forged handover rewrite', 'scheduled_time' => '10:01'],
            ['label' => 'New manual', 'scheduled_time' => null]];
        $this->requestSave('update', $body, $row)->assertRedirect();
        $this->assertDatabaseMissing('shift_tasks', ['id' => $removable->id]);
        $this->assertSame('saved', session('shift_result.outcome'));
        $this->assertTrue(session('shift_result.changed'));
        $this->assertSame($originalShift, $row->fresh()->getRawOriginal());
        $this->assertSame($originalOwned, $owned->fresh()->getRawOriginal());
        $this->assertSame($originalCarried, $carried->fresh()->getRawOriginal());
        $actualManual = $manual->fresh();
        $this->assertSame($manual->id, $actualManual->id);
        $this->assertSame('Reworded manual', $actualManual->label);
        $this->assertTrue($actualManual->is_completed);
        foreach (['completed_at', 'completed_by', 'creation_key', 'source_handover_id'] as $field) {
            $this->assertSame($originalManual[$field], $actualManual->getRawOriginal($field));
        }
        $this->assertGreaterThan((int) $originalManual['version'], $actualManual->version);
        $body['tasks'] = [$body['tasks'][0], $body['tasks'][3]];
        $this->assertSame($this->expectedHash($body, $row), session('shift_result.values_hash'));
    }

    public function test_omitted_tasks_retain_the_cohort_and_empty_tasks_remove_manual_rows_only(): void
    {
        $row = $this->shift();
        $manual = $this->task($row);
        $owned = $this->task($row, ['creation_key' => (string) Str::uuid()]);
        $this->commitFixtures();
        $originalOwned = $owned->fresh()->getRawOriginal();
        $before = ShiftTask::where('shift_id', $row->id)->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->requestSave('update', $this->unchangedBody($row), $row)->assertRedirect();
        $this->assertSame($before, ShiftTask::where('shift_id', $row->id)->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->requestSave('update', [...$this->unchangedBody($row), 'tasks' => []], $row->fresh())->assertRedirect();
        $this->assertDatabaseMissing('shift_tasks', ['id' => $manual->id]);
        $this->assertSame($originalOwned, $owned->fresh()->getRawOriginal());
    }

    public function test_foreign_task_id_is_concealed_without_partial_shift_task_or_source_change(): void
    {
        $row = $this->shift();
        $foreign = $this->task($this->shift(['starts_at' => '2026-10-15 19:00:00', 'ends_at' => '2026-10-15 23:00:00']));
        $this->commitFixtures();
        $before = $this->state();
        $this->requestSave('update', $this->body(['tasks' => [['id' => $foreign->id, 'label' => 'Foreign rewrite']]]), $row, true)->assertNotFound();
        $this->assertNull(session('shift_result'));
        $this->assertSame($before, $this->state());
    }

    public function test_start_change_clears_existing_sent_reminders_without_replacing_task_ids(): void
    {
        $row = $this->shift();
        $task = $this->task($row, ['scheduled_time' => '08:00', 'reminder_sent_at' => now()]);
        $owned = $this->task($row, ['creation_key' => (string) Str::uuid(), 'scheduled_time' => '08:01', 'reminder_sent_at' => now()]);
        $completed = $this->task($row, ['scheduled_time' => '08:02', 'reminder_sent_at' => now(), 'is_completed' => true,
            'completed_at' => now(), 'completed_by' => $this->worker->id]);
        $this->decision('pass');
        $this->commitFixtures();
        $body = $this->body();
        $this->requestSave('update', $body, $row)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('saved', session('shift_result.outcome'));
        $this->assertSame(Carbon::parse($body['starts_at'])->toISOString(), $row->fresh()->starts_at->toISOString());
        $this->assertSame(Carbon::parse($body['ends_at'])->toISOString(), $row->fresh()->ends_at->toISOString());
        $this->assertNull($task->fresh()->reminder_sent_at);
        $this->assertNull($owned->fresh()->reminder_sent_at);
        $this->assertNotNull($completed->fresh()->reminder_sent_at);
        $this->assertSame([$task->id, $owned->id, $completed->id], ShiftTask::where('shift_id', $row->id)->orderBy('id')->pluck('id')->all());
    }

    public static function persistenceFaults(): array
    {
        return ['create Shift veto' => ['create', 'shift', 'veto'], 'create Shift alteration' => ['create', 'shift', 'alter'],
            'update Shift veto' => ['update', 'shift', 'veto'], 'update Shift alteration' => ['update', 'shift', 'alter'],
            'create task veto' => ['create', 'task', 'veto'], 'create task alteration' => ['create', 'task', 'alter'],
            'update task veto' => ['update', 'task', 'veto'], 'update task alteration' => ['update', 'task', 'alter']];
    }

    #[DataProvider('persistenceFaults')]
    public function test_actual_model_refusal_or_altered_persistence_rolls_back_the_whole_planning_graph(string $action, string $target, string $fault): void
    {
        $row = $action === 'update' ? $this->shift() : null;
        $task = $row ? $this->task($row) : null;
        $body = $this->body(['location' => 'Measured change', 'tasks' => [['label' => 'Measured task', 'scheduled_time' => '08:45']]]);
        if ($task) {
            $body['tasks'][0]['id'] = $task->id;
        }
        $this->decision('pass');
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queueState();
        $this->isolateModelEvents();
        $model = $target === 'shift' ? Shift::class : ShiftTask::class;
        $model::saving(static function (Model $saved) use ($fault): ?bool {
            if ($fault === 'veto') {
                return false;
            }
            $saved->{$saved instanceof Shift ? 'location' : 'label'} = 'Altered by actual observer';

            return null;
        });
        $this->withProductionManager(function () use ($action, $body, $row, $before, $queue) {
            $response = $this->requestSave($action, $body, $row, true);
            $this->assertContains($response->status(), [409, 422]);
            $this->assertNull(session('shift_result'));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_actual_override_veto_rolls_back_saved_shift_tasks_audit_and_intents(): void
    {
        $row = $this->shift();
        $task = $this->task($row);
        $this->decision('warning');
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queueState();
        $this->isolateModelEvents();
        ShiftEligibilityOverride::creating(static fn (): bool => false);
        $this->withProductionManager(function () use ($row, $task, $before, $queue) {
            $this->requestSave('update', $this->body(['override_acknowledged' => true, 'override_reason' => 'Actual refusal reason',
                'tasks' => [['id' => $task->id, 'label' => 'Unapplied task']]]), $row, true)->assertStatus(409);
            $this->assertNull(session('shift_result'));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_actual_hold_fulfillment_veto_rolls_back_shift_tasks_and_the_original_active_hold(): void
    {
        $row = $this->shift();
        $rule = $this->coverageRule();
        $body = $this->body(['coverage_rule_id' => $rule->id, 'tasks' => [['label' => 'Unapplied held task']]]);
        $hold = $this->hold($body, $rule);
        $body['coverage_reservation_token'] = $hold->reservation_token;
        $this->decision('pass');
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queueState();
        $this->isolateModelEvents();
        CoverageReservation::updating(static fn (CoverageReservation $candidate): ?bool => $candidate->status === CoverageReservationService::STATUS_FULFILLED ? false : null);
        $this->withProductionManager(function () use ($body, $row, $hold, $before, $queue) {
            $this->requestSave('update', $body, $row, true)->assertStatus(409);
            $this->assertSame(CoverageReservationService::STATUS_ACTIVE, $hold->fresh()->status);
            $this->assertNull(session('shift_result'));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_hold_release_veto_cannot_certify_a_known_not_saved_warning_outcome(): void
    {
        $row = $this->shift();
        $rule = $this->coverageRule();
        $body = $this->body(['coverage_rule_id' => $rule->id]);
        $hold = $this->hold($body, $rule);
        $body['coverage_reservation_token'] = $hold->reservation_token;
        $this->decision('warning');
        $this->commitFixtures();
        $before = $this->state();
        $this->isolateModelEvents();
        CoverageReservation::updating(static fn (CoverageReservation $candidate): ?bool => $candidate->status === CoverageReservationService::STATUS_RELEASED ? false : null);
        $this->requestSave('update', $body, $row, true)->assertStatus(409);
        $this->assertNull(session('shift_result'));
        $this->assertSame($before, $this->state());
    }

    public function test_replacement_suffix_failure_cannot_leave_a_partial_assignment_task_or_hold_save(): void
    {
        $row = $this->shift();
        $replacement = $this->person([]);
        $this->decision('pass');
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queueState();
        $this->mock(ShiftReplacementService::class, fn ($mock) => $mock->shouldReceive('resolveFromManualAssignment')->once()->andThrow(new \RuntimeException('sanitized replacement failure')));
        $this->withProductionManager(function () use ($row, $replacement, $before, $queue) {
            $this->withoutExceptionHandling();
            try {
                $this->requestSave('update', $this->body(['user_id' => $replacement->id, 'tasks' => [['label' => 'Unapplied replacement task']]]), $row);
                $this->fail('Expected the real suffix exception.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('sanitized replacement failure', $exception->getMessage());
            }
            $this->assertNull(session('shift_result'));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_receipt_projection_and_diagnostic_faults_do_not_turn_a_committed_save_into_failure(): void
    {
        $this->commitFixtures();
        $this->app->instance(ShiftPlanningReceipt::class, new class extends ShiftPlanningReceipt
        {
            private int $calls = 0;

            protected function isPhysicalRoot(): bool
            {
                if (++$this->calls === 2) {
                    throw new \RuntimeException('sanitized presentation failure');
                }

                return parent::isPhysicalRoot();
            }
        });
        Log::shouldReceive('warning')->andThrow(new \RuntimeException('sanitized logger failure'));
        $this->requestSave('create', $this->body(['user_id' => null]))->assertRedirect();
        $this->assertNull(session('shift_result'));
        $this->assertDatabaseHas('shifts', ['client_id' => $this->client->id, 'created_by' => $this->actor->id, 'status' => 'draft']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_post_commit_notification_and_logging_failure_preserves_the_saved_root_receipt(): void
    {
        $this->commitFixtures();
        $this->mock(NotificationService::class, function ($mock): void {
            $mock->shouldReceive('notifyCrud')->once()->andReturnUsing(function (): void {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertFalse(DB::connection()->getPdo()->inTransaction());
                throw new \RuntimeException('sanitized notice failure');
            });
        });
        Log::shouldReceive('warning')->andThrow(new \RuntimeException('sanitized logger failure'));
        $this->requestSave('create', $this->body())->assertRedirect();
        $this->assertSame('saved', session('shift_result.outcome'));
        $this->assertDatabaseHas('shifts', ['id' => session('shift_result.shift_id'), 'user_id' => $this->worker->id]);
    }

    public function test_pdo_only_transaction_is_not_a_physical_root_receipt_boundary(): void
    {
        $this->commitFixtures();
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue($pdo->inTransaction());
            $request = Request::create('/operations/shifts', 'POST');
            $request->setLaravelSession(session()->driver());
            session()->put('shift_result', ['action' => 'old']);
            $this->assertFalse(app(ShiftPlanningReceipt::class)->begin($request));
            $this->assertNull(session('shift_result'));
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_optional_licence_fields_retain_when_absent_and_clear_only_when_explicitly_null(): void
    {
        $row = $this->shift(['required_licence_class' => '2', 'required_licence_endorsements' => ['P', 'F']]);
        $this->commitFixtures();
        $body = $this->unchangedBody($row);
        unset($body['required_licence_class'], $body['required_licence_endorsements']);
        $this->requestSave('update', $body, $row)->assertRedirect();
        $this->assertSame('2', $row->fresh()->required_licence_class);
        $this->assertSame(['P', 'F'], $row->fresh()->required_licence_endorsements);
        $this->decision('pass');
        $body['required_licence_class'] = null;
        $body['required_licence_endorsements'] = [];
        $this->requestSave('update', $body, $row->fresh())->assertRedirect();
        $this->assertNull($row->fresh()->required_licence_class);
        $this->assertSame([], $row->fresh()->required_licence_endorsements);
    }

    public function test_whitespace_nulls_and_explicit_context_override_keep_existing_resolver_priorities(): void
    {
        $explicit = ServiceContext::factory()->create(['is_active' => true, 'site_id' => null]);
        $this->commitFixtures();
        $body = $this->body(['user_id' => null, 'service_context_id' => $explicit->id, 'location' => " \u{00A0} ", 'notes' => ' ',
            'tasks' => [['label' => 'Trimmed task', 'scheduled_time' => null]]]);
        $this->requestSave('create', $body)->assertRedirect();
        $row = Shift::findOrFail(session('shift_result.shift_id'));
        $this->assertSame($explicit->id, $row->service_context_id);
        $this->assertNull($row->location);
        $this->assertNull($row->notes);
        $this->assertSame($this->expectedHash($body), session('shift_result.values_hash'));
        $this->requestSave('create', $this->body(['user_id' => null, 'service_context_id' => null,
            'starts_at' => '2026-10-15T19:00:00Z', 'ends_at' => '2026-10-15T23:00:00Z']))->assertRedirect();
        $this->assertSame($this->context->id, Shift::findOrFail(session('shift_result.shift_id'))->service_context_id);
    }

    public function test_single_occurrence_save_preserves_the_pattern_other_occurrences_and_source_tuple(): void
    {
        $series = ShiftSeries::create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'user_id' => $this->worker->id, 'start_date' => '2026-10-12', 'end_date' => '2026-10-19', 'timezone' => 'Pacific/Auckland',
            'by_weekday' => [1], 'starts_time' => '08:00', 'ends_time' => '12:00', 'status' => 'draft', 'shift_type' => 'standard',
            'is_sleepover' => false, 'is_on_call' => false, 'is_lone_worker' => false, 'coverage_roles' => [],
            'required_licence_endorsements' => [], 'created_by' => $this->actor->id]);
        $row = $this->shift(['shift_series_id' => $series->id]);
        $other = $this->shift(['shift_series_id' => $series->id, 'starts_at' => '2026-10-18 19:00:00', 'ends_at' => '2026-10-18 23:00:00']);
        $this->commitFixtures();
        $pattern = $series->fresh()->getRawOriginal();
        $otherRaw = $other->fresh()->getRawOriginal();
        $this->requestSave('update', [...$this->unchangedBody($row), 'location' => 'Only this occurrence', 'series_scope' => 'this'], $row)->assertRedirect();
        $this->assertSame($series->id, $row->fresh()->shift_series_id);
        $this->assertSame($series->id, session('shift_result.source.shift_series_id'));
        $this->assertSame('single', session('shift_result.scope'));
        $this->assertSame($pattern, $series->fresh()->getRawOriginal());
        $this->assertSame($otherRaw, $other->fresh()->getRawOriginal());
    }

    public static function timezones(): array
    {
        return ['Auckland overnight' => ['Pacific/Auckland', '2026-10-11T23:30:17+13:00', '2026-10-12T03:15:19+13:00', '2026-10-11T10:30:17.000000Z', '2026-10-11T14:15:19.000000Z'],
            'New York overnight' => ['America/New_York', '2026-10-11T23:30:17-04:00', '2026-10-12T03:15:19-04:00', '2026-10-12T03:30:17.000000Z', '2026-10-12T07:15:19.000000Z']];
    }

    #[DataProvider('timezones')]
    public function test_worker_zone_overnight_instants_and_seconds_survive_save_and_editable_reload(string $zone, string $starts, string $ends, string $expectedStarts, string $expectedEnds): void
    {
        config(['app.worker_timezone' => $zone]);
        $this->commitFixtures();
        $this->requestSave('create', $this->body(['user_id' => null, 'starts_at' => $starts, 'ends_at' => $ends]))->assertRedirect();
        $row = Shift::findOrFail(session('shift_result.shift_id'));
        $this->assertSame($expectedStarts, $row->starts_at->toISOString());
        $this->assertSame($expectedEnds, $row->ends_at->toISOString());
        $this->actingAs($this->actor)->getJson(route('operations.shifts.editable', $row))->assertOk()->assertJsonPath('worker_timezone', $zone);
    }

    public function test_create_and_single_update_keep_the_existing_maximum_24_elapsed_hours(): void
    {
        $row = $this->shift();
        $this->commitFixtures();
        $before = $this->state();
        $body = $this->body(['user_id' => null, 'starts_at' => '2026-10-11T19:00:00Z', 'ends_at' => '2026-10-12T19:00:01Z']);
        foreach (['create', 'update'] as $action) {
            $this->requestSave($action, $body, $action === 'update' ? $row : null)->assertRedirect()->assertSessionHasErrors('ends_at');
            $this->assertNull(session('shift_result'));
            $this->assertSame($before, $this->state());
        }
        $body['ends_at'] = '2026-10-12T19:00:00Z';
        session()->forget('errors');
        $this->requestSave('create', $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('saved', session('shift_result.outcome'));
    }

    public function test_denied_request_clears_previous_receipt_and_foreign_account_cannot_read_it(): void
    {
        $other = $this->person(['shifts.viewAny']);
        $row = $this->shift();
        $this->commitFixtures();
        $this->requestSave('create', $this->body(['user_id' => null]))->assertRedirect();
        $this->assertIsArray(session('shift_result'));
        $this->decision('warning');
        $this->requestSave('update', $this->body(), $row)->assertRedirect();
        $receipt = session('shift_result');
        $feedback = session('eligibility_result');
        $this->assertSame('not_saved', $receipt['outcome']);
        $this->assertSame($this->actor->id, $receipt['actor_id']);
        $this->assertSame(['Current fixture warning'], $feedback['warning_reasons']);
        $before = $this->state();
        $this->get(route('operations.shifts.index'))->assertInertia(fn ($page) => $page
            ->where('flash.shift_result', $receipt)
            ->where('flash.eligibility_result', $feedback));
        $this->assertSame($before, $this->state());
        // Restore the exact captured feedback so flash ageing cannot prove isolation.
        $this->withSession(['shift_result' => $receipt, 'eligibility_result' => $feedback]);
        $this->actingAs($other)->get(route('operations.shifts.index'))->assertRedirect(route('my-day'));
        $this->assertSame($before, $this->state());
        // Restore again after the real redirect so flash ageing cannot prove isolation.
        $this->withSession(['shift_result' => $receipt, 'eligibility_result' => $feedback]);
        $this->get(route('my-day'))->assertOk()->assertInertia(fn ($page) => $page
            ->component('my-day/index')
            ->where('flash.shift_result', null)
            ->where('flash.eligibility_result', null));
        $this->assertSame($before, $this->state());
        $before = $this->state();
        $beforeFeedback = session()->only(['shift_result', 'eligibility_result']);
        $this->assertSame(['shift_result' => $receipt, 'eligibility_result' => $feedback], $beforeFeedback);
        // Permission middleware denies before the controller can clear a prior receipt.
        $this->actingAs($other)->postJson(route('operations.shifts.store'), $this->body())->assertForbidden()
            ->assertJsonMissingPath('shift_result')->assertJsonMissingPath('eligibility_result');
        $this->assertSame($beforeFeedback, session()->only(['shift_result', 'eligibility_result']));
        $this->assertSame($this->actor->id, session('shift_result.actor_id'));
        $this->assertNotSame($other->id, session('shift_result.actor_id'));
        $this->assertSame($before, $this->state());

        $this->withSession($beforeFeedback);
        $this->get(route('my-day'))->assertOk()->assertInertia(fn ($page) => $page
            ->component('my-day/index')
            ->where('flash.shift_result', null)
            ->where('flash.eligibility_result', null));
        $this->assertSame($before, $this->state());

        // An authorised request reaches controller entry; validation denial clears its old receipt.
        $this->withSession($beforeFeedback);
        $this->assertSame($receipt, session('shift_result'));
        $this->actingAs($this->actor)->postJson(route('operations.shifts.store'),
            $this->body(['ends_at' => '2026-10-11T18:00:00Z']))
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->assertNull(session('shift_result'));
        $this->assertSame($before, $this->state());
    }

    public function test_independently_committed_replacement_and_current_recipient_name_are_not_lost_to_primed_rr(): void
    {
        $row = $this->shift();
        $next = $this->person([]);
        $this->decision('pass');
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        try {
            $this->assertFalse(ShiftReplacementRequest::where('shift_id', $row->id)->exists());
            $oldName = User::findOrFail($next->id)->name;
            $writer->table('users')->where('id', $next->id)->update(['name' => 'Current replacement ā']);
            $request = new ShiftReplacementRequest(['shift_id' => $row->id, 'requested_by' => $this->worker->id,
                'current_staff_id' => $this->worker->id, 'status' => 'requested', 'reason' => 'Current request', 'required_skills' => [], 'requested_at' => now()]);
            $request->setConnection('shift_save_writer');
            $this->assertSame($writer->getPdo(), $request->getConnection()->getPdo());
            $request->saveOrFail();
            $position = new ShiftOpenPosition(['shift_id' => $row->id, 'replacement_request_id' => $request->id,
                'status' => 'open', 'required_skills' => [], 'coverage_roles' => [], 'notes' => 'Current position']);
            $position->setConnection('shift_save_writer');
            $position->saveOrFail();
            $this->assertSame($oldName, User::findOrFail($next->id)->name);
            $this->assertFalse(ShiftReplacementRequest::where('shift_id', $row->id)->exists());
            $result = app(ShiftPlanningCommand::class)->save($this->actor, [...$this->unchangedBody($row), 'user_id' => $next->id], $row);
            $this->assertTrue($result->changed);
            // Current locking reads see the newly committed request inside this outer Tx.
            $current = DB::table('shift_replacement_requests')->where('id', $request->id)->lockForUpdate()->first();
            $currentPosition = DB::table('shift_open_positions')->where('id', $position->id)->lockForUpdate()->first();
            $this->assertSame('approved', $current->status);
            $this->assertSame($next->id, $current->replacement_user_id);
            $this->assertSame($this->actor->id, $current->approved_by);
            $this->assertSame('filled', $currentPosition->status);
            $this->assertSame($next->id, $currentPosition->claimed_by);
            $event = DB::table('timeline_events')->where('type', 'shift_replacement_approved')->orderByDesc('id')->lockForUpdate()->first();
            $this->assertNotNull($event);
            $this->assertStringContainsString('Current replacement ā', $event->body);
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertSame('requested', $writer->table('shift_replacement_requests')->where('id', $request->id)->value('status'));
        $this->assertSame('open', $writer->table('shift_open_positions')->where('id', $position->id)->value('status'));
        $this->assertSame($this->worker->id, $row->fresh()->user_id);
    }

    public function test_malformed_linked_replacement_position_cannot_partially_resolve_a_different_shift(): void
    {
        $row = $this->shift();
        $foreign = $this->shift(['starts_at' => '2026-10-16 19:00:00', 'ends_at' => '2026-10-16 23:00:00']);
        $next = $this->person([]);
        $request = ShiftReplacementRequest::create(['shift_id' => $row->id, 'requested_by' => $this->worker->id,
            'current_staff_id' => $this->worker->id, 'status' => 'requested', 'reason' => 'Malformed position', 'required_skills' => [], 'requested_at' => now()]);
        // Deliberately corrupt the legacy link through raw SQL; do not ask a model to construct a valid cross-Shift graph.
        $position = ShiftOpenPosition::create(['shift_id' => $row->id, 'replacement_request_id' => $request->id,
            'status' => 'open', 'required_skills' => [], 'coverage_roles' => []]);
        DB::table('shift_open_positions')->where('id', $position->id)->update(['shift_id' => $foreign->id]);
        $this->decision('pass');
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queueState();
        $this->withProductionManager(function () use ($row, $next, $before, $queue) {
            $this->requestSave('update', [...$this->unchangedBody($row), 'user_id' => $next->id], $row, true)->assertForbidden();
            $this->assertNull(session('shift_result'));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_actual_replacement_persistence_veto_rolls_back_assignment_and_all_linked_side_effects(): void
    {
        $row = $this->shift();
        $next = $this->person([]);
        $request = ShiftReplacementRequest::create(['shift_id' => $row->id, 'requested_by' => $this->worker->id,
            'current_staff_id' => $this->worker->id, 'status' => 'requested', 'reason' => 'Actual veto', 'required_skills' => [], 'requested_at' => now()]);
        ShiftOpenPosition::create(['shift_id' => $row->id, 'replacement_request_id' => $request->id,
            'status' => 'open', 'required_skills' => [], 'coverage_roles' => []]);
        $this->decision('pass');
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queueState();
        $this->isolateModelEvents();
        ShiftReplacementRequest::updating(static fn (): bool => false);
        $this->withProductionManager(function () use ($row, $next, $before, $queue) {
            $this->requestSave('update', [...$this->unchangedBody($row), 'user_id' => $next->id], $row, true)->assertStatus(409);
            $this->assertNull(session('shift_result'));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_existing_historical_replacement_references_do_not_acquire_new_current_profile_policy(): void
    {
        $row = $this->shift();
        $next = $this->person([]);
        $historical = User::factory()->create(['role' => 'client', 'approved_at' => null, 'external_clinical_account' => false]);
        $request = ShiftReplacementRequest::create(['shift_id' => $row->id, 'requested_by' => $historical->id,
            'current_staff_id' => $this->worker->id, 'replacement_user_id' => $historical->id, 'status' => 'claimed',
            'reason' => 'Retained legacy refs', 'required_skills' => [], 'requested_at' => now()]);
        $position = ShiftOpenPosition::create(['shift_id' => $row->id, 'replacement_request_id' => $request->id,
            'status' => 'claimed', 'claimed_by' => $historical->id, 'claimed_at' => now(), 'required_skills' => [], 'coverage_roles' => []]);
        $this->decision('pass');
        $this->commitFixtures();
        $this->requestSave('update', [...$this->unchangedBody($row), 'user_id' => $next->id], $row)->assertRedirect();
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame($historical->id, $request->fresh()->requested_by);
        $this->assertSame($next->id, $request->fresh()->replacement_user_id);
        $this->assertSame('filled', $position->fresh()->status);
        $this->assertSame($next->id, $position->fresh()->claimed_by);
        $this->assertSame('saved', session('shift_result.outcome'));
        $this->assertNull($historical->hrEmployeeProfile);
    }

    public static function warningOuter(): array
    {
        return ['commit' => [true], 'rollback' => [false]];
    }

    #[DataProvider('warningOuter')]
    public function test_nested_warning_cannot_publish_known_rejection_until_the_actual_hold_release_commits(bool $commit): void
    {
        $row = $this->shift();
        $rule = $this->coverageRule();
        $body = $this->body(['coverage_rule_id' => $rule->id]);
        $hold = $this->hold($body, $rule);
        $body['coverage_reservation_token'] = $hold->reservation_token;
        $this->decision('warning');
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queueState();
        $this->withProductionManager(function () use ($row, $body, $hold, $commit, $before, $queue): void {
            DB::beginTransaction();
            $this->requestSave('update', $body, $row)->assertRedirect();
            $this->assertNull(session('shift_result'));
            $this->assertSame(CoverageReservationService::STATUS_RELEASED, $hold->fresh()->status);
            $this->assertSame($before['shifts'], $this->state()['shifts']);
            $this->assertSame($queue, $this->queueState());
            $commit ? DB::commit() : DB::rollBack();
            $this->assertNull(session('shift_result'));
            $this->assertSame($commit ? CoverageReservationService::STATUS_RELEASED : CoverageReservationService::STATUS_ACTIVE, $hold->fresh()->status);
            if (! $commit) {
                $this->assertSame($before, $this->state());
            }
        });
    }

    public function test_current_client_context_resolution_uses_committed_context_after_primed_rr(): void
    {
        $row = $this->shift();
        $context = ServiceContext::factory()->create(['is_active' => true, 'site_id' => null]);
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        try {
            $this->assertSame($this->context->id, Client::findOrFail($this->client->id)->service_context_id);
            $writer->table('clients')->where('id', $this->client->id)->update(['service_context_id' => $context->id]);
            $this->assertSame($this->context->id, Client::findOrFail($this->client->id)->service_context_id);
            $result = app(ShiftPlanningCommand::class)->save($this->actor, [...$this->unchangedBody($row), 'service_context_id' => null], $row);
            $this->assertSame($context->id, $result->shift->service_context_id);
            $this->assertTrue($result->changed);
            $this->assertSame(null, $result->intent['service_context_id']);
            $this->assertSame($this->context->id, $result->source['service_context_id']);
        } finally {
            DB::rollBack();
        }
        $this->assertSame($this->context->id, $row->fresh()->service_context_id);
        $this->assertSame($context->id, $writer->table('clients')->where('id', $this->client->id)->value('service_context_id'));
    }

    public function test_current_owner_with_update_only_permission_keeps_existing_single_planning_access(): void
    {
        $owner = $this->person(['shifts.update']);
        $row = $this->shift(['user_id' => $owner->id]);
        $this->commitFixtures();
        $this->assertFalse($owner->canDo('shifts.manageAny'));
        $this->actingAs($owner)->put(route('operations.shifts.update', $row), [...$this->unchangedBody($row), 'notes' => 'Own planning update'])->assertRedirect();
        $this->assertSame('Own planning update', $row->fresh()->notes);
        $this->assertSame($owner->id, session('shift_result.actor_id'));
        $this->assertSame('saved', session('shift_result.outcome'));
    }

    public static function rootZones(): array
    {
        return ['configured zone' => ['America/New_York', 'UTC', 'America/New_York'],
            'null falls back' => [null, 'America/New_York', 'America/New_York'],
            'empty falls back' => ['', 'America/New_York', 'America/New_York']];
    }

    #[DataProvider('rootZones')]
    public function test_index_create_and_editable_share_the_configured_or_fallback_worker_zone(?string $workerZone, string $appZone, string $expected): void
    {
        $row = $this->shift(['is_lone_worker' => true]);
        config(['app.worker_timezone' => $workerZone, 'app.timezone' => $appZone]);
        $this->actingAs($this->actor)->get(route('operations.shifts.index', ['from' => '2026-10-10', 'to' => '2026-10-12']))
            ->assertInertia(fn ($page) => $page->where('workerTimezone', $expected));
        $this->getJson(route('operations.shifts.create'))->assertOk()->assertJsonPath('workerTimezone', $expected);
        $this->getJson(route('operations.shifts.editable', $row))->assertOk()->assertJsonPath('worker_timezone', $expected)
            ->assertJsonPath('actor_id', $this->actor->id)->assertJsonPath('is_lone_worker', true);
    }

    public function test_editable_reload_is_current_after_committed_control_and_task_changes_despite_primed_rr(): void
    {
        $row = $this->shift(['notes' => 'Private old note']);
        $manual = $this->task($row, ['label' => 'Old task', 'scheduled_time' => '08:00']);
        $protected = $this->task($row, ['label' => 'Source task', 'creation_key' => (string) Str::uuid()]);
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        try {
            $old = Shift::with('tasks')->findOrFail($row->id);
            $this->assertSame('Private old note', $old->notes);
            $this->assertSame('Old task', $old->tasks->firstWhere('id', $manual->id)->label);
            $writer->transaction(function () use ($writer, $row, $manual): void {
                $writer->table('shifts')->where('id', $row->id)->update(['notes' => 'Current note ā', 'location' => 'Current place',
                    'is_lone_worker' => true, 'expected_break_minutes' => 39, 'required_licence_class' => '2',
                    'required_licence_endorsements' => '["P","F"]', 'coverage_roles' => '["driver"]',
                    'starts_at' => '2026-10-10 19:00:17', 'ends_at' => '2026-10-10 23:00:19']);
                $writer->table('shift_tasks')->where('id', $manual->id)->update(['label' => 'Current task ā', 'scheduled_time' => '08:21']);
            });
            $this->assertSame('Private old note', Shift::findOrFail($row->id)->notes);
            $this->assertSame('Old task', ShiftTask::findOrFail($manual->id)->label);
            $before = $this->state($writer);
            $result = $this->actingAs($this->actor)->getJson(route('operations.shifts.editable', $row))->assertOk()
                ->assertJsonPath('actor_id', $this->actor->id)->assertJsonPath('notes', 'Current note ā')
                ->assertJsonPath('location', 'Current place')->assertJsonPath('is_lone_worker', true)
                ->assertJsonPath('expected_break_minutes', 39)->assertJsonPath('required_licence_class', '2')
                ->assertJsonPath('required_licence_endorsements', ['P', 'F'])->assertJsonPath('coverage_roles', ['driver'])->json();
            $this->assertSame('2026-10-10T19:00:17.000000Z', Carbon::parse($result['starts_at'])->toISOString());
            $this->assertSame('2026-10-10T23:00:19.000000Z', Carbon::parse($result['ends_at'])->toISOString());
            $tasks = collect($result['tasks'])->keyBy('id');
            $this->assertSame('Current task ā', $tasks[$manual->id]['label']);
            $this->assertSame('08:21', $tasks[$manual->id]['scheduled_time']);
            $this->assertTrue($tasks[$manual->id]['can_edit']);
            $this->assertFalse($tasks[$protected->id]['can_edit']);
            $this->assertSame($before, $this->state($writer));
            $this->assertNull(session('shift_result'));
        } finally {
            DB::rollBack();
        }
    }

    public static function editableDenials(): array
    {
        return ['approval' => ['approval', 403], 'exact update grant' => ['grant', 403],
            'archived Site' => ['site', 403], 'owner retarget' => ['owner', 409]];
    }

    #[DataProvider('editableDenials')]
    public function test_editable_current_denial_after_primed_rr_withholds_private_old_controls(string $change, int $status): void
    {
        $row = $this->shift(['notes' => 'Private old note']);
        $this->task($row, ['label' => 'Private old task']);
        $other = $this->person([]);
        $role = $this->actor->roles()->firstOrFail();
        $pivot = $role->permissions()->getTable();
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        try {
            $stale = User::findOrFail($this->actor->id)->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
            $this->assertTrue($stale->canDo('shifts.update'));
            $this->assertSame('Private old note', Shift::with('tasks')->findOrFail($row->id)->notes);
            Site::findOrFail($this->site->id);
            match ($change) {
                'approval' => $writer->table('users')->where('id', $this->actor->id)->update(['approved_at' => null]),
                'grant' => $writer->table($pivot)->where('role_id', $role->id)->where('permission_id', Permission::where('key', 'shifts.update')->value('id'))->delete(),
                'site' => $writer->table('sites')->where('id', $this->site->id)->update(['archived' => true, 'archived_at' => now()]),
                'owner' => $writer->table('shifts')->where('id', $row->id)->update(['user_id' => $other->id]),
            };
            $before = $this->state($writer);
            $response = $this->actingAs($stale)->getJson(route('operations.shifts.editable', $row))->assertStatus($status);
            $this->assertStringNotContainsString('Private old note', $response->getContent());
            $this->assertStringNotContainsString('Private old task', $response->getContent());
            $this->assertArrayNotHasKey('tasks', $response->json());
            $this->assertArrayNotHasKey('source', $response->json());
            $this->assertSame($before, $this->state($writer));
        } finally {
            DB::rollBack();
        }
    }

    #[DataProvider('actions')]
    public function test_supplied_active_coverage_hold_is_fulfilled_only_by_the_actual_saved_shift(string $action): void
    {
        $row = $action === 'update' ? $this->shift() : null;
        $rule = $this->coverageRule();
        $body = $this->body(['coverage_rule_id' => $rule->id, 'starts_at' => '2026-10-11T19:00:00Z', 'ends_at' => '2026-10-11T23:00:00Z']);
        $hold = $this->hold($body, $rule);
        $body['coverage_reservation_token'] = $hold->reservation_token;
        $this->decision('pass');
        $this->commitFixtures();
        $this->requestSave($action, $body, $row)->assertRedirect();
        $receipt = session('shift_result');
        $this->assertSame('saved', $receipt['outcome']);
        $this->assertSame(CoverageReservationService::STATUS_FULFILLED, $hold->fresh()->status);
        $this->assertSame($receipt['shift_id'], $hold->fresh()->shift_id);
        $this->assertSame($this->actor->id, $hold->fresh()->reserved_by_user_id);
        $this->assertSame($rule->id, $hold->fresh()->coverage_requirement_id);
    }

    public static function rejectedHolds(): array
    {
        return ['block' => ['block', 422], 'unavailable' => ['unavailable', 503]];
    }

    #[DataProvider('rejectedHolds')]
    public function test_known_block_or_unavailable_releases_supplied_hold_but_never_publishes_saved_or_warning_receipt(string $decision, int $status): void
    {
        $row = $this->shift();
        $rule = $this->coverageRule();
        $body = $this->body(['coverage_rule_id' => $rule->id]);
        $hold = $this->hold($body, $rule);
        $body['coverage_reservation_token'] = $hold->reservation_token;
        $this->decision($decision);
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queueState();
        $this->requestSave('update', $body, $row, true)->assertStatus($status)->assertJsonValidationErrors('user_id');
        $this->assertNull(session('shift_result'));
        $this->assertSame(CoverageReservationService::STATUS_RELEASED, $hold->fresh()->status);
        $this->assertNull($hold->fresh()->shift_id);
        foreach ($before as $table => $rows) {
            if (! in_array($table, ['coverage_reservations', 'audit_logs'], true)) {
                $this->assertSame($rows, $this->state()[$table], $table);
            }
        }
        $this->assertSame($queue, $this->queueState());
    }

    private function person(array $keys, bool $profile = true): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        if ($keys !== []) {
            $role = Role::create(['name' => 'shift-save-'.Str::uuid(), 'label' => 'Shift save fixture', 'type' => 'custom', 'level' => 10]);
            $role->permissions()->sync(collect($keys)->map(fn ($key) => Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'shifts', 'module' => 'Operations'])->id));
            $user->roles()->attach($role);
        }
        if ($profile) {
            HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
                'start_date' => '2026-01-01', 'end_date' => null, 'is_active' => true, 'hourly_rate' => 25,
                'created_by' => $user->id, 'updated_by' => $user->id]);
        }

        return $user->fresh();
    }

    private function body(array $overrides = []): array
    {
        return array_replace(['client_id' => $this->client->id, 'service_context_id' => $this->context->id,
            'user_id' => $this->worker->id, 'starts_at' => '2026-10-11T19:00:07Z', 'ends_at' => '2026-10-11T23:00:12Z',
            'location' => 'Requested location', 'notes' => 'Requested notes', 'status' => 'scheduled', 'shift_type' => 'standard',
            'is_sleepover' => false, 'is_on_call' => false, 'is_lone_worker' => false, 'expected_break_minutes' => 0,
            'coverage_roles' => []], $overrides);
    }

    private function unchangedBody(Shift $row): array
    {
        return ['client_id' => $row->client_id, 'service_context_id' => $row->service_context_id, 'user_id' => $row->user_id,
            'starts_at' => $row->starts_at->utc()->format('Y-m-d\TH:i:s\Z'), 'ends_at' => $row->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'location' => $row->location, 'notes' => $row->notes, 'status' => $row->status, 'shift_type' => $row->shift_type,
            'is_sleepover' => $row->is_sleepover, 'is_on_call' => $row->is_on_call, 'is_lone_worker' => $row->is_lone_worker,
            'expected_break_minutes' => $row->expected_break_minutes, 'coverage_roles' => $row->coverage_roles ?? []];
    }

    private function shift(array $overrides = []): Shift
    {
        return Shift::factory()->create(array_replace(['client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->context->id, 'user_id' => $this->worker->id, 'created_by' => $this->actor->id,
            'starts_at' => '2026-10-10 19:00:00', 'ends_at' => '2026-10-10 23:00:00', 'status' => 'scheduled',
            'location' => 'Original location', 'notes' => 'Original notes', 'shift_type' => 'standard',
            'is_sleepover' => false, 'is_on_call' => false, 'is_lone_worker' => false, 'expected_break_minutes' => 0,
            'coverage_roles' => [], 'required_licence_class' => null, 'required_licence_endorsements' => []], $overrides));
    }

    private function task(Shift $shift, array $overrides = []): ShiftTask
    {
        return ShiftTask::create(array_replace(['shift_id' => $shift->id, 'label' => 'Original task', 'scheduled_time' => null,
            'is_completed' => false, 'sort_order' => 0], $overrides));
    }

    private function source(Shift $shift): array
    {
        return ['shift_id' => $shift->id, 'client_id' => $shift->client_id, 'site_id' => $shift->site_id, 'user_id' => $shift->user_id,
            'service_context_id' => $shift->service_context_id, 'shift_series_id' => $shift->shift_series_id, 'status' => $shift->status];
    }

    /** Handwritten ordered control projection; never delegates to production Intent/Receipt. */
    private function expectedHash(array $body, ?Shift $original = null): string
    {
        $clean = static function (mixed $value) use (&$clean): mixed {
            if (is_array($value)) {
                return array_map($clean, $value);
            }
            if (! is_string($value)) {
                return $value;
            }
            $trimmed = preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $value);

            return $trimmed === '' ? null : $trimmed;
        };
        $data = $clean($body);
        $user = array_key_exists('user_id', $data) ? ($data['user_id'] === null ? null : (int) $data['user_id']) : $original?->user_id;
        $status = $data['status'] ?? ($original ? $original->status : ($user === null ? 'draft' : 'scheduled'));
        if ($user === null && $status === 'scheduled') {
            $status = 'draft';
        }
        $type = $data['shift_type'] ?? 'standard';
        $values = ['client_id' => (int) $data['client_id'], 'service_context_id' => isset($data['service_context_id']) ? (int) $data['service_context_id'] : null,
            'user_id' => $user, 'starts_at' => Carbon::parse($data['starts_at'])->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'ends_at' => Carbon::parse($data['ends_at'])->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'location' => array_key_exists('location', $data) ? $data['location'] : $original?->location,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $original?->notes, 'status' => $status, 'shift_type' => $type,
            'is_sleepover' => $type === 'sleepover' || (bool) ($data['is_sleepover'] ?? false),
            'is_on_call' => $type === 'on_call' || (bool) ($data['is_on_call'] ?? false), 'is_lone_worker' => (bool) ($data['is_lone_worker'] ?? false),
            'expected_break_minutes' => isset($data['expected_break_minutes']) ? (int) $data['expected_break_minutes'] : null,
            'coverage_roles' => array_values(array_key_exists('coverage_roles', $data) ? ($data['coverage_roles'] ?? []) : ($original?->coverage_roles ?? [])),
            'required_licence_class' => array_key_exists('required_licence_class', $data) ? $data['required_licence_class'] : $original?->required_licence_class,
            'required_licence_endorsements' => array_values(array_key_exists('required_licence_endorsements', $data) ? ($data['required_licence_endorsements'] ?? []) : ($original?->required_licence_endorsements ?? [])),
            'tasks' => array_key_exists('tasks', $data) ? array_map(static fn ($task) => ['id' => $original === null || empty($task['id']) ? null : (int) $task['id'],
                'label' => $task['label'], 'scheduled_time' => $task['scheduled_time'] ?? null], $data['tasks'] ?? []) : ($original ? null : [])];

        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
    }

    private function decision(string $outcome): void
    {
        $unavailablePrepared = false;
        if ($outcome === 'unavailable') {
            // The real gateway catches Throwable, including an assertion failure.
            // Prove outside that catch that the current-candidate checks completed.
            $this->beforeApplicationDestroyed(function () use (&$unavailablePrepared): void {
                $this->assertTrue($unavailablePrepared, 'Unavailable must follow the verified current candidate and recipient.');
            });
        }
        $this->mock(ShiftStaffEligibilityService::class, function ($mock) use ($outcome, &$unavailablePrepared): void {
            $mock->shouldReceive('evaluate')->once()->andReturnUsing(function (Shift $candidate, User $recipient) use ($outcome, &$unavailablePrepared): EligibilityResult {
                $this->assertSame($this->site->id, $candidate->site_id);
                $this->assertSame($candidate->user_id, $recipient->id);
                $this->assertTrue($recipient->isApproved());
                if ($outcome === 'unavailable') {
                    $unavailablePrepared = true;
                    throw new \RuntimeException('Sanitized fixture eligibility failure.');
                }
                $checks = $outcome === 'pass' ? [] : [['rule' => 'fatigue_weekly', 'passed' => false,
                    'severity' => $outcome === 'block' ? 'block' : 'warning', 'overrideable' => $outcome !== 'block',
                    'message' => $outcome === 'block' ? 'Current fixture hard block' : 'Current fixture warning']];

                return EligibilityResult::fromChecks($checks);
            });
        });
        $this->assertSame(AssignmentEligibilityGateway::class, get_class(app(AssignmentEligibilityGateway::class)));
    }

    private function requestSave(string $action, array $body, ?Shift $row = null, bool $json = false): TestResponse
    {
        $this->actingAs($this->actor)->withHeader('X-Shift-Result', 'committed-v1');
        $url = $action === 'create' ? route('operations.shifts.store') : route('operations.shifts.update', $row);
        if ($json) {
            return $action === 'create' ? $this->postJson($url, $body) : $this->putJson($url, $body);
        }

        return $action === 'create' ? $this->post($url, $body) : $this->put($url, $body);
    }

    private function coverageRule(): SiteCoverageRequirement
    {
        return SiteCoverageRequirement::create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'preferred_client_id' => $this->client->id, 'name' => 'Shift save coverage', 'coverage_type' => 'custom',
            'day_of_week' => 'mon', 'starts_time' => '08:00', 'ends_time' => '12:00', 'minimum_staff' => 1,
            'role_requirements' => [], 'allow_overstaffing' => true, 'shift_type' => 'standard', 'is_active' => true]);
    }

    private function hold(array $body, SiteCoverageRequirement $rule): CoverageReservation
    {
        return CoverageReservation::create(['site_id' => $this->site->id, 'coverage_requirement_id' => $rule->id,
            'reserved_by_user_id' => $this->actor->id, 'reservation_token' => (string) Str::uuid(),
            'status' => CoverageReservationService::STATUS_ACTIVE, 'reason' => 'shift_update',
            'window_starts_at' => $body['starts_at'], 'window_ends_at' => $body['ends_at'], 'expires_at' => now()->addMinutes(15)]);
    }

    private function state(?Connection $connection = null): array
    {
        $connection ??= DB::connection();
        $tables = ['shifts', 'shift_tasks', 'shift_series', 'shift_eligibility_overrides', 'coverage_reservations',
            'shift_replacement_requests', 'shift_open_positions', 'site_checklist_runs', 'audit_logs', 'notifications',
            'workforce_eligibility_rechecks', 'workforce_eligibility_observations', 'timeline_events',
            'shift_handovers', 'shift_notes', 'timesheets', 'hr_time_entries', 'billing_entries', 'shift_signals', 'shift_signal_outbox'];

        return collect($tables)->mapWithKeys(fn ($table) => [$table => $connection->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
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
        // Lawful setup observer jobs stay captured. Each measured command snapshots the whole map.
    }

    private function writer(): Connection
    {
        $name = 'shift_save_writer';
        config(['database.connections.'.$name => array_replace(DB::connection()->getConfig(), ['name' => $name])]);
        DB::purge($name);
        $writer = DB::connection($name);
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

    private function isolateModelEvents(): void
    {
        $events = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $events);
        $this->beforeApplicationDestroyed(static fn () => Model::setEventDispatcher($events));
    }

    private function assertCommandDenied(callable $command, int $status): void
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
