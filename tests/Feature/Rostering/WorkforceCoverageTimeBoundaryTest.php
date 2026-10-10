<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Events\CoverageSupplyAdded;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Client;
use App\Models\CoverageReservation;
use App\Models\Shift;
use App\Models\ShiftSeries;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\User;
use App\Services\CoverageReservationService;
use App\Services\ShiftCoverageService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Weekly demand is worker-local; DATETIME storage is UTC. One organisation, approved Site ownership. */
class WorkforceCoverageTimeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(Carbon::parse('2026-10-08 04:00:00', 'UTC'));
        // Keep real model/source observations; delivery of this unrelated job is outside this boundary.
        Queue::fake([RefreshWorkforceEligibility::class]);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null]);
        $this->actor = $this->person($this->site);
    }

    public static function workerZones(): array
    {
        return [
            'configured worker' => ['Pacific/Auckland', 'UTC', '2026-10-11T19:00:00Z', '2026-10-11T23:00:00Z', '2026-10-12T08:00:00+13:00'],
            'null worker uses app' => [null, 'Pacific/Auckland', '2026-10-11T19:00:00Z', '2026-10-11T23:00:00Z', '2026-10-12T08:00:00+13:00'],
            'empty worker uses app' => ['', 'Pacific/Auckland', '2026-10-11T19:00:00Z', '2026-10-11T23:00:00Z', '2026-10-12T08:00:00+13:00'],
            'explicit UTC retained' => ['UTC', 'Pacific/Auckland', '2026-10-12T08:00:00Z', '2026-10-12T12:00:00Z', '2026-10-12T08:00:00+00:00'],
        ];
    }

    #[DataProvider('workerZones')]
    public function test_calendar_uses_worker_zone_or_existing_app_fallback_without_mutating_callers(?string $worker, string $app, string $start, string $end, string $expectedStart): void
    {
        config(['app.worker_timezone' => $worker, 'app.timezone' => $app]);
        $rule = $this->rule();
        $start = Carbon::parse($start);
        $end = CarbonImmutable::parse($end);
        $before = [$start->toIso8601String(), $end->toIso8601String(), $rule->fresh()->getRawOriginal()];
        $windows = app(ShiftCoverageService::class)->buildRangeCoverage($start, $end, $this->site->id);
        $this->assertCount(1, $windows);
        $this->assertSame($rule->id, $windows[0]['rule_id']);
        $this->assertSame($expectedStart, $windows[0]['starts_at']);
        $this->assertSame(1, $windows[0]['missing_staff']);
        $this->assertSame($before, [$start->toIso8601String(), $end->toIso8601String(), $rule->fresh()->getRawOriginal()]);
    }

    public function test_utc_and_offset_callers_read_the_same_stored_duty_and_exact_window(): void
    {
        $rule = $this->rule();
        $shift = $this->duty('2026-10-12 08:00', '2026-10-12 12:00');
        $before = [$rule->fresh()->getRawOriginal(), $shift->fresh()->getRawOriginal()];
        $service = app(ShiftCoverageService::class);
        $utc = $service->buildRangeCoverage(Carbon::parse('2026-10-11T19:00:00Z'), Carbon::parse('2026-10-11T23:00:00Z'), $this->site->id);
        $local = $service->buildRangeCoverage(Carbon::parse('2026-10-12T08:00:00+13:00'), Carbon::parse('2026-10-12T12:00:00+13:00'), $this->site->id);
        $this->assertCount(1, $utc);
        $this->assertSame($utc, $local);
        $this->assertSame('2026-10-12T08:00:00+13:00', $utc[0]['starts_at']);
        $this->assertSame('2026-10-12T12:00:00+13:00', $utc[0]['ends_at']);
        $this->assertSame(1, $utc[0]['assigned_staff']);
        $this->assertSame(0, $utc[0]['missing_staff']);
        $this->assertSame([$shift->id], array_column($utc[0]['contributing_shifts'], 'id'));
        $this->assertSame($before, [$rule->fresh()->getRawOriginal(), $shift->fresh()->getRawOriginal()]);
    }

    public function test_series_occurrence_counts_query_exact_utc_instants_while_rule_and_series_dates_remain_local(): void
    {
        $this->rule();
        $series = ShiftSeries::create(['site_id' => $this->site->id, 'client_id' => $this->client->id,
            'service_context_id' => null, 'user_id' => null, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
            'timezone' => 'Pacific/Auckland', 'by_weekday' => ['mon'], 'starts_time' => '08:00', 'ends_time' => '12:00',
            'status' => 'scheduled', 'coverage_roles' => [], 'created_by' => $this->actor->id]);
        $open = $this->duty('2026-10-12 08:00', '2026-10-12 12:00', ['user_id' => null, 'shift_series_id' => $series->id]);
        $before = [$series->fresh()->getRawOriginal(), $open->fresh()->getRawOriginal()];
        $windows = $this->windows('2026-10-12 08:00', '2026-10-12 12:00');
        $this->assertCount(1, $windows);
        $this->assertCount(1, $windows[0]['matching_series']);
        $matching = $windows[0]['matching_series'][0];
        $this->assertSame($series->id, $matching['id']);
        $this->assertSame(1, $matching['active_occurrences_count']);
        $this->assertSame(1, $matching['open_occurrences_count']);
        $this->assertTrue(Carbon::parse($matching['next_starts_at'])->equalTo(Carbon::parse('2026-10-11T19:00:00Z')));
        $this->assertSame([$open->id], $windows[0]['open_shift_ids']);
        $this->assertSame(1, $windows[0]['planned_staff']);
        $this->assertSame($before, [$series->fresh()->getRawOriginal(), $open->fresh()->getRawOriginal()]);
    }

    public static function overnightSupply(): array
    {
        return ['assigned' => [false, 1, 0, 0], 'open planned' => [true, 0, 1, 1]];
    }

    #[DataProvider('overnightSupply')]
    public function test_previous_local_day_overnight_demand_carries_into_midnight_without_new_supply_rules(bool $open, int $assigned, int $plannedOpen, int $missing): void
    {
        $rule = $this->rule(['starts_time' => '22:00', 'ends_time' => '07:00']);
        $shift = $this->duty('2026-10-13 00:00', '2026-10-13 07:00', ['user_id' => $open ? null : $this->actor->id]);
        $before = [$rule->fresh()->getRawOriginal(), $shift->fresh()->getRawOriginal()];
        $windows = $this->windows('2026-10-13 00:00', '2026-10-13 08:00');
        $this->assertCount(1, $windows);
        $this->assertSame('2026-10-13T00:00:00+13:00', $windows[0]['starts_at']);
        $this->assertSame('2026-10-13T07:00:00+13:00', $windows[0]['ends_at']);
        $this->assertSame($assigned, $windows[0]['assigned_staff']);
        $this->assertSame($plannedOpen, $windows[0]['open_shifts']);
        $this->assertSame($missing, $windows[0]['missing_staff']);
        $this->assertSame([$shift->id], array_column($windows[0]['contributing_shifts'], 'id'));
        $this->assertSame($before, [$rule->fresh()->getRawOriginal(), $shift->fresh()->getRawOriginal()]);
    }

    public static function overnightEndpoints(): array
    {
        return ['ends exactly at range start' => ['2026-10-13 07:00', '2026-10-13 08:00'],
            'starts exactly at range end' => ['2026-10-12 21:00', '2026-10-12 22:00']];
    }

    #[DataProvider('overnightEndpoints')]
    public function test_exact_overnight_endpoints_do_not_create_overlapping_demand(string $start, string $end): void
    {
        $this->rule(['starts_time' => '22:00', 'ends_time' => '07:00']);
        $this->assertSame([], $this->windows($start, $end));
    }

    public function test_sunday_carry_in_is_present_at_monday_week_start_without_an_extra_terminal_window(): void
    {
        $this->rule(['day_of_week' => 'sun', 'starts_time' => '22:00', 'ends_time' => '07:00']);
        $windows = $this->windows('2026-10-12 00:00', '2026-10-19 00:00');
        $this->assertCount(2, $windows);
        $this->assertSame('2026-10-12T00:00:00+13:00', $windows[0]['starts_at']);
        $this->assertSame('2026-10-12T07:00:00+13:00', $windows[0]['ends_at']);
        $this->assertSame('2026-10-18T22:00:00+13:00', $windows[1]['starts_at']);
        $this->assertSame('2026-10-19T00:00:00+13:00', $windows[1]['ends_at']);
    }

    public static function dstWindows(): array
    {
        return [
            'spring skips one hour' => ['2026-09-27 00:00', '2026-09-27 04:00', '2026-09-27T00:00:00+12:00', '2026-09-27T04:00:00+13:00', '2026-09-26 12:00:00', '2026-09-26 15:00:00', 10800],
            'autumn repeats one hour' => ['2026-04-05 00:00', '2026-04-05 04:00', '2026-04-05T00:00:00+13:00', '2026-04-05T04:00:00+12:00', '2026-04-04 11:00:00', '2026-04-04 16:00:00', 18000],
        ];
    }

    #[DataProvider('dstWindows')]
    public function test_dst_carry_in_keeps_real_instants_in_calendar_and_hold_storage(string $start, string $end, string $displayStart, string $displayEnd, string $rawStart, string $rawEnd, int $seconds): void
    {
        $rule = $this->rule(['day_of_week' => 'sat', 'starts_time' => '22:00', 'ends_time' => '04:00']);
        $windows = $this->windows($start, $end);
        $this->assertCount(1, $windows);
        $this->assertSame($displayStart, $windows[0]['starts_at']);
        $this->assertSame($displayEnd, $windows[0]['ends_at']);
        $this->assertSame($seconds, Carbon::parse($windows[0]['ends_at'])->getTimestamp() - Carbon::parse($windows[0]['starts_at'])->getTimestamp());
        $hold = app(CoverageReservationService::class)->createQuickFillReservation($this->actor, $this->site->id,
            Carbon::parse($displayStart), Carbon::parse($displayEnd), $rule->id);
        $this->assertRawWindow($hold, $rawStart, $rawEnd);
        $this->assertTrue($hold->fresh()->expires_at->equalTo(now()->addMinutes(10)));
        $this->assertTrue($hold->fresh()->window_starts_at->equalTo(Carbon::parse($displayStart)));
        $this->assertTrue($hold->fresh()->window_ends_at->equalTo(Carbon::parse($displayEnd)));
    }

    public static function payloadRepresentations(): array
    {
        return ['UTC ISO' => ['utc'], 'offset ISO' => ['offset'], 'mutable Carbon' => ['mutable'],
            'immutable Carbon' => ['immutable'], 'bare scalar preserves app parsing' => ['bare']];
    }

    #[DataProvider('payloadRepresentations')]
    public function test_automatic_hold_uses_identical_utc_instants_for_string_and_carbon_inputs(string $representation): void
    {
        $rule = $this->rule();
        [$start, $end] = $this->inputs($representation);
        $before = [$this->inputValue($start), $this->inputValue($end), $rule->fresh()->getRawOriginal()];
        $service = app(CoverageReservationService::class);
        $hold = $service->reserveForCoveragePayload($this->actor, ['site_id' => $this->site->id,
            'coverage_rule_id' => $rule->id, 'starts_at' => $start, 'ends_at' => $end], 'shift_store');
        $this->assertNotNull($hold);
        $this->assertRawWindow($hold, '2026-10-11 19:00:00', '2026-10-11 23:00:00');
        $this->assertSame($rule->id, $hold->coverage_requirement_id);
        $this->assertSame($this->actor->id, $hold->reserved_by_user_id);
        $this->assertSame('shift_store', $hold->reason);
        $this->assertTrue($hold->fresh()->expires_at->equalTo(now()->addMinutes(5)));
        $this->assertSame($before, [$this->inputValue($start), $this->inputValue($end), $rule->fresh()->getRawOriginal()]);
        $repeat = $service->reserveForCoveragePayload($this->actor, ['site_id' => $this->site->id,
            'coverage_rule_id' => $rule->id, 'starts_at' => '2026-10-11T19:00:00Z', 'ends_at' => '2026-10-11T23:00:00Z'], 'shift_store');
        $this->assertSame($hold->id, $repeat->id);
        $this->assertSame($hold->reservation_token, $repeat->reservation_token);
        $this->assertSame(1, CoverageReservation::query()->count());
        $this->assertRawWindow($repeat, '2026-10-11 19:00:00', '2026-10-11 23:00:00');
    }

    public function test_quick_fill_deduplication_token_context_and_expiry_keep_existing_ownership_rules(): void
    {
        $rule = $this->rule();
        $service = app(CoverageReservationService::class);
        [$start, $end] = $this->inputs('mutable');
        $before = [$start->toIso8601String(), $end->toIso8601String()];
        $hold = $service->createQuickFillReservation($this->actor, $this->site->id, $start, $end, $rule->id, null, ['source' => 'recorded gap'], 7);
        $repeat = $service->createQuickFillReservation($this->actor, $this->site->id,
            CarbonImmutable::parse('2026-10-11T19:00:00Z'), CarbonImmutable::parse('2026-10-11T23:00:00Z'), $rule->id, null, ['reviewed' => true], 9);
        $this->assertSame($hold->id, $repeat->id);
        $this->assertSame($hold->reservation_token, $repeat->reservation_token);
        $this->assertSame(1, CoverageReservation::query()->count());
        $this->assertRawWindow($repeat, '2026-10-11 19:00:00', '2026-10-11 23:00:00');
        $this->assertTrue($repeat->fresh()->expires_at->equalTo(now()->addMinutes(9)));
        $this->assertSame('recorded gap', $repeat->meta['source']);
        $this->assertTrue($repeat->meta['reviewed']);
        $this->assertSame($before, [$start->toIso8601String(), $end->toIso8601String()]);
        $context = ['site_id' => $this->site->id, 'coverage_requirement_id' => $rule->id,
            'window_starts_at' => $start, 'window_ends_at' => CarbonImmutable::parse('2026-10-12T12:00:00+13:00')];
        $this->assertSame($hold->id, $service->validateToken($hold->reservation_token, $this->actor, $context)->id);
        $other = $this->person($this->site);
        $this->assertValidation(fn () => $service->validateToken($hold->reservation_token, $other, $context), 'coverage_reservation_token');
        $this->assertValidation(fn () => $service->validateToken($hold->reservation_token, $this->actor,
            array_replace($context, ['window_ends_at' => '2026-10-11T23:00:01Z'])), 'coverage_reservation_token');
        $this->travelTo(now()->addMinutes(9));
        $this->assertValidation(fn () => $service->validateToken($hold->reservation_token, $this->actor, $context), 'coverage_reservation_token');
        $this->assertSame(CoverageReservationService::STATUS_EXPIRED, $hold->fresh()->status);
    }

    public function test_offset_utc_hold_blocks_another_actor_and_calendar_reports_the_same_reserved_slot(): void
    {
        $rule = $this->rule();
        $service = app(CoverageReservationService::class);
        $hold = $service->createQuickFillReservation($this->actor, $this->site->id,
            Carbon::parse('2026-10-12T08:00:00+13:00'), Carbon::parse('2026-10-12T12:00:00+13:00'), $rule->id);
        $other = $this->person($this->site);
        $before = $hold->fresh()->getRawOriginal();
        $this->assertValidation(fn () => $service->createQuickFillReservation($other, $this->site->id,
            Carbon::parse('2026-10-11T19:00:00Z'), Carbon::parse('2026-10-11T23:00:00Z'), $rule->id), 'coverage');
        $this->assertSame($before, $hold->fresh()->getRawOriginal());
        $this->assertSame(1, CoverageReservation::query()->count());
        $windows = $this->windows('2026-10-12 08:00', '2026-10-12 12:00');
        $this->assertCount(1, $windows);
        $this->assertSame('reserved', collect($windows[0]['coverage_slots'])->firstWhere('kind', 'headcount')['status']);
        $service->release($hold);
        $this->assertSame(CoverageReservationService::STATUS_RELEASED, $hold->fresh()->status);
        $this->assertTrue($hold->fresh()->expires_at->equalTo(now()));
        $newHold = $service->createQuickFillReservation($other, $this->site->id,
            Carbon::parse('2026-10-11T19:00:00Z'), Carbon::parse('2026-10-11T23:00:00Z'), $rule->id);
        $this->assertNotSame($hold->id, $newHold->id);
        $this->assertRawWindow($newHold, '2026-10-11 19:00:00', '2026-10-11 23:00:00');
    }

    public function test_assignment_fulfillment_and_release_by_shift_keep_the_same_exact_utc_slice(): void
    {
        $rule = $this->rule(['minimum_staff' => 2]);
        $shift = $this->duty('2026-10-12 08:00', '2026-10-12 12:00');
        $before = [$rule->fresh()->getRawOriginal(), $shift->fresh()->getRawOriginal()];
        $service = app(CoverageReservationService::class);
        $hold = $service->reserveForAssignment($shift, $this->actor);
        $this->assertNotNull($hold);
        $this->assertSame($shift->id, $hold->shift_id);
        $this->assertSame($rule->id, $hold->coverage_requirement_id);
        $this->assertRawWindow($hold, '2026-10-11 19:00:00', '2026-10-11 23:00:00');
        $this->assertTrue($hold->fresh()->expires_at->equalTo(now()->addMinutes(5)));
        Event::fake([CoverageSupplyAdded::class]);
        $service->fulfill($hold, $shift);
        $this->assertSame(CoverageReservationService::STATUS_FULFILLED, $hold->fresh()->status);
        $this->assertSame($shift->id, $hold->fresh()->shift_id);
        Event::assertDispatched(CoverageSupplyAdded::class, fn (CoverageSupplyAdded $event) => $event->shiftId === $shift->id
            && $event->windowStartsAt === '2026-10-11T19:00:00+00:00' && $event->windowEndsAt === '2026-10-11T23:00:00+00:00');
        // An active unbound hold uses the existing exact-window release fallback, not a new linkage policy.
        $active = CoverageReservation::create(['site_id' => $this->site->id, 'coverage_requirement_id' => $rule->id,
            'reserved_by_user_id' => $this->actor->id, 'reservation_token' => 'release-window-proof', 'status' => 'active',
            'reason' => 'quick_fill', 'window_starts_at' => '2026-10-11 19:00:00', 'window_ends_at' => '2026-10-11 23:00:00',
            'expires_at' => now()->addMinutes(5)]);
        $offsetShift = clone $shift;
        $offsetShift->setRawAttributes(array_replace($shift->getRawOriginal(), [
            'starts_at' => Carbon::parse('2026-10-12T08:00:00+13:00'), 'ends_at' => CarbonImmutable::parse('2026-10-12T12:00:00+13:00'),
        ]));
        $service->releaseForShift($offsetShift);
        $this->assertSame(CoverageReservationService::STATUS_RELEASED, $active->fresh()->status);
        $this->assertTrue($active->fresh()->expires_at->equalTo(now()));
        $this->assertSame(CoverageReservationService::STATUS_FULFILLED, $hold->fresh()->status);
        $this->assertSame($before, [$rule->fresh()->getRawOriginal(), $shift->fresh()->getRawOriginal()]);
    }

    public function test_same_calendar_fix_does_not_broaden_actor_site_access_or_requirement_ownership(): void
    {
        $rule = $this->rule();
        $foreign = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $foreignActor = $this->person($foreign);
        $service = app(CoverageReservationService::class);
        try {
            $service->createQuickFillReservation($foreignActor, $this->site->id,
                Carbon::parse('2026-10-11T19:00:00Z'), Carbon::parse('2026-10-11T23:00:00Z'), $rule->id);
            $this->fail('Foreign Site actor must be denied.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $foreignRule = $this->rule(['site_id' => $foreign->id, 'name' => 'Hidden recorded demand']);
        try {
            $service->createQuickFillReservation($this->actor, $this->site->id,
                Carbon::parse('2026-10-11T19:00:00Z'), Carbon::parse('2026-10-11T23:00:00Z'), $foreignRule->id);
            $this->fail('Foreign requirement must be denied.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $windows = $this->windows('2026-10-12 08:00', '2026-10-12 12:00');
        $this->assertSame([$rule->id], array_column($windows, 'rule_id'));
        $this->assertSame([$this->site->id], array_column($windows, 'site_id'));
        $this->assertStringNotContainsString('Hidden recorded demand', json_encode($windows));
        $this->assertSame(0, CoverageReservation::query()->count());
    }

    public function test_no_gap_and_missing_payload_preserve_existing_null_outcomes(): void
    {
        $this->rule();
        $this->duty('2026-10-12 08:00', '2026-10-12 12:00');
        $service = app(CoverageReservationService::class);
        $this->assertNull($service->reserveForCoveragePayload($this->actor, ['site_id' => $this->site->id,
            'starts_at' => '2026-10-12T08:00:00+13:00', 'ends_at' => '2026-10-12T12:00:00+13:00']));
        $this->assertNull($service->reserveForCoveragePayload($this->actor, ['site_id' => $this->site->id]));
        $this->assertSame(0, CoverageReservation::query()->count());
    }

    public function test_role_slot_queries_preserve_the_existing_role_shortage_and_actor_deduplication(): void
    {
        $rule = $this->rule(['role_requirements' => ['driver' => 1]]);
        $service = app(CoverageReservationService::class);
        $payload = ['site_id' => $this->site->id, 'coverage_rule_id' => $rule->id,
            'starts_at' => '2026-10-12T08:00:00+13:00', 'ends_at' => '2026-10-12T12:00:00+13:00', 'role_key' => 'driver'];
        $hold = $service->reserveForCoveragePayload($this->actor, $payload);
        $this->assertNotNull($hold);
        $this->assertSame('driver', $hold->role_key);
        $this->assertRawWindow($hold, '2026-10-11 19:00:00', '2026-10-11 23:00:00');
        $repeat = $service->reserveForCoveragePayload($this->actor,
            array_replace($payload, ['starts_at' => '2026-10-11T19:00:00Z', 'ends_at' => '2026-10-11T23:00:00Z']));
        $this->assertSame($hold->id, $repeat->id);
        $other = $this->person($this->site);
        $this->assertValidation(fn () => $service->reserveForCoveragePayload($other, $payload), 'coverage');
        $this->assertSame(1, CoverageReservation::query()->count());
    }

    private function rule(array $overrides = []): SiteCoverageRequirement
    {
        return SiteCoverageRequirement::create(array_replace(['site_id' => $this->site->id, 'service_context_id' => null,
            'name' => 'Recorded coverage time boundary', 'coverage_type' => 'custom', 'day_of_week' => 'mon',
            'starts_time' => '08:00', 'ends_time' => '12:00', 'minimum_staff' => 1, 'role_requirements' => [],
            'allow_overstaffing' => true, 'is_active' => true], $overrides));
    }

    private function person(Site $site): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => '2025-01-01', 'end_date' => null, 'is_active' => true, 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function duty(string $start, string $end, array $overrides = []): Shift
    {
        return Shift::factory()->create(array_replace(['site_id' => $this->site->id, 'client_id' => $this->client->id,
            'service_context_id' => null, 'user_id' => $this->actor->id, 'created_by' => $this->actor->id,
            'starts_at' => Carbon::parse($start, 'Pacific/Auckland')->utc(), 'ends_at' => Carbon::parse($end, 'Pacific/Auckland')->utc(),
            'status' => 'scheduled', 'coverage_roles' => []], $overrides))->fresh();
    }

    private function windows(string $start, string $end): array
    {
        return app(ShiftCoverageService::class)->buildRangeCoverage(Carbon::parse($start, 'Pacific/Auckland')->utc(),
            Carbon::parse($end, 'Pacific/Auckland')->utc(), $this->site->id);
    }

    private function inputs(string $representation): array
    {
        return match ($representation) {
            'utc' => ['2026-10-11T19:00:00Z', '2026-10-11T23:00:00Z'],
            'offset' => ['2026-10-12T08:00:00+13:00', '2026-10-12T12:00:00+13:00'],
            'mutable' => [Carbon::parse('2026-10-12T08:00:00+13:00'), Carbon::parse('2026-10-12T12:00:00+13:00')],
            'immutable' => [CarbonImmutable::parse('2026-10-12T08:00:00+13:00'), CarbonImmutable::parse('2026-10-12T12:00:00+13:00')],
            'bare' => ['2026-10-11 19:00:00', '2026-10-11 23:00:00'],
        };
    }

    private function inputValue(mixed $value): string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : (string) $value;
    }

    private function assertRawWindow(CoverageReservation $hold, string $start, string $end): void
    {
        $stored = DB::table('coverage_reservations')->where('id', $hold->id)->first();
        $this->assertSame($start, $stored->window_starts_at);
        $this->assertSame($end, $stored->window_ends_at);
    }

    private function assertValidation(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected the existing reservation validation denial.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }
}
