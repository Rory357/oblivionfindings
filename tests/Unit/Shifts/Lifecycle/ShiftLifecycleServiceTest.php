<?php

namespace Tests\Unit\Shifts\Lifecycle;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Shifts\Lifecycle\Data\CompleteShiftData;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleSource;
use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftEligibilityOverride;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Eligibility\EligibilityResult;
use App\Services\ShiftStaffEligibilityService;
use App\Services\ShiftTimelineService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ShiftLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_create_rejects_a_site_bound_service_context_without_canonical_client_ownership(): void
    {
        $site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $serviceContext = ServiceContext::factory()->create([
            'site_id' => $site->id,
            'is_active' => true,
        ]);
        $actor = User::factory()->create(['approved_at' => now()]);

        try {
            app(ShiftLifecycleService::class)->create([
                'client_id' => null,
                'site_id' => $site->id,
                'service_context_id' => $serviceContext->id,
                'user_id' => null,
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addHours(8),
                'status' => 'draft',
            ], $actor, ShiftLifecycleSource::Bulk);
            $this->fail('A service context must not replace canonical Shift client ownership.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_start_moves_scheduled_shift_to_in_progress_once(): void
    {
        $actor = User::factory()->create();
        $shift = $this->shiftFor($actor, ['status' => 'scheduled']);
        $startedAt = now()->subMinutes(5)->startOfMinute();

        $service = app(ShiftLifecycleService::class);
        $started = $service->start($shift, $actor, $startedAt, ShiftLifecycleSource::Manual);
        $again = $service->start($started, $actor, $startedAt->copy()->addMinute(), ShiftLifecycleSource::Manual);

        $this->assertSame('in_progress', $started->status);
        $this->assertSame('in_progress', $again->status);
        $this->assertTrue($again->actual_starts_at->equalTo($startedAt));
        $this->assertSame(1, TimelineEvent::query()
            ->where('type', ShiftTimelineService::STARTED_EVENT_TYPE)
            ->where('source_type', Shift::class)
            ->where('source_id', $shift->id)
            ->count());
    }

    public function test_clock_in_source_can_start_draft_shift_to_preserve_attendance_flow(): void
    {
        $actor = User::factory()->create();
        $shift = $this->shiftFor($actor, ['status' => 'draft']);

        $started = app(ShiftLifecycleService::class)->start($shift, $actor, now(), ShiftLifecycleSource::ClockIn);

        $this->assertSame('in_progress', $started->status);
        $this->assertSame($actor->id, $started->started_by);
    }

    public function test_complete_is_idempotent_and_does_not_duplicate_timeline_or_timesheet(): void
    {
        $actor = User::factory()->create();
        $shift = $this->shiftFor($actor, [
            'status' => 'in_progress',
            'actual_starts_at' => now()->subHours(4)->startOfMinute(),
            'started_by' => $actor->id,
            'ends_at' => now()->subMinute(),
            'expected_break_minutes' => 15,
        ]);

        $service = app(ShiftLifecycleService::class);
        $data = new CompleteShiftData(
            finalNoteBody: 'Completed with the shared lifecycle service.',
            source: ShiftLifecycleSource::Manual,
        );

        $completed = $service->complete($shift, $actor, $data);
        $again = $service->complete($completed, $actor, $data);

        $this->assertSame('completed', $again->status);
        $this->assertSame(1, Timesheet::query()
            ->where('shift_id', $shift->id)
            ->where('user_id', $actor->id)
            ->count());
        $this->assertSame(1, TimelineEvent::query()
            ->where('type', ShiftTimelineService::COMPLETED_EVENT_TYPE)
            ->where('source_type', Shift::class)
            ->where('source_id', $shift->id)
            ->count());
    }

    public function test_complete_requeries_locked_attendance_evidence_instead_of_using_a_stale_relation(): void
    {
        $actor = User::factory()->create();
        $shift = $this->shiftFor($actor, [
            'status' => 'in_progress',
            'actual_starts_at' => now()->subHours(3),
            'started_by' => $actor->id,
        ]);
        $shift->load(['attendanceSessions', 'tasks']);
        $this->assertCount(0, $shift->attendanceSessions);
        HrAttendanceSession::query()->create([
            'user_id' => $actor->id,
            'shift_id' => $shift->id,
            'site_id' => $shift->site_id,
            'clock_in_at' => now()->subHours(3),
            'clock_out_at' => null,
            'status' => 'open',
            'source' => 'manual',
            'created_by' => $actor->id,
        ]);

        try {
            app(ShiftLifecycleService::class)->complete(
                $shift,
                $actor,
                new CompleteShiftData(finalNoteBody: 'A stale relation must not hide open attendance.'),
            );
            $this->fail('Open attendance evidence was ignored.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame('in_progress', $shift->fresh()->status);
    }

    public function test_complete_requeries_locked_task_evidence_instead_of_using_a_stale_relation(): void
    {
        $actor = User::factory()->create();
        $shift = $this->shiftFor($actor, [
            'status' => 'in_progress',
            'actual_starts_at' => now()->subHours(3),
            'started_by' => $actor->id,
        ]);
        $shift->load(['attendanceSessions', 'tasks']);
        $this->assertCount(0, $shift->tasks);
        ShiftTask::query()->create([
            'shift_id' => $shift->id,
            'label' => 'Late blocker added after the stale aggregate was loaded',
            'is_completed' => false,
            'sort_order' => 1,
        ]);

        try {
            app(ShiftLifecycleService::class)->complete(
                $shift,
                $actor,
                new CompleteShiftData(finalNoteBody: 'A stale relation must not hide incomplete tasks.'),
            );
            $this->fail('Incomplete task evidence was ignored.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allow_incomplete_tasks', $exception->errors());
        }

        $this->assertSame('in_progress', $shift->fresh()->status);
    }

    public function test_assign_records_assignment_timeline_event(): void
    {
        $actor = User::factory()->create();
        $assignee = User::factory()->create();
        $shift = $this->shiftFor($actor, [
            'status' => 'draft',
            'user_id' => null,
        ]);
        $this->makeCurrentAtSite($assignee, Site::query()->findOrFail($shift->site_id));
        $this->mock(ShiftStaffEligibilityService::class, function ($mock): void {
            $mock->shouldReceive('evaluate')
                ->once()
                ->andReturn(EligibilityResult::fromChecks([]));
        });

        $assigned = app(ShiftLifecycleService::class)->assign($shift, $actor, $assignee);

        $this->assertSame('scheduled', $assigned->status);
        $this->assertSame($assignee->id, $assigned->user_id);

        $event = TimelineEvent::query()
            ->where('type', ShiftTimelineService::ASSIGNED_EVENT_TYPE)
            ->where('source_type', Shift::class)
            ->where('source_id', $shift->id)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('Shift assigned', $event->subject);
        $this->assertStringContainsString($assignee->name, $event->body);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame($assignee->id, $event->meta['assigned_user_id']);
    }

    public function test_assign_rebuilds_override_provenance_from_the_locked_actor_and_current_gateway_decision(): void
    {
        $actor = User::factory()->create();
        $assignee = User::factory()->create();
        $shift = $this->shiftFor($actor, ['status' => 'draft', 'user_id' => null]);
        $site = Site::query()->findOrFail($shift->site_id);
        $this->makeCurrentAtSite($assignee, $site);
        $overridePermission = Permission::query()->where('key', 'shifts.overrideEligibility')->firstOrFail();
        $actor->permissionOverrides()->syncWithoutDetaching([
            $overridePermission->id => ['allowed' => true],
        ]);
        $currentWarning = EligibilityResult::fromChecks([[
            'rule' => 'current_turnaround',
            'passed' => false,
            'severity' => 'warning',
            'overrideable' => true,
            'message' => 'Current turnaround evidence needs acknowledgement.',
        ]]);
        $this->mock(ShiftStaffEligibilityService::class, function ($mock) use ($currentWarning): void {
            $mock->shouldReceive('evaluate')->once()->andReturn($currentWarning);
        });

        app(ShiftLifecycleService::class)->assign($shift, $actor, $assignee, [
            'override_acknowledged' => true,
            'override_reason' => 'Current manager review.',
            'user_id' => $actor->id,
            'overridden_by' => $assignee->id,
            'rules_overridden' => ['stale_caller_rule'],
        ]);

        $override = ShiftEligibilityOverride::query()->where('shift_id', $shift->id)->sole();
        $this->assertSame($assignee->id, (int) $override->user_id);
        $this->assertSame($actor->id, (int) $override->overridden_by);
        $this->assertSame(['current_turnaround'], $override->rules_overridden);
        $this->assertSame('Current manager review.', $override->override_reason);
    }

    public function test_assign_rejects_a_current_override_warning_without_current_override_permission_and_writes_nothing(): void
    {
        $actor = User::factory()->create();
        $assignee = User::factory()->create();
        $shift = $this->shiftFor($actor, ['status' => 'draft', 'user_id' => null]);
        $this->makeCurrentAtSite($assignee, Site::query()->findOrFail($shift->site_id));
        $warning = EligibilityResult::fromChecks([[
            'rule' => 'fatigue_weekly',
            'passed' => false,
            'severity' => 'warning',
            'overrideable' => true,
            'message' => 'Weekly fatigue warning.',
        ]]);
        $this->mock(ShiftStaffEligibilityService::class, function ($mock) use ($warning): void {
            $mock->shouldReceive('evaluate')->once()->andReturn($warning);
        });
        $before = $shift->fresh()->getRawOriginal();

        try {
            app(ShiftLifecycleService::class)->assign($shift, $actor, $assignee, [
                'override_acknowledged' => true,
                'override_reason' => 'Stale route permission must not authorize this.',
            ]);
            $this->fail('Current override permission must be required at the persistence boundary.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame($before, $shift->fresh()->getRawOriginal());
        $this->assertFalse(ShiftEligibilityOverride::query()->where('shift_id', $shift->id)->exists());
        $this->assertFalse(TimelineEvent::query()
            ->where('type', ShiftTimelineService::ASSIGNED_EVENT_TYPE)
            ->where('source_id', $shift->id)
            ->exists());
    }

    public function test_unassign_records_internal_timeline_event_with_optional_reason(): void
    {
        $actor = User::factory()->create();
        $staff = User::factory()->create(['name' => 'Aroha King']);
        $shift = $this->shiftFor($staff, ['status' => 'scheduled']);
        $this->makeCurrentAtSite(
            $actor,
            Site::query()->findOrFail($shift->site_id),
            ['shifts.manageAny'],
        );

        $unassigned = app(ShiftLifecycleService::class)->unassign(
            $shift,
            $actor,
            'Staff called in sick',
        );

        $this->assertSame('draft', $unassigned->status);
        $this->assertNull($unassigned->user_id);

        $event = TimelineEvent::query()
            ->where('type', ShiftTimelineService::UNASSIGNED_EVENT_TYPE)
            ->where('source_type', Shift::class)
            ->where('source_id', $shift->id)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('Shift unassigned', $event->subject);
        $this->assertStringContainsString('Aroha King', $event->body);
        $this->assertStringContainsString('Staff called in sick', $event->body);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame($staff->id, $event->meta['previous_user_id']);
        $this->assertSame('Staff called in sick', $event->meta['reason']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function shiftFor(User $staff, array $overrides = []): Shift
    {
        $site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $serviceContext = ServiceContext::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $this->makeCurrentAtSite(
            $staff,
            $site,
            [
                'shifts.update',
                'shifts.viewAssigned',
                'timesheets.create',
                'shifts.manageAny',
            ],
        );

        return Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'created_by' => $staff->id,
            ...$overrides,
        ]);
    }

    /** @param array<int, string> $permissionKeys */
    private function makeCurrentAtSite(User $user, Site $site, array $permissionKeys = []): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        $permissions = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $this->assertCount(count($permissionKeys), $permissions);
        $user->permissionOverrides()->syncWithoutDetaching($permissions);
    }
}
