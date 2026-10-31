<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Rostering\RosteringFeatureFlags;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftSeries;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\User;
use App\Services\ShiftCoverageService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkforceCalendarReadIntegrityTest extends TestCase
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
        AppSetting::query()->updateOrCreate(['key' => 'features.rostering.publish'], ['value' => true]);
        app()->forgetInstance(RosteringFeatureFlags::class);
        $this->site = Site::factory()->create(['name' => 'Calendar allowed House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->foreignSite = Site::factory()->create(['name' => 'PRIVATE CALENDAR House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->context = ServiceContext::factory()->create(['name' => 'Calendar global Context', 'site_id' => null, 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
        $this->foreignClient = Client::factory()->create(['first_name' => 'PRIVATE CALENDAR Client', 'site_id' => $this->foreignSite->id, 'service_context_id' => $this->context->id]);
    }

    public function test_scoped_coordinator_reads_unpublished_coworker_without_management_or_foreign_detail(): void
    {
        $actor = $this->person($this->site, ['rostering.viewAny', 'shifts.viewAny']);
        $worker = $this->person($this->site);
        $row = $this->shift($worker);
        $foreignWorker = $this->person($this->foreignSite);
        $this->shift($foreignWorker, ['site_id' => $this->foreignSite->id, 'client_id' => $this->foreignClient->id, 'notes' => 'PRIVATE CALENDAR note']);
        $this->assertFalse($actor->canDo('shifts.manageAny'));
        $this->assertFalse($actor->canDo('reports.viewAny'));
        $events = $this->read($actor);
        $this->assertSame([$row->id], $this->shiftIds($events));
        $this->assertStringNotContainsString('PRIVATE CALENDAR', json_encode($events));
        $meta = $this->assessment($events);
        $this->assertSame('approved_sites', $meta['scope']['kind']);
        $this->assertSame([$this->site->id], $meta['scope']['site_ids']);
        $this->assertSame(['status' => 'not_assessed', 'reason' => 'permission', 'finding_count' => null], $meta['coverage']);
    }

    public function test_frontline_retains_own_published_read_and_ignores_other_worker_client_display_filters(): void
    {
        $actor = $this->person($this->site, ['rostering.viewAny']);
        $other = $this->person($this->site);
        $published = $this->shift($actor, ['published_at' => now()]);
        $this->shift($actor);
        $this->shift($other, ['published_at' => now()]);
        $events = $this->read($actor, ['staff_id' => $other->id, 'client_id' => $this->foreignClient->id]);
        $this->assertSame([$published->id], $this->shiftIds($events));
        $meta = $this->assessment($events);
        $this->assertSame(['staff_id' => $other->id, 'client_id' => $this->foreignClient->id, 'site_ids' => null], $meta['filters']);
        $this->assertSame(['kind' => 'own', 'site_ids' => [$this->site->id], 'published_only' => true, 'staff_id' => $actor->id, 'client_id' => null], $meta['scope']);
    }

    public function test_publication_disabled_keeps_legacy_own_unpublished_visibility_and_honest_scope_flag(): void
    {
        $actor = $this->person($this->site, ['rostering.viewAny']);
        $row = $this->shift($actor);
        AppSetting::query()->where('key', 'features.rostering.publish')->update(['value' => json_encode(false, JSON_THROW_ON_ERROR)]);
        app()->forgetInstance(RosteringFeatureFlags::class);
        $events = $this->read($actor);
        $this->assertSame([$row->id], $this->shiftIds($events));
        $this->assertSame('own', $this->assessment($events)['scope']['kind']);
        $this->assertFalse($this->assessment($events)['scope']['published_only']);
    }

    #[DataProvider('historicalStatuses')]
    public function test_existing_feed_statuses_remain_visible_for_canonical_sources(string $status): void
    {
        $actor = $this->manager();
        $worker = $this->person($this->site);
        $row = $this->shift($worker, ['status' => $status]);
        $events = $this->read($actor);
        $this->assertSame([$row->id], $this->shiftIds($events));
        $this->assertSame($status, collect($events)->firstWhere('id', $row->id)['extendedProps']['status']);
    }

    public static function historicalStatuses(): array
    {
        return ['draft' => ['draft'], 'completed' => ['completed'], 'cancelled' => ['cancelled']];
    }

    public function test_feed_still_requires_canonical_roster_route_permission(): void
    {
        $actor = $this->person($this->site, ['shifts.viewAny']);
        $actor->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', 'rostering.viewAny')->firstOrFail()->id => ['allowed' => false]]);
        $state = $this->state();
        $queue = $this->queueState();
        $this->actingAs($actor)->getJson($this->url())->assertForbidden();
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_management_site_bypass_keeps_canonical_roots_and_withholds_invalid_worker_or_client_sources(): void
    {
        $actor = $this->manager();
        $foreignWorker = $this->person($this->foreignSite);
        $validForeign = $this->shift($foreignWorker, ['site_id' => $this->foreignSite->id, 'client_id' => $this->foreignClient->id]);
        $worker = $this->person($this->site);
        $this->shift($worker, ['client_id' => $this->foreignClient->id, 'notes' => 'PRIVATE invalid canonical Client']);
        $ended = $this->person($this->site);
        $ended->hrEmployeeProfile->update(['is_active' => false]);
        $this->shift($ended, ['notes' => 'PRIVATE invalid worker']);
        $this->assertSame([$validForeign->id], $this->shiftIds($this->read($actor)));
    }

    public function test_scoped_staff_client_and_flat_site_filters_apply_to_display_and_never_expand_read_authority(): void
    {
        $actor = $this->person($this->site, ['rostering.viewAny', 'shifts.viewAny']);
        $worker = $this->person($this->site);
        $other = $this->person($this->site);
        $secondClient = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
        $selected = $this->shift($worker);
        $this->shift($other);
        $this->shift($worker, ['client_id' => $secondClient->id]);
        $events = $this->read($actor, ['staff_id' => $worker->id, 'client_id' => $this->client->id, 'site_id' => [$this->foreignSite->id, $this->site->id, $this->site->id]]);
        $this->assertSame([$selected->id], $this->shiftIds($events));
        $expectedSites = [$this->site->id, $this->foreignSite->id];
        sort($expectedSites);
        $meta = $this->assessment($events);
        $this->assertSame($expectedSites, $meta['filters']['site_ids']);
        $this->assertSame([$this->site->id], $meta['scope']['site_ids']);
        $this->assertSame([], $this->shiftIds($this->read($actor, ['site_id' => $this->foreignSite->id])));
        $this->assertSame([], $this->shiftIds($this->read($actor, ['client_id' => $this->foreignClient->id])));
    }

    public function test_nested_or_non_numeric_site_filters_fail_validation_without_domain_writes(): void
    {
        $actor = $this->manager();
        $state = $this->state();
        $queue = $this->queueState();
        $this->actingAs($actor)->getJson($this->url(['site_id' => [['id' => $this->site->id]]]))->assertUnprocessable()->assertJsonValidationErrors('site_id');
        $this->getJson($this->url(['site_id' => 'all']))->assertUnprocessable()->assertJsonValidationErrors('site_id');
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    #[DataProvider('civilDays')]
    public function test_civil_range_edges_overlap_and_assessment_use_identical_worker_local_instants(string $workerZone, string $day, int $hours): void
    {
        $zone = $workerZone ?: 'UTC';
        $start = Carbon::parse($day, $zone)->startOfDay();
        $end = $start->copy()->addDay();
        $this->assertSame($hours, (int) $start->diffInHours($end));
        $actor = $this->manager();
        $worker = $this->person($this->site);
        $carryIn = $this->shift($worker, ['starts_at' => $start->copy()->subMinutes(30)->utc(), 'ends_at' => $start->copy()->addMinutes(30)->utc()]);
        $inside = $this->shift($worker, ['starts_at' => $start->copy()->setTime(9, 0)->utc(), 'ends_at' => $start->copy()->setTime(10, 0)->utc()]);
        $last = $this->shift($worker, ['starts_at' => $end->copy()->subMinutes(30)->utc(), 'ends_at' => $end->copy()->addMinutes(30)->utc()]);
        $this->shift($worker, ['starts_at' => $start->copy()->subHour()->utc(), 'ends_at' => $start->copy()->utc()]);
        $this->shift($worker, ['starts_at' => $end->copy()->utc(), 'ends_at' => $end->copy()->addHour()->utc()]);
        config(['app.worker_timezone' => $workerZone]);
        $events = $this->read($actor, ['start' => $day, 'end' => $end->toDateString()]);
        $this->assertEqualsCanonicalizing([$carryIn->id, $inside->id, $last->id], $this->shiftIds($events));
        $meta = $this->assessment($events);
        $this->assertSame($zone, $meta['worker_timezone']);
        $this->assertSame(['start' => $start->copy()->utc()->toIso8601String(), 'end' => $end->copy()->utc()->toIso8601String(), 'end_exclusive' => true], $meta['range']);
    }

    public static function civilDays(): array
    {
        return [
            'NZ ordinary day' => ['Pacific/Auckland', '2026-10-26', 24],
            'NZ spring DST' => ['Pacific/Auckland', '2026-09-27', 23],
            'NZ autumn DST' => ['Pacific/Auckland', '2026-04-05', 25],
            'LA spring DST' => ['America/Los_Angeles', '2026-03-08', 23],
            'LA autumn DST' => ['America/Los_Angeles', '2026-11-01', 25],
            'empty worker uses valid app UTC' => ['', '2026-10-26', 24],
        ];
    }

    public function test_explicit_offset_inputs_keep_their_instants_and_zero_width_range_is_empty(): void
    {
        $actor = $this->manager();
        $worker = $this->person($this->site);
        $row = $this->shift($worker);
        $start = $row->starts_at->copy()->timezone('America/Los_Angeles')->toIso8601String();
        $end = $row->ends_at->copy()->timezone('America/Los_Angeles')->toIso8601String();
        $events = $this->read($actor, ['start' => $start, 'end' => $end]);
        $this->assertSame([$row->id], $this->shiftIds($events));
        $this->assertSame($row->starts_at->toIso8601String(), $this->assessment($events)['range']['start']);
        $inside = $row->starts_at->copy()->addMinutes(30)->toIso8601String();
        $this->assertSame([], $this->shiftIds($this->read($actor, ['start' => $inside, 'end' => $inside])));
    }

    public function test_staff_or_client_display_filter_does_not_remove_other_canonical_site_supply_from_coverage(): void
    {
        $actor = $this->manager();
        $first = $this->person($this->site);
        $second = $this->person($this->site);
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $selected = $this->shift($first);
        $this->shift($second, ['client_id' => $otherClient->id]);
        $this->rule(2);
        $events = $this->read($actor, ['staff_id' => $first->id, 'client_id' => $this->client->id]);
        $this->assertSame([$selected->id], $this->shiftIds($events));
        $this->assertSame(['status' => 'assessed', 'reason' => null, 'finding_count' => 0], $this->assessment($events)['coverage']);
        $event = collect($events)->firstWhere('id', $selected->id);
        $this->assertSame(2, $event['extendedProps']['coverage_assigned_staff']);
        $this->assertSame([], $this->gaps($events));
    }

    public function test_legacy_site_fallback_and_global_context_survive_while_foreign_series_and_context_metadata_do_not_leak(): void
    {
        $actor = $this->person($this->site, ['rostering.viewAny', 'shifts.viewAny']);
        $worker = $this->person($this->site);
        $legacy = $this->shift($worker, ['site_id' => null]);
        $foreignContext = ServiceContext::factory()->create(['name' => 'PRIVATE CALENDAR Context', 'site_id' => $this->foreignSite->id, 'is_active' => true]);
        $masked = $this->shift($worker, ['service_context_id' => $foreignContext->id]);
        $badSeries = $this->series($worker, ['site_id' => $this->foreignSite->id, 'client_id' => $this->foreignClient->id]);
        $this->shift($worker, ['shift_series_id' => $badSeries->id, 'notes' => 'PRIVATE CALENDAR series occurrence']);
        $events = $this->read($actor);
        $this->assertEqualsCanonicalizing([$legacy->id, $masked->id], $this->shiftIds($events));
        $this->assertStringNotContainsString('PRIVATE CALENDAR', json_encode($events));
        $legacyEvent = collect($events)->firstWhere('id', $legacy->id);
        $this->assertSame($this->site->id, $legacyEvent['extendedProps']['site_id']);
        $this->assertSame($this->site->name, $legacyEvent['extendedProps']['site_name']);
        $this->assertSame($this->context->id, $legacyEvent['extendedProps']['service_context_id']);
        $maskedEvent = collect($events)->firstWhere('id', $masked->id);
        $this->assertNull($maskedEvent['extendedProps']['service_context_id']);
        $this->assertNull($maskedEvent['extendedProps']['service_context']);
    }

    #[DataProvider('unsafeSupply')]
    public function test_hidden_supply_never_becomes_assessed_zero_or_foreign_metadata(string $source): void
    {
        $actor = $this->manager();
        $worker = $this->person($this->site);
        $rule = $this->rule(1);
        if ($source === 'shift') {
            $this->shift($worker, ['client_id' => $this->foreignClient->id, 'notes' => 'PRIVATE CALENDAR invalid supply']);
        } else {
            $this->series($worker, ['client_id' => $this->foreignClient->id, 'location' => 'PRIVATE CALENDAR invalid pattern']);
        }
        $raw = app(ShiftCoverageService::class)->buildRangeCoverage(Carbon::parse('2026-10-26', 'Pacific/Auckland'), Carbon::parse('2026-11-02', 'Pacific/Auckland'), $this->site->id);
        $this->assertNotEmpty($raw);
        $this->assertSame($rule->id, $raw[0]['rule_id']);
        $this->assertNotEmpty($raw[0][$source === 'shift' ? 'contributing_shifts' : 'matching_series']);
        if ($source === 'shift') {
            $this->assertFalse($raw[0]['has_actionable_gap']);
            $this->assertSame(1, $raw[0]['assigned_staff']);
        }
        $events = $this->read($actor, ['site_id' => [$this->site->id]]);
        $this->assertSame(['status' => 'partially_assessed', 'reason' => 'unsafe_source', 'finding_count' => null], $this->assessment($events)['coverage']);
        $this->assertSame([], $this->gaps($events));
        $this->assertStringNotContainsString('PRIVATE CALENDAR', json_encode($events));
    }

    public static function unsafeSupply(): array
    {
        return ['malformed canonical Client Shift' => ['shift'], 'malformed canonical Client series' => ['series']];
    }

    public function test_safe_gap_count_and_unassessed_empty_site_scope_are_distinct_from_complete_zero(): void
    {
        $actor = $this->manager();
        $rule = $this->rule(1);
        $events = $this->read($actor, ['site_id' => $this->site->id]);
        $this->assertSame(['status' => 'assessed', 'reason' => null, 'finding_count' => 1], $this->assessment($events)['coverage']);
        $this->assertCount(1, $this->gaps($events));
        $this->assertSame($rule->id, $this->gaps($events)[0]['extendedProps']['coverage_rule_id']);
        $inactive = Site::factory()->create(['is_active' => false, 'archived' => false, 'archived_at' => null]);
        $this->assertSame(['status' => 'not_assessed', 'reason' => 'no_sites', 'finding_count' => null], $this->assessment($this->read($actor, ['site_id' => $inactive->id]))['coverage']);
    }

    public function test_numeric_shift_identity_and_respite_read_fields_survive_the_non_rendered_record(): void
    {
        $actor = $this->manager();
        $booking = RespiteBooking::factory()->create(['client_id' => $this->client->id, 'location_id' => $this->site->id, 'status' => 'confirmed',
            'start_at' => Carbon::parse('2026-10-27 09:00', 'Pacific/Auckland')->utc(), 'end_at' => Carbon::parse('2026-10-27 10:00', 'Pacific/Auckland')->utc()]);
        $row = $this->shift(null, ['respite_booking_id' => $booking->id]);
        $events = $this->read($actor);
        $event = collect($events)->firstWhere('id', $row->id);
        $this->assertNotNull($event);
        $this->assertSame($row->id, $event['id']);
        $this->assertTrue($event['extendedProps']['is_respite']);
        $this->assertSame($booking->id, $event['extendedProps']['respite_booking_id']);
        $this->assertCount(1, collect($events)->where('id', 'roster-calendar-assessment'));
        $marker = collect($events)->firstWhere('id', 'roster-calendar-assessment');
        $this->assertNull($marker['start']);
        $this->assertNull($marker['end']);
        $this->assertSame('calendar_assessment', $marker['extendedProps']['event_type']);
    }

    public function test_owning_coverage_exception_is_not_converted_to_a_successful_empty_feed(): void
    {
        $actor = $this->manager();
        $state = $this->state();
        $queue = $this->queueState();
        $this->mock(ShiftCoverageService::class)->shouldReceive('buildRangeCoverage')->once()->andThrow(new \RuntimeException('PRIVATE coverage SQL failure'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($actor)->getJson($this->url(['site_id' => $this->site->id]));
            $this->fail('The service failure must not produce an assessed empty success.');
        } catch (\RuntimeException $error) {
            $this->assertSame('PRIVATE coverage SQL failure', $error->getMessage());
        }
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    #[DataProvider('mixedRangeEdges')]
    public function test_mixed_civil_and_offset_edges_are_ordered_only_after_worker_zone_normalisation(string $rawStart, string $rawEnd, string $startUtc, string $endUtc, bool $valid): void
    {
        $actor = $this->person($this->site, ['rostering.viewAny', 'shifts.viewAny']);
        $worker = $this->person($this->site);
        $first = Carbon::parse($startUtc);
        $last = Carbon::parse($endUtc);
        $low = $first->lt($last) ? $first : $last;
        $row = $this->shift($worker, ['starts_at' => $low->copy()->addMinutes(5), 'ends_at' => $low->copy()->addMinutes(10)]);
        $state = $this->state();
        $queue = $this->queueState();
        if ($valid) {
            $events = $this->read($actor, ['start' => $rawStart, 'end' => $rawEnd]);
            $this->assertSame([$row->id], $this->shiftIds($events));
            $this->assertSame(['start' => $first->toIso8601String(), 'end' => $last->toIso8601String(), 'end_exclusive' => true], $this->assessment($events)['range']);
        } else {
            $this->actingAs($actor)->getJson($this->url(['start' => $rawStart, 'end' => $rawEnd]))->assertUnprocessable();
        }
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public static function mixedRangeEdges(): array
    {
        return [
            'civil start offset end valid local half hour' => ['2026-10-26', '2026-10-26T00:30:00+13:00', '2026-10-25T11:00:00Z', '2026-10-25T11:30:00Z', true],
            'offset start civil end valid local half hour' => ['2026-10-26T23:30:00+13:00', '2026-10-27', '2026-10-26T10:30:00Z', '2026-10-26T11:00:00Z', true],
            'offset start civil end reversed local half hour' => ['2026-10-26T00:30:00+13:00', '2026-10-26', '2026-10-25T11:30:00Z', '2026-10-25T11:00:00Z', false],
            'civil start offset end reversed local half hour' => ['2026-10-26', '2026-10-25T23:30:00+13:00', '2026-10-25T11:00:00Z', '2026-10-25T10:30:00Z', false],
        ];
    }

    #[DataProvider('showReadCapabilities')]
    public function test_calendar_detail_capability_matches_actual_show_route_scope_and_publication_without_writes(array $permissions, bool $own, bool $foreign, bool $published, bool $publishEnabled, bool $inFeed, ?bool $canView, int $showStatus): void
    {
        $actor = $this->person($this->site, ['rostering.viewAny', ...$permissions]);
        foreach (['shifts.viewAny', 'shifts.viewAssigned', 'shifts.manageAny', 'reports.viewAny'] as $permission) {
            $actor->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', $permission)->firstOrFail()->id => ['allowed' => in_array($permission, $permissions, true)]]);
        }
        $actor = $actor->fresh();
        $worker = $own ? $actor : $this->person($foreign ? $this->foreignSite : $this->site);
        $row = $this->shift($worker, ['site_id' => $foreign ? $this->foreignSite->id : $this->site->id,
            'client_id' => $foreign ? $this->foreignClient->id : $this->client->id, 'published_at' => $published ? now() : null]);
        AppSetting::query()->where('key', 'features.rostering.publish')->update(['value' => json_encode($publishEnabled, JSON_THROW_ON_ERROR)]);
        app()->forgetInstance(RosteringFeatureFlags::class);
        $state = $this->state();
        $queue = $this->queueState();
        $events = $this->read($actor);
        $this->assertSame($inFeed ? [$row->id] : [], $this->shiftIds($events));
        $event = collect($events)->firstWhere('id', $row->id);
        $this->assertSame($canView, $event['extendedProps']['can_view_shift'] ?? null);
        $this->actingAs($actor)->get(route('operations.shifts.show', $row))->assertStatus($showStatus);
        foreach ($events as $item) {
            if (! is_int($item['id'])) {
                $this->assertArrayNotHasKey('can_view_shift', $item['extendedProps']);
            }
        }
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public static function showReadCapabilities(): array
    {
        return [
            'coordinator reads unpublished coworker' => [['shifts.viewAny'], false, false, false, true, true, true, 200],
            'assigned owner published' => [['shifts.viewAssigned'], true, false, true, true, true, true, 200],
            'assigned owner unpublished while publishing disabled' => [['shifts.viewAssigned'], true, false, false, false, true, true, 200],
            'assigned owner hidden unpublished' => [['shifts.viewAssigned'], true, false, false, true, false, null, 404],
            'roster feed alone grants no Show route' => [[], true, false, true, true, true, false, 403],
            'management alone grants no Show route' => [['shifts.manageAny'], false, false, false, true, true, false, 403],
            'management assigned route local unpublished coworker' => [['shifts.manageAny', 'shifts.viewAssigned'], false, false, false, true, true, true, 200],
            'management feed bypass does not bypass foreign Show Site' => [['shifts.manageAny', 'shifts.viewAssigned'], false, true, false, true, true, false, 403],
            'report bypass alone does not grant Show route' => [['shifts.manageAny', 'reports.viewAny'], false, true, false, true, true, false, 403],
            'Show route with existing report bypass permits foreign Site' => [['shifts.manageAny', 'shifts.viewAssigned', 'reports.viewAny'], false, true, false, true, true, true, 200],
        ];
    }

    private function person(Site $site, array $keys = []): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker', 'external_clinical_account' => false]);
        $user->permissionOverrides()->sync(collect($keys)->mapWithKeys(fn ($key) => [Permission::query()->where('key', $key)->firstOrFail()->id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null,
            'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user;
    }

    private function manager(): User
    {
        return $this->person($this->site, ['rostering.viewAny', 'shifts.manageAny']);
    }

    private function shift(?User $worker, array $values = []): Shift
    {
        $attributes = ['client_id' => $this->client->id, 'site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'user_id' => $worker?->id, 'created_by' => $worker?->id, 'starts_at' => Carbon::parse('2026-10-27 09:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-10-27 10:00', 'Pacific/Auckland')->utc(), 'status' => 'scheduled', 'published_at' => null, ...$values];
        if ($attributes['status'] === 'completed') {
            $attributes['actual_starts_at'] ??= $attributes['starts_at'];
            $attributes['actual_ends_at'] ??= $attributes['ends_at'];
        }

        return Shift::factory()->create($attributes)->fresh();
    }

    private function rule(int $minimum): SiteCoverageRequirement
    {
        return SiteCoverageRequirement::create(['site_id' => $this->site->id, 'name' => 'Calendar Tuesday demand', 'coverage_type' => 'custom',
            'day_of_week' => 'tue', 'starts_time' => '09:00', 'ends_time' => '10:00', 'minimum_staff' => $minimum,
            'role_requirements' => [], 'allow_overstaffing' => true, 'is_active' => true]);
    }

    private function series(User $worker, array $values = []): ShiftSeries
    {
        return ShiftSeries::create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'user_id' => $worker->id,
            'service_context_id' => $this->context->id, 'start_date' => '2026-10-26', 'end_date' => '2026-11-01', 'timezone' => 'Pacific/Auckland',
            'by_weekday' => ['tue'], 'starts_time' => '09:00', 'ends_time' => '10:00', 'status' => 'scheduled', ...$values])->fresh();
    }

    private function url(array $filters = []): string
    {
        return route('operations.rostering.calendar.events', ['start' => '2026-10-26', 'end' => '2026-11-02', ...$filters]);
    }

    private function read(User $actor, array $filters = []): array
    {
        $state = $this->state();
        $queue = $this->queueState();
        $events = $this->actingAs($actor)->getJson($this->url($filters))->assertOk()->json();
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());

        return $events;
    }

    private function assessment(array $events): array
    {
        return collect($events)->firstWhere('id', 'roster-calendar-assessment')['extendedProps'];
    }

    private function shiftIds(array $events): array
    {
        return collect($events)->filter(fn ($event) => is_int($event['id']))->pluck('id')->all();
    }

    private function gaps(array $events): array
    {
        return collect($events)->filter(fn ($event) => ($event['extendedProps']['event_type'] ?? null) === 'coverage_gap')->values()->all();
    }

    private function state(): array
    {
        return collect(['sites', 'clients', 'users', 'hr_employee_profiles', 'service_contexts', 'site_coverage_requirements', 'respite_bookings',
            'roster_periods', 'shifts', 'shift_tasks', 'shift_series', 'shift_eligibility_overrides', 'coverage_reservations',
            'shift_replacement_requests', 'shift_open_positions', 'site_checklist_runs', 'audit_logs', 'notifications',
            'workforce_eligibility_rechecks', 'workforce_eligibility_observations', 'timeline_events', 'shift_handovers', 'shift_notes',
            'timesheets', 'hr_time_entries', 'billing_entries', 'shift_signals', 'shift_signal_outbox'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function queueState(): array
    {
        return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
    }
}
