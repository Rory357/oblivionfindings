<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\Permission;
use App\Models\RosterPeriod;
use App\Models\Site;
use App\Models\StaffTimeOff;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkforceTimeOffSourceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $manager;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->manager = $this->person($this->site, ['rostering.viewAny', 'shifts.manageAny', 'hr.leave.viewAny', 'hr.leave.approve']);
        $this->worker = $this->person($this->site, ['shifts.viewAssigned']);
        // An existing source period prevents this reader proof from becoming period creation.
        RosterPeriod::factory()->create(['site_id' => $this->site->id, 'week_start' => '2026-10-26', 'week_end' => '2026-11-02',
            'status' => 'draft', 'version' => 1, 'created_by' => $this->manager->id]);
    }

    public function test_approved_half_day_and_its_projection_expose_the_same_source_without_inventing_hours(): void
    {
        $leave = $this->leave(['period' => 'half_day_am', 'hours_requested' => 2.75]);
        $projection = $this->timeOff(['hr_leave_request_id' => $leave->id, 'type' => $leave->leave_type, 'period' => $leave->period,
            'starts_at' => $leave->starts_at, 'ends_at' => $leave->ends_at]);
        $leave->update(['time_off_id' => $projection->id]);

        $this->read($this->manager, fn (Assert $page) => $page
            ->has('timeOffs', 1)->where('timeOffs.0.id', $projection->id)
            ->where('timeOffs.0.hr_leave_request_id', $leave->id)->where('timeOffs.0.user_id', $this->worker->id)
            ->where('timeOffs.0.type', $leave->leave_type)
            ->where('timeOffs.0.period', 'half_day_am')->where('timeOffs.0.hours_requested', null)
            ->where('timeOffs.0.selected_week_hours', null)
            ->where('timeOffs.0.starts_at', $leave->starts_at->toIso8601String())
            ->where('timeOffs.0.ends_at', $leave->ends_at->toIso8601String())
            ->has('approvedLeave', 1)->where('approvedLeave.0.id', $leave->id)
            ->where('approvedLeave.0.user_id', $this->worker->id)->where('approvedLeave.0.leave_type', $leave->leave_type)
            ->where('approvedLeave.0.period', 'half_day_am')
            ->where('approvedLeave.0.status', 'approved')->where('approvedLeave.0.hours_requested', 2.75)
            ->where('approvedLeave.0.selected_week_hours', 2.75));
    }

    public function test_independent_unavailability_retains_its_period_and_has_no_formal_leave_hours(): void
    {
        $row = $this->timeOff(['period' => 'half_day_pm', 'type' => 'unavailable']);
        $this->read($this->manager, fn (Assert $page) => $page
            ->has('timeOffs', 1)->where('timeOffs.0.id', $row->id)->where('timeOffs.0.hr_leave_request_id', null)
            ->where('timeOffs.0.period', 'half_day_pm')->where('timeOffs.0.type', 'unavailable')
            ->where('timeOffs.0.hours_requested', null)->where('timeOffs.0.selected_week_hours', null)
            ->has('approvedLeave', 0)->has('pendingLeave', 0));
    }

    public function test_pending_leave_keeps_its_status_and_requested_amount_without_claiming_approval(): void
    {
        $row = $this->leave(['status' => 'pending', 'hours_requested' => 5.25, 'period' => 'half_day_pm']);
        $this->read($this->manager, fn (Assert $page) => $page
            ->has('pendingLeave', 1)->where('pendingLeave.0.id', $row->id)->where('pendingLeave.0.status', 'pending')
            ->where('pendingLeave.0.period', 'half_day_pm')->where('pendingLeave.0.hours_requested', 5.25)
            ->where('pendingLeave.0.selected_week_hours', 5.25)->has('approvedLeave', 0)->has('timeOffs', 0));
    }

    public static function requestWindows(): array
    {
        return [
            'whole request contained' => ['2026-10-27 00:00:00', '2026-10-27 23:59:59', 5.25],
            'crossing selected week remains unknown' => ['2026-10-25 00:00:00', '2026-10-27 23:59:59', null],
            'lookahead request has known zero selected-week hours' => ['2026-11-03 00:00:00', '2026-11-03 23:59:59', 0.0],
        ];
    }

    #[DataProvider('requestWindows')]
    public function test_selected_week_hours_do_not_prorate_an_aggregate_request(string $start, string $end, ?float $weekHours): void
    {
        $row = $this->leave(['starts_at' => Carbon::parse($start, 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse($end, 'Pacific/Auckland')->utc(), 'hours_requested' => 5.25]);
        $this->read($this->manager, fn (Assert $page) => $page
            ->has('approvedLeave', 1)->where('approvedLeave.0.id', $row->id)
            ->where('approvedLeave.0.hours_requested', 5.25)
            ->where('approvedLeave.0.selected_week_hours', fn ($value) => $weekHours === null ? $value === null : is_numeric($value) && (float) $value === $weekHours));
    }

    public static function workerZones(): array
    {
        return ['Auckland' => ['Pacific/Auckland'], 'Los Angeles' => ['America/Los_Angeles']];
    }

    #[DataProvider('workerZones')]
    public function test_source_horizons_use_the_worker_local_week_and_half_open_boundaries(string $zone): void
    {
        config(['app.worker_timezone' => $zone]);
        $start = Carbon::parse('2026-10-26 00:00:00', $zone)->utc();
        $end = Carbon::parse('2026-11-02 00:00:00', $zone)->utc();
        $lookahead = Carbon::parse('2026-11-09 00:00:00', $zone)->utc();
        $included = $this->timeOff(['starts_at' => $start->copy(), 'ends_at' => $start->copy()->addHour()]);
        $this->timeOff(['starts_at' => $start->copy()->subHour(), 'ends_at' => $start->copy()]);
        $this->timeOff(['starts_at' => $end->copy(), 'ends_at' => $end->copy()->addHour()]);
        $nextWeek = $this->leave(['starts_at' => $end->copy(), 'ends_at' => $end->copy()->addHour(), 'hours_requested' => 1]);
        $this->leave(['starts_at' => $lookahead->copy(), 'ends_at' => $lookahead->copy()->addHour()]);
        $this->read($this->manager, fn (Assert $page) => $page
            ->where('timeOffWindow.timezone', $zone)->where('timeOffWindow.starts_at', $start->toIso8601String())
            ->where('timeOffWindow.ends_at', $end->toIso8601String())
            ->where('timeOffWindow.leave_lookahead_ends_at', $lookahead->toIso8601String())
            ->has('timeOffs', 1)->where('timeOffs.0.id', $included->id)
            ->has('approvedLeave', 1)->where('approvedLeave.0.id', $nextWeek->id)
            ->where('approvedLeave.0.selected_week_hours', fn ($value) => is_numeric($value) && (float) $value === 0.0));
    }

    public function test_new_fields_preserve_sensitive_reason_redaction_and_the_existing_hr_site_boundary(): void
    {
        $own = $this->leave(['leave_type' => 'sick', 'reason' => 'PRIVATE HR medical detail', 'hours_requested' => 2.75]);
        $foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $foreign = $this->person($foreignSite, ['shifts.viewAssigned']);
        $this->leave(['user_id' => $foreign->id, 'reason' => 'PRIVATE OTHER Site leave detail']);
        $response = $this->read($this->manager, fn (Assert $page) => $page
            ->has('approvedLeave', 1)->where('approvedLeave.0.id', $own->id)->where('approvedLeave.0.reason', null)
            ->where('approvedLeave.0.hours_requested', 2.75)->missing('approvedLeave.0.review_notes')
            ->missing('approvedLeave.0.supporting_doc_path'));
        $response->assertDontSee('PRIVATE HR medical detail')->assertDontSee('PRIVATE OTHER Site leave detail');
    }

    public function test_an_ordinary_roster_reader_does_not_gain_other_workers_or_an_hr_leave_queue(): void
    {
        $reader = $this->person($this->site, ['rostering.viewAny', 'shifts.viewAssigned']);
        $own = $this->timeOff(['user_id' => $reader->id]);
        $this->timeOff();
        $this->leave(['user_id' => $reader->id]);
        $this->leave(['status' => 'pending']);
        $this->read($reader, fn (Assert $page) => $page
            ->has('timeOffs', 1)->where('timeOffs.0.id', $own->id)->where('timeOffs.0.hr_leave_request_id', null)
            ->where('timeOffs.0.hours_requested', null)->has('approvedLeave', 0)->has('pendingLeave', 0)
            ->where('canApproveLeave', false));
    }

    public static function projectionReaders(): array
    {
        return ['roster-only scheduler' => ['scheduler'], 'projection owner without HR read' => ['owner'],
            'canonical HR approver' => ['approver']];
    }

    #[DataProvider('projectionReaders')]
    public function test_linked_review_notes_stay_in_hr_while_independent_notes_remain_on_the_roster(string $scope): void
    {
        $actor = match ($scope) {
            'scheduler' => $this->person($this->site, ['rostering.viewAny', 'shifts.manageAny']),
            'owner' => $this->person($this->site, ['rostering.viewAny', 'shifts.viewAssigned']),
            default => $this->manager,
        };
        $subject = $scope === 'owner' ? $actor : $this->worker;
        $reviewNote = 'PRIVATE linked HR reviewer-note sentinel';
        $reason = 'PRIVATE canonical sick reason sentinel';
        $independentNote = 'PUBLIC independent unavailability note sentinel';
        $leave = $this->leave(['user_id' => $subject->id, 'leave_type' => 'sick', 'review_notes' => $reviewNote,
            'reason' => $reason, 'reviewed_by' => $this->manager->id, 'reviewed_at' => now()]);
        $projection = $this->timeOff(['user_id' => $subject->id, 'hr_leave_request_id' => $leave->id,
            'type' => $leave->leave_type, 'period' => $leave->period, 'label' => 'Sick', 'notes' => $reviewNote,
            'starts_at' => $leave->starts_at, 'ends_at' => $leave->ends_at]);
        $leave->update(['time_off_id' => $projection->id]);
        $independent = $this->timeOff(['user_id' => $subject->id, 'notes' => $independentNote,
            'starts_at' => $leave->starts_at->copy()->addDay(), 'ends_at' => $leave->ends_at->copy()->addDay()]);
        $this->assertSame($scope === 'approver', $actor->canDo('hr.leave.viewAny'));
        $this->assertFalse($actor->canDo('hr.leave.manage'));

        $response = $this->read($actor, fn (Assert $page) => $page
            ->has('timeOffs', 2)->where('timeOffs.0.id', $projection->id)
            ->where('timeOffs.0.user_id', $subject->id)->where('timeOffs.0.hr_leave_request_id', $leave->id)
            ->where('timeOffs.0.type', $leave->leave_type)->where('timeOffs.0.period', $leave->period)
            ->where('timeOffs.0.starts_at', $leave->starts_at->toIso8601String())
            ->where('timeOffs.0.ends_at', $leave->ends_at->toIso8601String())
            ->where('timeOffs.0.label', 'Sick')->where('timeOffs.0.notes', null)
            ->where('timeOffs.1.id', $independent->id)->where('timeOffs.1.hr_leave_request_id', null)
            ->where('timeOffs.1.type', 'unavailable')->where('timeOffs.1.notes', $independentNote),
            function () use ($actor, $scope, $leave, $reviewNote): void {
                // The complete store/queue baseline encloses this owning-flow read too.
                $detail = $this->actingAs($actor)->get(route('hr.leave.show', $leave));
                if ($scope !== 'approver') {
                    $detail->assertForbidden();

                    return;
                }
                $detail->assertOk()->assertInertia(fn (Assert $page) => $page->component('hr/leave/show')
                    ->where('request.id', $leave->id)->where('request.review_notes', $reviewNote)
                    ->where('request.reason', null)->where('request.reason_restricted', true));
            });
        $response->assertDontSee($reviewNote)->assertDontSee($reason)->assertSee($independentNote);
        $this->assertSame($reviewNote, $projection->fresh()->notes);
        $this->assertSame($reviewNote, $leave->fresh()->review_notes);
        $this->assertSame($independentNote, $independent->fresh()->notes);
    }

    private function person(Site $site, array $keys): User
    {
        $person = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker', 'external_clinical_account' => false]);
        $person->permissionOverrides()->sync(collect($keys)->mapWithKeys(fn ($key) => [
            Permission::query()->where('key', $key)->firstOrFail()->id => ['allowed' => true],
        ])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $person->id, 'is_active' => true, 'start_date' => '2025-01-01',
            'end_date' => null, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'created_by' => $person->id, 'updated_by' => $person->id]);

        return $person;
    }

    private function leave(array $values = []): HrLeaveRequest
    {
        return HrLeaveRequest::factory()->create(['user_id' => $this->worker->id, 'created_by' => $this->manager->id,
            'status' => 'approved', 'leave_type' => 'annual', 'period' => 'full_day', 'hours_requested' => 5.25,
            'starts_at' => Carbon::parse('2026-10-27 00:00:00', config('app.worker_timezone'))->utc(),
            'ends_at' => Carbon::parse('2026-10-27 23:59:59', config('app.worker_timezone'))->utc(), ...$values])->fresh();
    }

    private function timeOff(array $values = []): StaffTimeOff
    {
        return StaffTimeOff::create(['user_id' => $this->worker->id, 'created_by' => $this->manager->id,
            'type' => 'unavailable', 'period' => 'full_day', 'label' => 'Source contract fixture', 'notes' => null,
            'starts_at' => Carbon::parse('2026-10-27 00:00:00', config('app.worker_timezone'))->utc(),
            'ends_at' => Carbon::parse('2026-10-27 23:59:59', config('app.worker_timezone'))->utc(), ...$values])->fresh();
    }

    private function read(User $actor, callable $assertions, ?callable $additionalRead = null)
    {
        $stores = fn () => collect(['staff_time_offs', 'hr_leave_requests', 'hr_leave_balances', 'hr_leave_balance_ledgers',
            'roster_periods', 'shifts', 'audit_logs', 'notifications', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations', 'jobs'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
        $queue = fn () => collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
        $before = $stores();
        $beforeQueue = $queue();
        $response = $this->actingAs($actor)->get(route('operations.rostering.index', [
            'tab' => 'timeoff', 'week' => '2026-10-26', 'site_id' => [$this->site->id],
        ]))->assertOk()->assertInertia(fn (Assert $page) => $assertions($page->component('operations/rostering/index')));
        if ($additionalRead) {
            $additionalRead();
        }
        $this->assertSame($before, $stores());
        $this->assertSame($beforeQueue, $queue());

        return $response;
    }
}
