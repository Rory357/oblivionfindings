<?php

namespace Tests\Feature\Operations;

use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\Eligibility\EligibilityResult;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class JobBoardControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $worker;

    private User $currentStaff;

    private Client $client;

    private ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.worker_timezone' => 'Pacific/Auckland']);

        $this->manager = $this->userWithPermissions(['job_board.viewAny', 'job_board.create', 'job_board.approve', 'shifts.manageAny', 'reports.viewAny'], [
            'role' => 'admin',
            'organization_id' => 1,
        ]);
        $this->attachRole($this->manager, 'admin');

        $this->worker = $this->userWithPermissions(['shifts.viewAssigned'], [
            'role' => 'support_worker',
            'organization_id' => 1,
        ]);

        $this->currentStaff = User::factory()->create([
            'role' => 'support_worker',
            'organization_id' => 1,
        ]);

        $this->client = Client::factory()->create(['organization_id' => 1]);
        $this->serviceContext = ServiceContext::factory()->create();
    }

    public function test_index_returns_only_positions_in_the_actors_org(): void
    {
        $visible = $this->positionForShift($this->shiftForOrg(1));
        $this->positionForShift($this->shiftForOrg(2), ['organization_id' => 2]);

        $this->allowEligibility();

        $this->actingAs($this->manager)
            ->get(route('operations.job_board.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/job-board/Index')
                ->has('jobs.data', 1)
                ->where('jobs.data.0.id', $visible->id));
    }

    public function test_index_hides_expired_open_positions_but_keeps_claimed_and_filled(): void
    {
        $freshOpen = $this->positionForShift($this->shiftForOrg(), [
            'status' => 'open',
            'expires_at' => now()->addHour(),
        ]);
        $expiredOpen = $this->positionForShift($this->shiftForOrg(), [
            'status' => 'open',
            'expires_at' => now()->subMinute(),
        ]);
        $expiredClaimed = $this->positionForShift($this->shiftForOrg(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subHour(),
            'expires_at' => now()->subMinute(),
        ]);
        $expiredFilled = $this->positionForShift($this->shiftForOrg(), [
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
        $freshOpen = $this->positionForShift($this->shiftForOrg(), [
            'status' => 'open',
            'expires_at' => now()->addHour(),
        ]);
        $expiredOpen = $this->positionForShift($this->shiftForOrg(), [
            'status' => 'open',
            'expires_at' => now()->subMinute(),
        ]);
        $claimed = $this->positionForShift($this->shiftForOrg(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subHour(),
        ]);
        $filled = $this->positionForShift($this->shiftForOrg(), [
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
        $later = $this->positionForShift($this->shiftForOrg(startsAt: now()->addDays(3)));
        $earlier = $this->positionForShift($this->shiftForOrg(startsAt: now()->addDay()));

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
        $position = $this->positionForShift($this->shiftForOrg());

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
        $mineClaimed = $this->positionForShift($this->shiftForOrg(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subDay(),
        ]);
        $mineFilled = $this->positionForShift($this->shiftForOrg(), [
            'status' => 'filled',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subDays(2),
            'approved_at' => now()->subDays(2),
        ]);
        $oldFilled = $this->positionForShift($this->shiftForOrg(), [
            'status' => 'filled',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subDays(20),
            'approved_at' => now()->subDays(20),
        ]);
        $someoneElsesClaim = $this->positionForShift($this->shiftForOrg(), [
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
        $matching = $this->positionForShift($this->shiftForOrg(startsAt: now()->addDays(3)), [
            'required_skills' => ['NZSL'],
        ]);
        $wrongSkill = $this->positionForShift($this->shiftForOrg(startsAt: now()->addDays(4)), [
            'required_skills' => ['Medication'],
        ]);
        $tooLate = $this->positionForShift($this->shiftForOrg(startsAt: now()->addDays(10)), [
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
        $position = $this->positionForShift($this->shiftForOrg());

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

    public function test_create_position_refuses_a_shift_from_another_organisation(): void
    {
        $foreignShift = $this->shiftForOrg(2);

        $this->actingAs($this->manager)
            ->post(route('operations.job_board.create', $foreignShift), [
                'shift_id' => $foreignShift->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('shift_open_positions', [
            'shift_id' => $foreignShift->id,
            'organization_id' => $this->manager->organization_id,
        ]);
    }

    public function test_create_position_rejects_when_active_position_already_exists(): void
    {
        $shift = $this->shiftForOrg();
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
        $position = $this->positionForShift($this->shiftForOrg());

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
    }

    public function test_claim_is_rejected_when_actor_is_the_assigned_shift_worker(): void
    {
        $position = $this->positionForShift($this->shiftForOrg(assignedStaff: $this->worker));

        $this->actingAs($this->worker)
            ->from(route('operations.job_board.index'))
            ->post(route('operations.job_board.claim', $position))
            ->assertRedirect(route('operations.job_board.index'))
            ->assertSessionHasErrors('claim');
    }

    public function test_claim_is_rejected_when_shift_is_completed_or_cancelled(): void
    {
        $position = $this->positionForShift($this->shiftForOrg(status: 'cancelled'));

        $this->actingAs($this->worker)
            ->from(route('operations.job_board.index'))
            ->post(route('operations.job_board.claim', $position))
            ->assertRedirect(route('operations.job_board.index'))
            ->assertSessionHasErrors('claim');
    }

    public function test_claim_is_rejected_when_position_is_expired(): void
    {
        $position = $this->positionForShift($this->shiftForOrg(), [
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
        $position = $this->positionForShift($this->shiftForOrg());

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
            'organization_id' => 1,
        ]);
        $position = $this->positionForShift($this->shiftForOrg());

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
        $position = $this->positionForShift($this->shiftForOrg());

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
        $position = $this->positionForShift($this->shiftForOrg(), [
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
        $position = $this->positionForShift($this->shiftForOrg(), [
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

    public function test_approve_refuses_for_a_different_organisations_position(): void
    {
        $foreignPosition = $this->positionForShift($this->shiftForOrg(2), [
            'organization_id' => 2,
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now(),
        ]);

        $this->actingAs($this->manager)
            ->post(route('operations.job_board.approve', $foreignPosition))
            ->assertNotFound();
    }

    public function test_cancel_notifies_the_claiming_worker_and_requester(): void
    {
        Notification::fake();

        $requester = User::factory()->create(['organization_id' => 1]);
        $position = $this->positionForShift($this->shiftForOrg(), [
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

        app(\App\Services\ShiftReplacementService::class)->cancel($replacement->fresh(['openPosition', 'shift.client']), $this->manager);

        Notification::assertSentTo($requester, AppEventNotification::class, function (AppEventNotification $notification) {
            return $notification->payload['title'] === 'Shift replacement cancelled';
        });
        Notification::assertSentTo($this->worker, AppEventNotification::class);
    }

    public function test_permission_gates_block_view_and_approve_without_capability(): void
    {
        $noAccess = User::factory()->create(['organization_id' => 1]);
        $position = $this->positionForShift($this->shiftForOrg(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now(),
        ]);

        $this->actingAs($noAccess)
            ->get(route('operations.job_board.index'))
            ->assertForbidden();

        $viewerOnly = $this->userWithPermissions(['job_board.viewAny'], [
            'organization_id' => 1,
        ]);

        $this->actingAs($viewerOnly)
            ->post(route('operations.job_board.approve', $position))
            ->assertForbidden();
    }

    public function test_expire_positions_command_cancels_expired_open_positions_and_nudges_stale_claims(): void
    {
        Notification::fake();
        $expired = $this->positionForShift($this->shiftForOrg(), [
            'status' => 'open',
            'expires_at' => now()->subMinute(),
        ]);
        $fresh = $this->positionForShift($this->shiftForOrg(), [
            'status' => 'open',
            'expires_at' => now()->addHour(),
        ]);
        $staleClaim = $this->positionForShift($this->shiftForOrg(), [
            'status' => 'claimed',
            'claimed_by' => $this->worker->id,
            'claimed_at' => now()->subDays(3),
        ]);
        $recentClaim = $this->positionForShift($this->shiftForOrg(), [
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

    private function userWithPermissions(array $permissionKeys, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'approved_at' => now(),
            'organization_id' => 1,
        ], $attributes));

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

    private function shiftForOrg(
        int $organizationId = 1,
        ?User $assignedStaff = null,
        ?Carbon $startsAt = null,
        string $status = 'scheduled',
    ): Shift {
        $client = $organizationId === (int) $this->client->organization_id
            ? $this->client
            : Client::factory()->create(['organization_id' => $organizationId]);

        $startsAt ??= now()->addDay()->setTime(9, 0);

        return Shift::factory()->create([
            'organization_id' => $organizationId,
            'client_id' => $client->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => ($assignedStaff ?? $this->currentStaff)->id,
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
            'organization_id' => $shift->organization_id,
            'shift_id' => $shift->id,
            'requested_by' => $requester->id,
            'current_staff_id' => $shift->user_id,
            'replacement_user_id' => $overrides['claimed_by'] ?? null,
            'status' => $positionStatus === 'claimed' ? 'claimed' : 'requested',
            'reason' => 'Needs cover for readiness test',
            'requested_at' => now()->subHour(),
            'claimed_at' => $overrides['claimed_at'] ?? null,
        ]);

        $position = ShiftOpenPosition::create(array_merge([
            'organization_id' => $shift->organization_id,
            'shift_id' => $shift->id,
            'replacement_request_id' => $replacement->id,
            'status' => 'open',
            'required_skills' => [],
            'coverage_roles' => [],
            'expires_at' => null,
        ], $overrides));

        return $position->fresh(['shift', 'replacementRequest']) ?? $position;
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
}
