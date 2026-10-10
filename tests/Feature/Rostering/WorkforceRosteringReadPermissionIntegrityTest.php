<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Rostering\RosteringFeatureFlags;
use App\Domain\Rostering\RosterPeriodService;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\Permission;
use App\Models\RosterPeriod;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\ShiftSeries;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
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

class WorkforceRosteringReadPermissionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $foreignSite;

    private Client $client;

    private Client $foreignClient;

    private ServiceContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RbacSeeder::class);
        Permission::query()->firstOrCreate(['key' => 'rostering.publish'], ['description' => 'Publish Roster']);
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        AppSetting::query()->updateOrCreate(['key' => 'features.rostering.publish'], ['value' => true]);
        app()->forgetInstance(RosteringFeatureFlags::class);
        $this->site = Site::factory()->create(['name' => 'READ TEST own House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->foreignSite = Site::factory()->create(['name' => 'PRIVATE ROSTER Site', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->context = ServiceContext::factory()->create(['name' => 'READ TEST global Context', 'is_active' => true, 'site_id' => null]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
        $this->foreignClient = Client::factory()->create(['first_name' => 'PRIVATE ROSTER Client', 'site_id' => $this->foreignSite->id, 'service_context_id' => $this->context->id]);
    }

    public function test_scoped_view_any_publisher_reads_other_worker_and_existing_period_without_management_grants_or_writes(): void
    {
        $reader = $this->reader();
        $worker = $this->person($this->site, ['shifts.viewAssigned']);
        $row = $this->shift($worker, ['created_by' => $reader->id]);
        $period = $this->period($this->site, $reader);
        $this->assertFalse($reader->canDo('shifts.manageAny'));
        $this->assertFalse($reader->canDo('reports.viewAny'));
        $this->assertFalse($reader->canDo('roster_templates.create'));
        $this->assertNull($row->published_at);
        $this->assertNull($row->roster_period_id);
        $this->assertSame([$row->id], app(RosterPeriodService::class)->shiftsQuery($period)->pluck('id')->all());
        $state = $this->state();
        $queue = $this->queueState();

        $this->actingAs($reader)->get($this->url(['site_id' => [$this->site->id]]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/rostering/index')->where('canManageAny', false)->where('canPublishRoster', true)
            ->where('filters.site_id', $this->site->id)->where('filters.site_ids', [$this->site->id])
            ->has('shifts', 1)->where('shifts.0.id', $row->id)->where('shifts.0.user_id', $worker->id)
            ->where('shifts.0.published_at', null)->where('stats.total', 1)
            ->has('staff', 2)->has('clients', 1)->where('clients.0.id', $this->client->id)
            ->has('sites', 1)->where('sites.0.id', $this->site->id)
            ->where('rosterPeriod.id', $period->id)->where('rosterPeriod.site_id', $this->site->id)
            ->where('rosterPeriod.status', 'draft')->where('rosterPeriod.shift_count', 0));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_scoped_reader_withholds_foreign_or_invalid_whole_duties_and_picker_options(): void
    {
        $reader = $this->reader(false);
        $worker = $this->person($this->site, ['shifts.viewAssigned']);
        $foreignWorker = $this->person($this->foreignSite, ['shifts.viewAssigned']);
        $legacy = $this->shift($worker, ['site_id' => null]);
        $foreign = $this->shift($foreignWorker, ['site_id' => $this->foreignSite->id, 'client_id' => $this->foreignClient->id, 'location' => 'PRIVATE ROSTER foreign duty']);
        $this->shift($worker, ['client_id' => $this->foreignClient->id, 'location' => 'PRIVATE ROSTER mismatched duty']);
        $ended = $this->person($this->site, ['shifts.viewAssigned']);
        $ended->hrEmployeeProfile->update(['is_active' => false]);
        $this->shift($ended, ['location' => 'PRIVATE ROSTER inactive worker']);
        ServiceContext::factory()->create(['name' => 'PRIVATE ROSTER Context', 'is_active' => true, 'site_id' => $this->foreignSite->id]);
        $closedSite = Site::factory()->create(['name' => 'PRIVATE ROSTER inactive Site', 'is_active' => false, 'archived' => false, 'archived_at' => null]);
        $closedClient = Client::factory()->create(['first_name' => 'PRIVATE ROSTER inactive Client', 'site_id' => $closedSite->id]);
        $reader->hrEmployeeProfile->update(['secondary_site_ids' => [$closedSite->id]]);
        $this->shift(null, ['site_id' => $closedSite->id, 'client_id' => $closedClient->id, 'location' => 'PRIVATE ROSTER inactive Site duty']);
        $foreignWorker->update(['name' => 'PRIVATE ROSTER linked claimant']);
        $badSeries = ShiftSeries::create(['client_id' => $this->foreignClient->id, 'site_id' => $this->foreignSite->id,
            'user_id' => $foreignWorker->id, 'service_context_id' => $this->context->id, 'start_date' => '2026-10-26', 'end_date' => '2026-11-02',
            'timezone' => 'Pacific/Auckland', 'by_weekday' => [2], 'starts_time' => '09:00', 'ends_time' => '10:00', 'status' => 'scheduled',
            'location' => 'PRIVATE ROSTER series source']);
        $this->shift($worker, ['shift_series_id' => $badSeries->id, 'location' => 'PRIVATE ROSTER occurrence foreign series']);
        $goodSeries = ShiftSeries::create(['client_id' => $this->client->id, 'site_id' => null, 'user_id' => $worker->id,
            'service_context_id' => $this->context->id, 'start_date' => '2026-10-26', 'end_date' => '2026-11-02', 'timezone' => 'Pacific/Auckland',
            'by_weekday' => [2], 'starts_time' => '09:00', 'ends_time' => '10:00', 'status' => 'scheduled', 'location' => 'READ TEST legacy series']);
        $good = $this->shift($worker, ['shift_series_id' => $goodSeries->id,
            'starts_at' => $legacy->starts_at->copy()->addHours(2), 'ends_at' => $legacy->ends_at->copy()->addHours(2)]);
        $historical = $this->person($this->foreignSite, ['shifts.viewAssigned']);
        $historical->update(['name' => 'READ TEST historical participant']);
        $request = ShiftReplacementRequest::create(['shift_id' => $good->id, 'requested_by' => $historical->id, 'current_staff_id' => $worker->id,
            'status' => 'requested', 'reason' => 'READ TEST lawful source', 'requested_at' => now()]);
        $position = ShiftOpenPosition::create(['shift_id' => $good->id, 'replacement_request_id' => $request->id, 'status' => 'claimed',
            'claimed_by' => $historical->id, 'claimed_at' => now(), 'expires_at' => now()->addDay()]);
        $badRequest = ShiftReplacementRequest::create(['shift_id' => $legacy->id, 'requested_by' => $reader->id, 'current_staff_id' => $worker->id,
            'status' => 'requested', 'reason' => 'READ TEST child source withheld', 'requested_at' => now()]);
        ShiftOpenPosition::create(['shift_id' => $foreign->id, 'replacement_request_id' => $badRequest->id, 'status' => 'claimed',
            'claimed_by' => $foreignWorker->id, 'claimed_at' => now(), 'expires_at' => now()->addDay()]);
        $state = $this->state();
        $queue = $this->queueState();

        $this->actingAs($reader)->get($this->url())->assertOk()->assertDontSee('PRIVATE ROSTER', false)
            ->assertInertia(fn (Assert $page) => $page->component('operations/rostering/index')
                ->has('shifts', 2)->where('shifts.0.id', $legacy->id)->where('shifts.1.id', $good->id)->where('rosterPeriod', null)
                ->has('recurringPatterns', 1)->where('recurringPatterns.0.id', $goodSeries->id)
                ->has('replacementQueue', 2)->where('replacementQueue.0.open_position_id', null)
                ->where('replacementQueue.0.open_position_claimed_by', null)->where('replacementQueue.1.open_position_id', $position->id)
                ->where('replacementQueue.1.requested_by', 'READ TEST historical participant')
                ->where('replacementQueue.1.open_position_claimed_by', 'READ TEST historical participant')
                ->has('clients', 1)->where('clients.0.id', $this->client->id)
                ->has('sites', 1)->where('sites.0.id', $this->site->id)->has('staff', 2));
        $this->get($this->url(['site_id' => [$this->foreignSite->id]]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('shifts', 0)->where('rosterPeriod', null));
        $this->get($this->url(['client_id' => $this->foreignClient->id]))->assertOk()->assertDontSee('PRIVATE ROSTER', false)
            ->assertInertia(fn (Assert $page) => $page->has('shifts', 0));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_read_filters_include_only_selected_current_approved_sites_and_preserve_multi_site_period_null(): void
    {
        $reader = $this->reader();
        $secondSite = Site::factory()->create(['name' => 'READ TEST second House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $secondClient = Client::factory()->create(['site_id' => $secondSite->id, 'service_context_id' => $this->context->id]);
        $reader->hrEmployeeProfile->update(['secondary_site_ids' => [$secondSite->id]]);
        $reader = $reader->fresh();
        $firstWorker = $this->person($this->site, ['shifts.viewAssigned']);
        $secondWorker = $this->person($secondSite, ['shifts.viewAssigned']);
        $first = $this->shift($firstWorker);
        $second = $this->shift($secondWorker, ['site_id' => $secondSite->id, 'client_id' => $secondClient->id, 'starts_at' => $first->starts_at->copy()->addHours(2), 'ends_at' => $first->ends_at->copy()->addHours(2)]);
        $period = $this->period($secondSite, $reader);
        $state = $this->state();
        $queue = $this->queueState();

        $this->actingAs($reader)->get($this->url(['site_id' => [$this->site->id, $secondSite->id]]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('shifts', 2)->where('shifts.0.id', $first->id)->where('shifts.1.id', $second->id)
            ->where('filters.site_ids', [$this->site->id, $secondSite->id])->where('filters.site_id', null)->where('rosterPeriod', null));
        $this->get($this->url(['site_id' => [$secondSite->id]]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('shifts', 1)->where('shifts.0.id', $second->id)->where('rosterPeriod.id', $period->id));
        $this->get($this->url(['staff_id' => $firstWorker->id]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('shifts', 1)->where('shifts.0.id', $first->id));
        $this->get($this->url(['client_id' => $secondClient->id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('shifts', 1)->where('shifts.0.id', $second->id)->where('rosterPeriod.id', $period->id));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_scoped_publication_entry_does_not_create_a_missing_period_on_get(): void
    {
        $reader = $this->reader();
        $worker = $this->person($this->site, ['shifts.viewAssigned']);
        $this->shift($worker);
        $this->assertFalse(RosterPeriod::query()->where('site_id', $this->site->id)->exists());
        $state = $this->state();
        $queue = $this->queueState();

        $this->actingAs($reader)->get($this->url(['site_id' => [$this->site->id]]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canPublishRoster', true)->where('filters.site_id', $this->site->id)->where('rosterPeriod', null)->has('shifts', 1));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_publisher_cannot_project_an_existing_foreign_site_period_or_touch_any_store(): void
    {
        $reader = $this->reader();
        $foreign = $this->period($this->foreignSite, $reader);
        $state = $this->state();
        $queue = $this->queueState();

        $this->actingAs($reader)->get($this->url(['site_id' => [$this->foreignSite->id]]))->assertForbidden()->assertDontSee('PRIVATE ROSTER', false);
        $this->get(route('operations.rostering.periods.review.show', $foreign))->assertForbidden()->assertDontSee('PRIVATE ROSTER', false);
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_view_any_read_does_not_imply_publish_and_owner_only_roster_read_is_preserved(): void
    {
        $reader = $this->reader(false);
        $owner = $this->person($this->site, ['rostering.viewAny', 'shifts.viewAssigned'], 'coordinator');
        $other = $this->person($this->site, ['shifts.viewAssigned']);
        $own = $this->shift($owner);
        $another = $this->shift($other, ['starts_at' => $own->starts_at->copy()->addHours(2), 'ends_at' => $own->ends_at->copy()->addHours(2)]);
        $this->period($this->site, $reader);
        $state = $this->state();
        $queue = $this->queueState();

        $this->actingAs($reader)->get($this->url(['site_id' => [$this->site->id]]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canManageAny', false)->where('canPublishRoster', false)->where('rosterPeriod', null)->has('shifts', 2));
        $this->actingAs($owner)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canManageAny', false)->where('canPublishRoster', false)->has('shifts', 1)->where('shifts.0.id', $own->id)
            ->has('staff', 0)->has('clients', 0)->has('sites', 0));
        $this->assertNotSame($own->id, $another->id);
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_frontline_still_redirects_from_roster_and_does_not_receive_others_unpublished_duties(): void
    {
        $worker = $this->person($this->site, ['shifts.viewAssigned']);
        $other = $this->person($this->site, ['shifts.viewAssigned']);
        $this->shift($other, ['location' => 'PRIVATE ROSTER unpublished']);
        $state = $this->state();
        $queue = $this->queueState();

        $this->actingAs($worker)->get($this->url(['site_id' => [$this->site->id]]))->assertRedirect(route('my-day'))->assertDontSee('PRIVATE ROSTER', false);
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    #[DataProvider('historicalTrendWeeks')]
    public function test_historical_trend_uses_worker_local_half_open_weeks_and_exact_record_counts(string $week): void
    {
        $reader = $this->reader(false);
        $worker = $this->person($this->site, ['shifts.viewAssigned']);
        $selected = Carbon::parse($week, 'Pacific/Auckland');
        $first = $selected->copy()->subWeeks(3);
        foreach (['completed', 'cancelled', 'scheduled', 'completed'] as $i => $status) {
            $start = $first->copy()->addWeeks($i)->utc();
            $this->shift($worker, ['starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), 'status' => $status]);
        }
        // Sunday local belongs to the preceding bucket; Monday local can still be Sunday UTC.
        foreach ([[$selected->copy()->subSecond(), 'completed'], [$first->copy()->subSecond(), 'cancelled'], [$selected->copy()->addWeek(), 'cancelled']] as [$local, $status]) {
            $start = $local->copy()->utc();
            $this->shift($worker, ['starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), 'status' => $status]);
        }
        $expected = [];
        foreach ([[1, 0, 1], [0, 1, 1], [1, 0, 2], [1, 0, 1]] as $i => [$completed, $cancelled, $total]) {
            $expected[] = ['week' => $first->copy()->addWeeks($i)->format('d M'), 'completed' => $completed, 'cancelled' => $cancelled, 'total' => $total];
        }
        $state = $this->state();
        $queue = $this->queueState();
        $this->actingAs($reader)->get($this->url(['tab' => 'analytics', 'week' => $week]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('analytics.historicalTrend', $expected));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public static function historicalTrendWeeks(): array
    {
        return ['NZ daylight starts' => ['2026-09-28'], 'NZ daylight ends' => ['2026-04-06']];
    }

    public function test_historical_trend_preserves_current_scope_and_selected_site_staff_client_filters(): void
    {
        $reader = $this->reader(false);
        $secondSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $secondClient = Client::factory()->create(['site_id' => $secondSite->id, 'service_context_id' => $this->context->id]);
        $otherClient = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
        $reader->hrEmployeeProfile->update(['secondary_site_ids' => [$secondSite->id]]);
        $reader = $reader->fresh();
        $firstWorker = $this->person($this->site, ['shifts.viewAssigned']);
        $firstWorker->hrEmployeeProfile->update(['secondary_site_ids' => [$secondSite->id]]);
        $secondWorker = $this->person($this->site, ['shifts.viewAssigned']);
        $foreignWorker = $this->person($this->foreignSite, ['shifts.viewAssigned']);
        $this->shift($firstWorker, ['status' => 'completed']);
        $this->shift($secondWorker, ['status' => 'cancelled']);
        $this->shift($firstWorker, ['site_id' => $secondSite->id, 'client_id' => $secondClient->id]);
        $this->shift($firstWorker, ['client_id' => $otherClient->id, 'status' => 'completed']);
        $this->shift($foreignWorker, ['site_id' => $this->foreignSite->id, 'client_id' => $this->foreignClient->id, 'status' => 'completed']);
        $this->shift($firstWorker, ['client_id' => $this->foreignClient->id, 'status' => 'completed']);
        $ended = $this->person($this->site, ['shifts.viewAssigned']);
        $ended->hrEmployeeProfile->update(['is_active' => false]);
        $this->shift($ended, ['status' => 'completed']);
        $badSeries = ShiftSeries::create(['client_id' => $this->foreignClient->id, 'site_id' => $this->foreignSite->id,
            'user_id' => $foreignWorker->id, 'service_context_id' => $this->context->id, 'start_date' => '2026-10-26', 'end_date' => '2026-11-02',
            'timezone' => 'Pacific/Auckland', 'by_weekday' => [2], 'starts_time' => '09:00', 'ends_time' => '10:00', 'status' => 'scheduled']);
        $this->shift($firstWorker, ['shift_series_id' => $badSeries->id, 'status' => 'completed']);
        $state = $this->state();
        $queue = $this->queueState();
        foreach ([
            [[], [2, 1, 4]],
            [['site_id' => [$this->site->id]], [2, 1, 3]],
            [['staff_id' => $firstWorker->id], [2, 0, 3]],
            [['client_id' => $this->client->id], [1, 1, 2]],
            [['site_id' => [$secondSite->id], 'staff_id' => $firstWorker->id, 'client_id' => $secondClient->id], [0, 0, 1]],
            [['site_id' => [$this->foreignSite->id]], [0, 0, 0]],
        ] as [$filters, [$completed, $cancelled, $total]]) {
            $expected = array_map(fn ($week) => ['week' => $week, 'completed' => 0, 'cancelled' => 0, 'total' => 0], ['05 Oct', '12 Oct', '19 Oct']);
            $expected[] = ['week' => '26 Oct', 'completed' => $completed, 'cancelled' => $cancelled, 'total' => $total];
            $this->actingAs($reader)->get($this->url(['tab' => 'analytics', ...$filters]))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('analytics.historicalTrend', $expected));
        }
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_historical_trend_keeps_owner_only_and_availability_omission_and_zero_buckets(): void
    {
        $reader = $this->reader(false);
        $owner = $this->person($this->site, ['rostering.viewAny', 'shifts.viewAssigned'], 'coordinator');
        $this->shift($owner);
        $state = $this->state();
        $queue = $this->queueState();
        $this->actingAs($owner)->get($this->url(['tab' => 'analytics']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('analytics.historicalTrend', []));
        $this->actingAs($reader)->get($this->url(['tab' => 'availability']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('analytics.historicalTrend', []));
        $expected = array_map(fn ($week) => ['week' => $week, 'completed' => 0, 'cancelled' => 0, 'total' => 0], ['04 Jan', '11 Jan', '18 Jan', '25 Jan']);
        $this->get($this->url(['tab' => 'analytics', 'week' => '2027-01-25']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('analytics.historicalTrend', $expected));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    #[DataProvider('dailyCoverageReaders')]
    public function test_daily_coverage_and_selected_records_share_the_configured_worker_day(string $timezone, bool $ownerOnly): void
    {
        config(['app.worker_timezone' => $timezone]);
        $reader = $ownerOnly
            ? $this->person($this->site, ['rostering.viewAny', 'shifts.viewAssigned'], 'coordinator')
            : $this->reader(false);
        $worker = $ownerOnly ? $reader : $this->person($this->site, ['shifts.viewAssigned']);
        $rows = [];
        foreach ([['00:15', 'draft', false], ['09:00', 'scheduled', true], ['12:00', 'in_progress', true],
            ['18:00', 'completed', true], ['23:45', 'cancelled', false]] as [$clock, $status, $assigned]) {
            $start = Carbon::parse('2026-10-27 '.$clock, $timezone)->utc();
            $rows[] = $this->shift($assigned ? $worker : null, [
                'created_by' => $reader->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(10),
                'status' => $status,
            ]);
        }
        // The owner-only reader keeps the existing own-record restriction.
        $expectedIds = collect($rows)->filter(fn (Shift $row) => ! $ownerOnly || $row->user_id === $reader->id)->pluck('id')->all();
        $expected = [];
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $i => $day) {
            $expected[] = ['day' => $day, 'date' => Carbon::parse('2026-10-26', $timezone)->addDays($i)->toDateString(),
                'scheduled' => $i === 1 ? ($ownerOnly ? 3 : 5) : 0,
                'filled' => $i === 1 ? 3 : 0, 'open' => $i === 1 && ! $ownerOnly ? 2 : 0];
        }
        $state = $this->state();
        $queue = $this->queueState();
        $this->actingAs($reader)->get($this->url(['tab' => 'analytics']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workerTimezone', $timezone)->where('weekStart', '2026-10-26')->where('weekEnd', '2026-11-02')
                ->where('filters.week', '2026-10-26')->where('shifts', fn ($actual) => collect($actual)->pluck('id')->all() === $expectedIds)
                ->where('stats.total', count($expectedIds))->where('analytics.dailyCoverage', $expected));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public static function dailyCoverageReaders(): array
    {
        return ['NZ scoped reader' => ['Pacific/Auckland', false], 'NZ owner reader' => ['Pacific/Auckland', true],
            'Los Angeles scoped reader' => ['America/Los_Angeles', false], 'Los Angeles owner reader' => ['America/Los_Angeles', true]];
    }

    #[DataProvider('dailyCoverageDstWeeks')]
    public function test_worker_local_week_includes_boundary_overlap_but_daily_counts_follow_only_the_start_day(string $week, int $sundayHours): void
    {
        $reader = $this->reader(false);
        $worker = $this->person($this->site, ['shifts.viewAssigned']);
        $monday = Carbon::parse($week, 'Pacific/Auckland');
        $sunday = $monday->copy()->addDays(6);
        $nextMonday = $monday->copy()->addWeek();
        $this->assertEquals($sundayHours, $sunday->diffInHours($nextMonday));
        $carryIn = $this->shift($worker, ['starts_at' => $monday->copy()->subMinutes(30)->utc(), 'ends_at' => $monday->copy()->addMinutes(30)->utc()]);
        $first = $this->shift($worker, ['starts_at' => $monday->copy()->utc(), 'ends_at' => $monday->copy()->addMinutes(30)->utc()]);
        $dstRows = [];
        foreach (['01:30', '03:30', '23:59:59'] as $clock) {
            $start = Carbon::parse($sunday->toDateString().' '.$clock, 'Pacific/Auckland')->utc();
            $dstRows[] = $this->shift(null, ['created_by' => $reader->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30)]);
        }
        $this->shift($worker, ['starts_at' => $monday->copy()->subHour()->utc(), 'ends_at' => $monday->copy()->utc()]);
        $this->shift($worker, ['starts_at' => $nextMonday->copy()->utc(), 'ends_at' => $nextMonday->copy()->addMinutes(30)->utc()]);
        $expected = [];
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $i => $day) {
            $expected[] = ['day' => $day, 'date' => $monday->copy()->addDays($i)->toDateString(),
                'scheduled' => $i === 0 ? 1 : ($i === 6 ? 3 : 0), 'filled' => $i === 0 ? 1 : 0, 'open' => $i === 6 ? 3 : 0];
        }
        $expectedIds = [$carryIn->id, $first->id, ...collect($dstRows)->pluck('id')->all()];
        $state = $this->state();
        $queue = $this->queueState();
        $this->actingAs($reader)->get($this->url(['tab' => 'analytics', 'week' => $week]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('weekStart', $week)->where('weekEnd', $nextMonday->toDateString())->where('filters.week', $week)
                ->where('shifts', fn ($actual) => collect($actual)->pluck('id')->all() === $expectedIds)
                ->where('stats.total', 5)->where('analytics.dailyCoverage', $expected));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public static function dailyCoverageDstWeeks(): array
    {
        return ['NZ daylight starts' => ['2026-09-21', 23], 'NZ daylight ends' => ['2026-03-30', 25]];
    }

    public function test_worker_local_daily_counts_retain_selected_site_worker_client_and_canonical_scope(): void
    {
        $reader = $this->reader(false);
        $secondSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $secondClient = Client::factory()->create(['site_id' => $secondSite->id, 'service_context_id' => $this->context->id]);
        $otherClient = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
        $reader->hrEmployeeProfile->update(['secondary_site_ids' => [$secondSite->id]]);
        $reader = $reader->fresh();
        $worker = $this->person($this->site, ['shifts.viewAssigned']);
        $worker->hrEmployeeProfile->update(['secondary_site_ids' => [$secondSite->id]]);
        $otherWorker = $this->person($this->site, ['shifts.viewAssigned']);
        $foreignWorker = $this->person($this->foreignSite, ['shifts.viewAssigned']);
        $this->shift($worker);
        $this->shift($otherWorker, ['status' => 'cancelled']);
        $this->shift($worker, ['client_id' => $otherClient->id]);
        $wednesday = Carbon::parse('2026-10-28 09:00', 'Pacific/Auckland')->utc();
        $this->shift($worker, ['site_id' => $secondSite->id, 'client_id' => $secondClient->id, 'starts_at' => $wednesday, 'ends_at' => $wednesday->copy()->addHour()]);
        $thursday = Carbon::parse('2026-10-29 09:00', 'Pacific/Auckland')->utc();
        $this->shift(null, ['created_by' => $reader->id, 'starts_at' => $thursday, 'ends_at' => $thursday->copy()->addHour()]);
        $this->shift($foreignWorker, ['site_id' => $this->foreignSite->id, 'client_id' => $this->foreignClient->id]);
        $this->shift($worker, ['client_id' => $this->foreignClient->id]);
        $state = $this->state();
        $queue = $this->queueState();
        foreach ([[[], [3, 1, 1]], [['site_id' => [$this->site->id]], [3, 0, 1]],
            [['staff_id' => $worker->id], [2, 1, 0]], [['client_id' => $this->client->id], [2, 0, 1]],
            [['site_id' => [$secondSite->id], 'staff_id' => $worker->id, 'client_id' => $secondClient->id], [0, 1, 0]],
            [['site_id' => [$this->foreignSite->id]], [0, 0, 0]]] as [$filters, $counts]) {
            $expected = [];
            foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $i => $day) {
                $count = $i >= 1 && $i <= 3 ? $counts[$i - 1] : 0;
                $expected[] = ['day' => $day, 'date' => Carbon::parse('2026-10-26', 'Pacific/Auckland')->addDays($i)->toDateString(),
                    'scheduled' => $count, 'filled' => $i === 3 ? 0 : $count, 'open' => $i === 3 ? $count : 0];
            }
            $this->actingAs($reader)->get($this->url(['tab' => 'analytics', ...$filters]))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('stats.total', array_sum($counts))->where('analytics.dailyCoverage', $expected));
        }
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_worker_local_week_aligns_existing_period_capacity_coverage_and_leave_windows_without_writes(): void
    {
        $manager = $this->person($this->site, ['rostering.viewAny', 'shifts.manageAny', 'rostering.publish', 'hr.leave.manage'], 'coordinator');
        $worker = $this->person($this->site, ['shifts.viewAssigned']);
        $otherWorker = $this->person($this->site, ['shifts.viewAssigned']);
        $monday = Carbon::parse('2026-10-26', 'Pacific/Auckland');
        $nextMonday = $monday->copy()->addWeek();
        $period = $this->period($this->site, $manager);
        $first = $this->shift($worker, ['starts_at' => $monday->copy()->utc(), 'ends_at' => $monday->copy()->addHour()->utc()]);
        $last = $this->shift($worker, ['starts_at' => $nextMonday->copy()->subHour()->utc(), 'ends_at' => $nextMonday->copy()->addHour()->utc()]);
        $carryIn = $this->shift($otherWorker, ['starts_at' => $monday->copy()->subHour()->utc(), 'ends_at' => $monday->copy()->addMinutes(30)->utc()]);
        $this->shift($worker, ['starts_at' => $nextMonday->copy()->utc(), 'ends_at' => $nextMonday->copy()->addHour()->utc()]);
        $rule = SiteCoverageRequirement::create(['site_id' => $this->site->id, 'name' => 'Local Monday coverage', 'coverage_type' => 'custom',
            'day_of_week' => 'mon', 'starts_time' => '00:00', 'ends_time' => '01:00', 'minimum_staff' => 1,
            'role_requirements' => [], 'allow_overstaffing' => true, 'is_active' => true]);
        $timeOff = StaffTimeOff::create(['user_id' => $worker->id, 'created_by' => $manager->id, 'type' => 'unavailable',
            'starts_at' => $monday->copy()->utc(), 'ends_at' => $monday->copy()->addMinutes(30)->utc()]);
        foreach ([[$monday->copy()->subHour(), $monday], [$nextMonday, $nextMonday->copy()->addHour()]] as [$start, $end]) {
            StaffTimeOff::create(['user_id' => $worker->id, 'created_by' => $manager->id, 'type' => 'unavailable',
                'starts_at' => $start->copy()->utc(), 'ends_at' => $end->copy()->utc()]);
        }
        $approved = HrLeaveRequest::factory()->create(['user_id' => $otherWorker->id, 'created_by' => $manager->id, 'status' => 'approved',
            'starts_at' => $monday->copy()->utc(), 'ends_at' => $monday->copy()->addMinutes(30)->utc()]);
        HrLeaveRequest::factory()->create(['user_id' => $otherWorker->id, 'status' => 'approved',
            'starts_at' => $monday->copy()->subHour()->utc(), 'ends_at' => $monday->copy()->utc()]);
        HrLeaveRequest::factory()->create(['user_id' => $worker->id, 'status' => 'approved',
            'starts_at' => $monday->copy()->addDays(14)->utc(), 'ends_at' => $monday->copy()->addDays(14)->addHour()->utc()]);
        $pending = HrLeaveRequest::factory()->create(['user_id' => $otherWorker->id, 'status' => 'pending',
            'starts_at' => $nextMonday->copy()->subMinutes(30)->utc(), 'ends_at' => $nextMonday->copy()->addMinutes(30)->utc()]);
        $periodIds = app(RosterPeriodService::class)->shiftsQuery($period)->orderBy('starts_at')->pluck('id')->all();
        $this->assertSame([$carryIn->id, $first->id, $last->id], $periodIds);
        $relatedState = static fn () => collect(['staff_time_offs', 'hr_leave_requests', 'site_coverage_requirements'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
        $state = $this->state();
        $related = $relatedState();
        $queue = $this->queueState();
        $this->actingAs($manager)->get($this->url(['tab' => 'analytics', 'site_id' => [$this->site->id]]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('weekStart', '2026-10-26')->where('weekEnd', '2026-11-02')->where('filters.week', '2026-10-26')
                ->where('rosterPeriod.id', $period->id)->where('rosterPeriod.week_start', '2026-10-26')->where('rosterPeriod.week_end', '2026-11-02')
                ->where('shifts', fn ($actual) => collect($actual)->pluck('id')->all() === $periodIds)
                ->where('capacity', fn ($actual) => (float) collect($actual)->keyBy('user_id')->get($worker->id)['hours'] === 2.0
                    && (float) collect($actual)->keyBy('user_id')->get($otherWorker->id)['hours'] === 0.5)
                ->where('coverageSites.0.site_id', $this->site->id)->where('coverageSites.0.total_windows', 1)
                ->where('recurringCoverageAlignment.rule_drift.0.rule_id', $rule->id)
                ->where('recurringCoverageAlignment.rule_drift.0.starts_at', $monday->toIso8601String())
                ->where('recurringCoverageAlignment.rule_drift.0.ends_at', $monday->copy()->addHour()->toIso8601String())
                ->has('timeOffs', 1)->where('timeOffs.0.id', $timeOff->id)->where('analytics.onLeaveCount', 1)
                ->has('approvedLeave', 1)->where('approvedLeave.0.id', $approved->id)->has('pendingLeave', 1)->where('pendingLeave.0.id', $pending->id));
        $this->assertSame($state, $this->state());
        $this->assertSame($related, $relatedState());
        $this->assertSame($queue, $this->queueState());
    }

    private function reader(bool $publish = true): User
    {
        return $this->person($this->site, ['rostering.viewAny', 'shifts.viewAny', 'staff.viewAny', ...($publish ? ['rostering.publish'] : [])], 'coordinator');
    }

    private function person(Site $site, array $keys, string $role = 'support_worker'): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => $role, 'external_clinical_account' => false]);
        $user->permissionOverrides()->sync(collect($keys)->mapWithKeys(fn ($key) => [Permission::query()->where('key', $key)->firstOrFail()->id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null,
            'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user;
    }

    private function shift(?User $worker, array $values = []): Shift
    {
        $attributes = ['client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->context->id, 'user_id' => $worker?->id, 'created_by' => $worker?->id,
            'starts_at' => Carbon::parse('2026-10-27 09:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-10-27 10:00:00', 'Pacific/Auckland')->utc(),
            'status' => 'scheduled', 'published_at' => null, 'roster_period_id' => null, ...$values];
        if ($attributes['status'] === 'completed') {
            $attributes['actual_starts_at'] ??= $attributes['starts_at'];
            $attributes['actual_ends_at'] ??= $attributes['ends_at'];
        }

        return Shift::factory()->create($attributes)->fresh();
    }

    private function period(Site $site, User $creator): RosterPeriod
    {
        return RosterPeriod::factory()->create(['site_id' => $site->id, 'week_start' => '2026-10-26', 'week_end' => '2026-11-02',
            'status' => 'draft', 'version' => 1, 'shift_count' => 0, 'snapshot' => null, 'validation_summary' => null, 'created_by' => $creator->id]);
    }

    private function url(array $filters = []): string
    {
        return route('operations.rostering.index', ['tab' => 'shifts', 'week' => '2026-10-26', ...$filters]);
    }

    private function state(): array
    {
        $tables = ['roster_periods', 'shifts', 'shift_tasks', 'shift_series', 'shift_eligibility_overrides', 'coverage_reservations',
            'shift_replacement_requests', 'shift_open_positions', 'site_checklist_runs', 'audit_logs', 'notifications',
            'workforce_eligibility_rechecks', 'workforce_eligibility_observations', 'timeline_events',
            'shift_handovers', 'shift_notes', 'timesheets', 'hr_time_entries', 'billing_entries', 'shift_signals', 'shift_signal_outbox'];

        return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function queueState(): array
    {
        return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
    }
}
