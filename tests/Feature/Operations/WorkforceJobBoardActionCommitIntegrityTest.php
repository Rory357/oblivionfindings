<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\CoverageReservation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Eligibility\EligibilityResult;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Inactive/source-derived only. Physical commit and model-intent controls, no external child runner. */
class WorkforceJobBoardActionCommitIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $foreignSite;

    private Client $client;

    private ServiceContext $context;

    private User $actor;

    private User $other;

    private User $foreign;

    private User $worker;

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
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->context = ServiceContext::factory()->create(['is_active' => true, 'site_id' => null]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
        $keys = ['job_board.claim', 'job_board.approve', 'job_board.viewAny'];
        $this->actor = $this->person($this->site, $keys);
        $this->other = $this->person($this->site, $keys);
        $this->foreign = $this->person($this->foreignSite, $keys);
        $this->worker = $this->person($this->site, ['shifts.viewAssigned']);
        $this->evaluation();
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('job_action_observer');
            if ($this->committed && DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction()) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    #[DataProvider('actions')]
    public function test_real_root_result_matches_independent_stored_intent(string $action): void
    {
        $position = $action === 'set_alert_preference' ? null : $this->position($action, true);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $position): void {
            $source = $this->source($action, $position);
            $body = $this->body($source, $action);
            $result = $this->actionPost($action, $position, $body)->assertOk()->assertSessionMissing('success')->json('result');
            $this->assertResult($result, $body, $action, $position);
            if ($position) {
                $row = $this->observer()->table('shift_replacement_requests')->where('id', $position->replacement_request_id)->sole();
                $this->assertSame($action === 'claim' ? 'claimed' : 'approved', $row->status);
                $this->assertSame($action === 'claim' ? $this->actor->id : $this->worker->id, (int) $row->replacement_user_id);
            }
        });
    }

    public static function actions(): array
    {
        return ['claim' => ['claim'], 'approve' => ['approve'], 'explicit preference' => ['set_alert_preference']];
    }

    #[DataProvider('dutyActions')]
    public function test_warning_is_a_saved_advisory_without_new_acknowledgement_permission(string $action): void
    {
        $position = $this->position($action);
        $this->evaluation('warning', 'Unrelated private Client name and training file must not leave this endpoint.');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $position): void {
            $source = $this->source($action, $position);
            $this->assertTrue($source['can'][$action]);
            $this->assertFalse($source['eligibility']['acknowledgement_required']);
            $this->assertFalse($source['eligibility']['reason_required']);
            $body = $this->body($source, $action);
            $result = $this->actionPost($action, $position, $body)->assertOk()->json('result');
            $this->assertResult($result, $body, $action, $position);
            $this->assertSame(['The gap between duties needs review.'], $result['eligibility']['warning_reasons']);
            $this->assertStringNotContainsString('private Client', json_encode($result));
            $this->assertFalse($this->actor->canDo('shifts.overrideEligibility'));
        });
    }

    public static function dutyActions(): array
    {
        return ['claim' => ['claim'], 'approve' => ['approve']];
    }

    #[DataProvider('faults')]
    public function test_each_governing_write_and_late_suffix_failure_rolls_back_every_row_and_queue(string $action, string $kind): void
    {
        $position = $action === 'set_alert_preference' ? null : $this->position($action, true);
        if (str_starts_with($kind, 'hold_')) {
            $this->coverage();
        }
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $kind, $position): void {
            $source = $this->source($action, $position);
            $body = $this->body($source, $action);
            $before = $this->state($this->observer());
            $queue = $this->queue();
            $this->fault($kind, $position);
            $this->actionPost($action, $position, $body)->assertConflict()->assertSessionMissing('success');
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    public static function faults(): array
    {
        return ['claim position veto' => ['claim', 'position_veto'], 'claim position altered' => ['claim', 'position_alter'],
            'claim replacement veto' => ['claim', 'replacement_veto'], 'claim replacement altered' => ['claim', 'replacement_alter'],
            'claim timeline veto' => ['claim', 'timeline_veto'], 'claim late Shift alteration' => ['claim', 'late_shift'],
            'claim hold creation veto' => ['claim', 'hold_create_veto'], 'claim hold creation altered' => ['claim', 'hold_create_alter'],
            'claim hold link alteration' => ['claim', 'hold_link_alter'],
            'approve position veto' => ['approve', 'position_veto'], 'approve Shift veto' => ['approve', 'shift_veto'],
            'approve Shift altered' => ['approve', 'shift_alter'], 'approve replacement altered' => ['approve', 'replacement_alter'],
            'approve sibling veto' => ['approve', 'sibling_veto'], 'approve timeline altered' => ['approve', 'timeline_alter'],
            'approve hold fulfillment altered' => ['approve', 'hold_fulfill_alter'], 'approve late Position alteration' => ['approve', 'late_position'],
            'preference veto' => ['set_alert_preference', 'user_veto'], 'preference opposite state' => ['set_alert_preference', 'user_alter']];
    }

    public function test_automatic_hold_creation_and_link_fulfillment_preserve_literal_owner_window_and_token(): void
    {
        $position = $this->position('claim');
        $this->coverage();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($position): void {
            $body = $this->body($this->source('claim', $position), 'claim');
            $this->actionPost('claim', $position, $body)->assertOk();
            $hold = $this->observer()->table('coverage_reservations')->where('shift_open_position_id', $position->id)->sole();
            $this->assertSame('active', $hold->status);
            $this->assertSame($this->actor->id, (int) $hold->reserved_by_user_id);
            $this->assertSame('job_board_claim', $hold->reason);
            $this->assertSame((int) $position->shift_id, (int) $hold->shift_id);
            $this->assertSame('2026-10-20 20:00:00', $hold->window_starts_at);
            $this->assertSame('2026-10-20 21:00:00', $hold->window_ends_at);
            $this->assertTrue(Str::isUuid($hold->reservation_token));
            $this->assertSame('2026-10-10 00:15:11', $hold->expires_at);
        });
    }

    #[DataProvider('nestedActions')]
    public function test_nested_commit_or_rollback_never_returns_a_physical_receipt(string $action, bool $commit): void
    {
        $position = $action === 'set_alert_preference' ? null : $this->position($action);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $commit, $position): void {
            $body = $this->body($this->source($action, $position), $action);
            $before = $this->state($this->observer());
            DB::beginTransaction();
            $this->actionPost($action, $position, $body)->assertStatus(202)->assertExactJson(['result' => null])->assertSessionMissing('success');
            $this->assertSame($before, $this->state($this->observer()));
            $commit ? DB::commit() : DB::rollBack();
            if (! $commit) {
                $this->assertSame($before, $this->state($this->observer()));
            } else {
                $this->assertNotSame($before, $this->state($this->observer()));
            }
        });
    }

    public static function nestedActions(): array
    {
        $cases = [];
        foreach (['claim', 'approve', 'set_alert_preference'] as $action) {
            foreach ([true, false] as $commit) {
                $cases[$action.($commit ? ' outer commit' : ' outer rollback')] = [$action, $commit];
            }
        }

        return $cases;
    }

    #[DataProvider('actions')]
    public function test_untracked_pdo_transaction_refuses_before_any_change(string $action): void
    {
        $position = $action === 'set_alert_preference' ? null : $this->position($action);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $position): void {
            $body = $this->body($this->source($action, $position), $action);
            $before = $this->state($this->observer());
            DB::connection()->getPdo()->beginTransaction();
            try {
                $this->actionPost($action, $position, $body)->assertConflict()->assertSessionMissing('success');
            } finally {
                DB::connection()->getPdo()->rollBack();
            }
            $this->assertSame($before, $this->state($this->observer()));
        });
    }

    public function test_a_lost_preference_response_can_be_recovered_with_current_explicit_state_without_inverting(): void
    {
        $this->commitFixtures();
        $this->withProductionManager(function (): void {
            $body = $this->body($this->source('set_alert_preference'), 'set_alert_preference');
            $this->actionPost('set_alert_preference', null, $body)->assertOk();
            $saved = $this->state($this->observer());
            $this->actionPost('set_alert_preference', null, $body)->assertConflict(); // Stale original source never toggles.
            $source = $this->source('set_alert_preference');
            $this->assertTrue($source['enabled']);
            $next = $this->body($source, 'set_alert_preference');
            $result = $this->actionPost('set_alert_preference', null, $next)->assertOk()->json('result');
            $this->assertSame('unchanged', $result['outcome']);
            $this->assertFalse($result['changed']);
            $this->assertSame($saved, $this->state($this->observer()));
        });
    }

    #[DataProvider('dutyActions')]
    public function test_displayed_source_retime_claimant_and_privacy_are_bound_before_write(string $action): void
    {
        $position = $this->position($action);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $position): void {
            $source = $this->source($action, $position);
            $this->assertSame($position->id, $source['display']['position_id']);
            $this->assertSame($position->shift_id, $source['display']['shift_id']);
            $oldBody = $this->body($source, $action);
            $this->observer()->table('shifts')->where('id', $position->shift_id)->update(['starts_at' => '2026-10-20 20:05:00']);
            $current = $this->source($action, $position);
            $this->assertNotSame($source['source_revision'], $current['source_revision']);
            $this->assertSame('2026-10-20T20:05:00.000000Z', $current['display']['starts_at']);
            $before = $this->state($this->observer());
            $this->actionPost($action, $position, $oldBody)->assertConflict();
            $this->assertSame($before, $this->state($this->observer()));
        });
    }

    public function test_current_permission_and_site_revocations_refuse_cached_actor_and_hidden_target(): void
    {
        $position = $this->position('claim');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($position): void {
            $body = $this->body($this->source('claim', $position), 'claim');
            $this->actor->canDo('job_board.claim');
            $permission = Permission::where('key', 'job_board.claim')->sole();
            $this->observer()->table('permission_user')->where('user_id', $this->actor->id)->where('permission_id', $permission->id)->update(['allowed' => false]);
            $before = $this->state($this->observer());
            $this->actionPost('claim', $position, $body)->assertForbidden();
            $this->assertSame($before, $this->state($this->observer()));
            $this->observer()->table('permission_user')->where('user_id', $this->actor->id)->where('permission_id', $permission->id)->update(['allowed' => true]);
            $this->observer()->table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['primary_site_id' => $this->foreignSite->id]);
            $before = $this->state($this->observer());
            $this->actionPost('claim', $position, $body)->assertNotFound();
            $this->assertSame($before, $this->state($this->observer()));
        });
    }

    public function test_reports_site_exception_is_preserved_without_becoming_action_permission(): void
    {
        $position = $this->position('claim');
        $reporter = $this->person($this->foreignSite, ['reports.viewAny', 'job_board.claim']);
        $readOnly = $this->person($this->foreignSite, ['reports.viewAny', 'job_board.viewAny']);
        $this->actingAs($reporter)->getJson(route('operations.job_board.action_source', ['position' => $position->id, 'action' => 'claim']))
            ->assertOk()->assertJsonPath('actor_id', $reporter->id);
        $this->actingAs($readOnly)->getJson(route('operations.job_board.action_source', ['position' => $position->id, 'action' => 'claim']))->assertForbidden();
    }

    public function test_after_commit_transport_failure_is_uncertain_and_current_read_recovers_without_replay(): void
    {
        $position = $this->position('claim');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($position): void {
            $body = $this->body($this->source('claim', $position), 'claim');
            Event::listen('eloquent.updated: '.ShiftOpenPosition::class, function (ShiftOpenPosition $row) use ($position): void {
                if ($row->id === $position->id) {
                    DB::afterCommit(static fn () => throw new \RuntimeException('Synthetic lost response after commit.'));
                }
            });
            $this->actionPost('claim', $position, $body)->assertServerError()->assertSessionMissing('success');
            $stored = $this->observer()->table('shift_open_positions')->where('id', $position->id)->sole();
            $this->assertSame('claimed', $stored->status);
            $this->assertSame($this->actor->id, (int) $stored->claimed_by);
            $source = $this->source('claim', $position);
            $this->assertFalse($source['can']['claim']);
            $saved = $this->state($this->observer());
            $this->actionPost('claim', $position, $body)->assertConflict();
            $this->assertSame($saved, $this->state($this->observer()));
        });
    }

    #[DataProvider('pastOrInProgress')]
    public function test_installed_past_and_in_progress_policy_is_not_silently_tightened(string $action, string $status, bool $past): void
    {
        $position = $this->position($action);
        $values = ['status' => $status];
        if ($past) {
            $values += ['starts_at' => now()->subDays(2)->startOfSecond(), 'ends_at' => now()->subDays(2)->addHour()->startOfSecond()];
        }
        if ($status === 'in_progress') {
            $values['actual_starts_at'] = $values['starts_at'] ?? $position->shift->starts_at;
        }
        $position->shift->update($values);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $position, $status): void {
            $body = $this->body($this->source($action, $position), $action);
            $result = $this->actionPost($action, $position, $body)->assertOk()->json('result');
            $this->assertSame($status, $result['shift']['status']);
        });
    }

    public static function pastOrInProgress(): array
    {
        return ['past claim' => ['claim', 'scheduled', true], 'past approval' => ['approve', 'scheduled', true],
            'in-progress claim' => ['claim', 'in_progress', false], 'in-progress approval' => ['approve', 'in_progress', false]];
    }

    #[DataProvider('deniedAssessments')]
    public function test_blocked_and_unknown_assessments_never_write_or_claim_confirmation(string $action, string $severity): void
    {
        $position = $this->position($action);
        $source = $this->source($action, $position);
        if ($severity === 'unknown') {
            $this->mock(ShiftStaffEligibilityService::class)->shouldReceive('evaluate')->andThrow(new \RuntimeException('Private evaluator failure'));
        } else {
            $this->evaluation('block', 'Unrelated private Client is overlapping.');
        }
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $severity, $position, $source): void {
            $before = $this->state($this->observer());
            $queue = $this->queue();
            $response = $this->actionPost($action, $position, $this->body($source, $action));
            $response->assertStatus($severity === 'unknown' ? 503 : 422)->assertSessionMissing('success');
            $this->assertStringNotContainsString('Private evaluator', $response->getContent());
            $this->assertStringNotContainsString('private Client', $response->getContent());
            $this->assertSame($before, $this->state($this->observer()));
            $this->assertSame($queue, $this->queue());
        });
    }

    public static function deniedAssessments(): array
    {
        return ['claim blocked' => ['claim', 'block'], 'claim unknown' => ['claim', 'unknown'],
            'approve blocked' => ['approve', 'block'], 'approve unknown' => ['approve', 'unknown']];
    }

    public function test_redacted_source_and_request_actor_cannot_be_reused_by_another_actor(): void
    {
        $position = $this->position('claim');
        $this->actingAs($this->worker)->getJson(route('operations.job_board.action_source', ['position' => $position->id, 'action' => 'claim']))
            ->assertOk()->assertJsonPath('display.client_redacted', true)
            ->assertJsonMissing(['notes' => 'Private synthetic position notes']);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($position): void {
            $body = $this->body($this->source('claim', $position), 'claim');
            $before = $this->state($this->observer());
            $this->actingAs($this->other)->postJson(route('operations.job_board.claim', $position), $body)->assertConflict();
            $this->assertSame($before, $this->state($this->observer()));
            $this->actor = $this->other;
            $body = $this->body($this->source('claim', $position), 'claim');
            $result = $this->actionPost('claim', $position, $body)->assertOk()->json('result');
            $this->assertResult($result, $body, 'claim', $position);
        });
    }

    public function test_primed_repeatable_read_does_not_resurrect_a_revoked_action_grant(): void
    {
        $position = $this->position('claim');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($position): void {
            $body = $this->body($this->source('claim', $position), 'claim');
            $permission = Permission::where('key', 'job_board.claim')->sole();
            DB::beginTransaction();
            DB::table('permission_user')->where('user_id', $this->actor->id)->get();
            $this->actor->canDo('job_board.claim');
            $this->observer()->table('permission_user')->where('user_id', $this->actor->id)->where('permission_id', $permission->id)->update(['allowed' => false]);
            $before = $this->state($this->observer());
            try {
                $this->actionPost('claim', $position, $body)->assertForbidden()->assertSessionMissing('success');
                $this->assertSame($before, $this->state($this->observer()));
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_post_commit_row_change_withholds_result_instead_of_claiming_a_rollback(): void
    {
        $position = $this->position('claim');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($position): void {
            $body = $this->body($this->source('claim', $position), 'claim');
            Event::listen('eloquent.updated: '.ShiftOpenPosition::class, function (ShiftOpenPosition $row) use ($position): void {
                if ($row->id === $position->id) {
                    DB::afterCommit(fn () => DB::table('shift_open_positions')->where('id', $position->id)->update(['notes' => 'Later committed observer write']));
                }
            });
            $this->actionPost('claim', $position, $body)->assertStatus(202)->assertExactJson(['result' => null])->assertSessionMissing('success');
            $stored = $this->observer()->table('shift_open_positions')->where('id', $position->id)->sole();
            $this->assertSame('claimed', $stored->status);
            $this->assertSame('Later committed observer write', $stored->notes);
        });
    }

    #[DataProvider('actions')]
    public function test_legacy_routes_keep_root_redirect_and_success_but_not_a_modern_receipt(string $action): void
    {
        $position = $action === 'set_alert_preference' ? null : $this->position($action);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $position): void {
            $url = $position ? route('operations.job_board.'.$action, $position) : route('operations.job_board.alerts.toggle');
            $this->actingAs($this->actor)->post($url, [])->assertRedirect()->assertSessionHas('success');
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            if ($position) {
                $this->assertSame($action === 'claim' ? 'claimed' : 'filled', $this->observer()->table('shift_open_positions')->where('id', $position->id)->value('status'));
            } else {
                $this->assertTrue((bool) $this->observer()->table('users')->where('id', $this->actor->id)->value('job_board_alerts_enabled'));
            }
        });
    }

    private function evaluation(string $severity = 'pass', string $message = ''): void
    {
        $result = EligibilityResult::fromChecks([['rule' => 'turnaround', 'passed' => $severity === 'pass',
            'severity' => $severity === 'pass' ? 'warning' : $severity, 'overrideable' => true, 'message' => $message]]);
        $this->mock(ShiftStaffEligibilityService::class)->shouldReceive('evaluate')->andReturn($result);
    }

    private function position(string $action, bool $replacement = false): ShiftOpenPosition
    {
        $shift = $this->shift($replacement);
        if ($replacement) {
            $shift->update(['user_id' => $this->other->id]);
            $shift = $shift->fresh();
        }
        $request = $replacement ? $this->replacement($shift) : null;
        if ($action === 'approve' && $request) {
            $request->update(['status' => 'claimed', 'replacement_user_id' => $this->worker->id, 'claimed_at' => now()->startOfSecond()]);
        }
        $position = ShiftOpenPosition::create(['shift_id' => $shift->id, 'replacement_request_id' => $request?->id,
            'status' => $action === 'approve' ? 'claimed' : 'open', 'required_skills' => [], 'coverage_roles' => [],
            'notes' => 'Private synthetic position notes', 'claimed_by' => $action === 'approve' ? $this->worker->id : null,
            'claimed_at' => $action === 'approve' ? now()->startOfSecond() : null, 'expires_at' => now()->addDays(40)->startOfSecond()])->fresh(['shift']);
        if ($action === 'approve') {
            ShiftOpenPosition::create(['shift_id' => $shift->id, 'status' => 'open', 'required_skills' => [], 'coverage_roles' => []]);
        }

        return $position;
    }

    private function coverage(): void
    {
        SiteCoverageRequirement::create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'preferred_client_id' => $this->client->id, 'name' => 'Synthetic checked hold', 'coverage_type' => 'day', 'day_of_week' => 'wed',
            'starts_time' => '09:00', 'ends_time' => '10:00', 'minimum_staff' => 3, 'role_requirements' => [],
            'allow_overstaffing' => true, 'shift_type' => 'standard', 'is_active' => true]);
    }

    private function source(string $action, ?ShiftOpenPosition $position = null): array
    {
        $url = $position ? route('operations.job_board.action_source', ['position' => $position->id, 'action' => $action])
            : route('operations.job_board.alerts.preference');

        return $this->actingAs($this->actor)->getJson($url)->assertOk()->json();
    }

    private function body(array $source, string $action): array
    {
        $body = ['request_id' => (string) Str::uuid(), 'source_revision' => $source['source_revision']];
        if ($action === 'set_alert_preference') {
            $body['enabled'] = true;
        }

        return $body;
    }

    private function actionPost(string $action, ?ShiftOpenPosition $position, array $body)
    {
        $url = $action === 'set_alert_preference' ? route('operations.job_board.alerts.toggle')
            : route('operations.job_board.'.$action, $position->id);

        return $this->actingAs($this->actor)->postJson($url, $body);
    }

    private function assertResult(array $result, array $body, string $action, ?ShiftOpenPosition $position): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame(1, $result['version']);
        $this->assertSame('job_board', $result['scope']);
        $this->assertSame($action, $result['action']);
        $this->assertSame($this->actor->id, $result['actor_id']);
        $this->assertSame($body['request_id'], $result['request_id']);
        $this->assertSame($body['source_revision'], $result['source_revision']);
        $this->assertNotNull($result['committed_at']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $result['result_revision']);
        $this->assertSame($this->source($action, $position)['source_revision'], $result['result_revision']);
        if (! $position) {
            $this->assertNull($result['position']);
            $this->assertNull($result['shift']);
            $this->assertTrue((bool) $this->observer()->table('users')->where('id', $this->actor->id)->value('job_board_alerts_enabled'));
            $this->assertSame(['enabled' => true], $result['preference']);

            return;
        }
        $row = $this->observer()->table('shift_open_positions')->where('id', $position->id)->sole();
        $this->assertSame($action === 'claim' ? 'claimed' : 'filled', $row->status);
        $this->assertSame((int) $row->id, $result['position']['id']);
        $this->assertSame((int) $row->shift_id, $result['position']['shift_id']);
        $this->assertSame((int) $row->claimed_by, $result['position']['claimed_by']);
        $this->assertSame($row->approved_by === null ? null : (int) $row->approved_by, $result['position']['approved_by']);
        $this->assertSame(Carbon::parse($row->claimed_at, 'UTC')->toISOString(), $result['position']['claimed_at']);
        $this->assertSame($row->approved_at ? Carbon::parse($row->approved_at, 'UTC')->toISOString() : null, $result['position']['approved_at']);
        $duty = $this->observer()->table('shifts')->where('id', $position->shift_id)->sole();
        if ($action === 'approve') {
            $this->assertSame((int) $row->claimed_by, (int) $duty->user_id);
            $this->assertSame(['cancelled'], $this->observer()->table('shift_open_positions')->where('shift_id', $position->shift_id)
                ->where('id', '!=', $position->id)->pluck('status')->all());
        }
        $this->assertSame($duty->status, $result['shift']['status']);
        $this->assertNull($result['preference']);
        $this->assertFalse($result['eligibility']['acknowledgement_required']);
        $this->assertFalse($result['eligibility']['reason_required']);
    }

    private function fault(string $kind, ?ShiftOpenPosition $position): void
    {
        [$class, $event] = match (true) {
            str_starts_with($kind, 'position_'), $kind === 'sibling_veto' => [ShiftOpenPosition::class, 'saving'],
            str_starts_with($kind, 'replacement_') => [ShiftReplacementRequest::class, 'saving'],
            str_starts_with($kind, 'shift_') => [Shift::class, 'saving'],
            str_starts_with($kind, 'timeline_') => [TimelineEvent::class, 'creating'],
            str_starts_with($kind, 'hold_create_') => [CoverageReservation::class, 'creating'],
            str_starts_with($kind, 'hold_') => [CoverageReservation::class, 'saving'],
            str_starts_with($kind, 'user_') => [User::class, 'saving'],
            default => [TimelineEvent::class, 'created'],
        };
        Event::listen('eloquent.'.$event.': '.$class, function ($row) use ($kind, $position) {
            if ($kind === 'sibling_veto' && $row->id === $position->id) {
                return null;
            }
            if ($kind === 'hold_link_alter' && ! $row->shift_open_position_id) {
                return null;
            }
            if ($kind === 'hold_fulfill_alter' && $row->status !== 'fulfilled') {
                return null;
            }
            if (str_ends_with($kind, '_veto')) {
                return false;
            }
            match ($kind) {
                'position_alter' => $row->notes = 'Observer changed unrelated notes',
                'replacement_alter' => $row->reason = 'Observer changed governing reason',
                'shift_alter' => $row->notes = 'Observer changed original duty',
                'timeline_alter' => $row->subject = 'Observer changed required history',
                'hold_create_alter', 'hold_link_alter', 'hold_fulfill_alter' => $row->reserved_by_user_id = $this->other->id,
                'user_alter' => $row->job_board_alerts_enabled = false,
                'late_shift' => DB::table('shifts')->where('id', $position->shift_id)->update(['notes' => 'Late observer mutation']),
                'late_position' => DB::table('shift_open_positions')->where('id', $position->id)->update(['notes' => 'Late observer mutation']),
                default => null,
            };

            return null;
        });
    }

    private function person(Site $site, array $keys): User
    {
        $user = User::factory()->create(['role' => 'coordinator', 'approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'job-action-proof-'.Str::uuid(), 'label' => 'Synthetic action participant', 'level' => 10, 'type' => 'custom']);
        $user->roles()->attach($role);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'workforce', 'module' => 'Operations']);
            $user->permissionOverrides()->attach($permission, ['allowed' => true]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function shift(bool $assigned = false): Shift
    {
        return Shift::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->context->id, 'user_id' => $assigned ? $this->worker->id : null,
            'created_by' => $this->actor->id, 'starts_at' => Carbon::parse('2026-10-20T20:00:00Z'),
            'ends_at' => Carbon::parse('2026-10-20T21:00:00Z'), 'coverage_roles' => [], 'status' => 'scheduled', 'published_at' => null])->fresh();
    }

    private function replacement(Shift $shift): ShiftReplacementRequest
    {
        return ShiftReplacementRequest::create(['shift_id' => $shift->id, 'requested_by' => $this->worker->id,
            'current_staff_id' => $shift->user_id, 'status' => 'requested', 'reason' => 'Synthetic supported replacement',
            'requested_at' => now()->startOfSecond()])->fresh();
    }

    private function state(?Connection $connection = null): array
    {
        $tables = ['users', 'permission_user', 'hr_employee_profiles', 'sites', 'clients', 'service_contexts', 'shifts', 'shift_tasks',
            'shift_open_positions', 'shift_replacement_requests', 'coverage_reservations', 'shift_eligibility_overrides',
            'workforce_eligibility_rechecks', 'workforce_eligibility_observations', 'timeline_events', 'audit_logs',
            'notifications', 'shift_signals', 'shift_signal_outbox', 'site_coverage_requirements'];
        $sort = ['permission_user' => ['user_id', 'permission_id']];

        return collect($tables)->mapWithKeys(function (string $table) use ($connection, $sort): array {
            $query = ($connection ?? DB::connection())->table($table);
            foreach ($sort[$table] ?? ['id'] as $key) {
                $query->orderBy($key);
            }

            return [$table => $query->get()->map(fn ($row) => (array) $row)->all()];
        })->all();
    }

    private function queue(): array
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
        $this->assertSame($connection->getDatabaseName(), $connection->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertSame(1, $connection->transactionLevel());
        DB::commit();
        $this->committed = true;
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    private function observer(): Connection
    {
        $name = 'job_action_observer';
        config(['database.connections.'.$name => [...DB::connection()->getConfig(), 'name' => $name]]);
        $observer = DB::connection($name);
        $this->assertSame($name, $observer->getName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($observer->getDatabaseName(), getmypid()));
        $this->assertSame(DB::connection()->getDatabaseName(), $observer->getDatabaseName());
        $this->assertSame($observer->getDatabaseName(), $observer->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertNotSame(DB::connection()->getPdo(), $observer->getPdo());
        $this->assertSame(0, $observer->transactionLevel());
        $this->assertFalse($observer->getPdo()->inTransaction());

        return $observer;
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
