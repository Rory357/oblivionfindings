<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Client;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TimesheetReadCohortTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $viewer;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00', 'UTC'));
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        Queue::fake([RefreshWorkforceEligibility::class]);
        $this->site = Site::factory()->create(['name' => 'Timesheet visible House']);
        $this->viewer = $this->actor(['timesheets.viewAny', 'timesheets.manageAny']);
        // User and employee-profile IDs are distinct identity domains.
        User::factory()->create(['approved_at' => now()]);
        $this->worker = $this->actor([]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Read', 'last_name' => 'Resident']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_full_pre_status_counts_stable_pages_and_clamping_share_the_filtered_scope(): void
    {
        $first = null;
        for ($i = 0; $i < 51; $i++) {
            $row = $this->sheet(['status' => $i === 0 ? 'submitted' : 'draft']);
            $first ??= $row;
        }
        $hiddenSite = Site::factory()->create();
        $hiddenWorker = $this->actor([], $hiddenSite);
        $hiddenClient = Client::factory()->create(['site_id' => $hiddenSite->id]);
        $this->sheet(['user_id' => $hiddenWorker->id, 'site_id' => $hiddenSite->id,
            'shift_site_id' => $hiddenSite->id, 'client_id' => $hiddenClient->id]);
        $this->sheet(['archived_at' => now()]);
        $before = $this->history();
        $this->actingAs($this->viewer)->get($this->index(['page' => 99]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 1)->where('timesheets.data.0.id', $first->id)
            ->where('summary.total', 51)->where('summary.draft', 50)->where('summary.submitted', 1)
            ->where('pagination.current_page', 2)->where('pagination.total', 51)->where('pagination.from', 51)->where('pagination.to', 51)
            ->where('filters.page', 2)->where('lists.timesheets.shown', 1)->where('lists.timesheets.limit', 50)
            ->where('heroSummary.timesheets_total', 51)->where('heroSummary.hours_this_week', 204)
            ->where('evidence.complete', true)->where('evidence.scope', 'permitted_sites'));
        $this->get($this->index(['tab' => 'submitted', 'page' => 99]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 1)->where('summary.total', 51)->where('filters.page', 1)
            ->where('pagination.total', 1)->where('pagination.current_page', 1)
            ->where('pagination.links', fn ($links) => collect($links)->whereNotNull('url')
                ->every(fn ($link) => str_contains($link['url'], 'tab=submitted'))));
        $this->assertSame($before, $this->history());
    }

    public function test_review_queue_counts_other_submitted_workers_without_disclosing_their_drafts(): void
    {
        $reviewer = $this->actor(['timesheets.viewAny', 'timesheets.approve']);
        $own = $this->sheet(['user_id' => $reviewer->id, 'status' => 'draft']);
        $pending = $this->sheet(['status' => 'submitted']);
        $this->sheet(['status' => 'draft']);
        $this->sheet(['status' => 'approved']);
        $this->actingAs($reviewer)->get($this->index(['tab' => 'submitted']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 1)->where('timesheets.data.0.id', $pending->id)
            ->where('summary.total', 2)->where('summary.draft', 1)->where('summary.approved', 0)
            ->where('tabCounts.all', 1)->where('tabCounts.submitted', 1)->where('isOwnOnlyView', false)
            ->where('evidence.scope', 'own_and_permitted_submitted_records'));
        $this->get($this->index())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 1)->where('timesheets.data.0.id', $own->id)->where('summary.total', 1)
            ->where('tabCounts.submitted', 1)->where('isOwnOnlyView', true));
    }

    #[DataProvider('literalSearches')]
    public function test_search_is_literal_and_uses_only_the_existing_visible_snapshot_fields(string $needle): void
    {
        $target = $this->sheet(['client_name_snapshot' => 'Visible '.$needle.' match']);
        $this->sheet(['client_name_snapshot' => 'Other text', 'notes' => $needle, 'allowance_notes' => $needle]);
        $this->actingAs($this->viewer)->get($this->index(['search' => $needle]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 1)->where('timesheets.data.0.id', $target->id)
            ->where('summary.total', 1)->where('tabCounts.all', 1)->where('heroSummary.hours_this_week', 4)
            ->where('heroSummary.hours_target', null)->where('heroSummary.rostered_today', null)
            ->where('heroSummary.staff_on_shift', null)
            ->where('heroSummary.evidence.rostered_hours_basis', 'unavailable_for_snapshot_text_search'));
    }

    public static function literalSearches(): array
    {
        return ['percent' => ['50%'], 'underscore' => ['one_two'], 'escape' => ['='], 'backslash' => ['path\\leaf'], 'Unicode' => ['Māori care']];
    }

    public function test_client_staff_dates_and_status_filters_measure_the_same_full_cohort(): void
    {
        $target = $this->sheet(['status' => 'submitted']);
        $this->sheet(['status' => 'approved']);
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $this->sheet(['client_id' => $other->id]);
        $this->sheet(['user_id' => $this->viewer->id]);
        $this->sheet(['work_date' => '2026-09-28']);
        $filters = ['from' => '2026-10-05', 'to' => '2026-10-11', 'client_id' => $this->client->id,
            'staff_id' => $this->worker->id, 'tab' => 'submitted'];
        $this->actingAs($this->viewer)->get($this->index($filters))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 1)->where('timesheets.data.0.id', $target->id)
            ->where('summary.total', 2)->where('summary.approved', 1)->where('summary.submitted', 1)
            ->where('tabCounts.all', 2)->where('pagination.total', 1)->where('heroSummary.hours_this_week', 8)
            ->where('heroSummary.week_start', '2026-10-05'));
    }

    #[DataProvider('workerWeeks')]
    public function test_worker_week_boundaries_preserve_unfiltered_history_and_real_utc_endpoints(string $timezone, string $now, string $monday, string $utcStart, string $utcEnd): void
    {
        config(['app.worker_timezone' => $timezone]);
        Carbon::setTestNow(Carbon::parse($now, 'UTC'));
        $this->sheet(['work_date' => $monday]);
        $this->sheet(['work_date' => Carbon::parse($monday)->subWeeks(2)->toDateString()]);
        $this->actingAs($this->viewer)->get($this->index())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 2)->where('filters.from', null)->where('heroSummary.week_start', $monday)
            ->where('heroSummary.timesheets_total', 1)->where('heroSummary.all_weeks.total', 2)
            ->where('heroSummary.evidence.period_start', $utcStart)->where('heroSummary.evidence.period_end_exclusive', $utcEnd)
            ->where('workerTimezone', $timezone)->where('heroSummary.evidence.timezone', $timezone));
    }

    public static function workerWeeks(): array
    {
        return ['NZ Monday before UTC' => ['Pacific/Auckland', '2026-10-04 12:00:00', '2026-10-05', '2026-10-04T11:00:00+00:00', '2026-10-11T11:00:00+00:00'],
            'New York fall week' => ['America/New_York', '2026-10-26 12:00:00', '2026-10-26', '2026-10-26T04:00:00+00:00', '2026-11-02T05:00:00+00:00']];
    }

    public function test_non_week_date_range_preserves_current_week_summary_and_explicit_list_dates(): void
    {
        $this->sheet(['work_date' => '2026-09-28']);
        $this->sheet();
        $this->actingAs($this->viewer)->get($this->index(['from' => '2026-09-28', 'to' => '2026-09-30']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 1)->where('summary.total', 1)->where('heroSummary.week_start', '2026-10-05')
            ->where('heroSummary.timesheets_total', 0)->where('heroSummary.hours_this_week', 0)
            ->where('heroSummary.evidence.date_from', '2026-09-28')->where('heroSummary.all_weeks.total', 2));
    }

    public function test_roster_targets_use_canonical_employee_scope_and_preserve_real_zero(): void
    {
        $valid = $this->duty($this->worker, '2026-10-05 09:00', '2026-10-05 13:00');
        $this->duty($this->worker, '2026-10-06 09:00', '2026-10-06 17:00', ['status' => 'cancelled']);
        $this->duty(null, '2026-10-07 09:00', '2026-10-07 17:00');
        $foreignSite = Site::factory()->create();
        $foreignWorker = $this->actor([], $foreignSite);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $this->duty($foreignWorker, '2026-10-05 09:00', '2026-10-05 17:00', ['site_id' => $foreignSite->id, 'client_id' => $foreignClient->id]);
        $stay = $this->duty($this->worker, '2026-10-08 09:00', '2026-10-08 17:00');
        $booking = RespiteBooking::factory()->create(['client_id' => $this->client->id,
            'start_at' => '2026-10-07 20:00:00', 'end_at' => '2026-10-08 04:00:00',
            'created_by' => $this->viewer->id, 'updated_by' => $this->viewer->id]);
        DB::table('shifts')->where('id', $stay->id)->update(['respite_booking_id' => $booking->id]);
        $this->actingAs($this->viewer)->get($this->index())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('heroSummary.hours_target', 4)->where('heroSummary.next_payroll_date', null)
            ->where('heroSummary.regions_count', null)->where('heroSummary.rostered_today', 1));
        $this->get($this->index(['staff_id' => $this->viewer->id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('heroSummary.hours_target', 0)->where('heroSummary.rostered_today', 0));
        $this->assertSame($this->worker->id, $valid->fresh()->user_id);
    }

    public function test_current_roster_counts_deduplicate_workers_and_use_half_open_instants(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 22:00:00', 'UTC'));
        $this->duty($this->worker, '2026-10-05 09:00', '2026-10-05 13:00', ['status' => 'in_progress']);
        $this->duty($this->worker, '2026-10-05 10:00', '2026-10-05 14:00', ['status' => 'in_progress']);
        $this->duty($this->viewer, '2026-10-04 23:00', '2026-10-05 12:00', ['status' => 'in_progress']);
        $this->duty($this->viewer, '2026-10-05 09:00', '2026-10-05 11:00', ['status' => 'in_progress']);
        $this->actingAs($this->viewer)->get($this->index())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('heroSummary.staff_on_shift', 2)->where('heroSummary.rostered_today', 3)
            ->where('heroSummary.evidence.today', '2026-10-05')
            ->where('heroSummary.evidence.on_shift_basis', 'distinct_recorded_in_progress_workers_overlapping_now_not_verified_attendance'));
    }

    public function test_deep_link_record_is_explicitly_outside_filtered_page_counts_and_cannot_expose_foreign_scope(): void
    {
        $this->sheet();
        $archived = $this->sheet(['archived_at' => now()]);
        $this->actingAs($this->viewer)->get($this->index(['view' => $archived->id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 2)->where('pagination.total', 1)->where('pagination.from', 1)->where('pagination.to', 1)
            ->where('summary.total', 1)->where('lists.timesheets.shown', 1)
            ->where('lists.timesheets.materialized_count', 2)->where('lists.timesheets.includes_detail_record', true));
        $foreignSite = Site::factory()->create();
        $foreignWorker = $this->actor([], $foreignSite);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $foreign = $this->sheet(['user_id' => $foreignWorker->id, 'site_id' => $foreignSite->id,
            'shift_site_id' => $foreignSite->id, 'client_id' => $foreignClient->id]);
        $this->get($this->index(['view' => $foreign->id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 1)->where('lists.timesheets.includes_detail_record', false));
    }

    public function test_archive_summary_and_empty_results_never_claim_payroll_or_payment_evidence(): void
    {
        $this->sheet();
        $archived = $this->sheet(['archived_at' => now(), 'status' => 'approved']);
        $this->actingAs($this->viewer)->get($this->index(['tab' => 'archived']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 1)->where('timesheets.data.0.id', $archived->id)
            ->where('summary.total', 1)->where('summary.archived', 1)->where('summary.approved', 1)
            ->where('tabCounts.all', 1)->where('evidence.archive_mode', 'archived'));
        $this->get($this->index(['search' => 'Absent snapshot marker']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 0)->where('summary.total', 0)->where('evidence.state', 'no_records')
            ->where('pagination.current_page', 1)->where('pagination.from', null)->where('pagination.to', null)
            ->where('heroSummary.next_payroll_date', null)->where('heroSummary.regions_count', null));
    }

    public function test_staff_navigation_uses_authorised_profile_identity_and_withholds_unavailable_hr_links(): void
    {
        $this->sheet();
        $profile = $this->worker->hrEmployeeProfile;
        $this->assertNotSame($this->worker->id, $profile->id);
        $this->actingAs($this->viewer)->get($this->index())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('timesheets.data.0.staff_profile_url', null)->where('timesheets.data.0.staff_employee_profile_id', null));
        $reader = $this->actor(['timesheets.viewAny', 'timesheets.manageAny', 'hr.employees.viewAny']);
        $this->actingAs($reader)->get($this->index())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('timesheets.data.0.staff.id', $this->worker->id)
            ->where('timesheets.data.0.staff_employee_profile_id', $profile->id)
            ->where('timesheets.data.0.staff_profile_url', route('hr.people.show', $profile->id)));
    }

    public function test_reports_read_bypass_preserves_site_scope_without_granting_hr_navigation_or_mutation(): void
    {
        $foreignSite = Site::factory()->create();
        $worker = $this->actor([], $foreignSite);
        $client = Client::factory()->create(['site_id' => $foreignSite->id]);
        $row = $this->sheet(['user_id' => $worker->id, 'client_id' => $client->id,
            'shift_site_id' => $foreignSite->id, 'site_id' => $foreignSite->id]);
        $reader = $this->actor(['timesheets.viewAny', 'timesheets.manageAny', 'reports.viewAny']);
        $this->actingAs($reader)->get($this->index())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 1)->where('timesheets.data.0.id', $row->id)->where('summary.total', 1)
            ->where('timesheets.data.0.can_mutate', false)->where('timesheets.data.0.staff_profile_url', null));
    }

    public function test_current_link_contradictions_are_withheld_from_rows_counts_and_deep_links(): void
    {
        $visible = $this->sheet();
        $contradictory = $this->sheet();
        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        DB::table('timesheets')->where('id', $contradictory->id)->update(['client_id' => $foreignClient->id]);
        $before = $this->history();
        $this->actingAs($this->viewer)->get($this->index(['view' => $contradictory->id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 1)->where('timesheets.data.0.id', $visible->id)
            ->where('summary.total', 1)->where('pagination.total', 1)
            ->where('lists.timesheets.includes_detail_record', false));
        $this->assertSame($before, $this->history());
    }

    public function test_report_bypass_does_not_bypass_the_employee_profile_site_boundary(): void
    {
        $foreignSite = Site::factory()->create();
        $worker = $this->actor([], $foreignSite);
        $client = Client::factory()->create(['site_id' => $foreignSite->id]);
        $this->sheet(['user_id' => $worker->id, 'client_id' => $client->id,
            'site_id' => $foreignSite->id, 'shift_site_id' => $foreignSite->id]);
        $reader = $this->actor(['timesheets.viewAny', 'timesheets.manageAny', 'reports.viewAny', 'hr.employees.viewAny']);
        $this->actingAs($reader)->get($this->index())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('timesheets.data', 1)->where('summary.total', 1)
            ->where('timesheets.data.0.staff_profile_url', null)->where('timesheets.data.0.staff_employee_profile_id', null));
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected_before_query_projection(array $filters, string $field): void
    {
        $before = $this->history();
        $this->actingAs($this->viewer)->getJson($this->index($filters))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $this->history());
    }

    public static function invalidFilters(): array
    {
        return ['tab' => [['tab' => 'unknown'], 'tab'], 'date' => [['from' => 'not-a-date'], 'from'],
            'reverse' => [['from' => '2026-10-11', 'to' => '2026-10-05'], 'to'],
            'staff' => [['staff_id' => -1], 'staff_id'], 'client' => [['client_id' => 0], 'client_id'],
            'search' => [['search' => str_repeat('a', 256)], 'search'], 'page' => [['page' => 0], 'page'],
            'array' => [['search' => ['bad']], 'search']];
    }

    private function index(array $filters = []): string
    {
        return route('operations.timesheets.index', $filters);
    }

    private function sheet(array $attributes = []): Timesheet
    {
        return Timesheet::factory()->create(['shift_id' => null, 'shift_service_context_id' => null,
            'user_id' => $this->worker->id, 'created_by' => $this->viewer->id, 'client_id' => $this->client->id,
            'site_id' => $this->site->id, 'shift_site_id' => $this->site->id, 'work_date' => '2026-10-05',
            'starts_at' => '2026-10-04 20:00:00', 'ends_at' => '2026-10-05 00:00:00', 'break_minutes' => 0,
            'status' => 'draft', 'activity_type' => 'admin', 'client_name_snapshot' => 'Visible resident',
            'staff_name_snapshot' => 'Visible worker', 'shift_location_snapshot' => 'Visible House', ...$attributes]);
    }

    private function actor(array $permissions, ?Site $site = null): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'coordinator']);
        if ($permissions !== []) {
            $role = Role::create(['name' => 'timesheet-read-'.Str::uuid(), 'label' => 'Read fixture', 'type' => 'custom', 'level' => 40]);
            $role->permissions()->sync(collect($permissions)->map(fn ($key) => Permission::firstOrCreate(['key' => $key], ['description' => $key])->id));
            $user->roles()->attach($role);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id,
            'secondary_site_ids' => [], 'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true,
            'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function duty(?User $worker, string $starts, string $ends, array $attributes = []): Shift
    {
        $context = ServiceContext::factory()->create(['site_id' => $this->site->id, 'is_active' => true]);

        return Shift::factory()->create(['site_id' => $this->site->id, 'client_id' => $this->client->id,
            'user_id' => $worker?->id, 'created_by' => $this->viewer->id, 'service_context_id' => $context->id,
            'starts_at' => Carbon::parse($starts, 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse($ends, 'Pacific/Auckland')->utc(), 'status' => 'scheduled',
            'expected_break_minutes' => 0, ...$attributes]);
    }

    private function history(): array
    {
        return collect(['timesheets', 'audit_logs', 'hr_time_entries', 'hr_payroll_source_uses'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all()])->all();
    }
}
