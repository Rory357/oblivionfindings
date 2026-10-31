<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitoringThresholdRule;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\MonitoringHistoryService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
});

afterEach(function (): void {
    $this->travelBack();
});

function createHistoryViewerAtSite(Site $site, string $roleName = 'team_lead'): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);

    return $user;
}

it('returns bounded downsampled interface history across 1h, 24h, 7d, and 30d windows while preserving failure spikes', function (): void {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $device = Device::factory()->itInfrastructure()->create([
        'name' => 'Core Switch 1',
        'category' => 'network',
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subDays(40),
    ]);

    $profile = MonitoringProfile::factory()->create(['stale_after_seconds' => 300]);
    $monitor = Monitor::factory()->create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'name' => 'Gi1/0/48 WAN Trunk',
        'kind' => MonitorKind::SnmpInterface,
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'last_observation_at' => CarbonImmutable::parse('2026-10-26T11:59:00Z'),
    ]);

    MonitoringThresholdRule::query()->create([
        'name' => 'WAN trunk utilisation threshold',
        'scope_kind' => 'site',
        'site_id' => $site->id,
        'device_domain' => 'it_infrastructure',
        'metric_key' => 'in_utilization_pct',
        'threshold_direction' => 'above',
        'warning_rising_threshold' => 75,
        'warning_falling_threshold' => 65,
        'critical_rising_threshold' => 92,
        'critical_falling_threshold' => 82,
        'is_active' => true,
        'version' => 1,
    ]);

    $now = CarbonImmutable::parse('2026-10-26T12:00:00Z');
    DB::transaction(function () use ($monitor, $now): void {
        for ($i = 300; $i >= 1; $i--) {
            $isFailureSpike = $i === 150;
            $observedAt = $now->subMinutes($i * 4);
            $util = $isFailureSpike ? 96.5 : 42.0;
            MonitorObservation::create([
                'monitor_id' => $monitor->id,
                'source_key' => 'hist-24h-'.$i,
                'state' => $isFailureSpike ? MonitorState::Failed : MonitorState::Healthy,
                'value' => $util,
                'unit' => '%',
                'latency_ms' => null,
                'message' => 'ok',
                'metrics' => [
                    'interface_name' => 'Gi1/0/48',
                    'in_bps' => (int) ($util * 10_000_000),
                    'out_bps' => 180_000_000,
                    'in_utilization_pct' => $util,
                    'out_utilization_pct' => 18.0,
                    'errors' => $isFailureSpike ? 14 : 0,
                    'discards' => $isFailureSpike ? 3 : 0,
                ],
                'metric_data_class' => 'operational',
                'metric_privacy_class' => 'standard',
                'observed_at' => $observedAt,
                'ingested_at' => $observedAt,
            ]);
        }

        // Add one older sample at 20 days ago so 30d includes it while 24h and 7d exclude it.
        $twentyDaysAgo = $now->subDays(20);
        MonitorObservation::create([
            'monitor_id' => $monitor->id,
            'source_key' => 'hist-20d',
            'state' => MonitorState::Healthy,
            'value' => 31.0,
            'unit' => '%',
            'latency_ms' => null,
            'message' => 'ok',
            'metrics' => [
                'interface_name' => 'Gi1/0/48',
                'in_bps' => 310_000_000,
                'out_bps' => 120_000_000,
                'in_utilization_pct' => 31.0,
                'out_utilization_pct' => 12.0,
                'errors' => 0,
                'discards' => 0,
            ],
            'metric_data_class' => 'operational',
            'metric_privacy_class' => 'standard',
            'observed_at' => $twentyDaysAgo,
            'ingested_at' => $twentyDaysAgo,
        ]);
    });

    $viewer = createHistoryViewerAtSite($site, 'it_manager');

    $response24h = $this->actingAs($viewer)
        ->getJson("/security-devices/monitoring/monitors/{$monitor->id}/history?range=24h")
        ->assertOk();

    $history24h = $response24h->json('history');
    expect($history24h['range']['key'])->toBe('24h')
        ->and($history24h['range']['total_observations'])->toBe(300)
        ->and($history24h['range']['shown_points'])->toBe(MonitoringHistoryService::MAX_SERIES_POINTS)
        ->and($history24h['range']['downsampled'])->toBeTrue()
        ->and($history24h['primary_metric']['is_interface'])->toBeTrue()
        ->and($history24h['thresholds']['rule_name'])->toBe('WAN trunk utilisation threshold')
        ->and($history24h['thresholds']['warning_rising'])->toEqual(75)
        ->and($history24h['thresholds']['critical_rising'])->toEqual(92)
        ->and(collect($history24h['series'])->contains(fn (array $point): bool => $point['state'] === 'failed' && (float) $point['value'] === 96.5))->toBeTrue();

    $response1h = $this->actingAs($viewer)
        ->getJson("/security-devices/monitoring/monitors/{$monitor->id}/history?range=1h")
        ->assertOk()
        ->json('history');
    expect($response1h['range']['total_observations'])->toBe(15)
        ->and($response1h['range']['downsampled'])->toBeFalse();

    $response7d = $this->actingAs($viewer)
        ->getJson("/security-devices/monitoring/monitors/{$monitor->id}/history?range=7d")
        ->assertOk()
        ->json('history');
    expect($response7d['range']['total_observations'])->toBe(300);

    $response30d = $this->actingAs($viewer)
        ->getJson("/security-devices/monitoring/monitors/{$monitor->id}/history?range=30d")
        ->assertOk()
        ->json('history');
    expect($response30d['range']['total_observations'])->toBe(301);
});

it('conceals monitor history across Sites for Site-scoped staff', function (): void {
    $siteA = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $siteB = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);

    $deviceA = Device::factory()->itInfrastructure()->create(['category' => 'network']);
    DeviceAssignment::query()->create([
        'device_id' => $deviceA->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $siteA->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subDay(),
    ]);
    $monitorA = Monitor::factory()->create(['device_id' => $deviceA->id]);

    $siteBViewer = createHistoryViewerAtSite($siteB, 'team_lead');

    $this->actingAs($siteBViewer)
        ->getJson("/security-devices/monitoring/monitors/{$monitorA->id}/history?range=24h")
        ->assertNotFound();
});
