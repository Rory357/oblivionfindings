<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionCommand;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionReceipt;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\Site;
use App\Models\StaffAvailability;
use App\Models\User;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Current commands for one organisation: exact grants, approved Sites and canonical source records. */
class WorkforceSuggestionCommandIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $actor;

    private User $candidate;

    private Shift $shift;

    private RosterSuggestionRun $run;

    private RosterSuggestion $row;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08T04:00:00Z'));
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland', 'features.rostering.auto_schedule' => true]);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake();
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null, 'status' => 'active']);
        $this->actor = $this->person(['rostering.autoSchedule', 'shifts.overrideEligibility']);
        $this->candidate = $this->person();
        $this->shift = $this->duty();
        $this->run = RosterSuggestionRun::factory()->create(['site_id' => $this->site->id, 'requested_by' => $this->actor->id,
            'week_start' => '2026-10-12', 'week_end' => '2026-10-19', 'expires_at' => now()->addDay()]);
        $this->row = $this->suggestion($this->shift, $this->candidate);
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('suggestion_command_writer');
            if ($this->committed && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
            Carbon::setTestNow();
        } finally {
            parent::tearDown();
        }
    }

    public static function recordedStatuses(): array
    {
        return ['suggested' => [RosterSuggestion::STATUS_SUGGESTED], 'accepted' => [RosterSuggestion::STATUS_ACCEPTED],
            'dismissed' => [RosterSuggestion::STATUS_DISMISSED], 'applied' => [RosterSuggestion::STATUS_APPLIED],
            'stale' => [RosterSuggestion::STATUS_STALE], 'conflicted' => [RosterSuggestion::STATUS_CONFLICTED]];
    }

    #[DataProvider('recordedStatuses')]
    public function test_root_accept_preserves_every_permitted_status_and_its_unaffected_history(string $status): void
    {
        $prior = $this->person();
        $this->row->update(['status' => $status, 'accepted_by' => $prior->id, 'accepted_at' => now()->subHour(),
            'dismissed_by' => $prior->id, 'dismissed_at' => now()->subMinutes(30), 'applied_by' => $prior->id, 'applied_at' => now()->subMinutes(20)]);
        $body = $this->payload('accept');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->postCommand('accept', $body)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertReceipt('accept', $body, 'accepted', true);
            $saved = $this->row->fresh();
            $this->assertSame(RosterSuggestion::STATUS_ACCEPTED, $saved->status);
            $this->assertSame($this->actor->id, (int) $saved->accepted_by);
            $this->assertTrue($saved->accepted_at->equalTo(now()));
            $this->assertNull($saved->dismissed_by);
            $this->assertNull($saved->dismissed_at);
            $this->assertOnlySuggestionChanged($before, ['status', 'accepted_by', 'accepted_at', 'dismissed_by', 'dismissed_at', 'updated_at']);
            $this->assertSame($queue, $this->queueState());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        });
    }

    #[DataProvider('recordedStatuses')]
    public function test_root_dismiss_permits_expired_runs_and_preserves_acceptance_and_applied_history(string $status): void
    {
        $this->row->update(['status' => $status, 'accepted_by' => $this->actor->id, 'accepted_at' => now()->subHour(),
            'applied_by' => $this->actor->id, 'applied_at' => now()->subMinutes(20)]);
        $this->run->update(['expires_at' => now()->subSecond()]);
        $body = $this->payload('dismiss');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->postCommand('dismiss', $body)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertReceipt('dismiss', $body, 'dismissed', true);
            $saved = $this->row->fresh();
            $this->assertSame(RosterSuggestion::STATUS_DISMISSED, $saved->status);
            $this->assertSame($this->actor->id, (int) $saved->dismissed_by);
            $this->assertTrue($saved->dismissed_at->equalTo(now()));
            $this->assertOnlySuggestionChanged($before, ['status', 'dismissed_by', 'dismissed_at', 'updated_at']);
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_accept_does_not_invent_a_candidate_employment_prerequisite(): void
    {
        HrEmployeeProfile::where('user_id', $this->candidate->id)->update(['is_active' => false]);
        $body = $this->payload('accept');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->postCommand('accept', $body)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertReceipt('accept', $body, 'accepted', true);
            $this->assertFalse($this->candidate->hrEmployeeProfile()->firstOrFail()->is_active);
            $this->assertOnlySuggestionChanged($before, ['status', 'accepted_by', 'accepted_at', 'updated_at']);
            $this->assertSame($queue, $this->queueState());
            $this->assertNull($this->shift->fresh()->user_id);
        });
    }

    public static function expiredAcceptModes(): array
    {
        return ['legacy rejection after stale commit' => [false], 'correlated modern expired outcome' => [true]];
    }

    #[DataProvider('expiredAcceptModes')]
    public function test_expired_accept_commits_only_stale_and_never_confirms_acceptance(bool $modern): void
    {
        $this->run->update(['expires_at' => now()->subSecond()]);
        $body = $this->payload('accept');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($modern, $body): void {
            $before = $this->state();
            $queue = $this->queueState();
            if ($modern) {
                $this->postCommand('accept', $body)->assertRedirect()->assertSessionHasNoErrors();
                $this->assertReceipt('accept', $body, 'expired_marked_stale', true);
            } else {
                $this->post($this->commandUrl('accept'))->assertStatus(422);
                $this->assertNull(session('roster_suggestion_result'));
            }
            $this->assertSame(RosterSuggestion::STATUS_STALE, $this->row->fresh()->status);
            $this->assertNull($this->row->fresh()->accepted_by);
            $this->assertNull($this->shift->fresh()->user_id);
            $this->assertOnlySuggestionChanged($before, ['status', 'updated_at']);
            $this->assertSame($queue, $this->queueState());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        });
    }

    public static function sourceRetargets(): array
    {
        return ['another valid duty' => ['shift'], 'another valid candidate' => ['candidate'], 'another valid run' => ['run'],
            'same duty retimed' => ['time'], 'same duty reassigned' => ['owner']];
    }

    #[DataProvider('sourceRetargets')]
    public function test_a_read_issued_source_revision_rejects_pre_request_retarget_or_same_duty_control_changes(string $drift): void
    {
        $body = $this->payload('accept');
        $other = $this->person();
        $otherDuty = $this->duty();
        $otherRun = RosterSuggestionRun::factory()->create(['site_id' => $this->site->id, 'requested_by' => $this->actor->id, 'expires_at' => now()->addDay()]);
        match ($drift) {
            'shift' => $this->row->update(['shift_id' => $otherDuty->id]),
            'candidate' => $this->row->update(['candidate_user_id' => $other->id]),
            'run' => $this->row->update(['roster_suggestion_run_id' => $otherRun->id]),
            'time' => DB::table('shifts')->where('id', $this->shift->id)->update(['ends_at' => $this->shift->ends_at->copy()->addMinute()->format('Y-m-d H:i:s')]),
            'owner' => DB::table('shifts')->where('id', $this->shift->id)->update(['user_id' => $other->id, 'status' => 'scheduled']),
        };
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->postCommand('accept', $body)->assertStatus(409);
            $this->assertNull(session('roster_suggestion_result'));
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function malformedModernRequests(): array
    {
        return ['missing expected source' => ['source'], 'invalid correlation UUID' => ['uuid'],
            'incomplete source tuple' => ['tuple'], 'malformed opaque revision' => ['revision']];
    }

    #[DataProvider('malformedModernRequests')]
    public function test_modern_correlation_requires_a_complete_source_and_valid_uuid_before_any_write(string $bad): void
    {
        $body = $this->payload('accept');
        if ($bad === 'source') {
            unset($body['expected_source']);
        } elseif ($bad === 'uuid') {
            $body['request_id'] = 'not-a-uuid';
        } elseif ($bad === 'tuple') {
            unset($body['expected_source']['candidate_user_id']);
        } else {
            $body['expected_source']['source_revision'] = 'not-a-sha256';
        }
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->withSession(['roster_suggestion_result' => ['actor_id' => $this->actor->id, 'action' => 'accept']]);
            $this->postCommand('accept', $body)->assertRedirect()->assertSessionHasErrors();
            $this->assertNull(session('roster_suggestion_result'));
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_a_supplied_source_is_checked_even_without_the_opt_in_header(): void
    {
        $body = $this->payload('accept');
        DB::table('shifts')->where('id', $this->shift->id)->update(['ends_at' => $this->shift->ends_at->copy()->addMinute()->format('Y-m-d H:i:s')]);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->post($this->commandUrl('accept'), $body)->assertStatus(409);
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
            $this->assertNull(session('roster_suggestion_result'));
        });
    }

    public function test_show_issues_the_exact_canonical_source_revision_used_by_the_modern_writer(): void
    {
        $source = $this->source();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($source): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->get(route('operations.rostering.suggestions.show', $this->run))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('suggestions.0.expected_source', $source)
                    ->where('suggestions.0.source_revision', $source['source_revision']));
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
            $body = $this->payload('accept', $source);
            $this->postCommand('accept', $body)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertReceipt('accept', $body, 'accepted', true);
        });
    }

    public function test_withheld_current_rows_expose_no_revision_or_private_source_metadata(): void
    {
        $revision = $this->source()['source_revision'];
        $foreign = Site::factory()->create();
        $this->client->update(['site_id' => $foreign->id]);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($revision): void {
            $before = $this->state();
            $queue = $this->queueState();
            $response = $this->get(route('operations.rostering.suggestions.show', $this->run))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('suggestions', 0)->where('suggestion_visibility.withheld_count', 1));
            $this->assertStringNotContainsString($revision, $response->getContent());
            $this->assertStringNotContainsString('private-reason-', $response->getContent());
            $this->assertStringNotContainsString('private-snapshot-', $response->getContent());
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_accept_at_the_exact_expiry_instant_retains_the_existing_strict_expiry_boundary(): void
    {
        $this->run->update(['expires_at' => now()]);
        $body = $this->payload('accept');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->assertFalse($this->run->fresh()->isExpired());
            $this->postCommand('accept', $body)->assertStatus(303)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertReceipt('accept', $body, 'accepted', true);
            $this->assertOnlySuggestionChanged($before, ['status', 'accepted_by', 'accepted_at', 'updated_at']);
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_bulk_expired_choices_remain_recorded_and_receive_preflight_no_change_instead_of_an_acceptance_stale_write(): void
    {
        $this->row->update(['status' => RosterSuggestion::STATUS_ACCEPTED]);
        $this->run->update(['expires_at' => now()->subSecond()]);
        $body = $this->payload('apply_accepted');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->postCommand('apply_accepted', $body)->assertStatus(303)->assertRedirect()->assertSessionHasNoErrors();
            $result = $this->assertReceipt('apply_accepted', $body, 'not_applied', false);
            $this->assertSame('preflight_no_change', $result['disposition']);
            $this->assertSame(['selected' => 1, 'applied' => 0, 'stale' => 1, 'failed' => 0], $result['counts']);
            $this->assertSame([], $result['assignments']);
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
            $this->assertSame(RosterSuggestion::STATUS_ACCEPTED, $this->row->fresh()->status);
        });
    }

    public static function fractionalClockCommands(): array
    {
        return ['accept fractional clock' => ['accept'], 'dismiss fractional clock' => ['dismiss'], 'apply fractional clock' => ['apply']];
    }

    #[DataProvider('fractionalClockCommands')]
    public function test_live_precision_clocks_preserve_exact_saved_second_provenance_instead_of_rejecting_the_committed_intent(string $action): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08T04:00:00.654321Z'));
        $this->assertSame('654321', now()->format('u'));
        $body = $this->payload($action);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->postCommand($action, $body)->assertStatus(303)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertReceipt($action, $body, match ($action) {
                'accept' => 'accepted', 'dismiss' => 'dismissed', default => 'applied',
            }, true);
            $at = match ($action) {
                'accept' => $this->row->fresh()->accepted_at, 'dismiss' => $this->row->fresh()->dismissed_at,
                default => $this->row->fresh()->applied_at,
            };
            $this->assertSame('2026-10-08 04:00:00.000000', $at->format('Y-m-d H:i:s.u'));
            if ($action === 'apply') {
                $this->assertSame($this->candidate->id, $this->shift->fresh()->user_id);
                $this->assertSame([$this->assignment($this->row)], session('roster_suggestion_result')['assignments']);
            } else {
                $this->assertOnlySuggestionChanged($before, $action === 'accept' ? ['status', 'accepted_by', 'accepted_at', 'updated_at'] : ['status', 'dismissed_by', 'dismissed_at', 'updated_at']);
                $this->assertSame($queue, $this->queueState());
            }
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        });
    }

    public function test_a_legacy_root_caller_retains_its_redirect_and_cannot_claim_modern_request_correlation(): void
    {
        $source = $this->source();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($source): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->post($this->commandUrl('accept'))->assertStatus(302)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertReceipt('accept', ['request_id' => null, 'expected_source' => $source], 'accepted', true);
            $this->assertOnlySuggestionChanged($before, ['status', 'accepted_by', 'accepted_at', 'updated_at']);
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function unchangedActions(): array
    {
        return ['already accepted exactly' => ['accept', RosterSuggestion::STATUS_ACCEPTED],
            'already dismissed exactly' => ['dismiss', RosterSuggestion::STATUS_DISMISSED]];
    }

    #[DataProvider('unchangedActions')]
    public function test_an_exact_existing_status_is_a_no_change_with_retained_actual_attribution(string $action, string $status): void
    {
        $prefix = $action === 'accept' ? 'accepted' : 'dismissed';
        $this->row->update(['status' => $status, $prefix.'_by' => $this->actor->id, $prefix.'_at' => now()]);
        $body = $this->payload($action);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->postCommand($action, $body)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertReceipt($action, $body, 'unchanged', false);
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function applicableStatuses(): array
    {
        return ['suggested' => [RosterSuggestion::STATUS_SUGGESTED], 'accepted' => [RosterSuggestion::STATUS_ACCEPTED]];
    }

    #[DataProvider('applicableStatuses')]
    public function test_root_single_apply_records_the_actual_canonical_assignment_and_provenance(string $status): void
    {
        $this->row->update(['status' => $status]);
        $body = $this->payload('apply');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body): void {
            $beforeRun = $this->run->fresh()->getRawOriginal();
            $beforeShift = $this->shift->fresh()->getRawOriginal();
            $beforeRow = $this->row->fresh()->getRawOriginal();
            $this->postCommand('apply', $body)->assertRedirect()->assertSessionHasNoErrors();
            $result = $this->assertReceipt('apply', $body, 'applied', true);
            $this->assertSame('single', $result['disposition']);
            $this->assertSame(['selected' => 1, 'applied' => 1, 'stale' => 0, 'failed' => 0], $result['counts']);
            $this->assertSame([$this->assignment($this->row)], $result['assignments']);
            $this->assertSame($this->candidate->id, $this->shift->fresh()->user_id);
            $this->assertSame('scheduled', $this->shift->fresh()->status);
            $this->assertSame($beforeRun, $this->run->fresh()->getRawOriginal());
            $this->assertSame(array_diff_key($beforeShift, array_flip(['user_id', 'status', 'updated_at'])),
                array_diff_key($this->shift->fresh()->getRawOriginal(), array_flip(['user_id', 'status', 'updated_at'])));
            $this->assertSame(array_diff_key($beforeRow, array_flip(['status', 'applied_by', 'applied_at'])),
                array_diff_key($this->row->fresh()->getRawOriginal(), array_flip(['status', 'applied_by', 'applied_at'])));
            $this->assertSame($this->actor->id, (int) $this->row->fresh()->applied_by);
            $this->assertTrue($this->row->fresh()->applied_at->equalTo(now()));
            $this->assertTrue(DB::table('timeline_events')->where('shift_id', $this->shift->id)->exists());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        });
    }

    public function test_root_bulk_applies_all_current_accepted_choices_and_reports_the_actual_two_worker_cohort(): void
    {
        $secondWorker = $this->person();
        $secondShift = $this->duty();
        $second = $this->suggestion($secondShift, $secondWorker, ['status' => RosterSuggestion::STATUS_ACCEPTED]);
        $this->row->update(['status' => RosterSuggestion::STATUS_ACCEPTED]);
        $unselected = $this->suggestion($this->duty(), $this->person(), ['status' => RosterSuggestion::STATUS_DISMISSED]);
        $body = $this->payload('apply_accepted');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($second, $unselected, $body): void {
            $oldUnselected = $unselected->fresh()->getRawOriginal();
            $oldUnselectedShift = $unselected->shift()->firstOrFail()->getRawOriginal();
            $oldRun = $this->run->fresh()->getRawOriginal();
            $this->postCommand('apply_accepted', $body)->assertRedirect()->assertSessionHasNoErrors();
            $result = $this->assertReceipt('apply_accepted', $body, 'applied', true);
            $this->assertSame('applied', $result['disposition']);
            $this->assertSame(['selected' => 2, 'applied' => 2, 'stale' => 0, 'failed' => 0], $result['counts']);
            $this->assertSame([$this->assignment($this->row), $this->assignment($second)], $result['assignments']);
            $this->assertSame($oldUnselected, $unselected->fresh()->getRawOriginal());
            $this->assertSame($oldUnselectedShift, $unselected->shift()->firstOrFail()->getRawOriginal());
            $this->assertSame($oldRun, $this->run->fresh()->getRawOriginal());
            foreach ([$this->row, $second] as $row) {
                $this->assertSame(RosterSuggestion::STATUS_APPLIED, $row->fresh()->status);
                $this->assertSame((int) $row->candidate_user_id, (int) $row->shift()->firstOrFail()->user_id);
                $this->assertSame($this->actor->id, (int) $row->fresh()->applied_by);
            }
        });
    }

    public function test_empty_bulk_is_a_truthful_unchanged_outcome_without_assignment_side_effects(): void
    {
        $body = $this->payload('apply_accepted');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->postCommand('apply_accepted', $body)->assertRedirect()->assertSessionHasNoErrors();
            $result = $this->assertReceipt('apply_accepted', $body, 'unchanged', false);
            $this->assertSame('empty', $result['disposition']);
            $this->assertSame(['selected' => 0, 'applied' => 0, 'stale' => 0, 'failed' => 0], $result['counts']);
            $this->assertSame([], $result['assignments']);
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_duplicate_accepted_choices_keep_precise_preflight_counts_and_change_nothing(): void
    {
        $this->row->update(['status' => RosterSuggestion::STATUS_ACCEPTED]);
        $this->suggestion($this->shift, $this->person(), ['rank' => 2, 'status' => RosterSuggestion::STATUS_ACCEPTED]);
        $body = $this->payload('apply_accepted');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->postCommand('apply_accepted', $body)->assertRedirect()->assertSessionHasNoErrors();
            $result = $this->assertReceipt('apply_accepted', $body, 'not_applied', false);
            $this->assertSame('preflight_no_change', $result['disposition']);
            $this->assertSame(['selected' => 2, 'applied' => 0, 'stale' => 1, 'failed' => 0], $result['counts']);
            $this->assertSame([], $result['assignments']);
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function noWriteBulkBranches(): array
    {
        return ['empty accepted cohort' => [false], 'duplicate accepted cohort' => [true]];
    }

    #[DataProvider('noWriteBulkBranches')]
    public function test_bulk_branches_that_write_nothing_still_reject_a_current_revoked_action_grant_hidden_by_rr(bool $duplicate): void
    {
        if ($duplicate) {
            $this->row->update(['status' => RosterSuggestion::STATUS_ACCEPTED]);
            $this->suggestion($this->shift, $this->person(), ['rank' => 2, 'status' => RosterSuggestion::STATUS_ACCEPTED]);
        }
        $permission = Permission::where('key', 'rostering.autoSchedule')->firstOrFail();
        $body = $this->payload('apply_accepted');
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($permission, $body, $writer): void {
            DB::beginTransaction();
            $before = $this->state();
            $oldGrant = $this->driftEvidence('grant');
            $this->assertTrue($this->actor->canDo('rostering.autoSchedule'));
            $writer->transaction(fn () => $writer->table('permission_user')->where('user_id', $this->actor->id)
                ->where('permission_id', $permission->id)->update(['allowed' => false]));
            $this->assertSame($oldGrant, $this->driftEvidence('grant'));
            $this->assertNotSame($oldGrant, $this->driftEvidence('grant', $writer));
            $this->assertFalse((bool) $writer->table('permission_user')->where('user_id', $this->actor->id)
                ->where('permission_id', $permission->id)->value('allowed'));
            $this->assertTrue($this->actor->canDo('rostering.autoSchedule'));
            $queue = $this->queueState();
            $this->withSession(['roster_suggestion_result' => ['actor_id' => $this->actor->id, 'action' => 'apply_accepted']]);
            try {
                app(RosterSuggestionCommand::class)->execute($this->request('apply_accepted', $body), 'apply_accepted', $this->run);
                $this->fail('The real command must reject the independently revoked grant even when its accepted cohort needs no writes.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertNull(session('roster_suggestion_result'));
            $this->assertSame($queue, $this->queueState());
            DB::commit();
            $this->assertSame($before, $this->state($writer));
            $this->assertSame($queue, $this->queueState());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        });
    }

    public static function preflightFailures(): array
    {
        return ['real conflicting duty' => [false], 'current evaluator unavailable' => [true]];
    }

    #[DataProvider('preflightFailures')]
    public function test_preflight_refusal_is_not_reported_as_an_applied_bulk(bool $unavailable): void
    {
        $this->row->update(['status' => RosterSuggestion::STATUS_ACCEPTED]);
        if (! $unavailable) {
            $this->duty(['user_id' => $this->candidate->id, 'status' => 'scheduled']);
        }
        $body = $this->payload('apply_accepted');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body, $unavailable): void {
            if ($unavailable) {
                $this->mock(ShiftStaffEligibilityService::class, static function ($mock): void {
                    $mock->shouldReceive('evaluate')->once()->andThrow(new \RuntimeException('private evaluator failure'));
                });
            }
            $before = $this->state();
            $queue = $this->queueState();
            $this->postCommand('apply_accepted', $body)->assertRedirect()->assertSessionHasNoErrors();
            $result = $this->assertReceipt('apply_accepted', $body, 'not_applied', false);
            $this->assertSame('preflight_no_change', $result['disposition']);
            $this->assertSame(['selected' => 1, 'applied' => 0, 'stale' => $unavailable ? 0 : 1, 'failed' => $unavailable ? 1 : 0], $result['counts']);
            $this->assertSame([], $result['assignments']);
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
            $this->assertStringNotContainsString('private evaluator failure', json_encode($result, JSON_THROW_ON_ERROR));
        });
    }

    public static function nestedCommands(): array
    {
        $cases = [];
        foreach (['accept', 'dismiss', 'apply', 'apply_accepted'] as $action) {
            foreach ([true, false] as $commit) {
                $cases[$action.' outer '.($commit ? 'commit' : 'rollback')] = [$action, $commit];
            }
        }

        return $cases;
    }

    #[DataProvider('nestedCommands')]
    public function test_actual_nested_success_never_emits_a_committed_receipt_and_respects_outer_atomicity(string $action, bool $commit): void
    {
        if ($action === 'apply_accepted') {
            $this->row->update(['status' => RosterSuggestion::STATUS_ACCEPTED]);
        }
        $body = $this->payload($action);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $commit, $body): void {
            $before = $this->state();
            $queue = $this->queueState();
            DB::beginTransaction();
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->withSession(['roster_suggestion_result' => ['actor_id' => $this->actor->id, 'action' => 'accept']]);
            $this->postCommand($action, $body)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertNull(session('roster_suggestion_result'));
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($queue, $this->queueState(), 'Every command callback remains deferred until the actual outer boundary.');
            $this->assertNotSame($before['roster_suggestions'], $this->raw('roster_suggestions'));
            $commit ? DB::commit() : DB::rollBack();
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertNull(session('roster_suggestion_result'));
            if ($commit) {
                $this->assertSame(match ($action) {
                    'accept' => RosterSuggestion::STATUS_ACCEPTED, 'dismiss' => RosterSuggestion::STATUS_DISMISSED,
                    default => RosterSuggestion::STATUS_APPLIED,
                }, $this->row->fresh()->status);
                $this->assertSame(in_array($action, ['apply', 'apply_accepted'], true) ? $this->candidate->id : null, $this->shift->fresh()->user_id);
            } else {
                $this->assertSame($before, $this->state());
                $this->assertSame($queue, $this->queueState());
            }
        });
    }

    public static function rawPdoCommands(): array
    {
        return ['status-only accept' => ['accept'], 'actual accepted assignment' => ['apply_accepted']];
    }

    #[DataProvider('rawPdoCommands')]
    public function test_an_untracked_physical_pdo_transaction_cannot_certify_an_actual_saved_result(string $action): void
    {
        if ($action === 'apply_accepted') {
            $this->row->update(['status' => RosterSuggestion::STATUS_ACCEPTED]);
        }
        $body = $this->payload($action);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body, $action): void {
            $request = $this->request($action, $body);
            $actual = app(RosterSuggestionCommand::class)->execute($request, $action, $action === 'apply_accepted' ? $this->run : $this->row);
            $this->assertIsArray($actual['receipt']);
            $this->assertSame($body['request_id'], $actual['receipt']['request_id']);
            $before = $this->state();
            $queue = $this->queueState();
            $pdo = DB::connection()->getPdo();
            $pdo->beginTransaction();
            try {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertTrue($pdo->inTransaction());
                $request->session()->put('roster_suggestion_result', $actual['receipt']);
                $receipts = app(RosterSuggestionReceipt::class);
                $this->assertFalse($receipts->begin($request));
                $this->assertNull($request->session()->get('roster_suggestion_result'));
                $this->assertNull($receipts->committed(true, $actual['result']), 'Even a true logical entry flag cannot override the physical exit guard.');
            } finally {
                $pdo->rollBack();
            }
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse($pdo->inTransaction());
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function nestedExpiryBoundaries(): array
    {
        return ['outer stale commit' => [true], 'outer stale rollback' => [false]];
    }

    #[DataProvider('nestedExpiryBoundaries')]
    public function test_nested_expired_accept_keeps_legacy_rejection_and_outer_stale_marker_atomicity(bool $commit): void
    {
        $this->run->update(['expires_at' => now()->subSecond()]);
        $body = $this->payload('accept');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($commit, $body): void {
            $before = $this->state();
            $queue = $this->queueState();
            DB::beginTransaction();
            $this->postCommand('accept', $body)->assertStatus(422);
            $this->assertNull(session('roster_suggestion_result'));
            $this->assertSame(RosterSuggestion::STATUS_STALE, $this->row->fresh()->status);
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($queue, $this->queueState());
            $commit ? DB::commit() : DB::rollBack();
            if ($commit) {
                $this->assertOnlySuggestionChanged($before, ['status', 'updated_at']);
            } else {
                $this->assertSame($before, $this->state());
            }
            $this->assertNull(session('roster_suggestion_result'));
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function currentAuthorityDrift(): array
    {
        return ['actor approval revoked' => ['approval', 403], 'exact action override revoked' => ['grant', 403],
            'actor profile inactive' => ['profile', 403], 'actor primary Site changed' => ['membership', 403],
            'Site archived' => ['site', 403], 'current Client moved' => ['client', 403],
            'run Site retargeted' => ['run', 409], 'suggestion candidate retargeted' => ['candidate', 409],
            'same duty current end changed' => ['time', 409]];
    }

    #[DataProvider('currentAuthorityDrift')]
    public function test_current_command_rejects_independently_committed_authority_and_source_drift_hidden_by_rr(string $drift, int $status): void
    {
        $foreign = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $other = $this->person();
        $permission = Permission::where('key', 'rostering.autoSchedule')->firstOrFail();
        $body = $this->payload('accept');
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($drift, $status, $foreign, $other, $permission, $body, $writer): void {
            DB::beginTransaction();
            $old = $this->state();
            $this->assertTrue($this->actor->canDo('rostering.autoSchedule'));
            $oldEvidence = $this->driftEvidence($drift);
            $writer->transaction(function () use ($drift, $writer, $foreign, $other, $permission): void {
                match ($drift) {
                    'approval' => $writer->table('users')->where('id', $this->actor->id)->update(['approved_at' => null]),
                    'grant' => $writer->table('permission_user')->where('user_id', $this->actor->id)->where('permission_id', $permission->id)->update(['allowed' => false]),
                    'profile' => $writer->table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => false]),
                    'membership' => $writer->table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['primary_site_id' => $foreign->id]),
                    'site' => $writer->table('sites')->where('id', $this->site->id)->update(['archived' => true, 'archived_at' => now()->format('Y-m-d H:i:s')]),
                    'client' => $writer->table('clients')->where('id', $this->client->id)->update(['site_id' => $foreign->id]),
                    'run' => $writer->table('roster_suggestion_runs')->where('id', $this->run->id)->update(['site_id' => $foreign->id]),
                    'candidate' => $writer->table('roster_suggestions')->where('id', $this->row->id)->update(['candidate_user_id' => $other->id]),
                    'time' => $writer->table('shifts')->where('id', $this->shift->id)->update(['ends_at' => $this->shift->ends_at->copy()->addMinute()->format('Y-m-d H:i:s')]),
                };
            });
            $this->assertSame($oldEvidence, $this->driftEvidence($drift), 'Ordinary RR authority/source evidence must remain old.');
            $this->assertNotSame($oldEvidence, $this->driftEvidence($drift, $writer));
            $this->assertSame($old, $this->state());
            $this->assertTrue($this->actor->canDo('rostering.autoSchedule'), 'The request-era cached grant remains true.');
            $current = $this->state($writer);
            $queue = $this->queueState();
            $this->withSession(['roster_suggestion_result' => ['actor_id' => $this->actor->id, 'action' => 'accept', 'request_id' => 'old-request']]);
            $this->postCommand('accept', $body)->assertStatus($status);
            $this->assertNull(session('roster_suggestion_result'));
            $this->assertSame($queue, $this->queueState());
            DB::commit();
            $this->assertSame($current, $this->state($writer));
            $this->assertSame($queue, $this->queueState());
            $this->assertNotSame($oldEvidence, $this->driftEvidence($drift));
        });
    }

    private function driftEvidence(string $drift, ?Connection $connection = null): array
    {
        $connection ??= DB::connection();
        [$table, $key, $id] = match ($drift) {
            'approval' => ['users', 'id', $this->actor->id],
            'grant' => ['permission_user', 'user_id', $this->actor->id],
            'profile', 'membership' => ['hr_employee_profiles', 'user_id', $this->actor->id],
            'site' => ['sites', 'id', $this->site->id], 'client' => ['clients', 'id', $this->client->id],
            'run' => ['roster_suggestion_runs', 'id', $this->run->id], 'candidate' => ['roster_suggestions', 'id', $this->row->id],
            default => ['shifts', 'id', $this->shift->id],
        };

        return $connection->table($table)->where($key, $id)->orderBy($table === 'permission_user' ? 'permission_id' : 'id')
            ->get()->map(fn ($row) => (array) $row)->all();
    }

    public static function planningSaveFaults(): array
    {
        return ['accept save veto' => ['accept', false], 'accept saved source altered' => ['accept', true],
            'dismiss save veto' => ['dismiss', false], 'dismiss saved provenance altered' => ['dismiss', true]];
    }

    #[DataProvider('planningSaveFaults')]
    public function test_actual_suggestion_save_refusal_or_altered_readback_rolls_back_without_a_receipt(string $action, bool $alter): void
    {
        $other = $this->person();
        $body = $this->payload($action);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $alter, $other, $body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $fired = false;
            $this->withObserver(function () use ($action, $alter, $other, $body, &$fired): void {
                RosterSuggestion::saving(function ($row) use ($action, $alter, $other, &$fired): ?bool {
                    if ((int) $row->id !== (int) $this->row->id || $row->status !== ($action === 'accept' ? RosterSuggestion::STATUS_ACCEPTED : RosterSuggestion::STATUS_DISMISSED)) {
                        return null;
                    }
                    $fired = true;
                    if (! $alter) {
                        return false;
                    }
                    $action === 'accept' ? $row->candidate_user_id = $other->id : $row->dismissed_by = $other->id;

                    return null;
                });
                $this->postCommand($action, $body)->assertRedirect()->assertSessionHasErrors();
            });
            $this->assertTrue($fired, 'The actual command must reach the selected row saving observer.');
            $this->assertNull(session('roster_suggestion_result'));
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function lateBatchFaults(): array
    {
        return ['second Shift save veto' => ['shift'], 'second suggestion save veto' => ['suggestion']];
    }

    #[DataProvider('lateBatchFaults')]
    public function test_a_late_actual_batch_save_veto_rolls_back_prior_assignments_and_all_command_history(string $model): void
    {
        $second = $this->suggestion($this->duty(), $this->person(), ['status' => RosterSuggestion::STATUS_ACCEPTED]);
        $this->row->update(['status' => RosterSuggestion::STATUS_ACCEPTED]);
        $body = $this->payload('apply_accepted');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($model, $second, $body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $fired = false;
            $sawFirstAssignment = false;
            $this->withObserver(function () use ($model, $second, $body, &$fired, &$sawFirstAssignment): void {
                $class = $model === 'shift' ? Shift::class : RosterSuggestion::class;
                $class::saving(function ($row) use ($model, $second, &$fired, &$sawFirstAssignment): ?bool {
                    $id = $model === 'shift' ? $second->shift_id : $second->id;
                    $state = $model === 'shift' ? 'scheduled' : RosterSuggestion::STATUS_APPLIED;
                    if ((int) $row->id !== (int) $id || $row->status !== $state) {
                        return null;
                    }
                    $fired = true;
                    $sawFirstAssignment = Shift::query()->whereKey($this->shift->id)->where('user_id', $this->candidate->id)->where('status', 'scheduled')->exists();

                    return false;
                });
                $this->postCommand('apply_accepted', $body)->assertRedirect()->assertSessionHasErrors();
            });
            $this->assertTrue($fired);
            $this->assertTrue($sawFirstAssignment, 'The first actual assignment must be staged before the late veto.');
            $this->assertNull(session('roster_suggestion_result'));
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_a_post_commit_projection_failure_keeps_the_saved_status_without_a_false_receipt_or_http_failure(): void
    {
        $body = $this->payload('accept');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body): void {
            $receipt = new class extends RosterSuggestionReceipt
            {
                private int $checks = 0;

                protected function isPhysicalRoot(): bool
                {
                    if (++$this->checks === 1) {
                        return parent::isPhysicalRoot();
                    }
                    throw new \RuntimeException('private receipt connection metadata failure');
                }
            };
            $this->instance(RosterSuggestionReceipt::class, $receipt);
            $before = $this->state();
            $queue = $this->queueState();
            $response = $this->postCommand('accept', $body)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame(RosterSuggestion::STATUS_ACCEPTED, $this->row->fresh()->status);
            $this->assertSame($this->actor->id, (int) $this->row->fresh()->accepted_by);
            $this->assertNull(session('roster_suggestion_result'));
            $this->assertOnlySuggestionChanged($before, ['status', 'accepted_by', 'accepted_at', 'updated_at']);
            $this->assertSame($queue, $this->queueState());
            $this->assertStringNotContainsString('private receipt connection metadata failure', $response->getContent());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        });
    }

    public function test_only_the_current_requester_can_read_the_committed_correlated_receipt(): void
    {
        $other = $this->person(['rostering.autoSchedule']);
        $body = $this->payload('accept');
        $this->commitFixtures();
        $this->withProductionManager(function () use ($other, $body): void {
            $this->postCommand('accept', $body)->assertRedirect()->assertSessionHasNoErrors();
            $receipt = $this->assertReceipt('accept', $body, 'accepted', true);
            $capturedSession = session()->all();
            $before = $this->state();
            $queue = $this->queueState();
            $this->get(route('operations.rostering.suggestions.show', $this->run))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('flash.roster_suggestion_result', $receipt));
            $this->withSession($capturedSession)->actingAs($other)->get(route('operations.rostering.suggestions.show', $this->run))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('flash.roster_suggestion_result', null));
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function profilelessPlanningActions(): array
    {
        return ['accept' => ['accept'], 'dismiss' => ['dismiss']];
    }

    #[DataProvider('profilelessPlanningActions')]
    public function test_existing_manage_any_site_bypass_preserves_planning_without_an_actor_profile(string $action): void
    {
        $permission = Permission::firstOrCreate(['key' => 'shifts.manageAny'], ['description' => 'Manage', 'group' => 'workforce', 'module' => 'Operations']);
        $this->actor->permissionOverrides()->attach($permission, ['allowed' => true]);
        HrEmployeeProfile::where('user_id', $this->actor->id)->delete();
        $this->actor = $this->actor->fresh();
        $this->actingAs($this->actor);
        $body = $this->payload($action);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->postCommand($action, $body)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertReceipt($action, $body, $action === 'accept' ? 'accepted' : 'dismissed', true);
            $this->assertOnlySuggestionChanged($before, $action === 'accept' ? ['status', 'accepted_by', 'accepted_at', 'updated_at'] : ['status', 'dismissed_by', 'dismissed_at', 'updated_at']);
            $this->assertSame($queue, $this->queueState());
            $this->assertFalse(HrEmployeeProfile::where('user_id', $this->actor->id)->exists());
        });
    }

    private function request(string $action, array $body): Request
    {
        $request = Request::create($this->commandUrl($action), 'POST', $body);
        $request->headers->set('X-Roster-Suggestion-Result', 'committed-v1');
        $request->setUserResolver(fn () => $this->actor);
        $request->setLaravelSession(app('session')->driver());

        return $request;
    }

    private function assignment(RosterSuggestion $row): array
    {
        $row = $row->fresh();
        $shift = $row->shift()->firstOrFail();

        return ['suggestion_id' => (int) $row->id, 'shift_id' => (int) $shift->id, 'user_id' => (int) $shift->user_id,
            'status' => $shift->status, 'starts_at' => $this->instant($shift->starts_at), 'ends_at' => $this->instant($shift->ends_at)];
    }

    private function assertOnlySuggestionChanged(array $before, array $allowedFields): void
    {
        foreach ($before as $table => $rows) {
            if ($table !== 'roster_suggestions') {
                $this->assertSame($rows, $this->raw($table), $table);

                continue;
            }
            $after = $this->raw($table);
            $this->assertCount(count($rows), $after);
            foreach ($rows as $old) {
                $current = collect($after)->firstWhere('id', $old['id']);
                $this->assertNotNull($current);
                if ((int) $old['id'] === (int) $this->row->id) {
                    $this->assertSame(array_diff_key($old, array_flip($allowedFields)), array_diff_key($current, array_flip($allowedFields)));
                } else {
                    $this->assertSame($old, $current);
                }
            }
        }
    }

    private function person(array $keys = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'suggestion-command-'.Str::uuid(), 'label' => 'Suggestion fixture', 'level' => 10, 'type' => 'custom']);
        $user->roles()->attach($role);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'workforce', 'module' => 'Operations']);
            $user->permissionOverrides()->attach($permission, ['allowed' => true]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);
        StaffAvailability::create(['user_id' => $user->id, 'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'ends_next_day' => false]);

        return $user->fresh();
    }

    private function duty(array $values = []): Shift
    {
        return Shift::factory()->create(['site_id' => $this->site->id, 'client_id' => $this->client->id, 'service_context_id' => null,
            'user_id' => null, 'status' => 'draft', 'created_by' => $this->actor->id,
            'starts_at' => Carbon::parse('2026-10-12 08:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-10-12 12:00', 'Pacific/Auckland')->utc(),
            'coverage_roles' => [], 'required_licence_class' => null, 'required_licence_endorsements' => [],
            'expected_break_minutes' => null, 'is_sleepover' => false, 'is_on_call' => false, 'is_lone_worker' => false, ...$values]);
    }

    private function suggestion(Shift $shift, User $candidate, array $values = []): RosterSuggestion
    {
        return RosterSuggestion::factory()->create(['roster_suggestion_run_id' => $this->run->id, 'shift_id' => $shift->id,
            'candidate_user_id' => $candidate->id, 'rank' => 1, 'status' => RosterSuggestion::STATUS_SUGGESTED,
            'reasons' => ['private-reason-'.Str::uuid()], 'eligibility_snapshot' => ['private_marker' => 'private-snapshot-'.Str::uuid()], ...$values]);
    }

    private function instant(?Carbon $value): ?string
    {
        return $value?->copy()->utc()->format('Y-m-d\\TH:i:s.000\\Z');
    }

    private function source(?RosterSuggestion $row = null): array
    {
        $row ??= $this->row;
        $row = $row->fresh();
        $run = $row->run()->firstOrFail();
        $shift = $row->shift()->firstOrFail();
        $projection = ['run_id' => (int) $run->id, 'site_id' => (int) $run->site_id,
            'suggestion_id' => (int) $row->id, 'shift_id' => (int) $row->shift_id, 'candidate_user_id' => (int) $row->candidate_user_id,
            'status' => $row->status, 'shift_client_id' => $shift->client_id === null ? null : (int) $shift->client_id,
            'shift_site_id' => $shift->site_id === null ? null : (int) $shift->site_id,
            'service_context_id' => $shift->service_context_id === null ? null : (int) $shift->service_context_id,
            'respite_booking_id' => $shift->respite_booking_id === null ? null : (int) $shift->respite_booking_id,
            'shift_type' => $shift->shift_type, 'assigned_user_id' => $shift->user_id === null ? null : (int) $shift->user_id,
            'shift_status' => $shift->status, 'starts_at' => $this->instant($shift->starts_at), 'ends_at' => $this->instant($shift->ends_at)];

        return ['run_id' => (int) $run->id, 'site_id' => (int) $run->site_id, 'suggestion_id' => (int) $row->id,
            'shift_id' => (int) $row->shift_id, 'candidate_user_id' => (int) $row->candidate_user_id, 'status' => $row->status,
            'source_revision' => $this->hash($projection)];
    }

    private function hash(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function payload(string $action, ?array $source = null): array
    {
        return ['request_id' => (string) Str::uuid(), 'expected_source' => $source ?? ($action === 'apply_accepted'
            ? ['run_id' => (int) $this->run->id, 'site_id' => (int) $this->run->site_id] : $this->source())];
    }

    private function commandUrl(string $action, ?RosterSuggestion $row = null): string
    {
        return route('operations.rostering.suggestions.'.$action, $action === 'apply_accepted' ? $this->run : ($row ?? $this->row));
    }

    private function postCommand(string $action, array $body, ?RosterSuggestion $row = null)
    {
        return $this->withHeader('X-Roster-Suggestion-Result', 'committed-v1')->from(route('operations.rostering.suggestions.show', $this->run))
            ->post($this->commandUrl($action, $row), $body);
    }

    private function assertReceipt(string $action, array $body, string $outcome, bool $changed): array
    {
        $result = session('roster_suggestion_result');
        $this->assertIsArray($result);
        $this->assertSame($action, $result['action']);
        $this->assertSame($this->actor->id, $result['actor_id']);
        $this->assertSame($body['request_id'], $result['request_id']);
        $this->assertSame($action === 'apply_accepted' ? 'accepted_run' : 'single', $result['scope']);
        $this->assertSame($this->run->id, $result['run_id']);
        $this->assertSame($this->site->id, $result['site_id']);
        $this->assertSame($action === 'apply_accepted' ? null : $this->row->id, $result['suggestion_id']);
        $this->assertSame($body['expected_source'], $result['expected_source']);
        $this->assertSame($this->hash(['action' => $action, 'run_id' => $this->run->id,
            'suggestion_id' => $action === 'apply_accepted' ? null : $this->row->id, 'expected_source' => $body['expected_source']]), $result['values_hash']);
        $this->assertSame($outcome, $result['outcome']);
        $this->assertSame($changed, $result['changed']);
        $this->assertSame(['selected', 'applied', 'stale', 'failed'], array_keys($result['counts']));
        foreach ($result['counts'] as $count) {
            $this->assertIsInt($count);
            $this->assertGreaterThanOrEqual(0, $count);
        }
        if ($action === 'apply_accepted') {
            $this->assertNull($result['suggestion']);
        } else {
            $saved = $this->row->fresh();
            $this->assertSame(['id' => (int) $saved->id, 'status' => $saved->status,
                'accepted_by' => $saved->accepted_by === null ? null : (int) $saved->accepted_by,
                'accepted_at' => $this->instant($saved->accepted_at),
                'dismissed_by' => $saved->dismissed_by === null ? null : (int) $saved->dismissed_by,
                'dismissed_at' => $this->instant($saved->dismissed_at),
                'applied_by' => $saved->applied_by === null ? null : (int) $saved->applied_by,
                'applied_at' => $this->instant($saved->applied_at)], $result['suggestion']);
            if (in_array($action, ['accept', 'dismiss'], true)) {
                $this->assertSame([], $result['assignments']);
                $this->assertSame($outcome === 'expired_marked_stale' ? 'expired' : 'single', $result['disposition']);
                $this->assertSame(['selected' => 1, 'applied' => 0, 'stale' => $outcome === 'expired_marked_stale' ? 1 : 0, 'failed' => 0], $result['counts']);
            }
        }
        $wire = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-reason-', $wire);
        $this->assertStringNotContainsString('private-snapshot-', $wire);

        return $result;
    }

    private function raw(string $table, ?Connection $connection = null): array
    {
        return ($connection ?? DB::connection())->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function state(?Connection $connection = null): array
    {
        $tables = ['shifts', 'roster_suggestion_runs', 'roster_suggestions', 'shift_eligibility_overrides',
            'timeline_events', 'audit_logs', 'coverage_reservations', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations'];

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
        $name = 'suggestion_command_writer';
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

    private function withObserver(callable $proof): void
    {
        $events = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $events);
        try {
            $proof();
        } finally {
            Model::setEventDispatcher($events);
        }
    }
}
