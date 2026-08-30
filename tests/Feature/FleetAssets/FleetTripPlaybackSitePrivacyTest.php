<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\FleetDriverSession;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FleetTripPlaybackSitePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $visibleSite;

    private Site $hiddenSite;

    private User $viewer;

    private User $visibleDriver;

    private User $hiddenDriver;

    private Asset $visibleVehicle;

    private Asset $hiddenVehicle;

    private FleetTrip $visibleTrip;

    private FleetTrip $hiddenTrip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-08-30 12:00:00', config('app.timezone')));

        $this->visibleSite = Site::factory()->create([
            'name' => 'RUN183 Harbour Site',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $this->hiddenSite = Site::factory()->create([
            'name' => 'RUN183 Forest Site',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);

        $this->viewer = $this->siteUser($this->visibleSite, 'RUN183 Site Viewer');
        $this->grantPermission($this->viewer, 'fleet.viewAny');
        $this->visibleDriver = $this->siteUser($this->visibleSite, 'RUN183 Visible Driver');
        $this->hiddenDriver = $this->siteUser($this->hiddenSite, 'RUN183 HIDDEN DRIVER');

        $this->visibleVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $this->visibleSite->id,
            'home_site_id' => $this->visibleSite->id,
            'name' => 'RUN183 Harbour Van',
            'asset_tag' => 'RUN183-VISIBLE-VAN',
            'status' => 'active',
        ]);
        $this->hiddenVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $this->hiddenSite->id,
            'home_site_id' => $this->hiddenSite->id,
            'name' => 'RUN183 HIDDEN FOREST VAN',
            'asset_tag' => 'RUN183-HIDDEN-VAN',
            'status' => 'active',
        ]);

        $visibleStartedAt = now()->subHours(3);
        $hiddenStartedAt = now()->subHours(2);
        $visibleSession = $this->driverSession(
            $this->visibleVehicle,
            $this->visibleDriver,
            $visibleStartedAt,
        );
        $this->driverSession(
            $this->visibleVehicle,
            $this->hiddenDriver,
            now()->subHour(),
        );
        $hiddenSession = $this->driverSession(
            $this->hiddenVehicle,
            $this->hiddenDriver,
            $hiddenStartedAt,
        );

        $this->visibleTrip = $this->trip(
            $this->visibleVehicle,
            $visibleSession,
            $visibleStartedAt,
            -36.8100000,
            174.7100000,
        );
        $this->hiddenTrip = $this->trip(
            $this->hiddenVehicle,
            $hiddenSession,
            $hiddenStartedAt,
            -36.9900000,
            174.9900000,
        );

        $this->telemetry(
            $this->visibleVehicle,
            $visibleStartedAt->copy()->addMinutes(5),
            -36.8150000,
            174.7150000,
            42.5,
        );
        $this->telemetry(
            $this->visibleVehicle,
            $visibleStartedAt->copy()->addMinutes(6),
            -36.8160000,
            174.7160000,
            43.5,
            true,
        );
        $this->telemetry(
            $this->hiddenVehicle,
            $hiddenStartedAt->copy()->addMinutes(5),
            -36.9950000,
            174.9950000,
            88.5,
        );
    }

    public function test_site_limited_viewer_cannot_open_foreign_trip_page(): void
    {
        $viewAuditCount = AuditLog::query()->where('action', 'fleet.trip.view')->count();

        $this->actingAs($this->viewer)
            ->get("/fleet-assets/trips/{$this->hiddenTrip->id}/playback")
            ->assertNotFound();

        $this->assertSame(
            $viewAuditCount,
            AuditLog::query()->where('action', 'fleet.trip.view')->count(),
        );
    }

    public function test_site_limited_viewer_cannot_fetch_foreign_trip_telemetry(): void
    {
        $this->actingAs($this->viewer)
            ->getJson("/fleet-assets/trips/{$this->hiddenTrip->id}/playback/data")
            ->assertNotFound();
    }

    public function test_visible_trip_page_suppresses_driver_identities_outside_approved_sites(): void
    {
        for ($offset = 1; $offset <= 10; $offset++) {
            $this->driverSession(
                $this->visibleVehicle,
                $this->hiddenDriver,
                now()->subMinutes($offset),
            );
        }

        $archivedSite = $this->archivedSite('RUN183 Archived Driver Site');
        $archivedDriver = $this->siteUser($archivedSite, 'RUN183 ARCHIVED DRIVER');
        $this->driverSession($this->visibleVehicle, $archivedDriver, now()->subSeconds(30));

        $this->actingAs($this->viewer)
            ->get("/fleet-assets/trips/{$this->visibleTrip->id}/playback")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/trips/playback')
                ->where('trip.id', $this->visibleTrip->id)
                ->where('trip.driver.id', $this->visibleDriver->id)
                ->where('trip.driver.name', 'RUN183 Visible Driver')
                ->where('driver_sessions', function ($sessions): bool {
                    $rows = collect($sessions);

                    $this->assertSame(
                        [$this->visibleDriver->id],
                        $rows->pluck('user.id')->filter()->values()->all(),
                    );
                    $this->assertStringNotContainsString(
                        'RUN183 HIDDEN DRIVER',
                        (string) json_encode($rows),
                    );

                    return true;
                }));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fleet.trip.view',
            'auditable_type' => (new FleetTrip)->getMorphClass(),
            'auditable_id' => $this->visibleTrip->id,
        ]);
    }

    public function test_same_site_playback_data_remains_available_and_filters_blocked_points(): void
    {
        $startedAt = $this->visibleTrip->started_at;
        $endedAt = $this->visibleTrip->ended_at;

        $this->telemetry($this->visibleVehicle, $startedAt->copy()->subSecond(), -36.801, 174.701, 10.0);
        $this->telemetry($this->visibleVehicle, $startedAt->copy(), -36.810, 174.710, 20.0);
        $this->telemetry($this->visibleVehicle, $endedAt->copy(), -36.820, 174.720, 30.0);
        $this->telemetry($this->visibleVehicle, $endedAt->copy()->addSecond(), -36.821, 174.721, 40.0);
        $this->telemetry($this->hiddenVehicle, $startedAt->copy()->addMinute(), -36.990, 174.990, 80.0);

        $this->actingAs($this->viewer)
            ->getJson("/fleet-assets/trips/{$this->visibleTrip->id}/playback/data")
            ->assertOk()
            ->assertJsonPath('trip_id', $this->visibleTrip->id)
            ->assertJsonCount(3, 'points')
            ->assertJsonPath('points.0.occurred_at', $startedAt->toISOString())
            ->assertJsonPath('points.0.lat', '-36.8100000')
            ->assertJsonPath('points.1.lat', '-36.8150000')
            ->assertJsonPath('points.2.occurred_at', $endedAt->toISOString())
            ->assertJsonPath('points.2.lat', '-36.8200000');
    }

    public function test_fleet_manager_can_open_trip_at_another_operational_site(): void
    {
        $this->grantPermission($this->viewer, 'fleet.manage');

        $this->actingAs($this->viewer)
            ->get("/fleet-assets/trips/{$this->hiddenTrip->id}/playback")
            ->assertOk();
        $this->actingAs($this->viewer)
            ->getJson("/fleet-assets/trips/{$this->hiddenTrip->id}/playback/data")
            ->assertOk()
            ->assertJsonPath('trip_id', $this->hiddenTrip->id)
            ->assertJsonCount(1, 'points');

        $archivedSite = $this->archivedSite('RUN183 Archived Fleet Site');
        $archivedDriver = $this->siteUser($archivedSite, 'RUN183 ARCHIVED MANAGER DRIVER');
        $archivedVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $archivedSite->id,
            'home_site_id' => $archivedSite->id,
            'name' => 'RUN183 ARCHIVED FLEET VEHICLE',
            'status' => 'active',
        ]);
        $archivedTrip = $this->tripForAsset($archivedVehicle, $archivedDriver, now()->subDay());

        $this->actingAs($this->viewer)
            ->get("/fleet-assets/trips/{$archivedTrip->id}/playback")
            ->assertNotFound();
        $this->actingAs($this->viewer)
            ->getJson("/fleet-assets/trips/{$archivedTrip->id}/playback/data")
            ->assertNotFound();

        $hiddenClient = Client::factory()->create([
            'site_id' => $this->hiddenSite->id,
            'status' => 'active',
        ]);
        $conflictingVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $this->visibleSite->id,
            'home_site_id' => null,
            'client_id' => $hiddenClient->id,
            'name' => 'RUN183 MANAGER CONFLICT VEHICLE',
        ]);
        $unattributedVehicle = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => null,
            'name' => 'RUN183 MANAGER UNATTRIBUTED VEHICLE',
        ]);

        foreach ([$conflictingVehicle, $unattributedVehicle] as $offset => $vehicle) {
            $trip = $this->tripForAsset(
                $vehicle,
                $this->visibleDriver,
                now()->subDays(8)->addMinutes($offset),
            );

            $this->actingAs($this->viewer)
                ->get("/fleet-assets/trips/{$trip->id}/playback")
                ->assertNotFound();
            $this->actingAs($this->viewer)
                ->getJson("/fleet-assets/trips/{$trip->id}/playback/data")
                ->assertNotFound();
        }
    }

    public function test_missing_trip_ids_are_concealed_like_foreign_trip_ids(): void
    {
        $missingTripId = (int) FleetTrip::query()->max('id') + 1000;

        $this->actingAs($this->viewer)
            ->get("/fleet-assets/trips/{$missingTripId}/playback")
            ->assertNotFound();
        $this->actingAs($this->viewer)
            ->getJson("/fleet-assets/trips/{$missingTripId}/playback/data")
            ->assertNotFound();
    }

    public function test_playback_endpoints_preserve_authentication_and_view_permission_contract(): void
    {
        $pageUrl = "/fleet-assets/trips/{$this->visibleTrip->id}/playback";
        $dataUrl = "{$pageUrl}/data";

        auth()->logout();
        $this->get($pageUrl)->assertRedirect('/login');
        $this->getJson($dataUrl)->assertUnauthorized();

        $unprivileged = User::factory()->create(['approved_at' => now()]);
        $this->actingAs($unprivileged)->get($pageUrl)->assertForbidden();
        $this->actingAs($unprivileged)->getJson($dataUrl)->assertForbidden();

        $managerWithoutView = User::factory()->create(['approved_at' => now()]);
        $this->grantPermission($managerWithoutView, 'fleet.manage');
        $this->actingAs($managerWithoutView)->get($pageUrl)->assertForbidden();
        $this->actingAs($managerWithoutView)->getJson($dataUrl)->assertForbidden();
    }

    public function test_secondary_site_assignment_grants_the_same_playback_access_as_primary_site(): void
    {
        $secondaryViewer = $this->siteUser(
            $this->visibleSite,
            'RUN183 Secondary Site Viewer',
            [$this->hiddenSite->id],
        );
        $this->grantPermission($secondaryViewer, 'fleet.viewAny');

        $this->actingAs($secondaryViewer)
            ->get("/fleet-assets/trips/{$this->hiddenTrip->id}/playback")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trip.id', $this->hiddenTrip->id)
                ->where('trip.driver.id', $this->hiddenDriver->id));
        $this->actingAs($secondaryViewer)
            ->getJson("/fleet-assets/trips/{$this->hiddenTrip->id}/playback/data")
            ->assertOk()
            ->assertJsonPath('trip_id', $this->hiddenTrip->id);
    }

    public function test_playback_uses_canonical_direct_home_and_client_site_provenance(): void
    {
        $visibleClient = Client::factory()->create([
            'site_id' => $this->visibleSite->id,
            'status' => 'active',
        ]);
        $hiddenClient = Client::factory()->create([
            'site_id' => $this->hiddenSite->id,
            'status' => 'active',
        ]);

        $directVisible = Asset::factory()->vehicle()->create([
            'site_id' => $this->visibleSite->id,
            'home_site_id' => $this->hiddenSite->id,
            'client_id' => null,
            'name' => 'RUN183 DIRECT VISIBLE',
        ]);
        $homeVisible = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $this->visibleSite->id,
            'client_id' => $visibleClient->id,
            'name' => 'RUN183 HOME VISIBLE',
        ]);
        $clientVisible = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => $visibleClient->id,
            'name' => 'RUN183 CLIENT VISIBLE',
        ]);

        $directClientConflict = Asset::factory()->vehicle()->create([
            'site_id' => $this->visibleSite->id,
            'home_site_id' => null,
            'client_id' => $hiddenClient->id,
            'name' => 'RUN183 DIRECT CLIENT CONFLICT',
        ]);
        $homeClientConflict = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $this->visibleSite->id,
            'client_id' => $hiddenClient->id,
            'name' => 'RUN183 HOME CLIENT CONFLICT',
        ]);
        $hiddenHome = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $this->hiddenSite->id,
            'client_id' => null,
            'name' => 'RUN183 HIDDEN HOME',
        ]);
        $hiddenDirectVisibleHome = Asset::factory()->vehicle()->create([
            'site_id' => $this->hiddenSite->id,
            'home_site_id' => $this->visibleSite->id,
            'client_id' => null,
            'name' => 'RUN183 HIDDEN DIRECT VISIBLE HOME',
        ]);
        $hiddenClientFallback = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => $hiddenClient->id,
            'name' => 'RUN183 HIDDEN CLIENT FALLBACK',
        ]);
        $unattributed = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => null,
            'name' => 'RUN183 UNATTRIBUTED',
        ]);

        $allowedTrips = collect([$directVisible, $homeVisible, $clientVisible])
            ->map(fn (Asset $asset, int $offset) => $this->tripForAsset(
                $asset,
                $this->visibleDriver,
                now()->subDays(2)->addMinutes($offset),
            ));
        $deniedTrips = collect([
            $directClientConflict,
            $homeClientConflict,
            $hiddenHome,
            $hiddenDirectVisibleHome,
            $hiddenClientFallback,
            $unattributed,
        ])->map(fn (Asset $asset, int $offset) => $this->tripForAsset(
            $asset,
            $this->visibleDriver,
            now()->subDays(3)->addMinutes($offset),
        ));

        foreach ($allowedTrips as $trip) {
            $this->actingAs($this->viewer)
                ->get("/fleet-assets/trips/{$trip->id}/playback")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('trip.id', $trip->id));
            $this->actingAs($this->viewer)
                ->getJson("/fleet-assets/trips/{$trip->id}/playback/data")
                ->assertOk()
                ->assertJsonPath('trip_id', $trip->id);
        }

        foreach ($deniedTrips as $trip) {
            $this->actingAs($this->viewer)
                ->get("/fleet-assets/trips/{$trip->id}/playback")
                ->assertNotFound();
            $this->actingAs($this->viewer)
                ->getJson("/fleet-assets/trips/{$trip->id}/playback/data")
                ->assertNotFound();
        }
    }

    public function test_trip_driver_requires_matching_asset_and_approved_historical_site_identity(): void
    {
        $mismatchDriver = $this->siteUser($this->visibleSite, 'RUN183 MISMATCH DRIVER');
        $mismatchSession = $this->driverSession(
            $this->hiddenVehicle,
            $mismatchDriver,
            now()->subDays(4),
        );
        $mismatchTrip = $this->trip(
            $this->visibleVehicle,
            $mismatchSession,
            now()->subDays(4),
            -36.83,
            174.73,
        );

        $foreignSession = $this->driverSession(
            $this->visibleVehicle,
            $this->hiddenDriver,
            now()->subDays(5),
        );
        $foreignDriverTrip = $this->trip(
            $this->visibleVehicle,
            $foreignSession,
            now()->subDays(5),
            -36.84,
            174.74,
        );

        $archivedSite = $this->archivedSite('RUN183 Archived Identity Site');
        $archivedDriver = $this->siteUser($archivedSite, 'RUN183 ARCHIVED TRIP DRIVER');
        $archivedSession = $this->driverSession(
            $this->visibleVehicle,
            $archivedDriver,
            now()->subDays(6),
        );
        $archivedDriverTrip = $this->trip(
            $this->visibleVehicle,
            $archivedSession,
            now()->subDays(6),
            -36.85,
            174.75,
        );

        foreach ([$mismatchTrip, $foreignDriverTrip, $archivedDriverTrip] as $trip) {
            $this->actingAs($this->viewer)
                ->get("/fleet-assets/trips/{$trip->id}/playback")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('trip.id', $trip->id)
                    ->where('trip.driver_session_id', null)
                    ->where('trip.driver', null));
        }
    }

    public function test_playback_data_caps_chronological_eligible_points_at_two_thousand(): void
    {
        $vehicle = Asset::factory()->vehicle()->create([
            'site_id' => $this->visibleSite->id,
            'home_site_id' => $this->visibleSite->id,
            'name' => 'RUN183 LIMIT VEHICLE',
            'status' => 'active',
        ]);
        $startedAt = now()->subDays(7);
        $session = $this->driverSession($vehicle, $this->visibleDriver, $startedAt);
        $trip = $this->trip($vehicle, $session, $startedAt, -36.7, 174.7);
        $trip->update(['ended_at' => $startedAt->copy()->addHour()]);

        $createdAt = now();
        $events = [];
        for ($offset = 2000; $offset >= 0; $offset--) {
            $events[] = [
                'asset_id' => $vehicle->id,
                'vendor' => 'run183-limit',
                'vendor_message_id' => "RUN183-LIMIT-{$offset}",
                'occurred_at' => $startedAt->copy()->addSeconds($offset),
                'received_at' => $startedAt->copy()->addSeconds($offset),
                'latitude' => -36.7000000,
                'longitude' => 174.7000000,
                'speed_kph' => 40.0,
                'event_type' => 'location_report',
                'idempotency_key' => hash('sha256', "RUN183-LIMIT-{$offset}"),
                'raw_payload' => '[]',
                'consent_blocked' => false,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }
        foreach (array_chunk($events, 500) as $chunk) {
            FleetTelemetryEvent::query()->insert($chunk);
        }

        $this->actingAs($this->viewer)
            ->getJson("/fleet-assets/trips/{$trip->id}/playback/data")
            ->assertOk()
            ->assertJsonPath('trip_id', $trip->id)
            ->assertJsonCount(2000, 'points')
            ->assertJsonPath('points.0.occurred_at', $startedAt->toISOString())
            ->assertJsonPath('points.1999.occurred_at', $startedAt->copy()->addSeconds(1999)->toISOString());
    }

    /** @param list<int> $secondarySiteIds */
    private function siteUser(Site $site, string $name, array $secondarySiteIds = []): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => $secondarySiteIds,
        ]);

        return $user;
    }

    private function archivedSite(string $name): Site
    {
        return Site::factory()->create([
            'name' => $name,
            'is_active' => false,
            'archived' => true,
            'archived_at' => now(),
        ]);
    }

    private function grantPermission(User $user, string $key): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            [
                'description' => $key,
                'group' => 'fleet',
                'module' => 'fleet',
            ],
        );
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    private function driverSession(Asset $vehicle, User $driver, Carbon $startedAt): FleetDriverSession
    {
        return FleetDriverSession::query()->create([
            'asset_id' => $vehicle->id,
            'user_id' => $driver->id,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addMinutes(30),
            'source' => 'manual',
            'status' => 'closed',
        ]);
    }

    private function tripForAsset(Asset $vehicle, User $driver, Carbon $startedAt): FleetTrip
    {
        return $this->trip(
            $vehicle,
            $this->driverSession($vehicle, $driver, $startedAt),
            $startedAt,
            -36.8000000,
            174.7000000,
        );
    }

    private function trip(
        Asset $vehicle,
        FleetDriverSession $session,
        Carbon $startedAt,
        float $latitude,
        float $longitude,
    ): FleetTrip {
        return FleetTrip::query()->create([
            'asset_id' => $vehicle->id,
            'driver_session_id' => $session->id,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addMinutes(30),
            'start_latitude' => $latitude,
            'start_longitude' => $longitude,
            'end_latitude' => $latitude + 0.01,
            'end_longitude' => $longitude + 0.01,
            'distance_km' => 12.5,
            'duration_s' => 1800,
            'status' => 'closed',
            'consent_blocked' => false,
            'is_personal' => false,
        ]);
    }

    private function telemetry(
        Asset $vehicle,
        Carbon $occurredAt,
        float $latitude,
        float $longitude,
        float $speedKph,
        bool $consentBlocked = false,
    ): FleetTelemetryEvent {
        return FleetTelemetryEvent::query()->create([
            'asset_id' => $vehicle->id,
            'vendor' => 'run183',
            'vendor_message_id' => Str::uuid()->toString(),
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'speed_kph' => $speedKph,
            'event_type' => 'location_report',
            'idempotency_key' => hash('sha256', Str::uuid()->toString()),
            'raw_payload' => [],
            'consent_blocked' => $consentBlocked,
        ]);
    }
}
