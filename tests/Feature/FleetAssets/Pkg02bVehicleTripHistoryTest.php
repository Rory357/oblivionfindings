<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\FleetDrivingEventReview;
use App\Models\FleetDrivingMetric;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\FleetTripDriverConfirmation;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\FleetDrivingMetricsService;
use App\Services\Fleet\FleetTripService;
use App\Services\Fleet\VehicleTripHistoryService;
use App\Services\Fleet\VehicleTripReportExporter;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PKG-02B vehicle trip history: the trip list and its filters, a trip's
 * behaviour read from recorded telemetry, driver confirmation, PDF and
 * Excel exports, and the Queclink driving-metric counting fix.
 */
class Pkg02bVehicleTripHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE = 'Pacific/Auckland';

    private Site $site;

    private Site $foreignSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-22 09:30:00', self::ZONE)->utc());
        config([
            'fleet.behaviour.speeding_kph' => 60,
            'fleet.trip.coverage_gap_seconds' => 120,
            'fleet.behaviour.score_min_coverage_pct' => 90,
            'fleet.behaviour.score_weights' => ['harsh_brake' => 5, 'accel' => 3, 'speeding' => 4, 'idle' => 0.5],
        ]);
        $this->site = Site::factory()->create(['name' => 'Kōwhai House', 'is_active' => true]);
        $this->foreignSite = Site::factory()->create(['name' => 'Rimu House', 'is_active' => true]);
    }

    public function test_trip_history_is_concealed_outside_the_viewers_sites_and_needs_fleet_view(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $foreign = $this->vehicle($this->foreignSite, ['name' => 'RIMU HIDDEN VAN']);
        $foreignTrip = $this->trip($foreign, '2026-09-21 08:00', 12, ['start_address' => 'RIMU HIDDEN START']);
        $ownTrip = $this->trip($vehicle, '2026-09-21 09:00', 10);
        $foreignClient = Client::factory()->create(['site_id' => $this->foreignSite->id]);
        $conflicting = $this->vehicle($this->site, ['client_id' => $foreignClient->id]);
        $this->trip($conflicting, '2026-09-21 10:00', 10);

        foreach ([
            "/fleet-assets/vehicles/{$foreign->id}/trip-history",
            "/fleet-assets/vehicles/{$foreign->id}/trip-history/{$foreignTrip->id}",
            "/fleet-assets/vehicles/{$foreign->id}/trip-history/export/pdf?from=2026-09-21&to=2026-09-21",
            "/fleet-assets/vehicles/{$vehicle->id}/trip-history/{$foreignTrip->id}",
            "/fleet-assets/vehicles/{$conflicting->id}/trip-history",
            '/fleet-assets/vehicles/987654321/trip-history',
        ] as $url) {
            $this->actingAs($viewer)->getJson($url)->assertNotFound();
        }

        $this->actingAs($viewer)->getJson("/fleet-assets/vehicles/{$vehicle->id}/trip-history")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $ownTrip->id);
        $this->assertStringNotContainsString('RIMU HIDDEN', (string) $this->actingAs($viewer)
            ->getJson("/fleet-assets/vehicles/{$vehicle->id}/trip-history")->getContent());

        $unprivileged = $this->siteUser([$this->site], []);
        $this->actingAs($unprivileged)->getJson("/fleet-assets/vehicles/{$vehicle->id}/trip-history")->assertForbidden();
        auth()->logout();
        $this->getJson("/fleet-assets/vehicles/{$vehicle->id}/trip-history")->assertUnauthorized();
    }

    public function test_driver_names_from_other_sites_are_not_shown(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);
        $foreignDriver = $this->siteUser([$this->foreignSite], [], 'RIMU HIDDEN DRIVER');
        $vehicle = $this->vehicle($this->site);
        $trip = $this->trip($vehicle, '2026-09-21 08:00', 12);
        $this->booking($vehicle, $foreignDriver, $this->local('2026-09-21 07:50'), $this->local('2026-09-21 09:00'));

        $list = $this->actingAs($viewer)->getJson("/fleet-assets/vehicles/{$vehicle->id}/trip-history")
            ->assertOk()
            ->assertJsonPath('data.0.driver.state', 'hidden')
            ->assertJsonPath('data.0.driver.name', null)
            ->assertJsonPath('drivers', []);
        $detail = $this->actingAs($viewer)->getJson("/fleet-assets/vehicles/{$vehicle->id}/trip-history/{$trip->id}")
            ->assertOk()
            ->assertJsonPath('driver.state', 'hidden')
            ->assertJsonPath('driver.booked_driver', null);

        $this->assertStringNotContainsString('RIMU HIDDEN DRIVER', (string) $list->getContent());
        $this->assertStringNotContainsString('RIMU HIDDEN DRIVER', (string) $detail->getContent());
    }

    public function test_list_filters_by_auckland_day_search_driver_and_event_type(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);
        $jamie = $this->siteUser([$this->site], [], 'Jamie Taylor');
        $vehicle = $this->vehicle($this->site);

        // Full coverage with an overspeed; a booking by Jamie covers it.
        $morning = $this->trip($vehicle, '2026-09-21 08:00', 12, [
            'start_address' => 'Kōwhai House', 'end_address' => 'Community pickup', 'distance_km' => 2.4,
        ]);
        $this->regularSamples($vehicle, '2026-09-21 08:00', 12, [8 => 64, 9 => 68, 10 => 40]);
        $this->booking($vehicle, $jamie, $this->local('2026-09-21 07:45'), $this->local('2026-09-21 09:00'));

        // Sparse positions and a power alert.
        $afternoon = $this->trip($vehicle, '2026-09-20 16:40', 28, [
            'start_address' => 'Community transport stop', 'end_address' => 'Kōwhai House', 'distance_km' => 8.1,
        ]);
        foreach (['16:40', '16:50', '17:08'] as $time) {
            $this->sample($vehicle, $this->local("2026-09-20 {$time}"), 30);
        }
        $this->sample($vehicle, $this->local('2026-09-20 17:02'), 25, ['event_type' => 'external_power']);

        // 00:30 on 21 Sep in Auckland is still 20 Sep in UTC.
        $pastMidnight = $this->trip($vehicle, '2026-09-21 00:30', 20, ['distance_km' => 5]);
        $personal = $this->trip($vehicle, '2026-09-19 12:00', 30, ['is_personal' => true, 'distance_km' => 10]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/trip-history";

        $all = $this->actingAs($viewer)->getJson($url.'?per_page=2')->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('trip_ids', [$morning->id, $pastMidnight->id, $afternoon->id, $personal->id])
            ->assertJsonPath('summary.trips', 4)
            ->assertJsonPath('summary.personal_trips', 1)
            ->assertJsonPath('summary.business_trips', 3)
            ->assertJsonPath('summary.first_day', '2026-09-19')
            ->assertJsonPath('summary.last_day', '2026-09-21')
            ->assertJsonPath('data.0.driver.state', 'recorded')
            ->assertJsonPath('data.0.driver.name', 'Jamie Taylor')
            ->assertJsonPath('data.0.coverage_pct', 100)
            ->assertJsonPath('data.0.overspeed_episodes', 1)
            ->assertJsonPath('drivers.0.id', $jamie->id)
            ->assertJsonPath('unassigned_trips', 3);
        $this->assertEqualsWithDelta(25.5, $all->json('summary.distance_km'), 0.01);
        $this->assertContains('2026-09-21', $all->json('recent_days'));

        $ids = fn (string $query): array => $this->actingAs($viewer)->getJson($url.'?per_page=25&'.$query)
            ->assertOk()->json('trip_ids');

        $this->assertSame([$morning->id, $pastMidnight->id], $ids('from=2026-09-21&to=2026-09-21'));
        $this->assertSame([$afternoon->id], $ids('from=2026-09-20&to=2026-09-20'));
        $this->assertSame([$morning->id], $ids('q=pickup'));
        $this->assertSame([$morning->id], $ids('q=jamie'));
        $this->assertSame([$afternoon->id], $ids('q='.urlencode('#'.$afternoon->id)));
        $this->assertSame([$morning->id], $ids('driver='.$jamie->id));
        $this->assertSame([$pastMidnight->id, $afternoon->id, $personal->id], $ids('driver=unassigned'));
        $this->assertSame([$morning->id], $ids('event=overspeed'));
        $this->assertSame([$afternoon->id], $ids('event=faults'));
        $this->assertContains($afternoon->id, $ids('event=partial'));
        $this->assertNotContains($morning->id, $ids('event=partial'));

        $this->actingAs($viewer)->getJson($url.'?from=2026-09-21&to=2026-09-20')->assertUnprocessable()
            ->assertJsonValidationErrors('to');
        $this->actingAs($viewer)->getJson($url.'?summary_only=1&from=2026-09-19&to=2026-09-21')->assertOk()
            ->assertJsonPath('summary.business_trips', 3)
            ->assertJsonMissingPath('data');
    }

    public function test_the_list_reads_the_latest_days_and_at_most_a_year_and_says_so(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $latest = $this->trip($vehicle, '2026-09-21 08:00', 12);
        $spring = $this->trip($vehicle, '2026-05-01 08:00', 12);
        $this->trip($vehicle, '2024-01-10 08:00', 12);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/trip-history";

        // All recorded dates: the 90 days up to the latest trip, and whether there are earlier ones.
        $this->actingAs($viewer)->getJson($url)->assertOk()
            ->assertJsonPath('trip_ids', [$latest->id])
            ->assertJsonPath('summary.trips', 1)
            ->assertJsonPath('window.from', '2026-06-24')
            ->assertJsonPath('window.to', null)
            ->assertJsonPath('window.limited', 'recent')
            ->assertJsonPath('window.earlier_trips', true)
            ->assertJsonPath('truncated', false)
            ->assertJsonPath('limits.default_days', VehicleTripHistoryService::DEFAULT_WINDOW_DAYS)
            ->assertJsonPath('limits.max_range_days', VehicleTripHistoryService::MAX_RANGE_DAYS)
            ->assertJsonPath('limits.max_trips', VehicleTripHistoryService::MAX_LIST_TRIPS)
            ->assertJsonPath('has_trips', true);

        // A range within a year is read as asked.
        $this->actingAs($viewer)->getJson($url.'?from=2026-04-01&to=2026-09-21')->assertOk()
            ->assertJsonPath('trip_ids', [$latest->id, $spring->id])
            ->assertJsonPath('window.from', '2026-04-01')
            ->assertJsonPath('window.limited', null)
            ->assertJsonPath('window.earlier_trips', false);
        $this->actingAs($viewer)->getJson($url.'?from=2026-01-01')->assertOk()
            ->assertJsonPath('trip_ids', [$latest->id, $spring->id])
            ->assertJsonPath('window.limited', null);

        // A longer range, or one with no first date, keeps its most recent year.
        foreach (['?from=2023-01-01&to=2026-09-21', '?to=2026-09-21'] as $query) {
            $this->actingAs($viewer)->getJson($url.$query)->assertOk()
                ->assertJsonPath('trip_ids', [$latest->id, $spring->id])
                ->assertJsonPath('window.from', '2025-09-20')
                ->assertJsonPath('window.to', '2026-09-21')
                ->assertJsonPath('window.limited', 'range')
                ->assertJsonPath('window.earlier_trips', true);
        }
        $this->actingAs($viewer)->getJson($url.'?summary_only=1&from=2023-01-01&to=2026-09-21')->assertOk()
            ->assertJsonPath('summary.trips', 2)
            ->assertJsonPath('window.limited', 'range');
    }

    public function test_the_list_reads_at_most_the_latest_trips_while_exports_keep_their_own_limits(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $start = $this->local('2026-09-21 06:00');
        $rows = [];
        // One more trip than a list reads: every 20 minutes back from 21 September.
        foreach (range(0, VehicleTripHistoryService::MAX_LIST_TRIPS) as $index) {
            $at = $start->subMinutes($index * 20);
            $rows[] = [
                'asset_id' => $vehicle->id, 'started_at' => $at->format('Y-m-d H:i:s'),
                'ended_at' => $at->addMinutes(10)->format('Y-m-d H:i:s'), 'distance_km' => 1, 'duration_s' => 600,
                'status' => 'closed', 'consent_blocked' => false, 'is_personal' => false,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('fleet_trips')->insert($rows);
        $oldest = (int) DB::table('fleet_trips')->where('asset_id', $vehicle->id)->orderBy('started_at')->value('id');
        $url = "/fleet-assets/vehicles/{$vehicle->id}/trip-history";

        $list = $this->actingAs($viewer)->getJson($url.'?per_page=25')->assertOk()
            ->assertJsonPath('truncated', true)
            ->assertJsonPath('meta.total', VehicleTripHistoryService::MAX_LIST_TRIPS)
            ->assertJsonPath('summary.trips', VehicleTripHistoryService::MAX_LIST_TRIPS)
            ->assertJsonPath('window.limited', 'recent')
            ->assertJsonCount(25, 'data');
        $this->assertCount(VehicleTripHistoryService::MAX_LIST_TRIPS, $list->json('trip_ids'));
        $this->assertNotContains($oldest, $list->json('trip_ids'));
        // The last page is still there to read.
        $this->actingAs($viewer)->getJson($url.'?per_page=25&page=20')->assertOk()
            ->assertJsonPath('meta.page', 20)->assertJsonCount(25, 'data');

        // An export uses the dates it is given and its own limits.
        $service = app(VehicleTripHistoryService::class);
        $asset = $service->vehicle($viewer, $vehicle->id);
        $filters = $service->filters(['from' => '2026-09-13', 'to' => '2026-09-21']);
        $report = $service->exportReport($viewer, $asset, $filters, VehicleTripHistoryService::SPREADSHEET_TRIP_LIMIT, false, false);
        $this->assertSame(VehicleTripHistoryService::MAX_LIST_TRIPS + 1, $report['totals']['trips']);
        try {
            $service->exportReport($viewer, $asset, $filters, VehicleTripHistoryService::PDF_TRIP_LIMIT, false, false);
            $this->fail('A PDF of more trips than its limit was generated.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('choose a shorter date range', $exception->errors()['range'][0]);
        }
    }

    public function test_trip_detail_reads_behaviour_from_the_recorded_telemetry(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $trip = $this->trip($vehicle, '2026-09-21 08:00', 12, ['distance_km' => 2.4]);
        $this->sample($vehicle, $this->local('2026-09-21 07:59:30'), 0, ['event_type' => 'ignition_on', 'ignition' => true]);
        $this->regularSamples($vehicle, '2026-09-21 08:00', 12, [8 => 64, 9 => 68, 10 => 40]);
        // A tracker alarm inside the threshold episode is the same episode.
        $this->sample($vehicle, $this->local('2026-09-21 08:04:10'), 66, ['event_type' => 'speed_alarm', 'raw_payload' => ['report_id_type' => '10']]);
        $this->sample($vehicle, $this->local('2026-09-21 08:06:15'), 35, ['event_type' => 'harsh_behaviour', 'raw_payload' => ['report_id_type' => '10']]);
        $this->sample($vehicle, $this->local('2026-09-21 08:07:15'), 35, ['event_type' => 'harsh_behaviour', 'raw_payload' => ['report_id_type' => '11']]);
        $this->sample($vehicle, $this->local('2026-09-21 08:08:15'), 35, ['event_type' => 'harsh_behaviour']);
        $this->sample($vehicle, $this->local('2026-09-21 08:09:15'), 30, ['event_type' => 'external_power']);
        $this->sample($vehicle, $this->local('2026-09-21 08:10:15'), 99, [
            'consent_blocked' => true, 'latitude' => -41.9, 'longitude' => 174.9,
        ]);

        $response = $this->actingAs($viewer)->getJson("/fleet-assets/vehicles/{$vehicle->id}/trip-history/{$trip->id}")
            ->assertOk()
            ->assertJsonPath('trip.reference', 'Trip #'.$trip->id)
            ->assertJsonPath('trip.local_date', '2026-09-21')
            ->assertJsonPath('trip.start.trigger', 'ignition')
            ->assertJsonPath('trip.end.trigger', 'stopped')
            ->assertJsonPath('recorded_points', 30)
            ->assertJsonPath('behaviour.coverage_pct', 100)
            ->assertJsonPath('behaviour.partial', false)
            ->assertJsonPath('behaviour.overspeed_episodes', 1)
            ->assertJsonPath('behaviour.overspeed_seconds', 60)
            ->assertJsonPath('behaviour.harsh.braking', 1)
            ->assertJsonPath('behaviour.harsh.acceleration', 1)
            ->assertJsonPath('behaviour.harsh.unclassified', 1)
            ->assertJsonPath('behaviour.faults', 1)
            ->assertJsonPath('behaviour.driving_events', 4)
            ->assertJsonPath('behaviour.idle_minutes', null)
            ->assertJsonPath('behaviour.score_state', 'scored')
            // 100 − 5 braking − 3 acceleration − 3 unclassified − 4 overspeed.
            ->assertJsonPath('behaviour.score', 85)
            ->assertJsonPath('policy.speed_threshold_kph', 60)
            ->assertJsonPath('can.confirm_driver', false)
            ->assertJsonPath('driver_candidates', []);

        $this->assertEqualsWithDelta(68.0, $response->json('behaviour.max_speed_kph'), 0.01);
        $this->assertEqualsWithDelta(166.7, $response->json('behaviour.events_per_100km'), 0.01);
        $this->assertNotContains(-41.9, array_map('floatval', array_column($response->json('points'), 'lat')));
        $events = collect($response->json('events'));
        $overspeed = $events->firstWhere('type', 'overspeed');
        $this->assertSame('Over fleet speed threshold', $overspeed['title']);
        $this->assertStringContainsString('The tracker also raised a speed alarm.', $overspeed['detail']);
        $this->assertSame(['Harsh braking', 'Harsh acceleration', 'Harsh driving'],
            $events->where('kind', 'driving')->pluck('title')->values()->all());
        $this->assertSame('power', $events->firstWhere('type', 'external_power')['kind']);
        $this->assertTrue(AuditLog::query()->where('action', 'fleet.trip.history.view')
            ->where('auditable_id', $trip->id)->exists());

        // Sparse positions: the score is withheld rather than shown as perfect.
        $sparse = $this->trip($vehicle, '2026-09-21 10:00', 12);
        foreach (['10:00', '10:05', '10:12'] as $time) {
            $this->sample($vehicle, $this->local("2026-09-21 {$time}"), 30);
        }
        $this->actingAs($viewer)->getJson("/fleet-assets/vehicles/{$vehicle->id}/trip-history/{$sparse->id}")
            ->assertOk()
            ->assertJsonPath('behaviour.coverage_pct', 33)
            ->assertJsonPath('behaviour.partial', true)
            ->assertJsonPath('behaviour.score', null)
            ->assertJsonPath('behaviour.score_state', 'coverage')
            ->assertJsonPath('events.0.title', 'Missing report window');
    }

    public function test_personal_and_restricted_trips_are_listed_without_their_places_or_route(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $personal = $this->trip($vehicle, '2026-09-21 08:00', 12, [
            'is_personal' => true, 'start_address' => 'PERSONAL START', 'end_address' => 'PERSONAL END',
        ]);
        $restricted = $this->trip($vehicle, '2026-09-21 10:00', 12, [
            'consent_blocked' => true, 'start_address' => 'RESTRICTED START', 'end_address' => 'RESTRICTED END',
        ]);
        $this->regularSamples($vehicle, '2026-09-21 08:00', 12, [8 => 90, 9 => 95]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/trip-history";

        $list = $this->actingAs($viewer)->getJson($url)->assertOk();
        $this->assertSame([$restricted->id, $personal->id], array_column($list->json('data'), 'id'));
        $this->assertStringNotContainsString('PERSONAL', $list->getContent());
        $this->assertStringNotContainsString('RESTRICTED', $list->getContent());
        // Places can't be probed through search, and personal driving isn't an overspeed result.
        $this->actingAs($viewer)->getJson($url.'?q=personal')->assertOk()->assertJsonPath('trip_ids', []);
        $this->actingAs($viewer)->getJson($url.'?event=overspeed')->assertOk()->assertJsonPath('trip_ids', []);

        $detail = $this->actingAs($viewer)->getJson("{$url}/{$personal->id}")->assertOk()
            ->assertJsonPath('trip.start.address', null)
            ->assertJsonPath('trip.start.lat', null)
            ->assertJsonPath('trip.end.lng', null)
            ->assertJsonPath('points', [])
            ->assertJsonPath('events', [])
            ->assertJsonPath('behaviour.max_speed_kph', null)
            ->assertJsonPath('behaviour.overspeed_episodes', 0)
            ->assertJsonPath('behaviour.score', null)
            ->assertJsonPath('behaviour.score_state', 'personal');
        $this->assertStringNotContainsString('PERSONAL', $detail->getContent());
        $this->actingAs($viewer)->getJson("{$url}/{$restricted->id}")->assertOk()
            ->assertJsonPath('trip.end.address', null)
            ->assertJsonPath('points', []);
    }

    public function test_confirming_a_driver_needs_authority_a_site_candidate_and_is_audited(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $tripManager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.trips.manage']);
        $jamie = $this->siteUser([$this->site], [], 'Jamie Taylor');
        $alex = $this->siteUser([$this->site], [], 'Alex Morgan');
        $outsider = $this->siteUser([$this->foreignSite], [], 'Rimu Outsider');
        $vehicle = $this->vehicle($this->site);
        $trip = $this->trip($vehicle, '2026-09-21 08:00', 12);
        $booking = $this->booking($vehicle, $jamie, $this->local('2026-09-21 07:45'), $this->local('2026-09-21 09:00'));
        $url = "/fleet-assets/vehicles/{$vehicle->id}/trip-history/{$trip->id}/driver";
        $valid = ['driver_user_id' => $alex->id, 'reason' => 'Key log shows Jamie handed the van to Alex.',
            'verified' => true, 'expected_version' => 0];

        $this->actingAs($viewer)->withHeader('Idempotency-Key', 'drv-0')->postJson($url, $valid)->assertForbidden();
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'drv-1')
            ->postJson($url, ['driver_user_id' => $outsider->id] + $valid)
            ->assertUnprocessable()->assertJsonValidationErrors('driver_user_id');
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'drv-2')
            ->postJson($url, ['reason' => ''] + $valid)
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'drv-3')
            ->postJson($url, ['verified' => false] + $valid)
            ->assertUnprocessable()->assertJsonValidationErrors('verified');
        $this->assertSame(0, FleetTripDriverConfirmation::query()->count());

        $this->actingAs($manager)->withHeader('Idempotency-Key', 'drv-4')->postJson($url, $valid)
            ->assertOk()
            ->assertJsonPath('trip.driver_user_id', $alex->id)
            ->assertJsonPath('trip.driver_attribution_source', 'handover');
        // A retried request is answered once; a reused key with other data is refused.
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'drv-4')->postJson($url, $valid)->assertOk();
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'drv-4')
            ->postJson($url, ['reason' => 'Something else'] + $valid)->assertStatus(409);
        // Confirming against an out-of-date view is refused.
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'drv-5')->postJson($url, $valid)->assertStatus(409);

        $trip->refresh();
        $this->assertSame($alex->id, (int) $trip->driver_user_id);
        $this->assertSame('handover', $trip->driver_attribution_source);
        $this->assertSame($manager->id, (int) $trip->driver_confirmed_by);
        $this->assertNotNull($trip->driver_confirmed_at);
        $confirmation = FleetTripDriverConfirmation::query()->sole();
        $this->assertSame($booking->id, (int) $confirmation->booking_id);
        $this->assertSame('Key log shows Jamie handed the van to Alex.', $confirmation->reason);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fleet.trip.driver_confirmed',
            'auditable_type' => $vehicle->getMorphClass(),
            'auditable_id' => $vehicle->id,
        ]);

        // A trip manager may confirm too; the booked driver is recorded as such.
        $this->actingAs($tripManager)->withHeader('Idempotency-Key', 'drv-6')
            ->postJson($url, ['driver_user_id' => $jamie->id, 'expected_version' => 1] + $valid)
            ->assertOk()->assertJsonPath('trip.driver_attribution_source', 'booking');
        $this->actingAs($manager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/trip-history/{$trip->id}")
            ->assertOk()
            ->assertJsonPath('driver.state', 'confirmed')
            ->assertJsonPath('driver.name', 'Jamie Taylor')
            ->assertJsonPath('driver.attribution', 'booking')
            ->assertJsonPath('driver.version', 2)
            ->assertJsonCount(2, 'driver_history')
            ->assertJsonPath('driver_history.1.source', 'handover')
            ->assertJsonPath('can.confirm_driver', true);
        $this->actingAs($viewer)->getJson("/fleet-assets/vehicles/{$vehicle->id}/trip-history?driver={$jamie->id}")
            ->assertOk()->assertJsonPath('trip_ids', [$trip->id])->assertJsonPath('data.0.driver.state', 'confirmed');
        $this->actingAs($viewer)->getJson("/fleet-assets/vehicles/{$vehicle->id}/trip-history/{$trip->id}")
            ->assertOk()->assertJsonPath('driver_history', []);
    }

    public function test_deleting_a_trip_never_erases_its_driver_confirmation_or_event_reviews(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.trips.manage']);
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);
        $driver = $this->siteUser([$this->site], [], 'Jamie Taylor');
        $vehicle = $this->vehicle($this->site);
        $confirmed = $this->trip($vehicle, '2026-09-21 08:00', 12);
        $reviewed = $this->trip($vehicle, '2026-09-21 10:00', 12);
        $both = $this->trip($vehicle, '2026-09-21 12:00', 12);
        $plain = $this->trip($vehicle, '2026-09-21 14:00', 12);
        $foreign = $this->trip($this->vehicle($this->foreignSite), '2026-09-21 08:00', 12);
        foreach ([$confirmed, $both] as $index => $trip) {
            FleetTripDriverConfirmation::query()->create([
                'fleet_trip_id' => $trip->id, 'asset_id' => $vehicle->id, 'driver_user_id' => $driver->id,
                'source' => 'manual', 'reason' => 'Key log shows Jamie drove.', 'confirmed_by_user_id' => $manager->id,
                'confirmed_at' => now(), 'request_key' => 'confirm-before-delete-'.$index,
                'request_fingerprint' => str_repeat('a', 64),
            ]);
        }
        foreach ([$reviewed, $both] as $index => $trip) {
            FleetDrivingEventReview::query()->create([
                'asset_id' => $vehicle->id, 'fleet_trip_id' => $trip->id, 'event_key' => 'harsh-braking-'.$trip->id,
                'event_type' => 'harsh-braking', 'event_title' => 'Harsh braking', 'event_at' => $trip->started_at,
                'outcome' => 'dismissed', 'reason' => 'Braked for a pedestrian crossing.', 'recorded_by_user_id' => $manager->id,
                'policy_version' => 1, 'sequence' => 1, 'request_key' => 'review-before-delete-'.$index,
                'request_fingerprint' => str_repeat('b', 64),
            ]);
        }

        $this->actingAs($viewer)->deleteJson("/fleet/trips/{$plain->id}")->assertForbidden();
        $this->actingAs($manager)->deleteJson("/fleet/trips/{$confirmed->id}")->assertUnprocessable()
            ->assertJsonValidationErrors(['trip' => "This trip can't be deleted because its driver confirmation is kept as a record."]);
        $this->actingAs($manager)->deleteJson("/fleet/trips/{$reviewed->id}")->assertUnprocessable()
            ->assertJsonValidationErrors(['trip' => "This trip can't be deleted because its driving event reviews are kept as a record."]);
        $this->actingAs($manager)->deleteJson("/fleet/trips/{$both->id}")->assertUnprocessable()
            ->assertJsonValidationErrors(['trip' => 'its driver confirmation and driving event reviews are kept as a record']);
        // The trip playback page gets the reason back to show.
        $playback = "/fleet-assets/trips/{$confirmed->id}/playback";
        $this->actingAs($manager)->from($playback)->delete("/fleet/trips/{$confirmed->id}")
            ->assertRedirect($playback)->assertSessionHasErrors('trip');

        foreach ([$confirmed, $reviewed, $both] as $trip) {
            $this->assertDatabaseHas('fleet_trips', ['id' => $trip->id]);
        }
        $this->assertSame(2, FleetTripDriverConfirmation::query()->count());
        $this->assertSame(2, FleetDrivingEventReview::query()->count());
        $this->assertFalse(AuditLog::query()->where('action', 'fleet.trip.delete')->exists());
        // The database refuses as well, however a delete arrives.
        foreach ([$confirmed, $reviewed] as $trip) {
            try {
                DB::table('fleet_trips')->where('id', $trip->id)->delete();
                $this->fail('A trip with kept evidence was deleted.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('foreign key constraint fails', $exception->getMessage());
            }
        }

        // A trip at another Site is not found, and stays: it can't be deleted,
        // changed or closed from here.
        $this->actingAs($manager)->deleteJson("/fleet/trips/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('fleet_trips', ['id' => $foreign->id]);
        $this->actingAs($manager)->putJson("/fleet/trips/{$foreign->id}", ['notes' => 'Moved elsewhere.'])->assertNotFound();
        $this->actingAs($manager)->postJson("/fleet/trips/{$foreign->id}/close")->assertNotFound();
        $this->assertNotSame('Moved elsewhere.', $foreign->fresh()->notes);
        $this->actingAs($manager)->deleteJson('/fleet/trips/987654321')->assertNotFound();

        // A trip at the manager's Site with nothing kept against it is deleted and audited.
        $this->actingAs($manager)->deleteJson("/fleet/trips/{$plain->id}")
            ->assertRedirect(route('fleet-assets.trips.index'));
        $this->assertDatabaseMissing('fleet_trips', ['id' => $plain->id]);
        $this->assertTrue(AuditLog::query()->where('action', 'fleet.trip.delete')
            ->where('auditable_id', $plain->id)->exists());
    }

    public function test_exports_are_scoped_branded_sanitised_and_audited(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);
        $outsider = $this->siteUser([$this->foreignSite], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site, ['name' => 'Kōwhai van', 'registration_number' => 'KWH014']);
        $business = $this->trip($vehicle, '2026-09-21 08:00', 12, [
            'start_address' => '=HYPERLINK("http://example.test","click")', 'end_address' => 'Kōwhai House', 'distance_km' => 2.4,
        ]);
        $this->regularSamples($vehicle, '2026-09-21 08:00', 12, [8 => 64, 9 => 68, 10 => 40]);
        $personal = $this->trip($vehicle, '2026-09-20 12:00', 30, ['is_personal' => true, 'start_address' => 'PERSONAL ONLY']);
        $base = "/fleet-assets/vehicles/{$vehicle->id}/trip-history/export";

        $excel = $this->actingAs($viewer)->get("{$base}/excel?from=2026-09-19&to=2026-09-21");
        $excel->assertOk();
        $this->assertStringStartsWith('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', (string) $excel->headers->get('Content-Type'));
        $this->assertStringContainsString('kwh014-trips-2026-09-19-to-2026-09-21.xlsx', (string) $excel->headers->get('Content-Disposition'));
        $archive = tempnam(sys_get_temp_dir(), 'trip-export-test-');
        file_put_contents($archive, $excel->getContent());
        $zip = new \ZipArchive;
        try {
            $this->assertTrue($zip->open($archive));
            $this->assertNotFalse($zip->locateName('xl/media/image4-1.png'));
            $this->assertStringStartsWith("\x89PNG", $zip->getFromName('xl/media/image4-1.png'));
            $sheet = '';
            for ($entry = 0; $entry < $zip->numFiles; $entry++) {
                $name = $zip->getNameIndex($entry);
                if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                    $part = $zip->getFromIndex($entry);
                    $document = new \DOMDocument;
                    $this->assertTrue($document->loadXML($part, LIBXML_NONET), $name);
                    $sheet .= $part;
                }
            }
            $zip->close();
        } finally {
            unlink($archive);
        }
        $this->assertStringContainsString('Journey sketches', $sheet);
        $this->assertStringContainsString('Kōwhai van', $sheet);
        $this->assertStringContainsString('Kōwhai House', $sheet);
        $this->assertStringContainsString('Trip #'.$business->id, $sheet);
        // Native inline strings remain literal, even when they start with '='.
        $this->assertStringContainsString('t="inlineStr"><is><t xml:space="preserve">=HYPERLINK(', $sheet);
        $this->assertStringNotContainsString('<f>HYPERLINK', $sheet);
        $this->assertStringContainsString('<f>SUM(I8:I8)</f>', $sheet);
        $this->assertStringNotContainsString('PERSONAL ONLY', $sheet);
        $this->assertStringNotContainsString('Trip #'.$personal->id, $sheet);
        $this->assertStringContainsString('Over fleet speed threshold', $sheet);

        $pdf = $this->actingAs($viewer)->get("{$base}/pdf?from=2026-09-19&to=2026-09-21");
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('kwh014-trips-2026-09-19-to-2026-09-21.pdf', (string) $pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        // The Unicode font that carries macrons is embedded.
        $this->assertStringContainsString('DejaVuSans', (string) $pdf->getContent());

        $service = app(VehicleTripHistoryService::class);
        $report = $service->exportReport($viewer, $service->vehicle($viewer, $vehicle->id),
            $service->filters(['from' => '2026-09-19', 'to' => '2026-09-21']), 200, true, true);
        $html = app(VehicleTripReportExporter::class)->html($report, 'Test Viewer');
        $this->assertStringContainsString('DejaVu Sans', $html);
        $this->assertStringContainsString('Kōwhai van', $html);
        $this->assertStringContainsString('19 Sep 2026 – 21 Sep 2026', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('1 personal trip left out.', $html);
        $this->assertSame(1, $report['totals']['trips']);
        $this->assertSame(['personal' => 1, 'restricted' => 0], $report['excluded']);

        $this->assertSame(2, AuditLog::query()->where('action', 'fleet.trip_history.exported')
            ->where('auditable_id', $vehicle->id)->count());
        $this->actingAs($viewer)->getJson("{$base}/pdf?from=2026-09-21&to=2026-09-19")
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->actingAs($viewer)->getJson("{$base}/excel?to=2026-09-19")
            ->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->actingAs($viewer)->getJson("{$base}/excel?from=2026-09-01&to=2026-09-02")
            ->assertUnprocessable()->assertJsonValidationErrors('range');
        $this->actingAs($viewer)->getJson("{$base}/csv?from=2026-09-19&to=2026-09-21")->assertNotFound();
        $this->actingAs($outsider)->getJson("{$base}/pdf?from=2026-09-19&to=2026-09-21")->assertNotFound();
    }

    public function test_driving_metrics_count_queclink_harsh_reports_and_speed_episodes(): void
    {
        $vehicle = $this->vehicle($this->site);
        $service = app(FleetDrivingMetricsService::class);
        $state = new FleetVehicleStateSnapshot(['asset_id' => $vehicle->id]);
        config(['fleet.behaviour.speeding_kph' => 100]);
        $previous = null;
        $at = $this->local('2026-09-21 08:00');
        $feed = [
            [30, 'harsh_behaviour', ['report_id_type' => '10']],  // braking
            [30, 'harsh_behaviour', ['report_id_type' => '21']],  // acceleration
            [30, 'harsh_behaviour', ['report_id_type' => '32']],  // cornering
            [30, 'harsh_behaviour', []],                          // type not reported
            [30, 'harsh_braking', []],                            // earlier integrations
            [104, 'speed_alarm', ['report_id_type' => '10']],     // alarm start
            [96, 'speed_alarm', ['report_id_type' => '11']],      // back in range
            [90, 'location_report', []],
            [105, 'location_report', []],                         // crosses the threshold
            [110, 'location_report', []],                         // same episode
            [95, 'location_report', []],
            [102, 'location_report', []],                         // a new episode
        ];
        foreach ($feed as $index => [$speed, $type, $payload]) {
            $event = $this->sample($vehicle, $at->addSeconds($index * 30), $speed, ['event_type' => $type, 'raw_payload' => $payload]);
            $service->handleTelemetry($event, $previous, $state);
            $previous = $event;
        }

        $metric = FleetDrivingMetric::query()->where('asset_id', $vehicle->id)->sole();
        $this->assertSame(2, $metric->harsh_brake_count);
        $this->assertSame(1, $metric->accel_count);
        $this->assertSame(2, $metric->harsh_other_count);
        $this->assertSame(3, $metric->speeding_events);
        // 100 − 2×5 − 1×3 − 2×3 − 3×4.
        $this->assertSame(69, $metric->score);
    }

    public function test_trip_builder_records_maximum_speed_for_the_trips_list(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $trips = app(FleetTripService::class);
        $state = new FleetVehicleStateSnapshot([
            'asset_id' => $vehicle->id, 'latitude' => -41.2838, 'longitude' => 174.7743,
        ]);
        $start = $this->local('2026-09-21 08:00');
        $previous = null;
        foreach ([[0, 20], [60, 72], [120, 45], [540, 0]] as [$offset, $speed]) {
            $event = $this->sample($vehicle, $start->addSeconds($offset), $speed);
            $trips->handleTelemetry($event, $state, $previous);
            $state->latitude = $event->latitude;
            $state->longitude = $event->longitude;
            $previous = $event;
        }

        $trip = FleetTrip::query()->where('asset_id', $vehicle->id)->sole();
        $this->assertSame('closed', $trip->status);
        $this->assertEqualsWithDelta(72.0, $trip->max_speed_kph, 0.01);

        $this->actingAs($manager)->get('/fleet-assets/trips')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trips.data.0.id', $trip->id)
                ->where('trips.data.0.max_speed_kph', 72)
                ->where('summary.avg_speed_kph', 72)
                ->etc());
    }

    /**
     * @param  list<Site>  $sites
     * @param  list<string>  $permissions
     */
    private function siteUser(array $sites, array $permissions, ?string $name = null): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager'] + ($name ? ['name' => $name] : []));
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $sites[0]->id,
            'secondary_site_ids' => collect($sites)->skip(1)->pluck('id')->values()->all(),
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $user->permissionOverrides()->syncWithoutDetaching(collect($permissions)->mapWithKeys(fn (string $key): array => [
            Permission::query()->firstOrCreate(['key' => $key], [
                'description' => $key, 'group' => str($key)->before('.')->value(), 'module' => str($key)->before('.')->value(),
            ])->id => ['allowed' => true],
        ])->all());
        $user->unsetRelation('permissionOverrides');

        return $user;
    }

    /** @param  array<string,mixed>  $attributes */
    private function vehicle(Site $site, array $attributes = []): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id, 'home_site_id' => $site->id, 'name' => 'Kōwhai van', 'status' => 'active',
            ...$attributes,
        ]);
    }

    private function local(string $wallTime): CarbonImmutable
    {
        return CarbonImmutable::parse($wallTime, self::ZONE)->utc();
    }

    /** @param  array<string,mixed>  $attributes */
    private function trip(Asset $vehicle, string $startLocal, int $minutes, array $attributes = []): FleetTrip
    {
        $start = $this->local($startLocal);

        return FleetTrip::query()->create([
            'asset_id' => $vehicle->id,
            'started_at' => $start,
            'ended_at' => $start->addMinutes($minutes),
            'start_latitude' => -41.2838,
            'start_longitude' => 174.7743,
            'end_latitude' => -41.2865,
            'end_longitude' => 174.7762,
            'distance_km' => 3,
            'duration_s' => $minutes * 60,
            'status' => 'closed',
            'consent_blocked' => false,
            'is_personal' => false,
            ...$attributes,
        ]);
    }

    /**
     * A position every 30 seconds from the start for $minutes, all at 30 km/h
     * except the given sample indexes; the first and last are stationary.
     *
     * @param  array<int,float>  $speeds
     */
    private function regularSamples(Asset $vehicle, string $startLocal, int $minutes, array $speeds = []): void
    {
        $start = $this->local($startLocal);
        $count = $minutes * 2;
        for ($index = 0; $index <= $count; $index++) {
            $speed = $speeds[$index] ?? (($index === 0 || $index === $count) ? 0 : 30);
            $this->sample($vehicle, $start->addSeconds($index * 30), $speed, [
                'latitude' => -41.2838 - $index * 0.0001,
                'longitude' => 174.7743 + $index * 0.0001,
            ]);
        }
    }

    /** @param  array<string,mixed>  $attributes */
    private function sample(Asset $vehicle, CarbonImmutable $at, ?float $speed, array $attributes = []): FleetTelemetryEvent
    {
        return FleetTelemetryEvent::query()->create([
            'asset_id' => $vehicle->id,
            'vendor' => 'queclink',
            'vendor_message_id' => Str::uuid()->toString(),
            'occurred_at' => $at,
            'received_at' => $at,
            'latitude' => -41.2850,
            'longitude' => 174.7750,
            'speed_kph' => $speed,
            'event_type' => 'location_report',
            'idempotency_key' => hash('sha256', Str::uuid()->toString()),
            'raw_payload' => [],
            'consent_blocked' => false,
            ...$attributes,
        ]);
    }

    private function booking(Asset $vehicle, User $driver, CarbonImmutable $out, ?CarbonImmutable $returned): FleetVehicleBooking
    {
        return FleetVehicleBooking::query()->create([
            'asset_id' => $vehicle->id,
            'user_id' => $driver->id,
            'purpose' => 'Community outing',
            'starts_at' => $out,
            'ends_at' => $returned ?? $out->addHours(2),
            'checked_out_at' => $out,
            'returned_at' => $returned,
            'status' => $returned ? 'returned' : 'checked_out',
        ]);
    }
}
