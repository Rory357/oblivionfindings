<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringAlertRoute;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitoringThresholdRule;
use App\Domain\Monitoring\Services\EffectiveMonitoringPolicyResolver;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\Monitoring\Services\MonitoringPolicyAuthoringService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Jobs\DispatchDeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceMonitoringTicket;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\MonitoringCheckAlertNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T04:00:00Z'));
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->seed(SecurityDevicesSignalSeeder::class);
    Queue::fake();
    Notification::fake();
});

afterEach(function (): void {
    $this->travelBack();
});

/** @return array{Site, Device, Monitor} */
function createNetworkMonitorFixture(
    string $domain = 'it_infrastructure',
    string $category = 'network',
    bool $affectsAvailability = false,
): array {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $device = match ($domain) {
        'security' => Device::factory()->security()->create(['category' => $category]),
        'iot_healthcare' => Device::factory()->create(['domain' => 'iot_healthcare', 'category' => $category]),
        default => Device::factory()->itInfrastructure()->create(['category' => $category]),
    };

    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subDay(),
    ]);

    $profile = MonitoringProfile::factory()->create([
        'failure_confirmations' => 1,
        'recovery_confirmations' => 1,
        'failure_duration_seconds' => 0,
        'recovery_duration_seconds' => 0,
    ]);

    $monitor = Monitor::factory()->create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'collector_id' => null,
        'kind' => MonitorKind::SnmpInterface,
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'affects_availability' => $affectsAvailability,
    ]);

    return [$site, $device, $monitor];
}

function siteItActor(Site $site, string $roleName = 'it_manager'): User
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

function ingestNumericSample(
    Monitor $monitor,
    Site $site,
    int $secondOffset,
    float $value,
    string $unit = '%',
    array $metrics = [],
) {
    return app(MonitoringObservationIngestor::class)->ingest(
        $monitor->fresh(),
        new ObservationInput(
            sourceKey: 'sample-'.$monitor->id.'-'.$secondOffset,
            state: MonitorState::Healthy,
            observedAt: CarbonImmutable::parse('2026-10-26T04:00:00Z')->addSeconds($secondOffset),
            value: $value,
            unit: $unit,
            metrics: $metrics,
        ),
        $site->id,
        (int) $monitor->device_id,
        null,
    );
}

it('evaluates multi-level warning and critical thresholds with rising and falling hysteresis in both directions', function (): void {
    [$site, , $aboveMonitor] = createNetworkMonitorFixture();

    MonitoringThresholdRule::query()->create([
        'name' => 'Core uplink utilisation guard',
        'scope_kind' => 'monitor',
        'monitor_id' => $aboveMonitor->id,
        'metric_key' => 'in_utilization_pct',
        'threshold_direction' => 'above',
        'warning_rising_threshold' => 75,
        'warning_falling_threshold' => 65,
        'critical_rising_threshold' => 90,
        'critical_falling_threshold' => 80,
        'is_active' => true,
        'version' => 1,
    ]);

    ingestNumericSample($aboveMonitor, $site, 1, 50, '%', ['in_utilization_pct' => 50]);
    expect($aboveMonitor->fresh()->current_state)->toBe(MonitorState::Healthy);

    ingestNumericSample($aboveMonitor, $site, 2, 80, '%', ['in_utilization_pct' => 80]);
    expect($aboveMonitor->fresh()->current_state)->toBe(MonitorState::Degraded);

    // 70% stays Degraded because hysteresis requires dropping <= 65% to clear warning.
    ingestNumericSample($aboveMonitor, $site, 3, 70, '%', ['in_utilization_pct' => 70]);
    expect($aboveMonitor->fresh()->current_state)->toBe(MonitorState::Degraded);

    ingestNumericSample($aboveMonitor, $site, 4, 94, '%', ['in_utilization_pct' => 94]);
    expect($aboveMonitor->fresh()->current_state)->toBe(MonitorState::Failed);

    // 85% stays Failed because hysteresis requires dropping <= 80% to exit critical.
    ingestNumericSample($aboveMonitor, $site, 5, 85, '%', ['in_utilization_pct' => 85]);
    expect($aboveMonitor->fresh()->current_state)->toBe(MonitorState::Failed);

    // 72% drops below critical_falling (80) into warning band (still > warning_falling 65).
    ingestNumericSample($aboveMonitor, $site, 6, 72, '%', ['in_utilization_pct' => 72]);
    expect($aboveMonitor->fresh()->current_state)->toBe(MonitorState::Degraded);

    // 60% drops below warning_falling (65) -> Healthy.
    ingestNumericSample($aboveMonitor, $site, 7, 60, '%', ['in_utilization_pct' => 60]);
    expect($aboveMonitor->fresh()->current_state)->toBe(MonitorState::Healthy);

    [$belowSite, , $belowMonitor] = createNetworkMonitorFixture();
    MonitoringThresholdRule::query()->create([
        'name' => 'UPS remaining runtime floor',
        'scope_kind' => 'monitor',
        'monitor_id' => $belowMonitor->id,
        'metric_key' => 'value',
        'threshold_direction' => 'below',
        'warning_rising_threshold' => 30,
        'warning_falling_threshold' => 40,
        'critical_rising_threshold' => 15,
        'critical_falling_threshold' => 22,
        'is_active' => true,
        'version' => 1,
    ]);

    ingestNumericSample($belowMonitor, $belowSite, 10, 55, 'min');
    expect($belowMonitor->fresh()->current_state)->toBe(MonitorState::Healthy);

    ingestNumericSample($belowMonitor, $belowSite, 11, 25, 'min');
    expect($belowMonitor->fresh()->current_state)->toBe(MonitorState::Degraded);

    ingestNumericSample($belowMonitor, $belowSite, 12, 35, 'min');
    expect($belowMonitor->fresh()->current_state)->toBe(MonitorState::Degraded);

    ingestNumericSample($belowMonitor, $belowSite, 13, 12, 'min');
    expect($belowMonitor->fresh()->current_state)->toBe(MonitorState::Failed);

    ingestNumericSample($belowMonitor, $belowSite, 14, 18, 'min');
    expect($belowMonitor->fresh()->current_state)->toBe(MonitorState::Failed);

    ingestNumericSample($belowMonitor, $belowSite, 15, 45, 'min');
    expect($belowMonitor->fresh()->current_state)->toBe(MonitorState::Healthy);
});

it('resolves threshold precedence from monitor to device to Site to application and exposes the winning policy preview', function (): void {
    [$site, $device, $monitor] = createNetworkMonitorFixture();
    $actor = siteItActor($site);

    $appRule = MonitoringThresholdRule::query()->create([
        'name' => 'Application default threshold',
        'scope_kind' => 'application',
        'device_domain' => 'it_infrastructure',
        'metric_key' => 'value',
        'threshold_direction' => 'above',
        'warning_rising_threshold' => 85,
        'critical_rising_threshold' => 95,
        'is_active' => true,
        'version' => 1,
    ]);
    $siteRule = MonitoringThresholdRule::query()->create([
        'name' => 'Site network threshold',
        'scope_kind' => 'site',
        'site_id' => $site->id,
        'device_domain' => 'it_infrastructure',
        'metric_key' => 'value',
        'threshold_direction' => 'above',
        'warning_rising_threshold' => 80,
        'critical_rising_threshold' => 92,
        'is_active' => true,
        'version' => 1,
    ]);
    $deviceRule = MonitoringThresholdRule::query()->create([
        'name' => 'Core switch threshold',
        'scope_kind' => 'device',
        'device_id' => $device->id,
        'metric_key' => 'value',
        'threshold_direction' => 'above',
        'warning_rising_threshold' => 75,
        'critical_rising_threshold' => 90,
        'is_active' => true,
        'version' => 1,
    ]);
    $monitorRule = MonitoringThresholdRule::query()->create([
        'name' => 'Specific uplink threshold',
        'scope_kind' => 'monitor',
        'monitor_id' => $monitor->id,
        'metric_key' => 'value',
        'threshold_direction' => 'above',
        'warning_rising_threshold' => 70,
        'critical_rising_threshold' => 88,
        'is_active' => true,
        'version' => 1,
    ]);

    $this->actingAs($actor)
        ->getJson("/security-devices/settings/monitoring/effective-preview/{$monitor->id}")
        ->assertOk()
        ->assertJsonPath('preview.threshold.rule_id', $monitorRule->id)
        ->assertJsonPath('preview.threshold.source_kind', 'monitor')
        ->assertJsonPath('preview.alert_route.source_kind', 'default');

    $resolver = app(EffectiveMonitoringPolicyResolver::class);
    $monitorRule->update(['is_active' => false]);
    expect($resolver->resolveThreshold($monitor, $site->id)['rule_id'])->toBe($deviceRule->id);

    $deviceRule->update(['is_active' => false]);
    expect($resolver->resolveThreshold($monitor, $site->id)['rule_id'])->toBe($siteRule->id);

    $siteRule->update(['is_active' => false]);
    expect($resolver->resolveThreshold($monitor, $site->id)['rule_id'])->toBe($appRule->id);
});

it('delivers default technical warning notifications in-app without opening tickets, and opens IT tickets plus email on critical failures', function (): void {
    [$site, , $monitor] = createNetworkMonitorFixture(affectsAvailability: false);
    $recipient = siteItActor($site, 'it_manager');

    MonitoringThresholdRule::query()->create([
        'name' => 'Switch CPU threshold',
        'scope_kind' => 'monitor',
        'monitor_id' => $monitor->id,
        'metric_key' => 'value',
        'threshold_direction' => 'above',
        'warning_rising_threshold' => 75,
        'critical_rising_threshold' => 90,
        'is_active' => true,
        'version' => 1,
    ]);

    $warning = ingestNumericSample($monitor, $site, 1, 80, '%');
    expect($warning->to)->toBe(MonitorState::Degraded)
        ->and($warning->deviceEvent)->toBeNull()
        ->and(ItTicket::query()->count())->toBe(0)
        ->and(ControlRoomAlert::query()->count())->toBe(0);

    Notification::assertSentTo(
        $recipient,
        MonitoringCheckAlertNotification::class,
        fn (MonitoringCheckAlertNotification $notification, array $channels): bool => $channels === ['database'],
    );

    Notification::fake();

    $critical = ingestNumericSample($monitor, $site, 2, 95, '%');
    expect($critical->to)->toBe(MonitorState::Failed)
        ->and($critical->deviceEvent)->not->toBeNull();

    Notification::assertSentTo(
        $recipient,
        MonitoringCheckAlertNotification::class,
        fn (MonitoringCheckAlertNotification $notification, array $channels): bool => in_array('database', $channels, true)
            && in_array('mail', $channels, true),
    );

    $outbox = $critical->deviceEvent->signalOutbox()->sole();
    app()->call([new DispatchDeviceEventSignalOutbox($outbox->id), 'handle']);
    app()->call([new DispatchDeviceMonitoringTicket($outbox->id), 'handle']);

    expect(ItTicket::query()->count())->toBe(1)
        ->and(ControlRoomAlert::query()->count())->toBe(0);
});

it('escalates technical critical failures to Control Room when an active alert route enables escalation', function (): void {
    [$site, $device, $monitor] = createNetworkMonitorFixture(affectsAvailability: false);
    siteItActor($site, 'it_manager');

    MonitoringAlertRoute::query()->create([
        'name' => 'Escalate core gateway failures to Control Room',
        'scope_kind' => 'device',
        'device_id' => $device->id,
        'warning_notify_in_app' => true,
        'warning_notify_email' => false,
        'warning_open_it_ticket' => false,
        'critical_notify_in_app' => true,
        'critical_notify_email' => true,
        'critical_open_it_ticket' => true,
        'critical_escalate_control_room' => true,
        'recipient_role' => 'it_manager',
        'is_active' => true,
        'version' => 1,
    ]);

    $critical = app(MonitoringObservationIngestor::class)->ingest(
        $monitor,
        new ObservationInput(
            sourceKey: 'escalate-critical-1',
            state: MonitorState::Failed,
            observedAt: CarbonImmutable::parse('2026-10-26T04:01:00Z'),
        ),
        $site->id,
        $device->id,
        null,
    );

    $outbox = $critical->deviceEvent->signalOutbox()->sole();
    app()->call([new DispatchDeviceEventSignalOutbox($outbox->id), 'handle']);
    app()->call([new DispatchDeviceMonitoringTicket($outbox->id), 'handle']);

    expect(ControlRoomAlert::query()->count())->toBe(1)
        ->and(ItTicket::query()->count())->toBe(1);
});

it('suppresses technical checks during active maintenance while keeping security and care checks locked to Control Room and unsuppressed', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-27T04:00:00Z'));

    [$site, $itDevice, $itMonitor] = createNetworkMonitorFixture('it_infrastructure', 'network', true);
    $securityDevice = Device::factory()->security()->create(['category' => 'cctv']);
    DeviceAssignment::query()->create([
        'device_id' => $securityDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subDay(),
    ]);
    $securityMonitor = Monitor::factory()->create([
        'device_id' => $securityDevice->id,
        'profile_id' => $itMonitor->profile_id,
        'collector_id' => null,
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'affects_availability' => true,
    ]);

    $actor = siteItActor($site, 'admin');
    app(MonitoringPolicyAuthoringService::class)->createMaintenanceWindow($actor, [
        'site_id' => $site->id,
        'name' => 'Planned core switch upgrade',
        'starts_at' => CarbonImmutable::parse('2026-10-27T03:30:00Z')->setTimezone('Pacific/Auckland')->toIso8601String(),
        'ends_at' => CarbonImmutable::parse('2026-10-27T05:00:00Z')->setTimezone('Pacific/Auckland')->toIso8601String(),
        'timezone' => 'Pacific/Auckland',
        'policy' => 'suppress_notifications_and_ticketing',
        'reason' => 'Approved scheduled switch firmware maintenance.',
    ]);

    $ingestor = app(MonitoringObservationIngestor::class);
    $observedAt = CarbonImmutable::parse('2026-10-27T04:05:00Z');

    $itResult = $ingestor->ingest(
        $itMonitor,
        new ObservationInput('it-maint-fail', MonitorState::Failed, $observedAt),
        $site->id,
        $itDevice->id,
        null,
    );

    $securityResult = $ingestor->ingest(
        $securityMonitor,
        new ObservationInput('sec-maint-fail', MonitorState::Failed, $observedAt),
        $site->id,
        $securityDevice->id,
        null,
    );

    expect($itResult->to)->toBe(MonitorState::Suppressed)
        ->and($itResult->deviceEvent)->toBeNull()
        ->and($securityResult->to)->toBe(MonitorState::Failed)
        ->and($securityResult->deviceEvent)->not->toBeNull()
        ->and(DeviceEvent::query()->where('device_id', $securityDevice->id)->where('event_type', 'offline')->count())->toBe(1);

    $resolver = app(EffectiveMonitoringPolicyResolver::class);
    expect($resolver->resolveAlertRoute($securityDevice, $securityMonitor, $site->id)['locked_to_control_room'])->toBeTrue();

    $this->actingAs($actor)
        ->postJson('/security-devices/settings/monitoring/alert-routes', [
            'name' => 'Attempted security reroute',
            'scope_kind' => 'site',
            'site_id' => $site->id,
            'device_domain' => 'security',
            'warning_notify_in_app' => true,
            'warning_notify_email' => false,
            'warning_open_it_ticket' => false,
            'critical_notify_in_app' => true,
            'critical_notify_email' => false,
            'critical_open_it_ticket' => false,
            'critical_escalate_control_room' => false,
            'recipient_role' => 'it_staff',
        ])
        ->assertStatus(422);
});
