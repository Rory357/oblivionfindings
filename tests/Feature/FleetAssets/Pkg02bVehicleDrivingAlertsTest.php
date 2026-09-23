<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\FleetDrivingEventReview;
use App\Models\FleetDrivingScorePolicy;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\FleetVehicleAlertAction;
use App\Models\FleetVehicleAlertPlan;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleReminder;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomNotificationService;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use App\Services\Fleet\RecordedVehicleEvents;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PKG-02B vehicle map: Driving insights (trip scores and events under the
 * trip Site rule, human event reviews, the versioned scoring policy and
 * manual speed limits) and Alerts & Control Room (recorded events sent
 * through the canonical Fleet signal outbox, the Control Room lifecycle,
 * Maintenance and follow-up linked to the response, delivery retries and the
 * draft response plan).
 */
class Pkg02bVehicleDrivingAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE = 'Pacific/Auckland';

    private Site $site;

    private Site $foreignSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        Storage::fake('private');
        $this->travelTo(Carbon::parse('2026-09-22 09:30:00', self::ZONE)->utc());
        config([
            'fleet.behaviour.speeding_kph' => 60,
            'fleet.trip.coverage_gap_seconds' => 120,
            'fleet.behaviour.score_min_coverage_pct' => 90,
            'fleet.behaviour.score_weights' => ['harsh_brake' => 5, 'accel' => 3, 'speeding' => 4, 'idle' => 0.5],
        ]);
        $this->site = Site::factory()->create(['name' => 'Kōwhai House', 'is_active' => true]);
        $this->foreignSite = Site::factory()->create(['name' => 'Rimu House', 'is_active' => true]);
        // No scanning binary is configured in tests; every upload scans clean.
        $this->app->instance(MalwareScanner::class, new class extends MalwareScanner
        {
            public function scanPath(string $path, array $settings): MalwareScanResult
            {
                return new MalwareScanResult(MalwareScanDisposition::Clean, 'test-scanner', null);
            }
        });
    }

    public function test_driving_insights_follow_the_trip_site_rule_and_withhold_private_trips(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);
        $outsider = $this->siteUser([$this->foreignSite], ['fleet.viewAny', 'fleet.manage', 'controlRoom.alerts.view']);
        $unprivileged = $this->siteUser([$this->site], []);
        $hiddenDriver = $this->siteUser([$this->foreignSite], [], 'RIMU HIDDEN DRIVER');
        $vehicle = $this->vehicle($this->site);
        $foreign = $this->vehicle($this->foreignSite);
        $trip = $this->drivingTrip($vehicle);
        $this->booking($vehicle, $hiddenDriver, $this->local('2026-09-21 07:45'), $this->local('2026-09-21 09:00'));
        $personal = $this->trip($vehicle, '2026-09-21 10:00', 20, [
            'is_personal' => true, 'distance_km' => 12, 'start_address' => 'PERSONAL START',
        ]);
        $this->regularSamples($vehicle, '2026-09-21 10:00', 20, [10 => 95, 11 => 99]);
        $restricted = $this->trip($vehicle, '2026-09-20 15:00', 15, [
            'consent_blocked' => true, 'distance_km' => 6, 'end_address' => 'RESTRICTED END',
        ]);
        $foreignTrip = $this->drivingTrip($foreign);
        $base = "/fleet-assets/vehicles/{$vehicle->id}";

        // Only the business trip is scored, listed or totalled; the personal and
        // consent-blocked trips are counted as withheld and nothing more.
        $response = $this->actingAs($viewer)->getJson("{$base}/driving")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('period.key', 'week')
            ->assertJsonPath('policy.version', 1)
            ->assertJsonPath('policy.source', 'fleet_settings')
            ->assertJsonPath('summary.trips', 1)
            ->assertJsonPath('summary.withheld.personal', 1)
            ->assertJsonPath('summary.withheld.consent', 1)
            ->assertJsonPath('summary.overspeed_episodes', 1)
            ->assertJsonPath('summary.overspeed_seconds', 60)
            ->assertJsonPath('trips.0.id', $trip->id)
            // 100 − 5 braking − 4 overspeed.
            ->assertJsonPath('trips.0.score', 91)
            ->assertJsonPath('trips.0.score_state', 'scored')
            ->assertJsonPath('score.kind', 'vehicle')
            ->assertJsonPath('score.value', 91)
            ->assertJsonCount(2, 'events')
            ->assertJsonCount(1, 'overspeed')
            ->assertJsonPath('overspeed.0.trip_id', $trip->id)
            ->assertJsonPath('overspeed.0.route', null)
            ->assertJsonPath('can.review', false)
            ->assertJsonPath('can.route', false)
            ->assertJsonPath('can.view_alerts', false);
        $this->assertEqualsWithDelta(68.0, $response->json('overspeed.0.peak_kph'), 0.01);
        $this->assertSame(
            RecordedVehicleEvents::overspeedSourceKey($trip->id, $response->json('overspeed.0.key')),
            $response->json('overspeed.0.source_key'),
        );
        $this->assertNotContains($personal->id, array_column($response->json('trips'), 'id'));
        $this->assertNotContains($personal->id, array_column($response->json('events'), 'trip_id'));
        $this->assertNotContains($restricted->id, array_column($response->json('trips'), 'id'));
        // A driver from another Site is recorded without their name.
        $response->assertJsonPath('trips.0.driver.state', 'hidden')
            ->assertJsonPath('trips.0.driver.name', null)
            ->assertJsonPath('drivers', []);
        foreach (['PERSONAL', 'RESTRICTED', 'RIMU HIDDEN DRIVER'] as $hidden) {
            $this->assertStringNotContainsString($hidden, (string) $response->getContent());
        }

        $this->actingAs($viewer)->getJson("{$base}/driving?period=today")->assertOk()
            ->assertJsonPath('period.from', '2026-09-22')
            ->assertJsonPath('summary.trips', 0)
            ->assertJsonPath('score.value', null);
        $this->actingAs($viewer)->getJson("{$base}/driving?period=year")
            ->assertUnprocessable()->assertJsonValidationErrors('period');

        // The review workspace offers business trips only.
        $this->actingAs($viewer)->getJson("{$base}/driving/reviews")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'trips')
            ->assertJsonPath('trips.0.id', $trip->id)
            ->assertJsonPath('trip.id', $trip->id)
            ->assertJsonPath('people', []);
        foreach ([$personal->id, $restricted->id, $foreignTrip->id, 987654321] as $hiddenTrip) {
            $this->actingAs($viewer)->getJson("{$base}/driving/reviews?trip={$hiddenTrip}")->assertNotFound();
        }
        $this->actingAs($viewer)->getJson("{$base}/driving/speed-limits")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('limits', [])
            ->assertJsonCount(1, 'episodes')
            ->assertJsonPath('episodes.0.trip_id', $trip->id);

        // Without alert access the alerts view lists no responses and no tracker.
        $this->actingAs($viewer)->getJson("{$base}/alerts")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('counts', null)
            ->assertJsonPath('items', [])
            ->assertJsonPath('recorded_events', [])
            ->assertJsonPath('can.view', false)
            ->assertJsonPath('tracker.model', null);

        $views = ['driving', 'driving/reviews', 'driving/speed-limits', 'alerts'];
        foreach ([$foreign->id, 987654321] as $hiddenVehicle) {
            foreach ($views as $view) {
                $this->actingAs($viewer)->getJson("/fleet-assets/vehicles/{$hiddenVehicle}/{$view}")->assertNotFound();
            }
        }
        foreach ($views as $view) {
            $this->actingAs($outsider)->getJson("{$base}/{$view}")->assertNotFound();
            $this->actingAs($unprivileged)->getJson("{$base}/{$view}")->assertForbidden();
        }
        auth()->logout();
        $this->getJson("{$base}/driving")->assertUnauthorized();
    }

    public function test_event_reviews_are_idempotent_version_checked_audited_and_recalculate_the_trip_score(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $tripManager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.trips.manage']);
        $owner = $this->siteUser([$this->site], [], 'Aroha Smith');
        $outsider = $this->siteUser([$this->foreignSite], [], 'Rimu Outsider');
        $vehicle = $this->vehicle($this->site);
        $trip = $this->drivingTrip($vehicle);
        $personal = $this->trip($vehicle, '2026-09-21 10:00', 20, ['is_personal' => true]);
        $base = "/fleet-assets/vehicles/{$vehicle->id}";
        $telemetry = FleetTelemetryEvent::query()->count();

        $workspace = $this->actingAs($manager)->getJson("{$base}/driving/reviews?trip={$trip->id}")->assertOk()
            ->assertJsonPath('trip.score', 91)
            ->assertJsonPath('can.review', true)
            ->assertJsonCount(2, 'trip.events');
        $this->assertContains($owner->id, array_column($workspace->json('people'), 'id'));
        $this->assertNotContains($outsider->id, array_column($workspace->json('people'), 'id'));
        $events = collect($workspace->json('trip.events'))->keyBy('type');
        $this->assertSame(0, $events['harsh-braking']['version']);
        $braking = $events['harsh-braking']['key'];
        $overspeed = $events['overspeed']['key'];
        $url = "{$base}/driving/trips/{$trip->id}/reviews";
        $dismiss = [
            'event_key' => $braking, 'outcome' => 'dismissed',
            'reason' => 'Dash camera shows a hard stop for a child on a scooter.',
            'review_owner_user_id' => $owner->id, 'confirmed' => true, 'expected_version' => 0,
        ];

        $this->actingAs($viewer)->withHeader('Idempotency-Key', 'review-viewer')->postJson($url, $dismiss)->assertForbidden();
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'review-outsider')
            ->postJson($url, ['review_owner_user_id' => $outsider->id] + $dismiss)
            ->assertUnprocessable()->assertJsonValidationErrors('review_owner_user_id');
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'review-no-reason')
            ->postJson($url, ['reason' => ''] + $dismiss)
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'review-unconfirmed')
            ->postJson($url, ['confirmed' => false] + $dismiss)
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'review-unknown')
            ->postJson($url, ['event_key' => 'harsh-braking-999999'] + $dismiss)
            ->assertUnprocessable()->assertJsonValidationErrors('event_key');
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'review-personal')
            ->postJson("{$base}/driving/trips/{$personal->id}/reviews", $dismiss)->assertNotFound();
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'short')->postJson($url, $dismiss)
            ->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertSame(0, FleetDrivingEventReview::query()->count());

        // A dismissed event no longer deducts points: 100 − 4 overspeed.
        $saved = $this->actingAs($manager)->withHeader('Idempotency-Key', 'review-braking-1')->postJson($url, $dismiss)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('trip.score', 96)
            ->assertJsonPath('trip.original_score', 91);
        $reviewed = collect($saved->json('trip.events'))->keyBy('type');
        $this->assertSame('dismissed', $reviewed['harsh-braking']['review']['outcome']);
        $this->assertSame('Aroha Smith', $reviewed['harsh-braking']['review']['review_owner']);
        $this->assertSame(1, $reviewed['harsh-braking']['version']);
        // Trip history shows the same reviewed score for the trip.
        $this->actingAs($viewer)->getJson("{$base}/trip-history/{$trip->id}")->assertOk()
            ->assertJsonPath('behaviour.score', 96);

        // A retried request is answered once; a reused key with other data is refused.
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'review-braking-1')->postJson($url, $dismiss)
            ->assertOk()->assertJsonPath('trip.score', 96);
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'review-braking-1')
            ->postJson($url, ['outcome' => 'confirmed'] + $dismiss)->assertStatus(409);
        // Reviewing against an out-of-date view is refused.
        $this->actingAs($tripManager)->withHeader('Idempotency-Key', 'review-braking-stale')
            ->postJson($url, ['outcome' => 'confirmed'] + $dismiss)->assertStatus(409);
        $this->assertSame(1, FleetDrivingEventReview::query()->count());

        // A trip manager may review too; a disputed event withholds the score.
        $this->actingAs($tripManager)->withHeader('Idempotency-Key', 'review-overspeed-1')->postJson($url, [
            'event_key' => $overspeed, 'outcome' => 'disputed',
            'reason' => 'The driver says the tracker speed spiked in the tunnel.',
            'review_owner_user_id' => $owner->id, 'confirmed' => true, 'expected_version' => 0,
        ])->assertOk()
            ->assertJsonPath('trip.score', null)
            ->assertJsonPath('trip.score_state', 'disputed');
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'review-braking-2')
            ->postJson($url, ['outcome' => 'confirmed', 'expected_version' => 1] + $dismiss)->assertOk();

        $history = collect($this->actingAs($manager)->getJson("{$base}/driving/reviews?trip={$trip->id}")->assertOk()
            ->json('trip.events'))->keyBy('type');
        $this->assertSame(['confirmed', 'dismissed'], array_column($history['harsh-braking']['history'], 'outcome'));
        $this->assertSame(2, $history['harsh-braking']['version']);
        $this->actingAs($viewer)->getJson("{$base}/driving")->assertOk()
            ->assertJsonPath('trips.0.score', null)
            ->assertJsonPath('trips.0.score_state', 'disputed')
            ->assertJsonPath('trips.0.original_score', 91)
            ->assertJsonPath('summary.scored_trips', 0)
            ->assertJsonPath('score.value', null);
        $this->actingAs($viewer)->getJson("{$base}/trip-history/{$trip->id}")->assertOk()
            ->assertJsonPath('behaviour.score', null)
            ->assertJsonPath('behaviour.score_state', 'disputed');

        // Reviews are only added; the recorded telemetry is never changed.
        $this->assertSame(3, FleetDrivingEventReview::query()->count());
        $this->assertSame(3, AuditLog::query()->where('action', 'fleet.driving.event_reviewed')->count());
        $this->assertSame($telemetry, FleetTelemetryEvent::query()->count());
        $dispute = FleetDrivingEventReview::query()->where('outcome', 'disputed')->sole();
        $this->assertSame($tripManager->id, (int) $dispute->recorded_by_user_id);
        $this->assertSame(1, (int) $dispute->policy_version);
    }

    public function test_a_published_scoring_policy_versions_the_weights_and_gates_a_persons_score(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $tripManager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.trips.manage']);
        $jamie = $this->siteUser([$this->site], [], 'Jamie Taylor');
        $vehicle = $this->vehicle($this->site);
        $trip = $this->drivingTrip($vehicle);
        $this->booking($vehicle, $jamie, $this->local('2026-09-21 07:45'), $this->local('2026-09-21 09:00'));
        $base = "/fleet-assets/vehicles/{$vehicle->id}";
        $mine = "{$base}/driving?driver={$jamie->id}";

        // A booked driver is recorded, not confirmed: no score is attributed to them.
        $this->actingAs($manager)->getJson($mine)->assertOk()
            ->assertJsonPath('drivers.0.id', $jamie->id)
            ->assertJsonPath('trips.0.driver.state', 'recorded')
            ->assertJsonPath('score.kind', 'driver')
            ->assertJsonPath('score.personal_trips', 0)
            ->assertJsonPath('score.value', null);

        $this->actingAs($manager)->withHeader('Idempotency-Key', 'confirm-jamie')
            ->postJson("{$base}/trip-history/{$trip->id}/driver", [
                'driver_user_id' => $jamie->id, 'reason' => 'Key log and booking match.', 'verified' => true,
                'expected_version' => 0,
            ])->assertOk();
        $events = $this->actingAs($manager)->getJson("{$base}/driving/reviews?trip={$trip->id}")->assertOk()->json('trip.events');
        foreach ($events as $index => $event) {
            $this->actingAs($manager)->withHeader('Idempotency-Key', 'confirm-event-'.$index)
                ->postJson("{$base}/driving/trips/{$trip->id}/reviews", [
                    'event_key' => $event['key'], 'outcome' => 'confirmed', 'reason' => 'Matches the recorded trail.',
                    'review_owner_user_id' => $jamie->id, 'confirmed' => true, 'expected_version' => 0,
                ])->assertOk();
        }
        // Confirmed and fully reviewed, but the fleet settings set no minimums.
        $this->actingAs($manager)->getJson($mine)->assertOk()
            ->assertJsonPath('trips.0.driver.state', 'confirmed')
            ->assertJsonPath('score.personal_trips', 1)
            ->assertJsonPath('score.enough', false)
            ->assertJsonPath('score.value', null)
            ->assertJsonPath('policy.min_trips', null);

        $url = "{$base}/driving/policy";
        $policy = [
            'braking' => 10, 'acceleration' => 2, 'overspeed' => 6, 'idle' => 1, 'coverage' => 80, 'trips' => 1,
            'distance' => 2, 'reason' => 'Weight braking and overspeed higher after the July review.',
            'confirmed' => true, 'expected_version' => 1,
        ];
        $this->actingAs($tripManager)->withHeader('Idempotency-Key', 'policy-trip-manager')
            ->postJson($url, $policy)->assertForbidden();
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'policy-bad-coverage')
            ->postJson($url, ['coverage' => 40] + $policy)->assertUnprocessable()->assertJsonValidationErrors('coverage');
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'policy-unconfirmed')
            ->postJson($url, ['confirmed' => false] + $policy)->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'policy-v2')->postJson($url, $policy)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('policy.version', 2)
            ->assertJsonPath('policy.source', 'published')
            ->assertJsonPath('policy.min_trips', 1)
            ->assertJsonPath('policy.history.0.version', 2);
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'policy-v2')->postJson($url, $policy)->assertOk()
            ->assertJsonPath('policy.version', 2);
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'policy-v2')
            ->postJson($url, ['braking' => 9] + $policy)->assertStatus(409);
        // Publishing over a version someone else already replaced is refused.
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'policy-stale')
            ->postJson($url, ['braking' => 9] + $policy)->assertStatus(409);
        $this->assertSame(1, FleetDrivingScorePolicy::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'fleet.driving.score_policy_published')->count());

        // Version 2 scores the trip on every surface: 100 − 10 braking − 6 overspeed.
        $this->actingAs($manager)->getJson($mine)->assertOk()
            ->assertJsonPath('policy.version', 2)
            ->assertJsonPath('trips.0.score', 84)
            ->assertJsonPath('score.enough', true)
            ->assertJsonPath('score.value', 84);
        $this->actingAs($manager)->getJson("{$base}/trip-history/{$trip->id}")->assertOk()
            ->assertJsonPath('behaviour.score', 84);
    }

    public function test_manual_speed_limits_need_a_second_reviewer_and_evidence_before_an_evaluation_uses_them(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);
        $proposer = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'assets.viewAny', 'assets.documents.manage']);
        $approver = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'assets.viewAny', 'assets.documents.manage']);
        $vehicle = $this->vehicle($this->site);
        $trip = $this->drivingTrip($vehicle);
        $base = "/fleet-assets/vehicles/{$vehicle->id}";
        $url = "{$base}/driving/speed-limits";
        $limit = [
            'road_segment' => 'Great South Road', 'direction' => 'Northbound', 'limit_kph' => 50,
            'effective_from_local' => '2026-09-01T00:00', 'expires_at_local' => '2026-12-01T00:00',
            'reason' => 'Auckland Transport speed limit bylaw, schedule 4.',
        ];

        $this->actingAs($viewer)->withHeader('Idempotency-Key', 'limit-viewer')->postJson($url, $limit)->assertForbidden();
        $this->actingAs($proposer)->withHeader('Idempotency-Key', 'limit-too-fast')
            ->postJson($url, ['limit_kph' => 200] + $limit)->assertUnprocessable()->assertJsonValidationErrors('limit_kph');
        $this->actingAs($proposer)->withHeader('Idempotency-Key', 'limit-backwards')
            ->postJson($url, ['expires_at_local' => '2026-08-01T00:00'] + $limit)
            ->assertUnprocessable()->assertJsonValidationErrors('expires_at_local');
        $this->actingAs($proposer)->withHeader('Idempotency-Key', 'limit-direction')
            ->postJson($url, ['direction' => 'Sideways'] + $limit)->assertUnprocessable()->assertJsonValidationErrors('direction');

        $id = $this->actingAs($proposer)->withHeader('Idempotency-Key', 'limit-great-south')->postJson($url, $limit)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('limit.status', 'pending')
            ->assertJsonPath('limit.lock_version', 1)
            ->json('limit.id');
        $this->actingAs($proposer)->withHeader('Idempotency-Key', 'limit-great-south')->postJson($url, $limit)->assertOk()
            ->assertJsonPath('limit.id', $id);
        $this->actingAs($proposer)->withHeader('Idempotency-Key', 'limit-great-south')
            ->postJson($url, ['limit_kph' => 40] + $limit)->assertStatus(409);

        $approve = "{$url}/{$id}/approve";
        $decision = ['reason' => 'Checked the bylaw schedule and the sign photo.', 'confirmed' => true, 'expected_version' => 1];
        $this->actingAs($proposer)->withHeader('Idempotency-Key', 'approve-own-limit')->postJson($approve, $decision)
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Someone other than the person who proposed this limit must approve it.');
        $this->actingAs($approver)->withHeader('Idempotency-Key', 'approve-without-file')->postJson($approve, $decision)
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Add the sign photo or authority document before approval.');

        $file = $this->evidence($proposer, $vehicle, $id, 'great-south-road-sign.pdf', 'limit-evidence');
        $this->assertSame('available', $file['state']);
        $this->actingAs($viewer)->getJson($url)->assertOk()
            ->assertJsonPath('limits.0.id', $id)
            ->assertJsonPath('limits.0.status', 'pending')
            ->assertJsonPath('limits.0.files.0.name', 'great-south-road-sign.pdf')
            ->assertJsonPath('limits.0.proposed_by_me', false)
            ->assertJsonPath('limits.0.history.0.action', 'proposed')
            ->assertJsonPath('rule.source', 'fleet_setting')
            ->assertJsonPath('episodes.0.trip_id', $trip->id)
            ->assertJsonPath('can.manage', false);

        $this->actingAs($approver)->withHeader('Idempotency-Key', 'approve-stale-limit')
            ->postJson($approve, ['expected_version' => 2] + $decision)->assertStatus(409);
        $this->actingAs($approver)->withHeader('Idempotency-Key', 'approve-limit')->postJson($approve, $decision)->assertOk()
            ->assertJsonPath('limit.status', 'approved')
            ->assertJsonPath('limit.lock_version', 2);
        $this->actingAs($approver)->withHeader('Idempotency-Key', 'approve-limit')->postJson($approve, $decision)->assertOk()
            ->assertJsonPath('limit.lock_version', 2);

        // A second approved limit for the same road, direction and time would conflict.
        $second = $this->actingAs($proposer)->withHeader('Idempotency-Key', 'limit-overlap')
            ->postJson($url, ['limit_kph' => 40, 'road_segment' => ' great  south road '] + $limit)->assertOk()
            ->json('limit.id');
        $this->evidence($proposer, $vehicle, $second, 'overlap-sign.pdf', 'limit-overlap-evidence');
        $this->actingAs($approver)->withHeader('Idempotency-Key', 'approve-overlap')
            ->postJson("{$url}/{$second}/approve", $decision)->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'An approved limit overlaps this road, direction and time. Retire or correct it before approval.');

        // An evaluation sends an episode only when it qualifies under the
        // evidence and the vehicle's draft rule (60 km/h + 10 km/h for 30 s).
        Queue::fake();
        $this->actingAs($approver)->withHeader('Idempotency-Key', 'plan-for-limits')->putJson("{$base}/alert-plan", [
            'owner_user_id' => $approver->id, 'backup_user_id' => $proposer->id, 'speed_threshold_kph' => 60,
            'speed_tolerance_kph' => 10, 'speed_duration_s' => 30, 'speed_cooldown_s' => 60, 'offline_minutes' => 30,
            'low_voltage_v' => 11.5, 'low_voltage_minutes' => 10, 'notes' => 'Call the driver, then the house lead.',
            'expected_version' => 0,
        ])->assertOk();
        $episode = $this->actingAs($approver)->getJson($url)->assertOk()
            ->assertJsonPath('rule.source', 'plan')
            ->json('episodes.0');
        $route = "{$base}/alerts/route";
        $send = ['kind' => 'overspeed', 'trip_id' => $trip->id, 'event_key' => $episode['event_key']];
        // No approved limit for this road: the trigger is 70 km/h and the 68 km/h peak does not qualify.
        $this->actingAs($approver)->withHeader('Idempotency-Key', 'route-unmapped-road')
            ->postJson($route, $send + ['evaluation' => ['segment' => 'Dominion Road', 'direction' => 'Northbound']])
            ->assertUnprocessable()->assertJsonValidationErrors('evaluation');
        $this->assertSame(0, FleetSignal::query()->count());
        $this->assertSame(0, FleetVehicleAlertAction::query()->count());
        // The approved 50 km/h limit makes the trigger 60 km/h, so it does.
        $this->actingAs($approver)->withHeader('Idempotency-Key', 'route-great-south')
            ->postJson($route, $send + ['evaluation' => ['segment' => 'great south road', 'direction' => 'Northbound']])
            ->assertOk()->assertJsonPath('duplicate', false);
        $signal = FleetSignal::query()->sole();
        $this->assertSame('vehicle.overspeed', $signal->signal_type);
        $this->assertSame(50, $signal->payload['evidence']['road_limit']);
        $this->assertSame($id, $signal->payload['evidence']['evaluation']['limit_id']);
        $this->assertSame('plan', $signal->payload['evidence']['evaluation']['rule_source']);

        $retire = "{$url}/{$id}/retire";
        $this->actingAs($proposer)->withHeader('Idempotency-Key', 'retire-limit')
            ->postJson($retire, ['reason' => 'Road re-signed at 40 km/h.', 'confirmed' => true, 'expected_version' => 2])
            ->assertOk()->assertJsonPath('limit.status', 'retired');
        $this->actingAs($proposer)->withHeader('Idempotency-Key', 'retire-limit-again')
            ->postJson($retire, ['reason' => 'Again.', 'confirmed' => true, 'expected_version' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->assertSame(2, AuditLog::query()->where('action', 'fleet.speed_limit.proposed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'fleet.speed_limit.approved')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'fleet.speed_limit.retired')->count());
    }

    public function test_the_draft_response_plan_is_versioned_and_owned_by_site_staff(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny', 'controlRoom.alerts.view']);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $owner = $this->siteUser([$this->site], [], 'Aroha Smith');
        $backup = $this->siteUser([$this->site], [], 'Ben Jones');
        $outsider = $this->siteUser([$this->foreignSite], [], 'Rimu Outsider');
        $vehicle = $this->vehicle($this->site);
        $foreign = $this->vehicle($this->foreignSite);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/alert-plan";
        $plan = [
            'owner_user_id' => $owner->id, 'backup_user_id' => $backup->id, 'speed_threshold_kph' => 100,
            'speed_tolerance_kph' => 5, 'speed_duration_s' => 15, 'speed_cooldown_s' => 60, 'offline_minutes' => 30,
            'low_voltage_v' => 11.5, 'low_voltage_minutes' => 10,
            'notes' => 'Call the driver first, then the house lead.', 'expected_version' => 0,
        ];

        $this->actingAs($viewer)->withHeader('Idempotency-Key', 'plan-viewer')->putJson($url, $plan)->assertForbidden();
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'plan-foreign')
            ->putJson("/fleet-assets/vehicles/{$foreign->id}/alert-plan", $plan)->assertNotFound();
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'plan-same-backup')
            ->putJson($url, ['backup_user_id' => $owner->id] + $plan)
            ->assertUnprocessable()->assertJsonValidationErrors('backup_user_id');
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'plan-outsider')
            ->putJson($url, ['owner_user_id' => $outsider->id] + $plan)
            ->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'plan-voltage')
            ->putJson($url, ['low_voltage_v' => 40] + $plan)
            ->assertUnprocessable()->assertJsonValidationErrors('low_voltage_v');

        $this->actingAs($manager)->withHeader('Idempotency-Key', 'plan-version-1')->putJson($url, $plan)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('plan.version', 1)
            ->assertJsonPath('plan.status', 'draft')
            ->assertJsonPath('plan.owner.name', 'Aroha Smith')
            ->assertJsonPath('plan.backup.name', 'Ben Jones');
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'plan-version-1')->putJson($url, $plan)->assertOk()
            ->assertJsonPath('plan.version', 1);
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'plan-version-1')
            ->putJson($url, ['speed_threshold_kph' => 90] + $plan)->assertStatus(409);
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'plan-stale')
            ->putJson($url, ['speed_threshold_kph' => 90] + $plan)->assertStatus(409);
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'plan-no-reason')
            ->putJson($url, ['speed_threshold_kph' => 90, 'expected_version' => 1] + $plan)
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'plan-version-2')->putJson($url, [
            'speed_threshold_kph' => 90, 'expected_version' => 1, 'reason' => 'Lower threshold for the school route.',
        ] + $plan)->assertOk()
            ->assertJsonPath('plan.version', 2)
            ->assertJsonPath('plan.speed_threshold_kph', 90);

        $this->assertSame([1, 2], FleetVehicleAlertPlan::query()->orderBy('version')->pluck('version')->map(fn ($version): int => (int) $version)->all());
        $this->assertSame(2, AuditLog::query()->where('action', 'fleet.vehicle.alert_plan_saved')->count());
        // A draft is shown with the vehicle's alerts; it changes no device setting.
        $this->actingAs($viewer)->getJson("/fleet-assets/vehicles/{$vehicle->id}/alerts")->assertOk()
            ->assertJsonPath('plan.version', 2)
            ->assertJsonPath('plan.status', 'draft')
            ->assertJsonPath('plan.owner.name', 'Aroha Smith')
            ->assertJsonPath('can.plan', false);
    }

    public function test_a_recorded_overspeed_reaches_control_room_and_its_response_links_maintenance_and_a_follow_up(): void
    {
        Queue::fake();
        $this->fleetSource('active');
        $this->quietControlRoomNotifications();
        $sender = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $responder = $this->siteUser([$this->site], [
            'fleet.viewAny', 'controlRoom.alerts.view', 'controlRoom.alerts.manage', 'controlRoom.alerts.escalate',
            'fleet.maintenance.report',
        ]);
        $lead = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'controlRoom.alerts.view'], 'Hemi Walker');
        $alertViewer = $this->siteUser([$this->site], ['fleet.viewAny', 'controlRoom.alerts.view']);
        $fleetOnly = $this->siteUser([$this->site], ['fleet.viewAny']);
        $outsider = $this->siteUser([$this->foreignSite], [
            'fleet.viewAny', 'fleet.manage', 'controlRoom.alerts.view', 'controlRoom.alerts.manage',
        ]);
        $coordinator = $this->siteUser([$this->site], [], 'Mere Coordinator');
        $backup = $this->siteUser([$this->site], [], 'Tama Backup');
        $vehicle = $this->vehicle($this->site);
        $foreign = $this->vehicle($this->foreignSite);
        $trip = $this->drivingTrip($vehicle);
        $personal = $this->trip($vehicle, '2026-09-21 10:00', 20, ['is_personal' => true]);
        $this->regularSamples($vehicle, '2026-09-21 10:00', 20, [10 => 95, 11 => 99]);
        $base = "/fleet-assets/vehicles/{$vehicle->id}";

        $recorded = $this->actingAs($sender)->getJson("{$base}/alerts")->assertOk()
            ->assertJsonPath('can.route', true)
            ->assertJsonPath('can.view', false)
            ->assertJsonPath('items', [])
            ->assertJsonCount(1, 'recorded_events')
            ->assertJsonPath('recorded_events.0.kind', 'overspeed')
            ->assertJsonPath('recorded_events.0.trip_id', $trip->id)
            ->assertJsonPath('recorded_events.0.route', null);
        $eventKey = $recorded->json('recorded_events.0.event_key');
        $route = "{$base}/alerts/route";
        $send = ['kind' => 'overspeed', 'trip_id' => $trip->id, 'event_key' => $eventKey];

        $this->actingAs($alertViewer)->withHeader('Idempotency-Key', 'route-viewer')->postJson($route, $send)->assertForbidden();
        $this->actingAs($sender)->withHeader('Idempotency-Key', 'route-personal')
            ->postJson($route, ['trip_id' => $personal->id] + $send)->assertNotFound();
        $this->actingAs($sender)->withHeader('Idempotency-Key', 'route-unknown')
            ->postJson($route, ['event_key' => 'overspeed-999999'] + $send)
            ->assertUnprocessable()->assertJsonValidationErrors('event_key');
        $this->actingAs($sender)->withHeader('Idempotency-Key', 'route-foreign')
            ->postJson("/fleet-assets/vehicles/{$foreign->id}/alerts/route", $send)->assertNotFound();
        $this->actingAs($outsider)->withHeader('Idempotency-Key', 'route-outsider')->postJson($route, $send)->assertNotFound();
        $this->assertSame(0, FleetSignal::query()->count());

        // Sent through the canonical Fleet signal outbox; the sender cannot read
        // Control Room, so no response reference comes back to them.
        $sent = $this->actingAs($sender)->withHeader('Idempotency-Key', 'route-overspeed-1')->postJson($route, $send)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('delivery', 'pending')
            ->assertJsonPath('response', null);
        $signalId = $sent->json('signal_id');
        $this->actingAs($sender)->withHeader('Idempotency-Key', 'route-overspeed-1')->postJson($route, $send)->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('signal_id', $signalId);
        $this->actingAs($sender)->withHeader('Idempotency-Key', 'route-overspeed-1')
            ->postJson($route, ['kind' => 'telemetry', 'event_id' => 1])->assertStatus(409);
        // The same recorded event sent again joins its signal as a duplicate report.
        $this->actingAs($sender)->withHeader('Idempotency-Key', 'route-overspeed-2')->postJson($route, $send)->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('signal_id', $signalId);

        $signal = FleetSignal::query()->sole();
        $this->assertSame($signalId, $signal->id);
        $this->assertSame('vehicle.overspeed', $signal->signal_type);
        $this->assertSame($trip->id, (int) $signal->trip_id);
        $this->assertSame(RecordedVehicleEvents::signalKey($vehicle->id,
            RecordedVehicleEvents::overspeedSourceKey($trip->id, $eventKey)), $signal->idempotency_key);
        $this->assertFalse($signal->payload['privacy_blocked']);
        $this->assertSame('not_checked', $signal->payload['evidence']['road_limit']);
        $this->assertSame(2, FleetVehicleAlertAction::query()->where('action', 'routed')->count());
        $this->assertSame(1, FleetSignalOutbox::query()->count());

        $this->deliver($signal);
        $alert = ControlRoomAlert::query()->sole();
        $this->assertSame($vehicle->id, (int) $alert->asset_id);
        $this->assertSame($this->site->id, (int) $alert->site_id);
        $this->assertSame('queclink_fleet', $alert->source);
        $this->assertSame('fleet_vehicle_overspeed', data_get($alert->context, 'signal_type_code'));
        $this->assertSame(hash('sha256', 'safety-signal|fleet|'.$signal->idempotency_key), Signal::query()->sole()->idempotency_key);
        $foreignAlert = ControlRoomAlert::factory()->fromFleet()->open()->create([
            'site_id' => $this->foreignSite->id, 'asset_id' => $foreign->id,
        ]);

        $this->actingAs($sender)->getJson("{$base}/driving")->assertOk()
            ->assertJsonPath('overspeed.0.route.signal_id', $signalId)
            ->assertJsonPath('overspeed.0.route.delivery', 'sent')
            ->assertJsonPath('overspeed.0.route.response', null);
        $this->actingAs($responder)->getJson("{$base}/alerts")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('counts.open', 1)
            ->assertJsonPath('counts.all', 1)
            ->assertJsonPath('items.0.id', $alert->id)
            ->assertJsonPath('items.0.type', 'response')
            ->assertJsonPath('items.0.kind', 'Overspeed threshold')
            ->assertJsonPath('items.0.status', 'open')
            ->assertJsonPath('items.0.duplicates', 1)
            ->assertJsonPath('items.0.signal_id', $signalId)
            ->assertJsonPath('recorded_events', [])
            ->assertJsonPath('can.manage', true)
            ->assertJsonPath('can.route', false);

        $detail = $this->actingAs($responder)->getJson("{$base}/alerts/{$alert->id}")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('kind_key', 'overspeed')
            ->assertJsonPath('source.trip.id', $trip->id)
            ->assertJsonPath('source.sent_by', $sender->name)
            ->assertJsonPath('correlation.signal_id', $signalId)
            ->assertJsonPath('correlation.duplicates', 1)
            ->assertJsonPath('location.basis', 'recorded_event')
            ->assertJsonPath('location_withheld', null)
            ->assertJsonPath('driver.state', 'none')
            ->assertJsonPath('review_source', null)
            ->assertJsonPath('can.acknowledge', true)
            ->assertJsonPath('can.maintenance_create', false);
        $this->assertStringContainsString('68 km/h peak', $detail->json('evidence'));
        $this->assertStringContainsString('The road speed limit is not checked.', $detail->json('evidence'));
        $this->actingAs($fleetOnly)->getJson("{$base}/alerts/{$alert->id}")->assertForbidden();
        $this->actingAs($outsider)->getJson("{$base}/alerts/{$alert->id}")->assertNotFound();
        $this->actingAs($responder)->getJson("{$base}/alerts/{$foreignAlert->id}")->assertNotFound();
        $this->actingAs($responder)->getJson("{$base}/alerts/987654321")->assertNotFound();

        $act = fn (string $action): string => "{$base}/alerts/{$alert->id}/{$action}";
        $version = $detail->json('version');
        $ack = ['note' => 'Called the house lead; the van is parked safely.', 'expected_version' => $version];
        $this->actingAs($alertViewer)->withHeader('Idempotency-Key', 'ack-viewer')
            ->postJson($act('acknowledge'), $ack)->assertForbidden();
        $this->actingAs($responder)->withHeader('Idempotency-Key', 'ack-no-note')
            ->postJson($act('acknowledge'), ['note' => ''] + $ack)
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->actingAs($responder)->withHeader('Idempotency-Key', 'ack-stale-view')
            ->postJson($act('acknowledge'), ['expected_version' => str_repeat('0', 64)] + $ack)->assertStatus(409);
        $acknowledged = $this->actingAs($responder)->withHeader('Idempotency-Key', 'ack-response-1')
            ->postJson($act('acknowledge'), $ack)->assertOk()
            ->assertJsonPath('response.status', 'ack')
            ->assertJsonPath('response.can.acknowledge', false);
        $this->actingAs($responder)->withHeader('Idempotency-Key', 'ack-response-1')->postJson($act('acknowledge'), $ack)
            ->assertOk()->assertJsonPath('response.status', 'ack');
        $this->actingAs($responder)->withHeader('Idempotency-Key', 'ack-response-1')
            ->postJson($act('acknowledge'), ['note' => 'Something else.'] + $ack)->assertStatus(409);
        $this->actingAs($responder)->withHeader('Idempotency-Key', 'ack-response-2')
            ->postJson($act('acknowledge'), $ack)->assertStatus(409);

        $version = $acknowledged->json('response.version');
        $this->actingAs($responder)->withHeader('Idempotency-Key', 'triage-no-decision')
            ->postJson($act('triage'), ['note' => 'Needs a look.', 'expected_version' => $version])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $triaged = $this->actingAs($responder)->withHeader('Idempotency-Key', 'triage-response-1')->postJson($act('triage'), [
            'decision' => 'maintenance', 'note' => 'Possible speed limiter fault; needs a workshop check.',
            'expected_version' => $version,
        ])->assertOk()
            ->assertJsonPath('response.status', 'triaging')
            ->assertJsonPath('response.decision.key', 'maintenance')
            ->assertJsonPath('response.can.maintenance_create', true)
            ->assertJsonPath('response.can.maintenance_link', false);

        // Maintenance goes through the PKG-01 report path with the response as its source.
        $maintenance = "{$base}/alerts/{$alert->id}/maintenance";
        $work = [
            'mode' => 'create', 'title' => 'Check the speed limiter',
            'description' => 'Control Room triage asked for a workshop check.',
            'expected_version' => $triaged->json('response.version'),
        ];
        $this->actingAs($lead)->withHeader('Idempotency-Key', 'maintenance-lead')->postJson($maintenance, $work)->assertForbidden();
        $this->actingAs($responder)->withHeader('Idempotency-Key', 'maintenance-create-1')->postJson($maintenance, $work)
            ->assertUnprocessable()->assertJsonValidationErrors('asset_id');
        $this->assertSame(0, FleetWorkOrder::query()->count());
        DB::table('fleet_maintenance_site_routes')->insert([
            'site_id' => $this->site->id, 'coordinator_user_id' => $coordinator->id, 'backup_user_id' => $backup->id,
            'approved_by_user_id' => $lead->id, 'approved_at' => now(), 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $linked = $this->actingAs($responder)->withHeader('Idempotency-Key', 'maintenance-create-1')->postJson($maintenance, $work)
            ->assertOk()
            ->assertJsonPath('response.can.maintenance_create', false);
        $order = FleetWorkOrder::query()->sole();
        $linked->assertJsonPath('response.work.id', $order->id);
        $this->assertSame($coordinator->id, (int) $order->assigned_to_user_id);
        $report = DB::table('fleet_maintenance_reports')->where('work_order_id', $order->id)->sole();
        $this->assertSame('control_room_alert', $report->source_type);
        $this->assertSame($alert->id, (int) $report->source_id);
        $this->actingAs($responder)->withHeader('Idempotency-Key', 'maintenance-create-1')->postJson($maintenance, $work)->assertOk();
        $this->assertSame(1, FleetWorkOrder::query()->count());
        $this->actingAs($responder)->withHeader('Idempotency-Key', 'maintenance-create-2')
            ->postJson($maintenance, ['expected_version' => $linked->json('response.version')] + $work)->assertStatus(409);

        // A vehicle follow-up reminder linked to the response; Control Room is not changed.
        $followUp = "{$base}/alerts/{$alert->id}/follow-up";
        $reminder = [
            'title' => 'Coach the driver on the Great South Road episode',
            'action_text' => 'Talk through the 68 km/h episode and the limiter check.',
            'remind_local' => '2026-09-25T09:00', 'owner_user_id' => $coordinator->id,
        ];
        $this->actingAs($sender)->withHeader('Idempotency-Key', 'follow-up-sender')->postJson($followUp, $reminder)->assertForbidden();
        $this->actingAs($lead)->withHeader('Idempotency-Key', 'follow-up-no-action')
            ->postJson($followUp, ['action_text' => ''] + $reminder)
            ->assertUnprocessable()->assertJsonValidationErrors('action_text');
        $followed = $this->actingAs($lead)->withHeader('Idempotency-Key', 'follow-up-1')->postJson($followUp, $reminder)->assertOk()
            ->assertJsonPath('response.follow_ups.0.title', 'Coach the driver on the Great South Road episode')
            ->assertJsonPath('response.follow_ups.0.owner', 'Mere Coordinator')
            ->assertJsonPath('response.follow_ups.0.state', 'scheduled')
            ->assertJsonPath('response.review_source.trip_id', $trip->id)
            ->assertJsonPath('response.review_source.event_key', $eventKey);
        $this->actingAs($lead)->withHeader('Idempotency-Key', 'follow-up-1')->postJson($followUp, $reminder)->assertOk();
        $savedReminder = FleetVehicleReminder::query()->sole();
        $this->assertSame('vehicle', $savedReminder->source_type);
        $this->assertStringContainsString('Linked Control Room response: '.$followed->json('response.reference'), $savedReminder->action_text);

        $escalated = $this->actingAs($responder)->withHeader('Idempotency-Key', 'escalate-response-1')->postJson($act('escalate'), [
            'note' => 'Second overspeed on this route this month.', 'expected_version' => $followed->json('response.version'),
        ])->assertOk()
            ->assertJsonPath('response.escalation_level', 1)
            ->assertJsonPath('response.status', 'triaging');
        $version = $escalated->json('response.version');
        $this->actingAs($responder)->withHeader('Idempotency-Key', 'resolve-no-outcome')
            ->postJson($act('resolve'), ['note' => 'Done.', 'expected_version' => $version])
            ->assertUnprocessable()->assertJsonValidationErrors('outcome');
        $resolved = $this->actingAs($responder)->withHeader('Idempotency-Key', 'resolve-response-1')->postJson($act('resolve'), [
            'outcome' => 'incident_confirmed', 'note' => 'Limiter fault confirmed; the workshop check is booked.',
            'expected_version' => $version,
        ])->assertOk()
            ->assertJsonPath('response.status', 'resolved')
            ->assertJsonPath('response.resolution', 'Incident confirmed')
            ->assertJsonPath('response.work.id', $order->id)
            ->assertJsonPath('response.can.resolve', false);
        // Resolving the response never closes the linked work.
        $this->assertSame('open', FleetWorkOrder::query()->sole()->status);
        $this->actingAs($responder)->withHeader('Idempotency-Key', 'escalate-closed')->postJson($act('escalate'), [
            'note' => 'Too late.', 'expected_version' => $resolved->json('response.version'),
        ])->assertStatus(409);

        $history = array_column($resolved->json('response.history'), 'text');
        $this->assertContains($sender->name.' sent the recorded event to Control Room', $history);
        $this->assertContains($sender->name.' sent the same recorded event again; added as a duplicate report', $history);
        $this->assertSame(
            ['acknowledged', 'triaged', 'maintenance_created', 'follow_up_created', 'escalated', 'resolved'],
            FleetVehicleAlertAction::query()->where('control_room_alert_id', $alert->id)->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame($order->id, (int) FleetVehicleAlertAction::query()->where('action', 'maintenance_created')->value('work_order_id'));
        $this->assertSame($savedReminder->id, (int) FleetVehicleAlertAction::query()->where('action', 'follow_up_created')->value('reminder_id'));
        foreach ([
            'fleet.vehicle.alert_routed' => 2,
            'fleet.vehicle.alert_acknowledged' => 1,
            'fleet.vehicle.alert_triaged' => 1,
            'fleet.vehicle.alert_maintenance_created' => 1,
            'fleet.vehicle.alert_follow_up_created' => 1,
            'fleet.vehicle.alert_escalated' => 1,
            'fleet.vehicle.alert_resolved' => 1,
            'controlRoom.alert.escalate' => 1,
        ] as $action => $count) {
            $this->assertSame($count, AuditLog::query()->where('action', $action)->count(), $action);
        }

        $this->actingAs($responder)->getJson("{$base}/alerts?status=resolved")->assertOk()
            ->assertJsonPath('counts.open', 0)
            ->assertJsonPath('counts.resolved', 1)
            ->assertJsonPath('items.0.decision.key', 'maintenance')
            ->assertJsonPath('items.0.work.id', $order->id);
        // Someone who may read Control Room sees the response from the driving view.
        $this->actingAs($lead)->getJson("{$base}/driving")->assertOk()
            ->assertJsonPath('overspeed.0.route.response.id', $alert->id)
            ->assertJsonPath('overspeed.0.route.response.status', 'resolved');
    }

    public function test_an_undelivered_vehicle_signal_is_retried_through_recovery_and_private_reports_keep_no_trip_or_place(): void
    {
        Queue::fake();
        $source = $this->fleetSource('inactive');
        $this->quietControlRoomNotifications();
        $manager = $this->siteUser([$this->site], [
            'fleet.viewAny', 'fleet.manage', 'controlRoom.alerts.view', 'controlRoom.alerts.manage',
        ]);
        $technician = $this->siteUser([$this->site], ['fleet.viewAny', 'controlRoom.alerts.view', 'securityDevices.devices.view']);
        $lead = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'controlRoom.alerts.view']);
        $vehicle = $this->vehicle($this->site);
        $other = $this->vehicle($this->site, ['name' => 'Tōtara van']);
        $device = Device::factory()->tracking()->create([
            'name' => 'Van 14 tracker', 'category' => 'vehicle_tracker', 'model' => 'GV500CG', 'last_seen_at' => now(),
        ]);
        $powerOff = $this->sample($vehicle, $this->local('2026-09-22 07:10'), 0, [
            'event_type' => 'power_off', 'device_id' => $device->id, 'battery_pct' => 81,
        ]);
        // A low-voltage report during a personal trip.
        $this->trip($vehicle, '2026-09-22 08:00', 30, ['is_personal' => true]);
        $lowVoltage = $this->sample($vehicle, $this->local('2026-09-22 08:10'), 40, [
            'event_type' => 'external_power', 'device_id' => $device->id, 'latitude' => -36.9123, 'longitude' => 174.8123,
        ]);
        $base = "/fleet-assets/vehicles/{$vehicle->id}";

        $events = collect($this->actingAs($manager)->getJson("{$base}/alerts")->assertOk()
            ->assertJsonPath('tracker.model', null)
            ->json('recorded_events'))->keyBy('kind');
        $this->assertSame($powerOff->id, $events['power_disconnected']['event_id']);
        $this->assertSame($lowVoltage->id, $events['low_voltage']['event_id']);
        // The tracker model shows only with the vehicle technology view.
        $this->actingAs($technician)->getJson("{$base}/alerts")->assertOk()
            ->assertJsonPath('tracker.model', 'GV500CG')
            ->assertJsonPath('tracker.family', 'gv500cg')
            ->assertJsonPath('recorded_events', []);

        $route = "{$base}/alerts/route";
        $sent = $this->actingAs($manager)->withHeader('Idempotency-Key', 'route-power-off')
            ->postJson($route, ['kind' => 'telemetry', 'event_id' => $powerOff->id])->assertOk()
            ->assertJsonPath('delivery', 'pending');
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'route-other-event')
            ->postJson($route, ['kind' => 'telemetry', 'event_id' => 987654321])->assertNotFound();
        $signal = FleetSignal::query()->findOrFail($sent->json('signal_id'));
        $this->assertSame('vehicle.power_disconnected', $signal->signal_type);
        $this->assertNull($signal->trip_id);
        $this->deliver($signal);
        $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $signal->id)->sole();
        $this->assertSame('unroutable', $outbox->status);
        $this->assertSame(0, ControlRoomAlert::query()->count());

        // The undelivered signal is listed for retry.
        $this->actingAs($manager)->getJson("{$base}/alerts")->assertOk()
            ->assertJsonPath('counts.open', 1)
            ->assertJsonPath('items.0.id', 'signal:'.$signal->id)
            ->assertJsonPath('items.0.type', 'delivery')
            ->assertJsonPath('items.0.status', 'delivery_failed')
            ->assertJsonPath('items.0.kind', 'Power disconnected')
            ->assertJsonPath('items.0.delivery', 'unroutable')
            ->assertJsonPath('items.0.attempts', 1);

        $retry = "{$base}/alerts/signals/{$signal->id}/retry";
        $this->actingAs($lead)->withHeader('Idempotency-Key', 'retry-lead')->postJson($retry, ['expected_attempts' => 1])->assertForbidden();
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'retry-stale-view')
            ->postJson($retry, ['expected_attempts' => 3])->assertStatus(409);
        $otherSignal = FleetSignal::query()->create([
            'asset_id' => $other->id, 'signal_type' => 'vehicle.sos', 'severity_hint' => 'critical',
            'occurred_at' => now(), 'idempotency_key' => hash('sha256', 'pkg02b-other-vehicle-signal'),
        ]);
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'retry-other-vehicle')
            ->postJson("{$base}/alerts/signals/{$otherSignal->id}/retry", ['expected_attempts' => 0])->assertNotFound();
        $this->assertSame(0, FleetVehicleAlertAction::query()->where('action', 'delivery_retried')->count());

        $source->update(['status' => 'active']);
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'retry-delivery')->postJson($retry, ['expected_attempts' => 1])
            ->assertOk()->assertJsonPath('delivery', 'pending');
        $this->deliver($signal);
        $this->assertSame('sent', $outbox->fresh()->status);
        $alert = ControlRoomAlert::query()->sole();
        $this->actingAs($manager)->withHeader('Idempotency-Key', 'retry-delivery')->postJson($retry, ['expected_attempts' => 1])
            ->assertOk()->assertJsonPath('delivery', 'sent');
        $this->assertSame(1, FleetVehicleAlertAction::query()->where('action', 'delivery_retried')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'fleet.vehicle.alert_delivery_retried')->count());
        $this->actingAs($manager)->getJson("{$base}/alerts")->assertOk()
            ->assertJsonPath('counts.open', 1)
            ->assertJsonPath('items.0.id', $alert->id)
            ->assertJsonPath('items.0.kind', 'Power disconnected');

        // A report from a personal trip is sent as device state only.
        $private = $this->actingAs($manager)->withHeader('Idempotency-Key', 'route-low-voltage')
            ->postJson($route, ['kind' => 'telemetry', 'event_id' => $lowVoltage->id])->assertOk();
        $privateSignal = FleetSignal::query()->findOrFail($private->json('signal_id'));
        $this->assertSame('vehicle.low_voltage', $privateSignal->signal_type);
        $this->assertNull($privateSignal->trip_id);
        $this->assertTrue($privateSignal->payload['privacy_blocked']);
        $this->assertSame('personal', $privateSignal->payload['evidence']['withheld']);
        $this->assertArrayNotHasKey('location', $privateSignal->payload['evidence']);
        $this->assertNull(FleetVehicleAlertAction::query()->where('fleet_signal_id', $privateSignal->id)->sole()->evidence['location']);
        $this->deliver($privateSignal);
        $privateAlert = ControlRoomAlert::query()->whereKeyNot($alert->id)->sole();
        $this->assertTrue(data_get($privateAlert->context, 'normalized_data.privacy_blocked'));
        $this->assertNull(data_get($privateAlert->context, 'normalized_data.trip_id'));
        $privateDetail = $this->actingAs($manager)->getJson("{$base}/alerts/{$privateAlert->id}")->assertOk()
            ->assertJsonPath('kind', 'Low vehicle voltage')
            ->assertJsonPath('location', null)
            ->assertJsonPath('location_withheld', 'personal')
            ->assertJsonPath('source.trip', null)
            ->assertJsonPath('device', null);
        $this->assertStringNotContainsString('-36.9123', (string) $privateDetail->getContent());
        $this->actingAs($technician)->getJson("{$base}/alerts/{$privateAlert->id}")->assertOk()
            ->assertJsonPath('device', 'GV500CG')
            ->assertJsonPath('location', null);
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

    /**
     * A 12-minute business trip with full coverage, one overspeed episode
     * above the 60 km/h fleet setting (68 km/h peak for 60 seconds) and one
     * harsh braking report: a score of 100 − 5 − 4 = 91.
     */
    private function drivingTrip(Asset $vehicle, string $startLocal = '2026-09-21 08:00'): FleetTrip
    {
        $trip = $this->trip($vehicle, $startLocal, 12, [
            'distance_km' => 2.4, 'start_address' => 'Kōwhai House', 'end_address' => 'Community pickup',
        ]);
        $this->regularSamples($vehicle, $startLocal, 12, [8 => 64, 9 => 68, 10 => 40]);
        $this->sample($vehicle, $this->local($startLocal)->addSeconds(375), 35, ['event_type' => 'harsh_braking']);

        return $trip;
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

    /**
     * Upload a speed limit's evidence file through the vehicle documents
     * endpoint, as the Speed limits workspace does.
     *
     * @return array<string,mixed>
     */
    private function evidence(User $actor, Asset $vehicle, int $limitId, string $name, string $requestKey): array
    {
        return $this->actingAs($actor)->post("/fleet-assets/vehicles/{$vehicle->id}/documents", [
            'category' => 'Speed limit evidence', 'document_date' => '2026-09-22',
            'reason' => 'Sign photo and bylaw schedule', 'source_type' => 'speed_limit', 'source_id' => $limitId,
            'request_key' => $requestKey,
            'files' => [UploadedFile::fake()->createWithContent($name,
                "%PDF-1.4\n% {$name}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n")],
        ], ['Accept' => 'application/json'])->assertOk()->json('files.0');
    }

    private function fleetSource(string $status): SignalSource
    {
        return SignalSource::query()->updateOrCreate(['slug' => 'queclink_fleet'], [
            'name' => 'Queclink Fleet', 'vendor' => 'queclink', 'status' => $status,
        ]);
    }

    private function quietControlRoomNotifications(): void
    {
        $notifications = $this->mock(ControlRoomNotificationService::class);
        $notifications->shouldReceive('notifyAlert')->andReturnNull();
        $notifications->shouldReceive('stageAlertNotifications')->andReturn(collect());
    }

    /** Run the canonical outbox worker for a Fleet signal, as the queue would. */
    private function deliver(FleetSignal $signal): void
    {
        $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $signal->id)->sole();
        (new DispatchFleetSignalOutbox($outbox->id))->handle(app(SignalProcessingService::class));
    }
}
