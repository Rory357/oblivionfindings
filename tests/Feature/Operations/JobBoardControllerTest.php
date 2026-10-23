<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CoverageReservation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\ShiftSignalOutbox;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\Eligibility\AssignmentEligibilityDecision;
use App\Services\Eligibility\EligibilityResult;
use App\Services\ShiftReplacementService;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

class JobBoardControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $worker;

    private User $currentStaff;

    private Client $client;

    private ServiceContext $serviceContext;

    private Site $site;

    private Site $foreignSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareLegacyPhysicalRootFixture();

        config(['app.worker_timezone' => 'Pacific/Auckland']);

        $this->site = Site::factory()->create(['name' => 'Harbour House']);
        $this->foreignSite = Site::factory()->create(['name' => 'Forest House']);

        $this->manager = $this->userWithPermissions(['job_board.viewAny', 'job_board.create', 'job_board.approve', 'shifts.manageAny'], [
            'role' => 'admin',
        ]);
        $this->attachRole($this->manager, 'admin');

        $this->worker = $this->userWithPermissions(['shifts.viewAssigned'], [
            'role' => 'support_worker',
        ]);

        $this->currentStaff = User::factory()->create([
            'role' => 'support_worker',
        ]);
        $this->assignToSite($this->currentStaff, $this->site);

        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->serviceContext = ServiceContext::factory()->create();
        $this->activateLegacyPhysicalRootFixture();
    }

    public function test_posting_picker_is_scoped_and_redacts_searchable_private_fields_for_a_creator(): void
    {
        Queue::fake();
        $creator = $this->userWithPermissions(['job_board.create'], ['role' => 'coordinator']);
        $this->client->update(['first_name' => 'PrivateGiven', 'last_name' => 'PrivateFamily', 'suburb' => 'Public posting suburb']);
        $shift = $this->shiftForSite();
        $shift->update(['user_id' => null, 'location' => 'Private precise address']);
        $foreign = $this->shiftForSite($this->foreignSite);
        $before = $shift->fresh()->getRawOriginal();
        $queue = Queue::pushedJobs();

        $response = $this->actingAs($creator)->getJson(route('operations.job_board.postable_shifts', ['q' => 'Public posting suburb']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shift->id)
            ->assertJsonPath('data.0.can_post', true)->assertJsonPath('workerTimezone', 'Pacific/Auckland');
        $this->assertStringNotContainsString('Private', $response->getContent());
        $this->getJson(route('operations.job_board.postable_shifts', ['q' => 'PrivateGiven']))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('operations.job_board.postable_shifts', ['q' => $foreign->id]))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->manager)->getJson(route('operations.job_board.postable_shifts', ['q' => 'PrivateGiven', 'per_page' => 1]))
            ->assertOk()->assertJsonPath('data.0.label', 'Support shift for PrivateGiven PrivateFamily');
        $this->assertSame($before, $shift->fresh()->getRawOriginal());
        $this->assertSame($queue, Queue::pushedJobs());
        $this->assertDatabaseMissing('shift_open_positions', ['shift_id' => $shift->id]);
    }

    public function test_posting_picker_hidden_client_links_cannot_affect_search_or_pagination(): void
    {
        Queue::fake();
        $reader = $this->userWithPermissions(['job_board.create', 'shifts.viewAny'], ['role' => 'coordinator']);
        $this->client->update(['first_name' => 'LawfulPickerMatch', 'last_name' => 'Local']);
        $lawful = $this->shiftForSite();
        $lawful->update(['user_id' => null]);
        $hidden = [];
        for ($index = 0; $index < 2; $index++) {
            $shift = $this->shiftForSite($this->foreignSite);
            $shift->client->update(['first_name' => 'ForeignPickerSentinel', 'last_name' => 'Private']);
            // Normal model writes retain safety/observers while arranging a malformed source.
            $shift->update(['site_id' => $this->site->id, 'user_id' => null]);
            $hidden[] = $shift;
        }
        $before = Shift::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $clientsBefore = Client::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $positionsBefore = ShiftOpenPosition::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $queue = Queue::pushedJobs();

        $response = $this->actingAs($reader)->getJson(route('operations.job_board.postable_shifts', [
            'q' => 'ForeignPickerSentinel', 'per_page' => 1,
        ]))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('has_more', false);
        $this->assertStringNotContainsString('ForeignPickerSentinel', $response->getContent());
        $this->getJson(route('operations.job_board.postable_shifts', [
            'q' => 'LawfulPickerMatch', 'per_page' => 1,
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $lawful->id)
            ->assertJsonPath('has_more', false);
        $this->getJson(route('operations.job_board.postable_shifts', [
            'q' => 'Picker', 'per_page' => 1,
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $lawful->id)
            ->assertJsonPath('has_more', false);
        $this->getJson(route('operations.job_board.postable_shifts', [
            'q' => 'Picker', 'per_page' => 1, 'page' => 2,
        ]))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('has_more', false);
        foreach ($hidden as $shift) {
            $this->getJson(route('operations.job_board.postable_shifts', [
                'q' => $shift->id, 'per_page' => 1,
            ]))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('has_more', false);
        }
        $this->assertSame($before, Shift::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($clientsBefore, Client::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($positionsBefore, ShiftOpenPosition::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($queue, Queue::pushedJobs());
    }

    public function test_posting_requires_matching_route_and_body_and_a_valid_optional_request_id(): void
    {
        Queue::fake();
        $shift = $this->shiftForSite();
        $queue = Queue::pushedJobs();
        $this->actingAs($this->manager)->postJson(route('operations.job_board.create', $shift), ['shift_id' => $shift->id + 1])
            ->assertUnprocessable()->assertJsonValidationErrors('shift_id');
        $this->postJson(route('operations.job_board.create', $shift), ['shift_id' => $shift->id, 'request_id' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('request_id');
        $this->postJson(url('/operations/shifts/not-a-shift/open-position'), ['shift_id' => $shift->id])->assertNotFound();
        $this->postJson(route('operations.job_board.create', $shift), ['shift_id' => $shift->id, 'request_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('source_revision');
        $revision = $this->getJson(route('operations.job_board.postable_shifts', ['q' => $shift->id]))
            ->assertOk()->json('data.0.source_revision');
        $this->assertMatchesRegularExpression('/\\A[0-9a-f]{64}\\z/', $revision);
        $shift->update(['ends_at' => $shift->ends_at->copy()->addHour()]);
        $before = $shift->fresh()->getRawOriginal();
        $queue = Queue::pushedJobs();
        $this->postJson(route('operations.job_board.create', $shift), [
            'shift_id' => $shift->id, 'request_id' => (string) Str::uuid(), 'source_revision' => $revision,
        ])->assertConflict()->assertSessionMissing('success');
        $this->assertSame($before, $shift->fresh()->getRawOriginal());
        $this->assertDatabaseMissing('shift_open_positions', ['shift_id' => $shift->id]);
        $this->assertSame($queue, Queue::pushedJobs());
    }

    public function test_posting_rechecks_current_grant_instead_of_trusting_the_request_actor_cache(): void
    {
        Queue::fake();
        $creator = $this->userWithPermissions(['job_board.create'], ['role' => 'coordinator']);
        $shift = $this->shiftForSite();
        $shift->update(['user_id' => null]);
        $creator->load('roles.permissions', 'permissionOverrides');
        $this->assertTrue($creator->canDo('job_board.create'));
        $permission = Permission::where('key', 'job_board.create')->sole();
        $creator->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->assertTrue($creator->canDo('job_board.create'), 'The request actor still has its old loaded permission.');
        $before = $shift->fresh()->getRawOriginal();
        $queue = Queue::pushedJobs();
        $this->actingAs($creator)->postJson(route('operations.job_board.create', $shift), ['shift_id' => $shift->id])->assertForbidden();
        $missingId = (int) Shift::max('id') + 1;
        $this->postJson(route('operations.job_board.create', ['shift' => $missingId]), ['shift_id' => $missingId])->assertForbidden();
        $this->getJson(route('operations.job_board.postable_shifts'))->assertForbidden();
        $this->assertSame($before, $shift->fresh()->getRawOriginal());
        $this->assertDatabaseMissing('shift_open_positions', ['shift_id' => $shift->id]);
        $this->assertSame($queue, Queue::pushedJobs());
    }

    public static function unavailablePostingStates(): array
    {
        return ['completed' => ['completed'], 'cancelled' => ['cancelled'], 'assigned without replacement' => ['scheduled']];
    }

    #[DataProvider('unavailablePostingStates')]
    public function test_posting_refuses_terminal_or_assigned_duty_and_picker_explains_why(string $status): void
    {
        Queue::fake();
        $shift = $this->shiftForSite(status: $status === 'completed' ? 'scheduled' : $status);
        if ($status === 'completed') {
            $shift->update(['actual_starts_at' => $shift->starts_at, 'status' => 'completed']);
        }
        $before = $shift->fresh()->getRawOriginal();
        $queue = Queue::pushedJobs();
        $this->actingAs($this->manager)->getJson(route('operations.job_board.postable_shifts', ['q' => $shift->id]))->assertOk()
            ->assertJsonPath('data.0.can_post', false)
            ->assertJsonPath('data.0.reason', $status === 'scheduled' ? 'request_cover_required' : 'terminal_shift');
        $this->postJson(route('operations.job_board.create', $shift), ['shift_id' => $shift->id])
            ->assertUnprocessable()->assertJsonValidationErrors('shift_id');
        $this->assertSame($before, $shift->fresh()->getRawOriginal());
        $this->assertDatabaseMissing('shift_open_positions', ['shift_id' => $shift->id]);
        $this->assertSame($queue, Queue::pushedJobs());
    }

    public static function expiredDuplicateStatuses(): array
    {
        return ['open' => ['open'], 'claimed' => ['claimed']];
    }

    #[DataProvider('expiredDuplicateStatuses')]
    public function test_posting_picker_exposes_expired_active_duplicate_without_reposting(string $status): void
    {
        Queue::fake();
        $shift = $this->shiftForSite();
        $position = $this->positionForShift($shift, ['status' => $status, 'expires_at' => now()->subMinute(),
            'claimed_by' => $status === 'claimed' ? $this->worker->id : null]);
        $before = $position->fresh()->getRawOriginal();
        $queue = Queue::pushedJobs();
        $this->actingAs($this->manager)->getJson(route('operations.job_board.postable_shifts', ['q' => $shift->id]))->assertOk()
            ->assertJsonPath('data.0.active_position.id', $position->id)->assertJsonPath('data.0.active_position.expired', true)
            ->assertJsonPath('data.0.reason', 'duplicate');
        $this->postJson(route('operations.job_board.create', $shift), ['shift_id' => $shift->id])->assertUnprocessable()
            ->assertJsonPath('errors.shift_id.0', 'This shift already has an active open position.');
        $this->assertSame($before, $position->fresh()->getRawOriginal());
        $this->assertSame(1, ShiftOpenPosition::where('shift_id', $shift->id)->count());
        $this->assertSame($queue, Queue::pushedJobs());
    }

    public static function supportedPostingSources(): array
    {
        return ['unassigned past remains supported' => [false], 'assigned active replacement remains supported' => [true]];
    }

    #[DataProvider('supportedPostingSources')]
    public function test_posting_persists_exact_fields_but_nested_request_does_not_claim_commit(bool $assigned): void
    {
        Queue::fake();
        $shift = $this->shiftForSite(startsAt: now()->subDays(2));
        $replacement = null;
        if ($assigned) {
            $existing = $this->positionForShift($shift, ['status' => 'cancelled']);
            $replacement = $existing->replacementRequest;
            $replacement->update(['status' => ShiftReplacementService::REQUESTED, 'cancelled_by' => null, 'cancelled_at' => null]);
        } else {
            $shift->update(['user_id' => null]);
        }
        $shift->update(['coverage_roles' => ['driver']]);
        $before = $shift->fresh()->getRawOriginal();
        $replacementBefore = $replacement?->fresh()->getRawOriginal();
        $queue = Queue::pushedJobs();
        $expires = now()->addHour()->startOfSecond();
        $revision = $this->actingAs($this->manager)->getJson(route('operations.job_board.postable_shifts', ['q' => $shift->id]))
            ->assertOk()->json('data.0.source_revision');
        $this->postJson(route('operations.job_board.create', $shift), ['shift_id' => $shift->id,
            'request_id' => (string) Str::uuid(), 'source_revision' => $revision, 'required_skills' => ['Hoist', 'Hoist'],
            'notes' => 'Exact posting intent', 'expires_at' => $expires->toISOString()])
            ->assertStatus(202)->assertJsonPath('result', null)->assertSessionMissing('success');
        $row = ShiftOpenPosition::where('shift_id', $shift->id)->where('status', 'open')->sole();
        $this->assertSame($replacement?->id, $row->replacement_request_id);
        $this->assertSame(['Hoist', 'Hoist'], $row->required_skills);
        $this->assertSame(['driver'], $row->coverage_roles);
        $this->assertSame('Exact posting intent', $row->notes);
        $this->assertTrue($row->expires_at->equalTo($expires));
        $this->assertSame($before, $shift->fresh()->getRawOriginal());
        $this->assertSame($replacementBefore, $replacement?->fresh()->getRawOriginal());
        $this->getJson(route('operations.job_board.postable_shifts', ['q' => $shift->id]))->assertOk()
            ->assertJsonPath('data.0.active_position.id', $row->id)->assertJsonPath('data.0.reason', 'duplicate');
        $this->assertSame($queue, Queue::pushedJobs());
    }

    public function test_posting_save_veto_and_changed_readback_are_not_reported_as_success(): void
    {
        Queue::fake();
        $shift = $this->shiftForSite();
        $shift->update(['user_id' => null]);
        $before = $shift->fresh()->getRawOriginal();
        $queue = Queue::pushedJobs();
        $original = Model::getEventDispatcher();
        foreach ([true, false] as $veto) {
            $events = clone $original;
            $events->listen('eloquent.saving: '.ShiftOpenPosition::class, function (ShiftOpenPosition $position) use ($veto) {
                if ($veto) {
                    return false;
                }
                $position->notes = 'Observer changed the request';
            });
            Model::setEventDispatcher($events);
            try {
                $this->actingAs($this->manager)->postJson(route('operations.job_board.create', $shift), ['shift_id' => $shift->id, 'notes' => 'Original intent'])
                    ->assertUnprocessable()->assertJsonValidationErrors('shift_id')->assertSessionMissing('success');
            } finally {
                Model::setEventDispatcher($original);
            }
            $this->assertSame($before, $shift->fresh()->getRawOriginal());
            $this->assertDatabaseMissing('shift_open_positions', ['shift_id' => $shift->id]);
            $this->assertSame($queue, Queue::pushedJobs());
        }
    }

    public function test_posting_propagates_a_real_storage_error_instead_of_calling_it_a_duplicate(): void
    {
        Queue::fake();
        $shift = $this->shiftForSite();
        $shift->update(['user_id' => null]);
        $failure = new QueryException('mysql', 'INSERT INTO shift_open_positions', [], new \PDOException('Storage unavailable', 1205));
        $original = Model::getEventDispatcher();
        $events = clone $original;
        $events->listen('eloquent.saving: '.ShiftOpenPosition::class, fn () => throw $failure);
        $queue = Queue::pushedJobs();
        Model::setEventDispatcher($events);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->manager)->postJson(route('operations.job_board.create', $shift), ['shift_id' => $shift->id]);
            $this->fail('The storage error must propagate.');
        } catch (QueryException $actual) {
            $this->assertSame($failure, $actual);
        } finally {
            Model::setEventDispatcher($original);
        }
        $this->assertDatabaseMissing('shift_open_positions', ['shift_id' => $shift->id]);
        $this->assertSame($queue, Queue::pushedJobs());
    }

    #[DataProvider('claimCapabilityRoles')]
    public function test_viewer_claim_capability_matches_existing_action_authority_without_hiding_eligible_cards(array $keys, bool $allowed): void
    {
        Queue::fake();
        $viewer = $this->userWithPermissions($keys, ['role' => 'coordinator']);
        $position = $this->positionForShift($this->shiftForSite());
        $this->allowEligibility();
        $state = static function (): array {
            return collect(['shifts', 'shift_open_positions', 'shift_replacement_requests', 'coverage_reservations',
                'timeline_events', 'audit_logs', 'notifications', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations',
                'shift_signals', 'shift_signal_outbox'])->mapWithKeys(static fn (string $table) => [
                    $table => DB::table($table)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all(),
                ])->all();
        };
        $before = $state();
        $queue = collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
        $this->assertSame($allowed, $viewer->canDo('job_board.claim') || $viewer->canDo('shifts.viewAssigned') || $viewer->canDo('shifts.manageAny'));

        $this->actingAs($viewer)->get(route('operations.job_board.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/job-board/Index')->where('viewer.can_claim', $allowed)
            ->where('jobs.data.0.id', $position->id)->where('jobs.data.0.viewer_eligibility.is_eligible', true));
        if (! $allowed) {
            $this->postJson(route('operations.job_board.claim', $position))->assertForbidden()->assertSessionMissing('success');
        }
        $this->assertSame($before, $state());
        $this->assertSame($queue, collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all());
    }

    public static function claimCapabilityRoles(): array
    {
        return ['Job Board read only' => [['job_board.viewAny'], false],
            'Shift read only' => [['shifts.viewAny'], false],
            'exact Job Board claim' => [['job_board.claim'], true],
            'assigned Shift reader' => [['shifts.viewAssigned'], true],
            'existing manager claim with board read' => [['job_board.viewAny', 'shifts.manageAny'], true],
            'board read plus exact claim' => [['job_board.viewAny', 'job_board.claim'], true]];
    }

    public function test_index_returns_only_positions_at_an_accessible_site(): void
    {
        $visibleShift = $this->shiftForSite();
        $visible = $this->positionForShift($visibleShift);
        $foreign = $this->positionForShift($this->shiftForSite($this->foreignSite));
        $corrupt = ShiftOpenPosition::query()->create([
            'shift_id' => $visibleShift->id,
            'replacement_request_id' => $foreign->replacement_request_id,
            'status' => 'open',
            'required_skills' => [],
            'coverage_roles' => [],
        ]);

        $this->allowEligibility();

        $this->actingAs($this->manager)
            ->get(route('operations.job_board.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/job-board/Index')
                ->has('jobs.data', 1)
                ->where('jobs.data.0.id', $visible->id));

        $this->assertSame(1, (int) $visible->getRawOriginal('organization_id'));
        $this->assertArrayNotHasKey('organization_id', $visible->toArray());
        $this->assertSame(1, (int) $visible->replacementRequest->getRawOriginal('organization_id'));
        $this->assertArrayNotHasKey('organization_id', $visible->replacementRequest->toArray());
        $this->assertDatabaseHas('shift_open_positions', ['id' => $corrupt->id]);

        $this->actingAs($this->worker)
            ->post(route('operations.job_board.claim', $corrupt))
            ->assertNotFound();
    }

    public function test_index_hides_expired_open_positions_but_keeps_claimed_and_filled(): void
    {
        $freshOpen = $this->positionForShift($this->shiftForSite(), [
            'status' => 'open',
            'expires_at' => now()->addHour(),
        ]);
        $expiredOpen = $this->positionForShift($this->shiftForSite(), [
            'status' => 'open',
            'expires_at' => now()->subMinute(),
        ]);
        $expiredClaimed = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subHour(),
            'expires_at' => now()->subMinute(),
        ]);
        $expiredFilled = $this->positionForShift($this->shiftForSite(), [
            'status' => 'filled',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subHours(2),
            'approved_at' => now()->subHour(),
            'expires_at' => now()->subMinute(),
        ]);

        $this->allowEligibility();

        // The default "for-you" scope intentionally lists only open positions, so the
        // drop-expired-open-but-keep-claimed/filled behaviour is exercised via the
        // replacements scope, where open, claimed and filled positions coexist.
        $this->actingAs($this->manager)
            ->get(route('operations.job_board.index', ['scope' => 'replacements']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data', function ($jobs) use ($freshOpen, $expiredOpen, $expiredClaimed, $expiredFilled) {
                    $ids = collect($jobs)->pluck('id');

                    return $ids->contains($freshOpen->id)
                        && $ids->contains($expiredClaimed->id)
                        && $ids->contains($expiredFilled->id)
                        && ! $ids->contains($expiredOpen->id);
                }));
    }

    public function test_index_default_scope_lists_only_fresh_open_positions(): void
    {
        // The default "for-you"/"all" board is a claimable-work feed: it shows only
        // open, unexpired positions. Claimed and filled positions live under the
        // "mine"/"approvals" scopes and must not leak onto the default board.
        $freshOpen = $this->positionForShift($this->shiftForSite(), [
            'status' => 'open',
            'expires_at' => now()->addHour(),
        ]);
        $expiredOpen = $this->positionForShift($this->shiftForSite(), [
            'status' => 'open',
            'expires_at' => now()->subMinute(),
        ]);
        $claimed = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subHour(),
        ]);
        $filled = $this->positionForShift($this->shiftForSite(), [
            'status' => 'filled',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subHours(2),
            'approved_at' => now()->subHour(),
        ]);

        $this->allowEligibility();

        $this->actingAs($this->manager)
            ->get(route('operations.job_board.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data', function ($jobs) use ($freshOpen, $expiredOpen, $claimed, $filled) {
                    $ids = collect($jobs)->pluck('id');

                    return $ids->contains($freshOpen->id)
                        && ! $ids->contains($expiredOpen->id)
                        && ! $ids->contains($claimed->id)
                        && ! $ids->contains($filled->id);
                }));
    }

    public function test_index_orders_by_shift_start_time_ascending(): void
    {
        $later = $this->positionForShift($this->shiftForSite(startsAt: now()->addDays(3)));
        $earlier = $this->positionForShift($this->shiftForSite(startsAt: now()->addDay()));

        $this->allowEligibility();

        $this->actingAs($this->manager)
            ->get(route('operations.job_board.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.id', $earlier->id)
                ->where('jobs.data.1.id', $later->id));
    }

    public function test_index_includes_per_viewer_eligibility_for_each_open_card(): void
    {
        $position = $this->positionForShift($this->shiftForSite());

        $this->blockEligibility('Required qualification is missing.');

        $this->actingAs($this->worker)
            ->get(route('operations.job_board.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.id', $position->id)
                ->where('jobs.data.0.viewer_eligibility.is_eligible', false)
                ->where('jobs.data.0.viewer_eligibility.blocked_reasons.0', 'Required qualification is missing.'));
    }

    public function test_index_scope_mine_returns_only_recent_claims_for_the_actor(): void
    {
        $mineClaimed = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subDay(),
        ]);
        $mineFilled = $this->positionForShift($this->shiftForSite(), [
            'status' => 'filled',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subDays(2),
            'approved_at' => now()->subDays(2),
        ]);
        $oldFilled = $this->positionForShift($this->shiftForSite(), [
            'status' => 'filled',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subDays(20),
            'approved_at' => now()->subDays(20),
        ]);
        $someoneElsesClaim = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->currentStaff->id,
            'claimed_at' => now()->subDay(),
        ]);

        $this->allowEligibility();

        $this->actingAs($this->worker)
            ->get(route('operations.job_board.index', ['scope' => 'mine']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data', function ($jobs) use ($mineClaimed, $mineFilled, $oldFilled, $someoneElsesClaim) {
                    $ids = collect($jobs)->pluck('id');

                    return $ids->contains($mineClaimed->id)
                        && $ids->contains($mineFilled->id)
                        && ! $ids->contains($oldFilled->id)
                        && ! $ids->contains($someoneElsesClaim->id);
                }));
    }

    public function test_index_filters_by_date_range_and_required_skill(): void
    {
        $matching = $this->positionForShift($this->shiftForSite(startsAt: now()->addDays(3)), [
            'required_skills' => ['NZSL'],
        ]);
        $wrongSkill = $this->positionForShift($this->shiftForSite(startsAt: now()->addDays(4)), [
            'required_skills' => ['Medication'],
        ]);
        $tooLate = $this->positionForShift($this->shiftForSite(startsAt: now()->addDays(10)), [
            'required_skills' => ['NZSL'],
        ]);

        $this->allowEligibility();

        $this->actingAs($this->manager)
            ->get(route('operations.job_board.index', [
                'date_range' => 'next_7_days',
                'skill' => 'NZSL',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.week', null)
                ->where('available_skills.0', 'Medication')
                ->where('available_skills.1', 'NZSL')
                ->where('jobs.data', function ($jobs) use ($matching, $wrongSkill, $tooLate) {
                    $ids = collect($jobs)->pluck('id');

                    return $ids->contains($matching->id)
                        && ! $ids->contains($wrongSkill->id)
                        && ! $ids->contains($tooLate->id);
                }));
    }

    public function test_worker_view_redacts_sensitive_client_and_replacement_details_until_approval(): void
    {
        $this->client->update([
            'first_name' => 'Aroha',
            'last_name' => 'Brown',
            'suburb' => 'Kingsland',
        ]);
        $position = $this->positionForShift($this->shiftForSite());

        $this->allowEligibility();

        $this->actingAs($this->worker)
            ->get(route('operations.job_board.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.id', $position->id)
                ->where('jobs.data.0.privacy.can_view_sensitive_details', false)
                ->where('jobs.data.0.client.display_name', 'A. B.')
                ->where('jobs.data.0.client.suburb', 'Kingsland')
                ->where('jobs.data.0.replacement.reason', null)
                ->where('jobs.data.0.replacement.current_staff', null));

        $this->actingAs($this->manager)
            ->get(route('operations.job_board.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.privacy.can_view_sensitive_details', true)
                ->where('jobs.data.0.client.display_name', 'Aroha Brown')
                ->where('jobs.data.0.replacement.reason', 'Needs cover for readiness test')
                ->where('jobs.data.0.replacement.current_staff.name', $this->currentStaff->name));
    }

    public function test_create_position_refuses_a_shift_from_another_site(): void
    {
        $foreignShift = $this->shiftForSite($this->foreignSite);

        $this->actingAs($this->manager)
            ->post(route('operations.job_board.create', $foreignShift), [
                'shift_id' => $foreignShift->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('shift_open_positions', [
            'shift_id' => $foreignShift->id,
        ]);
    }

    public function test_create_position_rejects_when_active_position_already_exists(): void
    {
        $shift = $this->shiftForSite();
        $this->positionForShift($shift);

        $this->actingAs($this->manager)
            ->from(route('operations.job_board.index'))
            ->post(route('operations.job_board.create', $shift), [
                'shift_id' => $shift->id,
            ])
            ->assertRedirect(route('operations.job_board.index'))
            ->assertSessionHasErrors('shift_id');
    }

    public function test_claim_succeeds_for_an_eligible_worker_and_notifies_staff_and_managers(): void
    {
        Notification::fake();
        $foreignManager = $this->siteWorker($this->foreignSite);
        $foreignManager->update(['role' => 'admin']);
        $this->attachRole($foreignManager, 'admin');
        $position = $this->positionForShift($this->shiftForSite());

        $this->allowEligibility();

        $this->actingAs($this->worker)
            ->from(route('operations.job_board.index'))
            ->post(route('operations.job_board.claim', $position))
            ->assertRedirect(route('operations.job_board.index'))
            ->assertSessionHas('success');

        $position->refresh();

        $this->assertSame('claimed', $position->status);
        $this->assertSame($this->worker->id, $position->claimed_by);
        $this->assertDatabaseHas('shift_replacement_requests', [
            'id' => $position->replacement_request_id,
            'status' => 'claimed',
            'replacement_user_id' => $this->worker->id,
        ]);
        $this->assertDatabaseHas('timeline_events', [
            'source_type' => ShiftReplacementRequest::class,
            'source_id' => $position->replacement_request_id,
            'type' => 'shift_replacement_claimed',
        ]);

        Notification::assertSentTo($this->currentStaff, AppEventNotification::class, function (AppEventNotification $notification) {
            return $notification->payload['title'] === 'Shift replacement claim submitted';
        });
        Notification::assertSentTo($this->manager, AppEventNotification::class);
        Notification::assertNotSentTo($foreignManager, AppEventNotification::class);
    }

    public function test_worker_cannot_claim_a_position_from_another_site(): void
    {
        $foreignPosition = $this->positionForShift($this->shiftForSite($this->foreignSite));

        $this->allowEligibility();

        $this->actingAs($this->worker)
            ->post(route('operations.job_board.claim', $foreignPosition))
            ->assertNotFound();

        $this->assertDatabaseHas('shift_open_positions', [
            'id' => $foreignPosition->id,
            'status' => 'open',
            'claimed_by' => null,
        ]);
    }

    public function test_claim_is_rejected_when_actor_is_the_assigned_shift_worker(): void
    {
        $position = $this->positionForShift($this->shiftForSite(assignedStaff: $this->worker));

        $this->actingAs($this->worker)
            ->from(route('operations.job_board.index'))
            ->post(route('operations.job_board.claim', $position))
            ->assertRedirect(route('operations.job_board.index'))
            ->assertSessionHasErrors('claim');
    }

    public function test_claim_is_rejected_when_shift_is_completed_or_cancelled(): void
    {
        $position = $this->positionForShift($this->shiftForSite(status: 'cancelled'));

        $this->actingAs($this->worker)
            ->from(route('operations.job_board.index'))
            ->post(route('operations.job_board.claim', $position))
            ->assertRedirect(route('operations.job_board.index'))
            ->assertSessionHasErrors('claim');
    }

    public function test_claim_is_rejected_when_position_is_expired(): void
    {
        $position = $this->positionForShift($this->shiftForSite(), [
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($this->worker)
            ->from(route('operations.job_board.index'))
            ->post(route('operations.job_board.claim', $position))
            ->assertRedirect(route('operations.job_board.index'))
            ->assertSessionHasErrors('claim');
    }

    public function test_claim_is_blocked_when_eligibility_reports_a_hard_block(): void
    {
        $position = $this->positionForShift($this->shiftForSite());

        $this->blockEligibility('Contracted hours exceeded.');

        $this->actingAs($this->worker)
            ->from(route('operations.job_board.index'))
            ->post(route('operations.job_board.claim', $position))
            ->assertRedirect(route('operations.job_board.index'))
            ->assertSessionHasErrors(['claim' => 'Contracted hours exceeded.']);
    }

    public function test_double_claim_attempts_leave_exactly_one_success_and_clear_feedback(): void
    {
        $otherWorker = $this->userWithPermissions(['shifts.viewAssigned'], [
            'role' => 'support_worker',
        ]);
        $position = $this->positionForShift($this->shiftForSite());

        $this->allowEligibility();

        $this->actingAs($this->worker)
            ->from(route('operations.job_board.index'))
            ->post(route('operations.job_board.claim', $position))
            ->assertSessionHas('success');

        $this->actingAs($otherWorker)
            ->from(route('operations.job_board.index'))
            ->post(route('operations.job_board.claim', $position))
            ->assertRedirect(route('operations.job_board.index'))
            ->assertSessionHasErrors(['claim' => 'This position was just claimed by another worker.']);

        $this->assertDatabaseHas('shift_open_positions', [
            'id' => $position->id,
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
        ]);
    }

    public function test_cancelled_shift_cascades_active_job_board_replacement(): void
    {
        Notification::fake();
        $position = $this->positionForShift($this->shiftForSite());

        $this->actingAs($this->manager)
            ->from(route('operations.job_board.index'))
            ->patch(route('operations.shifts.cancel', $position->shift))
            ->assertRedirect(route('operations.job_board.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('shifts', [
            'id' => $position->shift_id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('shift_open_positions', [
            'id' => $position->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('shift_replacement_requests', [
            'id' => $position->replacement_request_id,
            'status' => 'cancelled',
        ]);
    }

    public function test_approve_sets_assignee_fills_position_cancels_siblings_and_notifies(): void
    {
        Notification::fake();
        $position = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now(),
        ]);
        $sibling = $this->positionForShift($position->shift, [
            'replacement_request_id' => $position->replacement_request_id,
            'status' => 'open',
        ]);

        $this->allowEligibility();

        $this->actingAs($this->manager)
            ->from(route('operations.job_board.index'))
            ->post(route('operations.job_board.approve', $position))
            ->assertRedirect(route('operations.job_board.index'))
            ->assertSessionHas('success');

        $position->refresh();
        $sibling->refresh();

        $this->assertSame('filled', $position->status);
        $this->assertSame('cancelled', $sibling->status);
        $this->assertSame($this->worker->id, $position->shift->fresh()->user_id);
        $this->assertDatabaseHas('shift_replacement_requests', [
            'id' => $position->replacement_request_id,
            'status' => 'approved',
            'replacement_user_id' => $this->worker->id,
        ]);
        Notification::assertSentTo($this->worker, AppEventNotification::class, function (AppEventNotification $notification) {
            return $notification->payload['title'] === 'Shift replacement approved';
        });
    }

    public function test_approve_refuses_when_claimer_has_become_ineligible(): void
    {
        $position = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now(),
        ]);

        $this->blockEligibility('Qualification expired yesterday.');

        $this->actingAs($this->manager)
            ->from(route('operations.job_board.index'))
            ->post(route('operations.job_board.approve', $position))
            ->assertRedirect(route('operations.job_board.index'))
            ->assertSessionHasErrors(['position' => 'Qualification expired yesterday.']);

        $this->assertDatabaseHas('shift_open_positions', [
            'id' => $position->id,
            'status' => 'claimed',
        ]);
    }

    public function test_approve_exception_is_safe_503_with_zero_side_effects_then_retry_and_replay_are_serialized(): void
    {
        Notification::fake();
        $position = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now(),
        ]);
        $baseline = [
            'audit' => AuditLog::query()->count(),
            'timeline' => TimelineEvent::query()->count(),
            'reservations' => CoverageReservation::query()->count(),
            'outbox' => ShiftSignalOutbox::query()->count(),
        ];
        $attempt = 0;
        // Failed approval, successful retry, and the committed Workforce observation each evaluate once.
        $this->mock(ShiftStaffEligibilityService::class, function (MockInterface $mock) use (&$attempt): void {
            $mock->shouldReceive('evaluate')->times(3)->andReturnUsing(function () use (&$attempt) {
                if (++$attempt === 1) {
                    throw new \RuntimeException('private eligibility provider detail');
                }

                return $this->eligibleResult();
            });
        });

        $this->actingAs($this->manager)
            ->postJson(route('operations.job_board.approve', $position))
            ->assertStatus(503)
            ->assertJsonPath('errors.position.0', AssignmentEligibilityDecision::UNAVAILABLE_MESSAGE)
            ->assertJsonMissing(['private eligibility provider detail']);

        $this->assertDatabaseHas('shift_open_positions', [
            'id' => $position->id,
            'status' => 'claimed',
            'approved_by' => null,
        ]);
        $this->assertDatabaseHas('shifts', [
            'id' => $position->shift_id,
            'user_id' => $this->currentStaff->id,
        ]);
        $this->assertDatabaseHas('shift_replacement_requests', [
            'id' => $position->replacement_request_id,
            'status' => ShiftReplacementService::CLAIMED,
        ]);
        $this->assertSame($baseline['audit'], AuditLog::query()->count());
        $this->assertSame($baseline['timeline'], TimelineEvent::query()->count());
        $this->assertSame($baseline['reservations'], CoverageReservation::query()->count());
        $this->assertSame($baseline['outbox'], ShiftSignalOutbox::query()->count());
        Notification::assertNothingSent();

        $this->actingAs($this->manager)
            ->post(route('operations.job_board.approve', $position))
            ->assertSessionHas('success');

        $this->actingAs($this->manager)
            ->postJson(route('operations.job_board.approve', $position))
            ->assertNotFound();

        $this->assertDatabaseHas('shift_open_positions', [
            'id' => $position->id,
            'status' => 'filled',
            'approved_by' => $this->manager->id,
        ]);
        $this->assertDatabaseHas('shifts', [
            'id' => $position->shift_id,
            'user_id' => $this->worker->id,
        ]);
        $this->assertSame(1, TimelineEvent::query()
            ->where('source_type', ShiftReplacementRequest::class)
            ->where('source_id', $position->replacement_request_id)
            ->where('type', 'shift_replacement_approved')
            ->count());
        Notification::assertSentToTimes($this->worker, AppEventNotification::class, 1);
    }

    public function test_approve_hard_block_is_422_and_warning_state_remains_advisory(): void
    {
        $blockedPosition = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now(),
        ]);
        $this->blockEligibility('Current credential is missing.');

        $this->actingAs($this->manager)
            ->postJson(route('operations.job_board.approve', $blockedPosition))
            ->assertUnprocessable()
            ->assertJsonPath('errors.position.0', 'Current credential is missing.');

        $this->assertDatabaseHas('shift_open_positions', [
            'id' => $blockedPosition->id,
            'status' => 'claimed',
        ]);
        $this->assertDatabaseHas('shifts', [
            'id' => $blockedPosition->shift_id,
            'user_id' => $this->currentStaff->id,
        ]);

        $warningPosition = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now(),
        ]);
        $warning = 'Medication competency expires within the warning window.';
        $this->warnEligibility($warning);
        Notification::fake();

        $this->actingAs($this->manager)
            ->post(route('operations.job_board.approve', $warningPosition))
            ->assertSessionHas('success')
            ->assertSessionHas('compliance_warnings', [$warning]);

        $this->assertDatabaseHas('shift_open_positions', [
            'id' => $warningPosition->id,
            'status' => 'filled',
        ]);
        $this->assertDatabaseCount('shift_eligibility_overrides', 0);
    }

    public function test_approve_refuses_when_claimant_no_longer_has_shift_site_eligibility(): void
    {
        $position = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now(),
        ]);
        $this->worker->hrEmployeeProfile()->update([
            'primary_site_id' => $this->foreignSite->id,
            'secondary_site_ids' => [],
        ]);

        $this->allowEligibility();

        $this->actingAs($this->manager)
            ->post(route('operations.job_board.approve', $position))
            ->assertSessionHasErrors([
                'position' => 'The claimed worker is no longer eligible to work at this Shift Site.',
            ]);

        $this->assertDatabaseHas('shift_open_positions', [
            'id' => $position->id,
            'status' => 'claimed',
        ]);
        $this->assertDatabaseHas('shifts', [
            'id' => $position->shift_id,
            'user_id' => $this->currentStaff->id,
        ]);
    }

    public function test_approve_refuses_a_position_from_another_site(): void
    {
        $foreignPosition = $this->positionForShift($this->shiftForSite($this->foreignSite), [
            'status' => 'claimed',
            'claimed_by' => $this->siteWorker($this->foreignSite)->id,
            'claimed_at' => now(),
        ]);

        $this->actingAs($this->manager)
            ->post(route('operations.job_board.approve', $foreignPosition))
            ->assertNotFound();
    }

    public function test_cancel_notifies_the_claiming_worker_and_requester(): void
    {
        Notification::fake();

        $requester = $this->siteWorker($this->site);
        $position = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now(),
        ], $requester);

        $replacement = $position->replacementRequest;
        $replacement->update([
            'status' => 'claimed',
            'replacement_user_id' => $this->worker->id,
            'claimed_at' => now(),
        ]);

        app(ShiftReplacementService::class)->cancel($replacement->fresh(['openPosition', 'shift.client']), $this->manager);

        Notification::assertSentTo($requester, AppEventNotification::class, function (AppEventNotification $notification) {
            return $notification->payload['title'] === 'Shift replacement cancelled';
        });
        Notification::assertSentTo($this->worker, AppEventNotification::class);
    }

    public function test_permission_gates_block_view_and_approve_without_capability(): void
    {
        $noAccess = User::factory()->create();
        $position = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now(),
        ]);

        $this->actingAs($noAccess)
            ->get(route('operations.job_board.index'))
            ->assertForbidden();

        $viewerOnly = $this->userWithPermissions(['job_board.viewAny'], [
        ]);

        $this->actingAs($viewerOnly)
            ->post(route('operations.job_board.approve', $position))
            ->assertForbidden();
    }

    public function test_index_allows_every_capability_admitted_by_the_workforce_nav_gate(): void
    {
        $claimOnly = $this->userWithPermissions(['job_board.claim'], [
        ]);
        $assignedShiftViewer = $this->userWithPermissions(['shifts.viewAssigned'], [
        ]);
        $noAccess = User::factory()->create();

        $this->allowEligibility();

        $this->actingAs($claimOnly)
            ->get(route('operations.job_board.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/job-board/Index'));

        $this->actingAs($assignedShiftViewer)
            ->get(route('operations.job_board.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/job-board/Index'));

        $this->actingAs($noAccess)
            ->get(route('operations.job_board.index'))
            ->assertForbidden();
    }

    public function test_expire_positions_command_cancels_expired_open_positions_and_nudges_stale_claims(): void
    {
        Notification::fake();
        $expired = $this->positionForShift($this->shiftForSite(), [
            'status' => 'open',
            'expires_at' => now()->subMinute(),
        ]);
        $fresh = $this->positionForShift($this->shiftForSite(), [
            'status' => 'open',
            'expires_at' => now()->addHour(),
        ]);
        $staleClaim = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subDays(3),
        ]);
        $recentClaim = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subHour(),
        ]);

        $this->artisan('shifts:expire-positions')
            ->expectsOutput('Cancelled 1 expired open position(s). Nudged managers for 1 stale claim(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('shift_open_positions', [
            'id' => $expired->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('shift_open_positions', [
            'id' => $fresh->id,
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('shift_open_positions', [
            'id' => $recentClaim->id,
            'status' => 'claimed',
        ]);
        $this->assertDatabaseHas('timeline_events', [
            'source_type' => ShiftOpenPosition::class,
            'source_id' => $staleClaim->id,
            'type' => 'shift_replacement_claim_pending_nudged',
        ]);

        Notification::assertSentTo($this->manager, AppEventNotification::class, function (AppEventNotification $notification) {
            return $notification->payload['title'] === 'Replacement claim awaiting approval';
        });
    }

    public function test_expire_positions_re_nudges_without_duplicating_timeline_event(): void
    {
        Notification::fake();

        $staleClaim = $this->positionForShift($this->shiftForSite(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subDays(3),
        ]);

        // A nudge recorded more than a day ago: recentNudgeExists() lets the command
        // remind again, but timeline_events has a unique (type, source) key — a plain
        // insert used to throw and abort the whole run. Re-running must refresh the
        // existing event, not duplicate it (regression for the nightly cron crash).
        TimelineEvent::create([
            'source_type' => ShiftOpenPosition::class,
            'source_id' => $staleClaim->id,
            'shift_id' => $staleClaim->shift_id,
            'type' => 'shift_replacement_claim_pending_nudged',
            'occurred_at' => now()->subDays(2),
            'subject' => 'Replacement claim reminder sent',
            'body' => 'Earlier reminder',
            'visibility' => 'internal',
        ]);

        $this->artisan('shifts:expire-positions')
            ->expectsOutput('Cancelled 0 expired open position(s). Nudged managers for 1 stale claim(s).')
            ->assertSuccessful();

        // Still exactly one nudge event for the position — refreshed, not duplicated.
        $this->assertSame(1, TimelineEvent::query()
            ->where('source_type', ShiftOpenPosition::class)
            ->where('source_id', $staleClaim->id)
            ->where('type', 'shift_replacement_claim_pending_nudged')
            ->count());

        // And its timestamp was bumped to now, so the 24h guard holds again.
        $this->assertTrue(TimelineEvent::query()
            ->where('source_id', $staleClaim->id)
            ->where('type', 'shift_replacement_claim_pending_nudged')
            ->where('occurred_at', '>=', now()->subDay())
            ->exists());
    }

    private function userWithPermissions(array $permissionKeys, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'approved_at' => now(),
        ], $attributes));

        $this->assignToSite($user, $this->site);

        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(
                ['key' => $key],
                ['description' => $key]
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }

    private function attachRole(User $user, string $roleName): void
    {
        $role = Role::firstOrCreate(
            ['name' => $roleName],
            [
                'label' => ucfirst(str_replace('_', ' ', $roleName)),
                'level' => $roleName === 'admin' ? 100 : 40,
                'type' => 'system',
            ],
        );

        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    private function shiftForSite(
        ?Site $site = null,
        ?User $assignedStaff = null,
        ?Carbon $startsAt = null,
        string $status = 'scheduled',
    ): Shift {
        $site ??= $this->site;
        $client = $site->is($this->site)
            ? $this->client
            : Client::factory()->create(['site_id' => $site->id]);
        $assignedStaff ??= $site->is($this->site)
            ? $this->currentStaff
            : $this->siteWorker($site);

        $startsAt ??= now()->addDay()->setTime(9, 0);

        return Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $assignedStaff->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(4),
            'status' => $status,
            'created_by' => $this->manager->id,
        ]);
    }

    private function positionForShift(
        Shift $shift,
        array $overrides = [],
        ?User $requester = null,
    ): ShiftOpenPosition {
        $requester ??= $this->manager;
        $positionStatus = $overrides['status'] ?? 'open';

        $replacement = ShiftReplacementRequest::create([
            'shift_id' => $shift->id,
            'requested_by' => $requester->id,
            'current_staff_id' => $shift->user_id,
            'replacement_user_id' => $overrides['claimed_by'] ?? null,
            'status' => match ($positionStatus) {
                'claimed' => ShiftReplacementService::CLAIMED,
                'filled' => ShiftReplacementService::APPROVED,
                'cancelled' => ShiftReplacementService::CANCELLED,
                default => ShiftReplacementService::REQUESTED,
            },
            'reason' => 'Needs cover for readiness test',
            'requested_at' => now()->subHour(),
            'claimed_at' => $overrides['claimed_at'] ?? null,
            'approved_by' => $positionStatus === 'filled' ? $this->manager->id : null,
            'approved_at' => $positionStatus === 'filled' ? ($overrides['approved_at'] ?? now()) : null,
            'cancelled_by' => $positionStatus === 'cancelled' ? $this->manager->id : null,
            'cancelled_at' => $positionStatus === 'cancelled' ? now() : null,
        ]);

        $position = ShiftOpenPosition::create(array_merge([
            'shift_id' => $shift->id,
            'replacement_request_id' => $replacement->id,
            'status' => 'open',
            'required_skills' => [],
            'coverage_roles' => [],
            'expires_at' => null,
            'approved_by' => $positionStatus === 'filled' ? $this->manager->id : null,
        ], $overrides));

        return $position->fresh(['shift', 'replacementRequest']) ?? $position;
    }

    private function siteWorker(Site $site): User
    {
        $worker = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);
        $this->assignToSite($worker, $site);

        return $worker;
    }

    private function assignToSite(User $user, Site $site): void
    {
        HrEmployeeProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'employee_number' => 'EMP-JOB-'.$user->id,
                'work_email' => $user->email,
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
            ],
        );
    }

    private function allowEligibility(): void
    {
        $this->mock(ShiftStaffEligibilityService::class, function (MockInterface $mock) {
            $mock->shouldReceive('evaluate')->andReturn($this->eligibleResult());
            // JobBoardController batches eligibility via evaluateMany(); return the
            // same [shiftId][userId] => result map shape the real service produces.
            $mock->shouldReceive('evaluateMany')->andReturnUsing(
                fn ($shifts, $users) => $this->eligibilityMatrix($shifts, $users, $this->eligibleResult())
            );
        });
    }

    private function blockEligibility(string $reason): void
    {
        $blocked = new EligibilityResult(
            is_allowed: false,
            blocking_reasons: [$reason],
            warnings: [],
            checked_rules: [],
            overrideable_warnings: [],
        );

        $this->mock(ShiftStaffEligibilityService::class, function (MockInterface $mock) use ($blocked) {
            $mock->shouldReceive('evaluate')->andReturn($blocked);
            $mock->shouldReceive('evaluateMany')->andReturnUsing(
                fn ($shifts, $users) => $this->eligibilityMatrix($shifts, $users, $blocked)
            );
        });
    }

    private function warnEligibility(string $warning): void
    {
        $result = new EligibilityResult(
            is_allowed: true,
            blocking_reasons: [],
            warnings: [$warning],
            checked_rules: [],
            overrideable_warnings: [[
                'rule' => 'medication_competency',
                'message' => $warning,
                'overrideable' => true,
            ]],
        );

        $this->mock(ShiftStaffEligibilityService::class, function (MockInterface $mock) use ($result) {
            $mock->shouldReceive('evaluate')->andReturn($result);
            $mock->shouldReceive('evaluateMany')->andReturnUsing(
                fn ($shifts, $users) => $this->eligibilityMatrix($shifts, $users, $result)
            );
        });
    }

    /**
     * Build the [shiftId][userId] => EligibilityResult map that
     * ShiftStaffEligibilityService::evaluateMany() returns, so mocked eligibility
     * satisfies the controller's batched path.
     */
    private function eligibilityMatrix(iterable $shifts, iterable $users, EligibilityResult $result): array
    {
        $matrix = [];
        foreach ($shifts as $shift) {
            foreach ($users as $user) {
                $matrix[$shift->id][$user->id] = $result;
            }
        }

        return $matrix;
    }

    private function eligibleResult(): EligibilityResult
    {
        return new EligibilityResult(
            is_allowed: true,
            blocking_reasons: [],
            warnings: [],
            checked_rules: [],
            overrideable_warnings: [],
        );
    }

    // Only these retained success cases leave RefreshDatabase's outer wrapper.
    private const LEGACY_ROOT_SUCCESS_METHODS = [
        'test_claim_succeeds_for_an_eligible_worker_and_notifies_staff_and_managers',
        'test_double_claim_attempts_leave_exactly_one_success_and_clear_feedback',
        'test_approve_sets_assignee_fills_position_cancels_siblings_and_notifies',
        'test_approve_exception_is_safe_503_with_zero_side_effects_then_retry_and_replay_are_serialized',
    ];

    private const LEGACY_NESTED_METHOD = 'test_legacy_nested_warning_action_withholds_confirmation_until_outer_commit_or_rollback';

    private ?Connection $legacyRootConnection = null;

    private ?\PDO $legacyRootPdo = null;

    private ?DatabaseTransactionsManager $legacyRootTestingManager = null;

    private ?DatabaseTransactionsManager $legacyRootProductionManager = null;

    private bool $legacyRootMayHaveCommitted = false;

    protected function assertPreConditions(): void
    {
        parent::assertPreConditions();
        if ($this->legacyRootProductionManager !== null) {
            $this->assertLegacyRootIdle();
        }
    }

    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();
        if ($this->legacyRootProductionManager === null) {
            return;
        }
        $this->assertLegacyRootIdle();
        if (in_array($this->name(), self::LEGACY_ROOT_SUCCESS_METHODS, true)) {
            $this->assertLegacyRootCommittedTuples();
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->legacyRootMayHaveCommitted) {
                $connection = $this->assertLegacyOwnedConnection();
                DB::purge('job_board_legacy_root_observer');
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
                $this->assertFalse($connection->getPdo()->inTransaction());
                $this->assertNotNull($this->legacyRootTestingManager);
                app()->instance('db.transactions', $this->legacyRootTestingManager);
                $connection->setTransactionManager($this->legacyRootTestingManager);
                // Let the existing RefreshDatabase callback close its own wrapper first.
                $connection->beginTransaction();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function prepareLegacyPhysicalRootFixture(): void
    {
        if (! in_array($this->name(), [...self::LEGACY_ROOT_SUCCESS_METHODS, self::LEGACY_NESTED_METHOD], true)) {
            return;
        }
        $connection = $this->assertLegacyOwnedConnection();
        $this->assertSame(1, $connection->transactionLevel());
        $this->assertTrue($connection->getPdo()->inTransaction());
        $this->assertInstanceOf(\Illuminate\Foundation\Testing\DatabaseTransactionsManager::class, app('db.transactions'));
        $this->legacyRootConnection = $connection;
        $this->legacyRootTestingManager = app('db.transactions');
        $cleanup = CommittedFixtureCleanup::capture();
        $this->beforeApplicationDestroyed(function () use ($cleanup): void {
            // RefreshDatabase disconnects first; reconnect only the same owned schema.
            $this->assertLegacyOwnedConnection();
            $cleanup->restore();
        });
    }

    private function activateLegacyPhysicalRootFixture(): void
    {
        if ($this->legacyRootConnection === null) {
            return;
        }
        $connection = $this->assertLegacyOwnedConnection();
        $this->assertSame($this->legacyRootConnection, $connection);
        $this->assertSame(1, $connection->transactionLevel());
        $this->assertTrue($connection->getPdo()->inTransaction());
        // Commit can throw from a callback after SQL has committed: retain cleanup intent first.
        $this->legacyRootMayHaveCommitted = true;
        $connection->commit();
        $this->legacyRootPdo = $connection->getPdo();
        $this->legacyRootProductionManager = new DatabaseTransactionsManager;
        app()->instance('db.transactions', $this->legacyRootProductionManager);
        $connection->setTransactionManager($this->legacyRootProductionManager);
        $this->assertLegacyRootIdle();
    }

    private function assertLegacyOwnedConnection(): Connection
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertContains($connection->getConfig('host'), ['127.0.0.1', 'localhost']);
        $this->assertSame(static::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), getmypid()));
        $connection->reconnectIfMissingConnection();
        $this->assertSame($connection->getDatabaseName(), $connection->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        if ($this->legacyRootConnection !== null) {
            $this->assertSame($this->legacyRootConnection, $connection);
        }

        return $connection;
    }

    private function assertLegacyRootIdle(): void
    {
        $connection = $this->assertLegacyOwnedConnection();
        $this->assertSame($this->legacyRootPdo, $connection->getPdo());
        $this->assertSame($this->legacyRootProductionManager, app('db.transactions'));
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    private function legacyRootObserver(): Connection
    {
        $writer = $this->assertLegacyOwnedConnection();
        $name = 'job_board_legacy_root_observer';
        config(['database.connections.'.$name => [...$writer->getConfig(), 'name' => $name]]);
        $observer = DB::connection($name);
        $this->assertSame($name, $observer->getName());
        $this->assertSame($writer->getDatabaseName(), $observer->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($observer->getDatabaseName(), getmypid()));
        $this->assertSame($observer->getDatabaseName(), $observer->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertNotSame($writer->getPdo(), $observer->getPdo());
        $this->assertSame(0, $observer->transactionLevel());
        $this->assertFalse($observer->getPdo()->inTransaction());

        return $observer;
    }

    private function assertLegacyRootCommittedTuples(): void
    {
        $approved = str_starts_with($this->name(), 'test_approve_');
        $writer = $this->assertLegacyOwnedConnection();
        $observer = $this->legacyRootObserver();
        $shiftIds = $observer->table('shifts')->where('site_id', $this->site->id)->pluck('id');
        $position = $observer->table('shift_open_positions')->whereIn('shift_id', $shiftIds)
            ->where('status', $approved ? 'filled' : 'claimed')->where('claimed_by', $this->worker->id)->sole();
        $shift = $observer->table('shifts')->where('id', $position->shift_id)->sole();
        $replacement = $observer->table('shift_replacement_requests')->where('id', $position->replacement_request_id)->sole();
        $this->assertSame((int) $this->site->id, (int) $shift->site_id);
        $this->assertSame((int) $this->client->id, (int) $shift->client_id);
        $this->assertSame((int) $this->worker->id, (int) $position->claimed_by);
        $this->assertSame((int) $shift->id, (int) $replacement->shift_id);
        $this->assertSame((int) $this->worker->id, (int) $replacement->replacement_user_id);
        $this->assertSame($approved ? ShiftReplacementService::APPROVED : ShiftReplacementService::CLAIMED, $replacement->status);
        $this->assertSame((int) ($approved ? $this->worker->id : $this->currentStaff->id), (int) $shift->user_id);
        if ($approved) {
            $this->assertSame((int) $this->manager->id, (int) $position->approved_by);
            $this->assertSame((int) $this->manager->id, (int) $replacement->approved_by);
        }
        foreach (['shift_open_positions' => $position, 'shifts' => $shift, 'shift_replacement_requests' => $replacement] as $table => $row) {
            // Complete stored tuples from a distinct, transaction-free physical PDO.
            $this->assertSame((array) $row, (array) $writer->table($table)->where('id', $row->id)->sole());
        }
    }

    #[DataProvider('legacyNestedActions')]
    public function test_legacy_nested_warning_action_withholds_confirmation_until_outer_commit_or_rollback(string $action, bool $commit): void
    {
        Notification::fake();
        $position = $this->positionForShift($this->shiftForSite(), $action === 'approve' ? [
            'status' => 'claimed', 'claimed_by' => $this->worker->id, 'claimed_at' => now(),
        ] : []);
        // Existing Job Board warnings remain advisory: no new acknowledgement or reason.
        $this->warnEligibility('Review the qualification warning before the duty.');
        $writer = $this->assertLegacyOwnedConnection();
        $observer = $this->legacyRootObserver();
        $stored = static function (Connection $reader) use ($position): array {
            return [
                'position' => (array) $reader->table('shift_open_positions')->where('id', $position->id)->sole(),
                'shift' => (array) $reader->table('shifts')->where('id', $position->shift_id)->sole(),
                'replacement' => (array) $reader->table('shift_replacement_requests')->where('id', $position->replacement_request_id)->sole(),
            ];
        };
        $before = $stored($observer);
        $this->assertSame($before, $stored($writer));
        $writer->beginTransaction();
        $this->actingAs($action === 'claim' ? $this->worker : $this->manager)
            ->from(route('operations.job_board.index'))
            ->post(route('operations.job_board.'.$action, $position))
            ->assertRedirect(route('operations.job_board.index'))
            ->assertSessionMissing('success')
            ->assertSessionMissing('job_board_claim_warnings')
            ->assertSessionMissing('compliance_warnings');
        $this->assertSame(1, $writer->transactionLevel());
        $this->assertTrue($writer->getPdo()->inTransaction());
        $this->assertSame($before, $stored($observer));
        $this->assertNotSame($before, $stored($writer));
        $this->assertSame($action === 'claim' ? 'claimed' : 'filled', $stored($writer)['position']['status']);
        $commit ? $writer->commit() : $writer->rollBack();
        $this->assertLegacyRootIdle();
        $after = $stored($observer);
        $this->assertSame($after, $stored($writer));
        if ($commit) {
            $this->assertNotSame($before, $after);
            $this->assertSame($action === 'claim' ? 'claimed' : 'filled', $after['position']['status']);
            $this->assertSame((int) $this->worker->id, (int) $after['position']['claimed_by']);
            $this->assertSame($action === 'claim' ? ShiftReplacementService::CLAIMED : ShiftReplacementService::APPROVED, $after['replacement']['status']);
            $this->assertSame((int) $this->worker->id, (int) $after['replacement']['replacement_user_id']);
            $this->assertSame((int) ($action === 'claim' ? $this->currentStaff->id : $this->worker->id), (int) $after['shift']['user_id']);
        } else {
            $this->assertSame($before, $after);
        }
        // Completion of the enclosing owner never rewrites the prior HTTP response/session.
        foreach (['success', 'job_board_claim_warnings', 'compliance_warnings'] as $key) {
            $this->assertFalse(session()->has($key));
        }
    }

    public static function legacyNestedActions(): array
    {
        return [
            'claim outer commit' => ['claim', true],
            'claim outer rollback' => ['claim', false],
            'approve outer commit' => ['approve', true],
            'approve outer rollback' => ['approve', false],
        ];
    }
}
