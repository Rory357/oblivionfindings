<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\Site;
use App\Models\User;
use App\Services\Eligibility\EligibilityResult;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkforceJobBoardRangeProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $foreignSite;

    private Client $client;

    private ServiceContext $context;

    private User $reader;

    private array $results = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-10-20T22:13:14.123456Z')->utc());
        Queue::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->context = ServiceContext::factory()->create(['is_active' => true, 'site_id' => null]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'first_name' => 'Scoped', 'last_name' => 'Person']);
        $this->reader = $this->person(['job_board.viewAny']);
        $this->mock(ShiftStaffEligibilityService::class, function ($mock): void {
            $mock->shouldReceive('evaluateMany')->andReturnUsing(function ($shifts, $users): array {
                $out = [];
                foreach ($shifts as $shift) {
                    foreach ($users as $user) {
                        $result = array_key_exists($shift->id, $this->results) ? $this->results[$shift->id] : $this->allowed();
                        if ($result !== null) {
                            $out[$shift->id][$user->id] = $result;
                        }
                    }
                }

                return $out;
            });
            $mock->shouldReceive('evaluate')->andReturnUsing(function ($shift): EligibilityResult {
                $result = array_key_exists($shift->id, $this->results) ? $this->results[$shift->id] : $this->allowed();
                if ($result === null) {
                    throw new \RuntimeException('Private provider diagnostic must not become a count.');
                }

                return $result;
            });
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_omitted_week_remains_all_weeks_and_global_eligibility_is_not_invented(): void
    {
        $first = $this->position('2026-10-21T09:00:00Z');
        $second = $this->position('2026-11-18T09:00:00Z');
        $props = $this->read();
        $this->assertSame([$first->id, $second->id], array_column($props['jobs']['data'], 'id'));
        $this->assertSame('all_weeks', $props['provenance']['list']['range_kind']);
        $this->assertFalse($props['provenance']['list']['week_applied']);
        $this->assertNull($props['filters']['week']);
        $this->assertNull($props['provenance']['list']['shift_start_window']);
        $this->assertSame(2, $props['provenance']['eligibility']['page_eligible_count']);
        $this->assertNull($props['provenance']['eligibility']['filtered_list_eligible_count']);
        $this->assertNull($props['provenance']['eligibility']['all_weeks_eligible_count']);
    }

    public static function workerZones(): iterable
    {
        yield 'NZ worker' => ['Pacific/Auckland'];
        yield 'LA worker' => ['America/Los_Angeles'];
    }

    #[DataProvider('workerZones')]
    public function test_explicit_week_describes_exact_legacy_sql_bounds_without_claiming_worker_local_week(string $zone): void
    {
        config(['app.worker_timezone' => $zone]);
        $this->position('2026-10-18T23:59:59Z');
        $first = $this->position('2026-10-19T00:00:00Z');
        $last = $this->position('2026-10-25T23:59:59Z');
        $this->position('2026-10-26T00:00:00Z');
        $props = $this->read(['week' => '2026-10-21']);
        $this->assertSame([$first->id, $last->id], array_column($props['jobs']['data'], 'id'));
        $this->assertSame('2026-10-19', $props['filters']['week']);
        $this->assertSame('selected_week', $props['provenance']['list']['range_kind']);
        $this->assertTrue($props['provenance']['list']['week_applied']);
        $this->assertSame('2026-10-19T00:00:00.000000Z', $props['provenance']['list']['shift_start_window']['starts_at_utc']);
        $this->assertSame('2026-10-25T23:59:59.000000Z', $props['provenance']['list']['shift_start_window']['ends_at_utc']);
        $this->assertTrue($props['provenance']['list']['shift_start_window']['end_inclusive']);
        $this->assertSame('UTC', $props['provenance']['list']['shift_start_window']['anchor_timezone']);
        $this->assertSame($zone, $props['provenance']['worker_timezone']);
    }

    public function test_all_weeks_is_an_explicit_clear_of_week_not_an_implicit_current_week(): void
    {
        $first = $this->position('2026-10-21T09:00:00Z');
        $second = $this->position('2026-11-18T09:00:00Z');
        $selected = $this->read(['week' => '2026-10-19']);
        $this->assertSame([$first->id], array_column($selected['jobs']['data'], 'id'));
        $all = $this->read(['week' => '']);
        $this->assertSame([$first->id, $second->id], array_column($all['jobs']['data'], 'id'));
        $this->assertSame('all_weeks', $all['provenance']['list']['range_kind']);
    }

    public static function requestedWeeks(): iterable
    {
        yield 'older week' => ['2026-09-07'];
        yield 'future week' => ['2026-12-07'];
    }

    #[DataProvider('requestedWeeks')]
    public function test_my_claims_retains_active_claims_and_recent_approval_policy_and_marks_week_ignored(string $week): void
    {
        $active = $this->position('2026-09-10T09:00:00Z', ['status' => 'claimed', 'claimed_by' => $this->reader->id, 'claimed_at' => now()->subDays(30)]);
        $recent = $this->position('2026-12-10T09:00:00Z', ['status' => 'filled', 'claimed_by' => $this->reader->id,
            'claimed_at' => now()->subDays(20), 'approved_at' => now()->subDays(14), 'approved_by' => $this->reader->id]);
        $this->position('2026-12-11T09:00:00Z', ['status' => 'filled', 'claimed_by' => $this->reader->id,
            'claimed_at' => now()->subDays(20), 'approved_at' => now()->subDays(14)->subSecond(), 'approved_by' => $this->reader->id]);
        $props = $this->read(['scope' => 'mine', 'week' => $week]);
        $this->assertSame([$active->id, $recent->id], array_column($props['jobs']['data'], 'id'));
        $this->assertSame('my_claims', $props['provenance']['list']['range_kind']);
        $this->assertFalse($props['provenance']['list']['week_applied']);
        $this->assertSame(['week'], $props['provenance']['list']['ignored_filters']);
        $this->assertNull($props['provenance']['list']['shift_start_window']);
        $this->assertSame('2026-10-06T22:13:14.000000Z', $props['provenance']['list']['filled_approved_since_utc']);
        $this->assertSame(2, $props['stats']['mine']);
    }

    public function test_filtered_list_and_all_range_meters_have_distinct_explicit_bases(): void
    {
        $selected = $this->position('2026-10-21T09:00:00Z', ['notes' => 'Visible needle', 'required_skills' => ['first_aid']]);
        $this->position('2026-10-22T09:00:00Z', ['notes' => 'Other', 'required_skills' => ['other']]);
        $this->position('2026-11-18T09:00:00Z', ['notes' => 'Visible needle', 'required_skills' => ['first_aid']]);
        $props = $this->read(['week' => '2026-10-19', 'q' => 'needle', 'skill' => 'first_aid']);
        $this->assertSame([$selected->id], array_column($props['jobs']['data'], 'id'));
        $this->assertSame(1, $props['provenance']['pagination']['total']);
        $this->assertSame(3, $props['stats']['open']);
        $this->assertSame(3, $props['provenance']['metrics']['open']['value']);
        $this->assertSame('accessible_all_weeks', $props['provenance']['metrics']['open']['basis']);
        $this->assertFalse($props['provenance']['metrics']['open']['list_filters_applied']);
        $this->assertSame(['scope', 'q', 'skill', 'week'], $props['provenance']['list']['applied_filters']);
    }

    public static function textFilters(): iterable
    {
        yield 'literal zero search' => ['q'];
        yield 'literal zero skill' => ['skill'];
    }

    #[DataProvider('textFilters')]
    public function test_literal_zero_text_filters_are_marked_applied_when_the_installed_query_applies_them(string $key): void
    {
        $wanted = $this->position('2026-10-21T09:00:00Z', ['notes' => '0', 'required_skills' => ['0']]);
        $this->position('2026-10-22T09:00:00Z', ['notes' => 'Other', 'required_skills' => ['other']]);
        $props = $this->read([$key => '0']);
        $this->assertSame([$wanted->id], array_column($props['jobs']['data'], 'id'));
        $this->assertSame(['scope', $key], $props['provenance']['list']['applied_filters']);
        $this->assertSame(2, $props['provenance']['metrics']['open']['value']);
    }

    public static function fits(): iterable
    {
        foreach (['all', 'eligible', 'no-conflict', 'site'] as $fit) {
            yield $fit => [$fit];
        }
    }

    #[DataProvider('fits')]
    public function test_for_you_and_fit_do_not_claim_an_eligibility_filter_that_is_not_installed(string $fit): void
    {
        $allowed = $this->position('2026-10-21T09:00:00Z');
        $blocked = $this->position('2026-10-22T09:00:00Z');
        $this->results[$blocked->shift_id] = new EligibilityResult(is_allowed: false, blocking_reasons: ['Synthetic blocked'], warnings: [], checked_rules: [], overrideable_warnings: []);
        $props = $this->read(['scope' => 'for-you', 'fit' => $fit]);
        $this->assertSame([$allowed->id, $blocked->id], array_column($props['jobs']['data'], 'id'));
        $this->assertFalse($props['provenance']['list']['eligibility_filtered']);
        $this->assertSame(['fit'], $props['provenance']['list']['ignored_filters']);
        $this->assertSame(1, $props['provenance']['eligibility']['assessed_eligible']);
        $this->assertSame(1, $props['provenance']['eligibility']['assessed_not_eligible']);
    }

    public function test_page_eligibility_does_not_become_a_filtered_list_or_all_weeks_total(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->position(Carbon::parse('2026-10-21T09:00:00Z')->addDays($i)->toISOString());
        }
        $first = $this->read(['page' => 1]);
        $second = $this->read(['page' => 2]);
        $this->assertSame(21, $first['provenance']['pagination']['total']);
        $this->assertSame(20, $first['provenance']['pagination']['returned']);
        $this->assertTrue($first['provenance']['pagination']['has_more']);
        $this->assertSame(20, $first['provenance']['eligibility']['page_eligible_count']);
        $this->assertSame(1, $second['provenance']['eligibility']['page_eligible_count']);
        $this->assertFalse($second['provenance']['pagination']['has_more']);
        $this->assertSame(20, $first['stats']['eligible_for_you']);
        $this->assertSame(1, $second['stats']['eligible_for_you']);
        $this->assertNull($first['provenance']['eligibility']['filtered_list_eligible_count']);
        $this->assertNull($second['provenance']['eligibility']['all_weeks_eligible_count']);
    }

    public function test_missing_pair_and_failed_fallback_are_unassessed_instead_of_a_complete_zero(): void
    {
        $this->position('2026-10-21T09:00:00Z');
        $unknown = $this->position('2026-10-22T09:00:00Z');
        $this->results[$unknown->shift_id] = null;
        $props = $this->read();
        $this->assertSame('partial', $props['provenance']['eligibility']['assessment_status']);
        $this->assertSame(1, $props['provenance']['eligibility']['assessed']);
        $this->assertSame(1, $props['provenance']['eligibility']['unassessed']);
        $this->assertSame(1, $props['provenance']['eligibility']['assessed_eligible']);
        $this->assertNull($props['provenance']['eligibility']['page_eligible_count']);
        $this->assertNull($props['provenance']['metrics']['eligible_for_you']['value']);
        $this->assertStringNotContainsString('Private provider diagnostic', json_encode($props['provenance'], JSON_THROW_ON_ERROR));
    }

    public function test_empty_page_has_an_explicit_page_zero_but_no_global_eligibility_assessment(): void
    {
        $props = $this->read();
        $this->assertSame('empty', $props['provenance']['eligibility']['assessment_status']);
        $this->assertSame(0, $props['provenance']['pagination']['total']);
        $this->assertSame(0, $props['provenance']['eligibility']['page_eligible_count']);
        $this->assertNull($props['provenance']['eligibility']['all_weeks_eligible_count']);
    }

    public function test_unapproved_approval_metric_is_not_reported_as_a_counted_zero(): void
    {
        $other = $this->person(['shifts.viewAssigned']);
        $this->position('2026-10-21T09:00:00Z', ['status' => 'claimed', 'claimed_by' => $other->id, 'claimed_at' => now()]);
        $props = $this->read();
        $this->assertSame(0, $props['stats']['pending_approval']);
        $this->assertNull($props['provenance']['metrics']['pending_approval']['value']);
        $this->assertSame('not_authorized', $props['provenance']['metrics']['pending_approval']['assessment_status']);
        $this->assertFalse($props['viewer']['can_approve']);
    }

    public static function reportReaders(): iterable
    {
        yield 'approved Site only' => [false, 1];
        yield 'existing reports exception' => [true, 2];
    }

    #[DataProvider('reportReaders')]
    public function test_list_metadata_and_metric_counts_retain_exact_approved_site_and_report_exception(bool $reports, int $expected): void
    {
        $this->position('2026-10-21T09:00:00Z');
        $foreign = $this->position('2026-10-22T09:00:00Z', [], $this->foreignSite);
        if ($reports) {
            $grant = Permission::firstOrCreate(['key' => 'reports.viewAny'], ['description' => 'Existing report exception']);
            $this->reader->permissionOverrides()->attach($grant, ['allowed' => true]);
            $this->reader = $this->reader->fresh();
        }
        $props = $this->read();
        $this->assertSame($expected, $props['stats']['open']);
        $this->assertSame($expected, $props['provenance']['pagination']['total']);
        $this->assertSame($expected, $props['provenance']['metrics']['open']['value']);
        if (! $reports) {
            $this->assertNotContains($foreign->id, array_column($props['jobs']['data'], 'id'));
        }
        $this->assertStringNotContainsString('client_id', json_encode($props['provenance'], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('site_id', json_encode($props['provenance'], JSON_THROW_ON_ERROR));
    }

    public static function relativeRanges(): iterable
    {
        yield 'tonight' => ['tonight', '2026-10-20T18:00:00Z', '2026-10-21T06:00:00Z', '2026-10-21T04:00:00Z'];
        yield 'installed next7days' => ['next_7_days', '2026-10-20T00:00:00Z', '2026-10-27T23:59:59Z', '2026-10-27T10:00:00Z'];
        yield 'this weekend' => ['this_weekend', '2026-10-24T00:00:00Z', '2026-10-25T23:59:59Z', '2026-10-24T10:00:00Z'];
    }

    #[DataProvider('relativeRanges')]
    public function test_relative_range_metadata_is_the_actual_filter_and_intersects_explicit_week(string $key, string $start, string $end, string $inside): void
    {
        $included = $this->position($inside);
        $this->position(Carbon::parse($start)->subSecond()->toISOString());
        $this->position(Carbon::parse($end)->addSecond()->toISOString());
        $props = $this->read(['date_range' => $key]);
        $this->assertSame([$included->id], array_column($props['jobs']['data'], 'id'));
        $window = $props['provenance']['list']['date_range']['window'];
        $this->assertSame(Carbon::parse($start)->toISOString(), $window['starts_at_utc']);
        $this->assertSame(Carbon::parse($end)->toISOString(), $window['ends_at_utc']);
        $intersection = $this->read(['date_range' => $key, 'week' => '2026-10-19']);
        $this->assertTrue($intersection['provenance']['list']['week_applied']);
        $this->assertSame($key === 'next_7_days' ? [] : [$included->id], array_column($intersection['jobs']['data'], 'id'));
        $this->assertSame('accessible_all_weeks', $intersection['provenance']['metrics']['open']['basis']);
    }

    public function test_posted_card_fields_and_legacy_props_are_preserved(): void
    {
        $position = $this->position('2026-10-21T09:00:00Z', ['notes' => 'Original posted note', 'required_skills' => ['first_aid'], 'coverage_roles' => ['support_worker']]);
        $props = $this->read();
        $job = $props['jobs']['data'][0];
        $this->assertSame($position->id, $job['id']);
        $this->assertSame(['first_aid'], $job['required_skills']);
        $this->assertSame(['support_worker'], $job['coverage_roles']);
        $this->assertSame(['id', 'title', 'status', 'date', 'start_time', 'end_time', 'location', 'required_skills', 'your_skills',
            'coverage_roles', 'coverage', 'privacy', 'client', 'replacement', 'claimed_by', 'eligibility', 'viewer_eligibility',
            'your_schedule', 'tasks', 'tasks_total', 'past_shifts_here', 'site_familiar'], array_keys($job));
        foreach (['jobs', 'filters', 'available_skills', 'week', 'viewer', 'stats'] as $key) {
            $this->assertArrayHasKey($key, $props);
        }
        $this->assertSame('request_read_not_atomic_snapshot', $props['provenance']['consistency']);
    }

    public function test_read_permission_denial_does_not_create_or_mutate_any_domain_row(): void
    {
        $this->position('2026-10-21T09:00:00Z');
        $this->reader = $this->person([]);
        $before = $this->state();
        $queue = Queue::pushedJobs();
        $this->actingAs($this->reader)->get(route('operations.job_board.index'))->assertForbidden();
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, Queue::pushedJobs());
    }

    private function read(array $query = []): array
    {
        $before = $this->state();
        $queue = Queue::pushedJobs();
        $response = $this->actingAs($this->reader)->get(route('operations.job_board.index', $query))->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('operations/job-board/Index')->has('provenance'));
        $props = $response->viewData('page')['props'];
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, Queue::pushedJobs());
        $this->assertSame((int) $this->reader->id, $props['provenance']['actor_id']);

        return $props;
    }

    private function person(array $keys): User
    {
        $user = User::factory()->create(['role' => 'coordinator', 'approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'job-range-'.Str::uuid(), 'label' => 'Synthetic scoped reader', 'level' => 10, 'type' => 'custom']);
        $user->roles()->attach($role);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
            $user->permissionOverrides()->attach($permission, ['allowed' => true]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function position(string $start, array $values = [], ?Site $site = null): ShiftOpenPosition
    {
        $site ??= $this->site;
        $client = $site->is($this->site) ? $this->client
            : Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $this->context->id]);
        $startsAt = Carbon::parse($start)->utc();
        $shift = Shift::factory()->create(['client_id' => $client->id, 'site_id' => $site->id,
            'service_context_id' => $this->context->id, 'user_id' => null, 'created_by' => $this->reader->id,
            'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(), 'location' => null, 'status' => 'scheduled', 'coverage_roles' => []]);

        return ShiftOpenPosition::create(array_merge(['shift_id' => $shift->id, 'replacement_request_id' => null,
            'status' => 'open', 'required_skills' => [], 'coverage_roles' => [], 'expires_at' => null], $values))->fresh();
    }

    private function allowed(): EligibilityResult
    {
        return new EligibilityResult(is_allowed: true, blocking_reasons: [], warnings: [], checked_rules: [], overrideable_warnings: []);
    }

    private function state(): array
    {
        $tables = ['users', 'roles', 'permission_user', 'hr_employee_profiles', 'sites', 'clients', 'service_contexts', 'shifts', 'shift_tasks',
            'shift_open_positions', 'shift_replacement_requests', 'coverage_reservations', 'timeline_events', 'audit_logs',
            'workforce_eligibility_rechecks', 'workforce_eligibility_observations', 'shift_signals', 'shift_signal_outbox'];
        $out = [];
        foreach ($tables as $table) {
            $query = DB::table($table);
            foreach ($table === 'permission_user' ? ['user_id', 'permission_id'] : ['id'] as $key) {
                $query->orderBy($key);
            }
            $out[$table] = $query->get()->map(fn ($row) => (array) $row)->all();
        }

        return $out;
    }
}
