<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetServiceSchedule;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetVehicleMileageFeed;
use App\Models\FleetVehicleOdometerObservation;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PKG-02B tracker distance feed: reconciling the dashboard with the tracker
 * keeps the dashboard figure as a recorded reading, and only planning (never
 * readiness) moves on with the tracker when automatic planning is on.
 */
class Pkg02bMileageFeedTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-22 09:30:00', 'Pacific/Auckland')->utc());
        $this->site = Site::factory()->create(['name' => 'Kōwhai House']);
    }

    public function test_reconciling_keeps_the_dashboard_reading_and_plans_from_the_tracker_when_enabled(): void
    {
        $manager = $this->siteUser(['fleet.viewAny', 'fleet.manage', 'securityDevices.devices.view']);
        $vehicle = $this->vehicle();
        $this->reading($vehicle, $manager, 82000, now()->subDays(3));
        $this->sample($vehicle, now()->subMinutes(10), 1500.0);
        $schedule = FleetServiceSchedule::query()->create(['asset_id' => $vehicle->id, 'name' => 'Routine service',
            'interval_km' => 10000, 'next_due_km' => 85000, 'is_active' => true]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/mileage-feed/reconcile";
        $body = ['value_km' => 82460, 'reason' => 'Dashboard photo taken at the depot.', 'automatic' => true,
            'tolerance_km' => 25, 'confirmed' => true];

        $this->actingAs($manager)->postJson($url, ['value_km' => 81000] + $body + ['request_key' => 'reconcile-low'])
            ->assertUnprocessable()->assertJsonValidationErrors('value_km');
        $this->actingAs($manager)->postJson($url, ['confirmed' => false] + $body + ['request_key' => 'reconcile-unconfirmed'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $result = $this->actingAs($manager)->postJson($url, $body + ['request_key' => 'reconcile-1'])->assertOk()->json();
        $this->actingAs($manager)->postJson($url, $body + ['request_key' => 'reconcile-1'])->assertOk()
            ->assertJsonPath('observation_id', $result['observation_id']);

        $reading = FleetVehicleOdometerObservation::query()->findOrFail($result['observation_id']);
        $this->assertSame(82460.0, (float) $reading->value_km);
        $this->assertSame('dashboard_manual', $reading->source_kind);
        $feed = FleetVehicleMileageFeed::query()->where('asset_id', $vehicle->id)->sole();
        $this->assertTrue($feed->automatic);
        $this->assertSame(1500.0, $feed->baseline_tracker_km);

        // The tracker moves on 150 km: planning follows it, readiness stays on the recorded reading.
        $this->sample($vehicle, now()->subMinute(), 1650.0);
        $this->actingAs($manager)->get("/fleet-assets/vehicles/{$vehicle->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace.mileage_feed.usable', true)
                ->where('workspace.mileage_feed.planning_km', 82610)
                ->where('workspace.mileage_feed.difference_km', 150)
                ->where('workspace.odometer.current_km', 82460)
                ->where('workspace.schedules.0.km_remaining', 2390)
                ->etc());
        $this->assertSame(85000.0, (float) $schedule->fresh()->next_due_km);
        // Tracker figures are separately permissioned: other viewers plan from the recorded reading.
        $this->actingAs($this->siteUser(['fleet.viewAny']))->get("/fleet-assets/vehicles/{$vehicle->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace.mileage_feed', null)
                ->where('workspace.schedules.0.km_remaining', 2540)
                ->etc());

        // A newer dashboard reading needs another cross-check before planning uses the tracker again.
        $this->reading($vehicle, $manager, 82700, now());
        $this->actingAs($manager)->get("/fleet-assets/vehicles/{$vehicle->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace.mileage_feed.reconciled', false)
                ->where('workspace.mileage_feed.usable', false)
                ->where('workspace.mileage_feed.planning_km', 82700)
                ->etc());
    }

    public function test_reconciling_needs_a_manager_and_a_current_tracker_sample(): void
    {
        $manager = $this->siteUser(['fleet.viewAny', 'fleet.manage', 'securityDevices.devices.view']);
        $reader = $this->siteUser(['fleet.viewAny']);
        $vehicle = $this->vehicle();
        $this->sample($vehicle, now()->subHours(6), 900.0);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/mileage-feed/reconcile";
        $body = ['value_km' => 50000, 'reason' => 'Checked.', 'automatic' => false, 'tolerance_km' => 25, 'confirmed' => true];

        $this->actingAs($reader)->postJson($url, $body + ['request_key' => 'reconcile-reader'])->assertForbidden();
        // Managing the fleet isn't enough: the feed works from tracker figures.
        $this->actingAs($this->siteUser(['fleet.viewAny', 'fleet.manage']))
            ->postJson($url, $body + ['request_key' => 'reconcile-no-technology'])->assertForbidden();
        $this->actingAs($manager)->postJson($url, $body + ['request_key' => 'reconcile-stale'])
            ->assertUnprocessable()->assertJsonValidationErrors('value_km');
        $this->assertSame(0, FleetVehicleOdometerObservation::query()->count());

        // People who can't see vehicle technology get no tracker data at all.
        $this->actingAs($reader)->get("/fleet-assets/vehicles/{$vehicle->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspace.mileage_feed', null)->etc());
    }

    public function test_pausing_turns_automatic_planning_off_with_a_reason(): void
    {
        $manager = $this->siteUser(['fleet.viewAny', 'fleet.manage', 'securityDevices.devices.view']);
        $vehicle = $this->vehicle();
        $this->sample($vehicle, now()->subMinutes(5), 2000.0);
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/mileage-feed/reconcile", [
            'value_km' => 70000, 'reason' => 'Depot check.', 'automatic' => true, 'tolerance_km' => 25,
            'confirmed' => true, 'request_key' => 'reconcile-pause',
        ])->assertOk();
        $feed = FleetVehicleMileageFeed::query()->sole();
        $url = "/fleet-assets/vehicles/{$vehicle->id}/mileage-feed/pause";

        $this->actingAs($manager)->postJson($url, ['reason' => '', 'expected_version' => $feed->lock_version, 'request_key' => 'pause-empty'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($manager)->postJson($url, ['reason' => 'Tracker being replaced.', 'expected_version' => 99, 'request_key' => 'pause-stale'])
            ->assertStatus(409);
        $this->actingAs($manager)->postJson($url, ['reason' => 'Tracker being replaced.', 'expected_version' => $feed->lock_version,
            'request_key' => 'pause-first'])->assertOk()->assertJsonPath('feed.automatic', false);
        $this->assertSame(['reconciled', 'paused'], $feed->events()->orderBy('id')->pluck('action')->all());
    }

    private function reading(Asset $vehicle, User $by, float $km, $at): void
    {
        FleetVehicleOdometerObservation::query()->create(['asset_id' => $vehicle->id, 'value_km' => $km,
            'observed_at' => $at, 'source_kind' => 'dashboard_manual', 'recorded_by_user_id' => $by->id,
            'request_key' => 'seed-'.Str::uuid(), 'request_fingerprint' => str_repeat('a', 64), 'created_at' => now()]);
    }

    private function sample(Asset $vehicle, $at, float $odometer): void
    {
        $at = CarbonImmutable::parse($at);
        FleetTelemetryEvent::query()->create([
            'asset_id' => $vehicle->id, 'vendor' => 'queclink', 'vendor_message_id' => Str::uuid()->toString(),
            'occurred_at' => $at, 'received_at' => $at, 'latitude' => -41.285, 'longitude' => 174.775,
            'odometer_km' => $odometer, 'event_type' => 'location_report',
            'idempotency_key' => hash('sha256', Str::uuid()->toString()), 'raw_payload' => [], 'consent_blocked' => false,
        ]);
    }

    /** @param list<string> $permissions */
    private function siteUser(array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
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

    private function vehicle(): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $this->site->id, 'home_site_id' => $this->site->id, 'name' => 'Kōwhai van', 'status' => 'active',
        ]);
    }
}
