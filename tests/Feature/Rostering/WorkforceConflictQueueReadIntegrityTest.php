<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Rostering\RosteringFeatureFlags;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\CoverageGapAcknowledgement;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\ShiftSeries;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Services\ShiftCoverageService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkforceConflictQueueReadIntegrityTest extends TestCase
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
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->site = Site::factory()->create(['name' => 'QUEUE own House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->foreignSite = Site::factory()->create(['name' => 'PRIVATE QUEUE Site', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->context = ServiceContext::factory()->create(['name' => 'QUEUE global Context', 'is_active' => true, 'site_id' => null]);
        $this->client = Client::factory()->create(['first_name' => 'QUEUE Client', 'site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
        $this->foreignClient = Client::factory()->create(['first_name' => 'PRIVATE QUEUE Client', 'site_id' => $this->foreignSite->id, 'service_context_id' => $this->context->id]);
    }

    public function test_view_any_reads_scoped_duties_with_canonical_ids_and_withholds_invalid_whole_sources(): void
    {
        $reader = $this->reader();
        $worker = $this->person($this->site);
        $series = $this->series(['site_id' => null]);
        $legacy = $this->shift($worker, ['site_id' => null, 'shift_series_id' => $series->id]);
        $manual = $this->shift(null, ['starts_at' => $legacy->starts_at->copy()->addHours(2), 'ends_at' => $legacy->ends_at->copy()->addHours(2)]);
        $foreign = $this->person($this->foreignSite);
        $this->shift($foreign, ['site_id' => $this->foreignSite->id, 'client_id' => $this->foreignClient->id, 'location' => 'PRIVATE QUEUE foreign duty']);
        $this->shift($worker, ['client_id' => $this->foreignClient->id, 'location' => 'PRIVATE QUEUE broken Client']);
        $ended = $this->person($this->site);
        $ended->hrEmployeeProfile->update(['is_active' => false]);
        $this->shift($ended, ['location' => 'PRIVATE QUEUE ended worker']);
        $badSeries = $this->series(['client_id' => $this->foreignClient->id, 'site_id' => $this->foreignSite->id]);
        $this->shift($worker, ['shift_series_id' => $badSeries->id, 'location' => 'PRIVATE QUEUE foreign series']);
        $this->assertFalse($reader->canDo('shifts.manageAny'));
        $this->assertFalse($reader->canDo('reports.viewAny'));
        $this->grant($reader, ['reports.viewAny']);
        $this->read($reader)->assertDontSee('PRIVATE QUEUE', false)->assertInertia(fn (Assert $page) => $page
            ->component('operations/rostering/conflicts')->where('assessment.visible_duty_count', 2)
            ->where('assessment.categories.coverage_gaps.status', 'not_assessed')->where('assessment.categories.coverage_gaps.finding_count', null)
            ->has('openShifts', 1)->where('openShifts.0.id', $manual->id)->where('openShifts.0.client_id', $this->client->id)
            ->where('openShifts.0.site_id', $this->site->id)->where('openShifts.0.effective_site_id', $this->site->id));
        $other = $this->shift($worker, ['starts_at' => $legacy->starts_at->copy()->addMinutes(15), 'ends_at' => $legacy->ends_at->copy()->addMinutes(15)]);
        $this->read($reader)->assertInertia(fn (Assert $page) => $page->has('staffOverlaps', 1)
            ->where('staffOverlaps.0.first.id', $legacy->id)->where('staffOverlaps.0.second.id', $other->id)
            ->where('staffOverlaps.0.first.shift_series_id', $series->id)->where('staffOverlaps.0.first.site_id', null)->where('staffOverlaps.0.first.effective_site_id', $this->site->id)
            ->where('staffOverlaps.0.first.can.view_shift', true)->where('staffOverlaps.0.first.can.view_client', false)
            ->where('staffOverlaps.0.first.urls.client', null));
    }

    public function test_complete_nested_pairs_are_stable_and_touching_endpoints_or_distinct_clients_are_not_client_overlap(): void
    {
        $reader = $this->reader();
        $worker = $this->person($this->site);
        $outer = $this->shift($worker, ['ends_at' => $this->at('12:00')]);
        $first = $this->shift($worker, ['starts_at' => $this->at('09:15'), 'ends_at' => $this->at('10:00')]);
        $second = $this->shift($worker, ['starts_at' => $this->at('11:00'), 'ends_at' => $this->at('12:00')]);
        $touching = $this->shift($worker, ['starts_at' => $this->at('12:00'), 'ends_at' => $this->at('13:00')]);
        $separateClients = Client::factory()->count(2)->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
        foreach ($separateClients as $client) {
            $this->shift(null, ['client_id' => $client->id]);
        }
        $response = $this->read($reader);
        $staffIds = array_column($response->inertiaProps('staffOverlaps'), 'pair_id');
        $clientIds = array_column($response->inertiaProps('clientOverlaps'), 'pair_id');
        $this->assertSame(['staff_overlap:'.$outer->id.':'.$first->id, 'staff_overlap:'.$outer->id.':'.$second->id], $staffIds);
        $this->assertSame(['client_overlap:'.$outer->id.':'.$first->id, 'client_overlap:'.$outer->id.':'.$second->id], $clientIds);
        $response->assertInertia(fn (Assert $page) => $page->where('assessment.categories.staff_overlaps.finding_count', 2)
            ->where('assessment.categories.client_overlaps.finding_count', 2)->has('tightTurnarounds', 1)
            ->where('tightTurnarounds.0.pair_id', 'tight_turnaround:'.$second->id.':'.$touching->id)
            ->where('tightTurnarounds.0.gap_minutes', fn ($value) => (float) $value === 0.0)->where('assessment.publication_assessed', false)
            ->where('assessment.scan_criteria.turnaround_threshold_minutes', 30)->where('assessment.scan_criteria.automatic_scan', false));
        $this->assertSame($staffIds, array_column($this->read($reader)->inertiaProps('staffOverlaps'), 'pair_id'));
    }

    public function test_all_time_off_pairs_use_recorded_types_and_do_not_expose_reasons_or_assert_approved_leave(): void
    {
        $reader = $this->reader();
        $worker = $this->person($this->site);
        $shift = $this->shift($worker);
        foreach (['training', 'unavailable'] as $type) {
            StaffTimeOff::create(['user_id' => $worker->id, 'starts_at' => $this->at('09:10'), 'ends_at' => $this->at('09:50'), 'type' => $type,
                'label' => 'PRIVATE QUEUE free-text reason', 'notes' => 'PRIVATE QUEUE notes', 'created_by' => $reader->id]);
        }
        $leave = HrLeaveRequest::factory()->create(['user_id' => $worker->id, 'status' => 'pending', 'starts_at' => $this->at('09:00'),
            'ends_at' => $this->at('10:00'), 'reason' => 'PRIVATE QUEUE pending HR reason']);
        $projection = StaffTimeOff::create(['hr_leave_request_id' => $leave->id, 'user_id' => $worker->id, 'starts_at' => $this->at('09:00'),
            'ends_at' => $this->at('10:00'), 'type' => 'leave', 'label' => 'PRIVATE QUEUE sensitive leave', 'created_by' => $reader->id]);
        $response = $this->read($reader)->assertDontSee('PRIVATE QUEUE', false);
        $rows = $response->inertiaProps('timeOffConflicts');
        $this->assertCount(3, $rows);
        $this->assertCount(3, array_unique(array_column($rows, 'pair_id')));
        $this->assertSame(['leave', 'training', 'unavailable'], collect($rows)->pluck('time_off.type')->sort()->values()->all());
        foreach ($rows as $row) {
            $this->assertSame($worker->id, $row['time_off']['user_id']);
            $this->assertNull($row['time_off']['label']);
            $this->assertNull($row['time_off']['hr_leave_request_id']);
            $this->assertNull($row['time_off']['urls']['leave']);
        }
        $this->grant($reader, ['hr.leave.viewAny', 'hr.leave.approve']);
        $visible = collect($this->read($reader)->inertiaProps('timeOffConflicts'))->firstWhere('pair_id', 'time_off:'.$shift->id.':'.$projection->id);
        $this->assertSame($leave->id, $visible['time_off']['hr_leave_request_id']);
        $this->assertTrue($visible['time_off']['can']['view_leave']);
        $this->assertSame(route('hr.leave.index'), $visible['time_off']['urls']['leave']);
        $this->assertArrayNotHasKey('status', $visible['time_off']);
    }

    public function test_replacement_history_is_retained_but_wrong_position_source_is_not_projected(): void
    {
        $reader = $this->reader();
        $worker = $this->person($this->site);
        $historical = $this->person($this->foreignSite);
        $historical->update(['name' => 'QUEUE historical participant']);
        $historical->hrEmployeeProfile->update(['is_active' => false]);
        $good = $this->shift($worker);
        $bad = $this->shift($worker, ['starts_at' => $this->at('11:00'), 'ends_at' => $this->at('12:00')]);
        $foreign = $this->shift($this->person($this->foreignSite), ['site_id' => $this->foreignSite->id, 'client_id' => $this->foreignClient->id]);
        $first = ShiftReplacementRequest::create(['shift_id' => $good->id, 'current_staff_id' => $worker->id, 'requested_by' => $historical->id,
            'status' => 'requested', 'reason' => 'QUEUE replacement', 'requested_at' => now()]);
        $position = ShiftOpenPosition::create(['shift_id' => $good->id, 'replacement_request_id' => $first->id, 'status' => 'claimed',
            'claimed_by' => $historical->id, 'claimed_at' => now(), 'expires_at' => now()->addDay()]);
        $second = ShiftReplacementRequest::create(['shift_id' => $bad->id, 'current_staff_id' => $worker->id, 'requested_by' => $reader->id,
            'status' => 'requested', 'reason' => 'QUEUE second replacement', 'requested_at' => now()]);
        ShiftOpenPosition::create(['shift_id' => $foreign->id, 'replacement_request_id' => $second->id, 'status' => 'claimed',
            'claimed_by' => $foreign->user_id, 'claimed_at' => now(), 'expires_at' => now()->addDay()]);
        $this->read($reader)->assertDontSee('PRIVATE QUEUE', false)->assertInertia(fn (Assert $page) => $page->has('activeReplacements', 2)
            ->where('activeReplacements.0.finding_id', 'replacement:'.$first->id)->where('activeReplacements.0.requested_by', 'QUEUE historical participant')
            ->where('activeReplacements.0.claimed_by', 'QUEUE historical participant')->where('activeReplacements.0.open_position_id', $position->id)
            ->where('activeReplacements.1.open_position_id', null)->where('activeReplacements.1.claimed_by', null));
    }

    #[DataProvider('localWeeks')]
    public function test_half_open_worker_local_week_handles_dst_and_utc_carry_boundaries(string $week): void
    {
        $reader = $this->reader();
        $start = Carbon::parse($week, 'Pacific/Auckland')->startOfDay();
        $end = $start->copy()->addDays(7);
        $inside = $this->shift(null, ['starts_at' => $start->copy()->utc(), 'ends_at' => $start->copy()->addHour()->utc()]);
        $carry = $this->shift(null, ['starts_at' => $start->copy()->subHour()->utc(), 'ends_at' => $start->copy()->addMinutes(30)->utc()]);
        $this->shift(null, ['starts_at' => $start->copy()->subHours(2)->utc(), 'ends_at' => $start->copy()->utc()]);
        $this->shift(null, ['starts_at' => $end->copy()->utc(), 'ends_at' => $end->copy()->addHour()->utc()]);
        $this->read($reader, $week)->assertInertia(fn (Assert $page) => $page->where('weekStart', $week)->where('weekEnd', $end->toDateString())
            ->where('workerTimezone', 'Pacific/Auckland')->has('openShifts', 2)->where('openShifts.0.id', $carry->id)->where('openShifts.1.id', $inside->id)
            ->where('assessment.visible_duty_count', 2));
    }

    public static function localWeeks(): array
    {
        return ['NZ daylight starts' => ['2026-09-21'], 'NZ daylight ends' => ['2026-03-30']];
    }

    public function test_owner_only_lens_retains_publication_visibility_and_no_other_worker_sources(): void
    {
        AppSetting::query()->updateOrCreate(['key' => 'features.rostering.publish'], ['value' => true]);
        app()->forgetInstance(RosteringFeatureFlags::class);
        $owner = $this->person($this->site, ['rostering.viewAny', 'shifts.viewAssigned'], 'coordinator');
        $this->shift($owner, ['published_at' => now()]);
        $this->shift($owner, ['published_at' => now()]);
        $this->shift($owner, ['published_at' => null]);
        $this->shift($this->person($this->site), ['published_at' => now()]);
        $this->read($owner)->assertInertia(fn (Assert $page) => $page->where('assessment.visible_duty_count', 2)->has('staffOverlaps', 1)
            ->where('staffOverlaps.0.staff_id', $owner->id)->where('staffOverlaps.0.first.can.view_shift', true)->has('openShifts', 0));
    }

    #[DataProvider('clientLinks')]
    public function test_client_handoff_requires_actual_route_and_record_policy_separately_from_shift_read(array $keys, bool $assigned, bool $allowed): void
    {
        $reader = $this->reader($keys);
        if ($assigned) {
            $this->client->supportWorkers()->attach($reader->id);
        }
        $this->shift(null);
        $this->read($reader)->assertInertia(fn (Assert $page) => $page->where('openShifts.0.can.view_shift', true)
            ->where('openShifts.0.can.view_client', $allowed)->where('openShifts.0.urls.client', $allowed ? route('operations.clients.show', $this->client) : null)
            ->where('assessment.workflow_urls.workforce_settings', route('operations.workforce.settings')));
    }

    public static function clientLinks(): array
    {
        return ['no Client permission' => [[], false, false], 'unassigned Client reader' => [['clients.viewAssigned'], false, false],
            'assigned Client reader' => [['clients.viewAssigned'], true, true], 'scoped Client viewAny' => [['clients.viewAny'], false, true]];
    }

    public function test_manager_keeps_real_coverage_and_recurring_review_without_unrestricted_nested_details(): void
    {
        $manager = $this->reader(['shifts.manageAny']);
        $manager->hrEmployeeProfile->delete();
        $manager->unsetRelation('hrEmployeeProfile');
        $worker = $this->person($this->site);
        $shift = $this->shift($worker);
        $rule = $this->rule(['minimum_staff' => 2]);
        $pattern = $this->series(['starts_time' => '12:00', 'ends_time' => '13:00', 'by_weekday' => ['wed']]);
        $this->read($manager)->assertInertia(fn (Assert $page) => $page->has('coverageGaps', 1)
            ->where('coverageGaps.0.rule_id', $rule->id)->where('coverageGaps.0.site_id', $this->site->id)
            ->where('coverageGaps.0.required_staff', 2)->where('coverageGaps.0.assigned_staff', 1)->where('coverageGaps.0.missing_staff', 1)
            ->where('coverageGaps.0.source_assessment', 'assessed')->where('coverageGaps.0.contributing_shifts.0.id', $shift->id)
            ->has('recurringCoverageAlignment.orphan_series', 1)->where('recurringCoverageAlignment.orphan_series.0.series_id', $pattern->id));
    }

    public function test_coverage_actions_use_original_window_and_scoped_writer_authority_without_private_acknowledgement_details(): void
    {
        $manager = $this->reader(['shifts.manageAny']);
        $rule = $this->rule(['minimum_staff' => 1, 'preferred_client_id' => $this->client->id]);
        $open = $this->shift(null);
        $row = $this->read($manager)->inertiaProps('coverageGaps.0');
        $this->assertSame($rule->id, $row['rule_id']);
        $this->assertSame($this->client->id, $row['preferred_client_id']);
        $this->assertSame([$open->id], $row['open_shift_ids']);
        $this->assertSame('fill_existing_open_shift', $row['recommended_fill_action']);
        $this->assertSame(['site_id' => $this->site->id, 'coverage_requirement_id' => $rule->id,
            'window_starts_at' => $row['starts_at'], 'window_ends_at' => $row['ends_at']], $row['action_window']);
        $this->assertStringContainsString('+13:00', $row['starts_at']);
        $this->assertSame('site:'.$this->site->id.':rule:'.$rule->id.':start:202610270900:end:202610271000', $row['coverage_window_key']);
        $this->assertTrue($row['can']['acknowledge']);
        $this->assertFalse($row['can']['clear']);
        $this->assertFalse($row['staffing_resolved']);
        $historical = $this->person($this->foreignSite);
        $ack = CoverageGapAcknowledgement::create(['site_id' => $this->site->id, 'coverage_requirement_id' => $rule->id,
            'coverage_window_key' => $row['coverage_window_key'], 'window_starts_at' => Carbon::parse($row['starts_at']),
            'window_ends_at' => Carbon::parse($row['ends_at']), 'state' => CoverageGapAcknowledgement::STATE_ACKED,
            'actor_user_id' => $historical->id, 'reason' => 'PRIVATE QUEUE acknowledgement reason', 'created_at' => now('UTC')]);
        $response = $this->read($manager->fresh())->assertDontSee('PRIVATE QUEUE acknowledgement reason', false);
        $actual = $response->inertiaProps('coverageGaps.0');
        $this->assertSame(['id' => $ack->id, 'state' => 'acked', 'since' => $ack->fresh()->created_at->toISOString()], $actual['acknowledgement']);
        $this->assertSame('assessed', $actual['acknowledgement_assessment']);
        $this->assertTrue($actual['can']['clear']);
        $this->assertSame(1, $actual['missing_staff']);
        $this->assertSame(route('operations.rostering.coverage.clear', ['key' => $row['coverage_window_key']]), $actual['urls']['clear']);
        // Same key but UTC clock storage is not the installed offset-clock match.
        $ack->update(['window_starts_at' => Carbon::parse($row['starts_at'])->utc(), 'window_ends_at' => Carbon::parse($row['ends_at'])->utc()]);
        $mismatch = $this->read($manager->fresh())->inertiaProps('coverageGaps.0');
        $this->assertSame('unavailable', $mismatch['acknowledgement_assessment']);
        $this->assertNull($mismatch['acknowledgement']);
        $this->assertFalse($mismatch['can']['clear']);
        $this->assertFalse($mismatch['can']['acknowledge']);
        $ack->update(['window_starts_at' => Carbon::parse($row['starts_at']), 'window_ends_at' => Carbon::parse($row['ends_at'])]);
        $manager->hrEmployeeProfile->delete();
        $profileless = $this->read($manager->fresh())->inertiaProps('coverageGaps.0');
        $this->assertSame('assessed', $profileless['source_assessment']);
        $this->assertFalse($profileless['can']['acknowledge']);
        $this->assertNull($profileless['urls']['ack']);
        $this->grant($manager, ['reports.viewAny']);
        $bypass = $this->read($manager->fresh())->inertiaProps('coverageGaps.0');
        $this->assertTrue($bypass['can']['acknowledge']);
        $this->assertTrue($bypass['can']['clear']);
        $manager->update(['approved_at' => null]);
        $state = $this->state();
        $queue = $this->queueState();
        $this->actingAs($manager->fresh())->get(route('operations.rostering.conflicts', ['week' => '2026-10-26']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Your account access has been revoked. Contact your administrator if this is unexpected.']);
        $this->assertGuest();
        $this->assertNull(session('success'));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_unsafe_coverage_sources_are_withheld_and_capped_counts_do_not_claim_a_complete_assessment(): void
    {
        $manager = $this->reader(['shifts.manageAny']);
        $worker = $this->person($this->site);
        $this->shift($worker, ['client_id' => $this->foreignClient->id, 'location' => 'PRIVATE QUEUE supply']);
        $rule = $this->rule(['minimum_staff' => 2]);
        $this->rule(['preferred_client_id' => $this->foreignClient->id, 'name' => 'PRIVATE QUEUE malformed rule']);
        $this->series(['client_id' => $this->foreignClient->id, 'site_id' => $this->site->id, 'location' => 'PRIVATE QUEUE malformed series']);
        $response = $this->read($manager)->assertDontSee('PRIVATE QUEUE', false);
        $response->assertInertia(fn (Assert $page) => $page->has('coverageGaps', 1)->where('coverageGaps.0.rule_id', $rule->id)
            ->where('coverageGaps.0.source_assessment', 'not_assessed')->where('coverageGaps.0.assigned_staff', null)
            ->where('coverageGaps.0.missing_staff', null)->has('coverageGaps.0.contributing_shifts', 0)
            ->where('assessment.categories.coverage_gaps.finding_count', null));
        $this->assertArrayNotHasKey('site_clients', $response->inertiaProps('coverageGaps.0'));
        $this->assertArrayNotHasKey('service_context_id', $response->inertiaProps('coverageGaps.0'));
        $this->assertArrayNotHasKey('coverage_slots', $response->inertiaProps('coverageGaps.0'));
        // A separate week has nine actual demand windows; existing service limit is eight.
        for ($i = 0; $i < 9; $i++) {
            $this->rule(['day_of_week' => 'mon', 'starts_time' => sprintf('%02d:00', $i), 'ends_time' => sprintf('%02d:30', $i)]);
        }
        $this->read($manager, '2026-11-02')->assertInertia(fn (Assert $page) => $page->has('coverageGaps', 8)
            ->where('assessment.categories.coverage_gaps.truncated', true)->where('assessment.categories.coverage_gaps.finding_count', null)
            ->where('assessment.categories.coverage_gaps.displayed_count', 8));
    }

    #[DataProvider('hiddenSupplySources')]
    public function test_unsafe_supply_cannot_make_a_filtered_empty_coverage_scan_claim_assessed_zero(string $kind): void
    {
        $manager = $this->reader(['shifts.manageAny']);
        $worker = $this->person($this->site);
        $this->rule(['minimum_staff' => 1]);
        $this->series();
        $values = ['location' => 'PRIVATE QUEUE hidden supply'];
        if ($kind === 'foreign_client') {
            $values['client_id'] = $this->foreignClient->id;
        } else {
            $booking = RespiteBooking::factory()->create(['client_id' => $this->client->id,
                'start_at' => $this->at('08:00'), 'end_at' => $this->at('11:00')]);
            $values['respite_booking_id'] = $booking->id;
        }
        $this->shift($worker, $values);
        // Real service inputs are fully covered, so its finding-only surfaces hide them.
        $service = app(ShiftCoverageService::class);
        $start = Carbon::parse('2026-10-26', 'Pacific/Auckland');
        $end = $start->copy()->addWeek();
        $windows = $service->buildRangeCoverage($start, $end, $this->site->id);
        $this->assertCount(1, $windows);
        $this->assertSame(1, $windows[0]['assigned_staff']);
        $this->assertSame('exact', $windows[0]['coverage_state']);
        $this->assertSame([], $service->buildRecurringAlignment($start, $end, $this->site->id)['rule_drift']);
        $this->read($manager)->assertDontSee('PRIVATE QUEUE hidden supply', false)->assertInertia(fn (Assert $page) => $page
            ->has('coverageGaps', 0)->has('recurringCoverageAlignment.rule_drift', 0)
            ->where('assessment.categories.coverage_gaps.status', 'partially_assessed')
            ->where('assessment.categories.coverage_gaps.finding_count', null)
            ->where('assessment.categories.recurring_alignment.status', 'partially_assessed')
            ->where('assessment.categories.recurring_alignment.finding_count', null));
    }

    public static function hiddenSupplySources(): array
    {
        return ['malformed Client source' => ['foreign_client'], 'nonemployee respite source' => ['respite']];
    }

    public function test_route_denial_and_frontline_redirect_do_not_mutate_records_or_emit_a_success_claim(): void
    {
        $frontline = $this->person($this->site, ['shifts.viewAssigned']);
        $state = $this->state();
        $queue = $this->queueState();
        $this->actingAs($frontline)->get(route('operations.rostering.conflicts'))->assertRedirect(route('my-day'));
        $this->actingAs($frontline)->getJson(route('operations.rostering.conflicts'))->assertForbidden();
        $this->assertNull(session('success'));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_multi_site_scope_keeps_cross_site_worker_pairs_without_name_based_filtering(): void
    {
        $reader = $this->reader();
        $reader->hrEmployeeProfile->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $reader->unsetRelation('hrEmployeeProfile');
        $worker = $this->person($this->site);
        $worker->hrEmployeeProfile->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $first = $this->shift($worker);
        $second = $this->shift($worker, ['site_id' => $this->foreignSite->id, 'client_id' => $this->foreignClient->id]);
        $this->foreignClient->update(['first_name' => $this->client->first_name, 'last_name' => $this->client->last_name]);
        $this->read($reader)->assertInertia(fn (Assert $page) => $page->where('assessment.visible_duty_count', 2)
            ->has('staffOverlaps', 1)->has('clientOverlaps', 0)
            ->where('staffOverlaps.0.pair_id', 'staff_overlap:'.$first->id.':'.$second->id)
            ->where('staffOverlaps.0.first.client_id', $this->client->id)->where('staffOverlaps.0.second.client_id', $this->foreignClient->id)
            ->where('staffOverlaps.0.first.effective_site_id', $this->site->id)->where('staffOverlaps.0.second.effective_site_id', $this->foreignSite->id));
    }

    public function test_empty_worker_zone_uses_valid_utc_application_zone_for_supported_scan_boundaries(): void
    {
        // Fixture writes retain the installed observer's valid configured clock.
        config(['app.worker_timezone' => 'UTC', 'app.timezone' => 'UTC']);
        $reader = $this->reader();
        $inside = $this->shift(null, ['starts_at' => Carbon::parse('2026-10-26 00:00:00', 'UTC'), 'ends_at' => Carbon::parse('2026-10-26 01:00:00', 'UTC')]);
        $this->shift(null, ['starts_at' => Carbon::parse('2026-10-25 23:00:00', 'UTC'), 'ends_at' => Carbon::parse('2026-10-26 00:00:00', 'UTC')]);
        // The actual HTTP read still receives an empty worker zone and valid app zone.
        config(['app.worker_timezone' => '']);
        $this->assertSame('', config('app.worker_timezone'));
        $this->assertSame('UTC', config('app.timezone'));
        $this->read($reader)->assertInertia(fn (Assert $page) => $page->where('workerTimezone', 'UTC')->where('assessment.scan_criteria.worker_timezone', 'UTC')
            ->has('openShifts', 1)->where('openShifts.0.id', $inside->id));
    }

    private function reader(array $keys = []): User
    {
        return $this->person($this->site, ['rostering.viewAny', 'shifts.viewAny', ...$keys], 'coordinator');
    }

    private function person(Site $site, array $keys = ['shifts.viewAssigned'], string $role = 'support_worker'): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now(), 'external_clinical_account' => false]);
        $this->grant($user, $keys);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user;
    }

    private function grant(User $user, array $keys): void
    {
        $user->permissionOverrides()->syncWithoutDetaching(collect($keys)->mapWithKeys(fn ($key) => [Permission::query()->where('key', $key)->firstOrFail()->id => ['allowed' => true]])->all());
        $user->unsetRelation('permissionOverrides');
    }

    private function at(string $clock): Carbon
    {
        return Carbon::parse('2026-10-27 '.$clock, 'Pacific/Auckland')->utc();
    }

    private function shift(?User $worker, array $values = []): Shift
    {
        return Shift::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'user_id' => $worker?->id, 'starts_at' => $this->at('09:00'), 'ends_at' => $this->at('10:00'), 'status' => 'scheduled',
            'published_at' => null, 'roster_period_id' => null, 'respite_booking_id' => null, ...$values])->fresh();
    }

    private function series(array $values = []): ShiftSeries
    {
        return ShiftSeries::create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'user_id' => null,
            'service_context_id' => $this->context->id, 'start_date' => '2026-10-26', 'end_date' => '2026-11-02', 'timezone' => 'Pacific/Auckland',
            'by_weekday' => ['tue'], 'starts_time' => '09:00', 'ends_time' => '10:00', 'status' => 'scheduled', 'shift_type' => 'standard', ...$values]);
    }

    private function rule(array $values = []): SiteCoverageRequirement
    {
        return SiteCoverageRequirement::create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'preferred_client_id' => null, 'name' => 'QUEUE demand', 'coverage_type' => 'custom', 'day_of_week' => 'tue',
            'starts_time' => '09:00', 'ends_time' => '10:00', 'minimum_staff' => 1, 'role_requirements' => [],
            'allow_overstaffing' => false, 'shift_type' => 'standard', 'is_active' => true, ...$values]);
    }

    private function read(User $user, string $week = '2026-10-26')
    {
        $state = $this->state();
        $queue = $this->queueState();
        $response = $this->actingAs($user)->get(route('operations.rostering.conflicts', ['week' => $week]))->assertOk();
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());

        return $response;
    }

    private function state(): array
    {
        $tables = ['roster_periods', 'shifts', 'shift_tasks', 'shift_series', 'shift_eligibility_overrides', 'coverage_reservations',
            'shift_replacement_requests', 'shift_open_positions', 'site_checklist_runs', 'audit_logs', 'notifications',
            'workforce_eligibility_rechecks', 'workforce_eligibility_observations', 'timeline_events', 'shift_handovers', 'shift_notes',
            'timesheets', 'hr_time_entries', 'billing_entries', 'shift_signals', 'shift_signal_outbox', 'staff_time_offs', 'hr_leave_requests',
            'site_coverage_requirements', 'coverage_gap_acknowledgements', 'respite_bookings'];

        return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function queueState(): array
    {
        return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
    }
}
