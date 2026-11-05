<?php

namespace App\Console\Commands;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Enums\RelationshipType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\NetworkChecks\Models\NetworkCheckSession;
use App\Domain\SecurityDevices\NetworkChecks\Models\NetworkCheckStep;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local-only screen-check command (S&D Task 9): seeds one realistic demo UniFi switch with ports,
 * linked child devices, PoE port-check readings, history, and Settings extras into a local or
 * test database so the switch's Overview, Ports, Checks & alerts, History, and Settings tabs can
 * be inspected in a browser.
 *
 * Hard-refuses to run outside local/testing environments or against non-local database hosts.
 * Never add this command to DatabaseSeeder.
 */
final class SeedDemoUnifiSwitchCommand extends Command
{
    public const string DEMO_SWITCH_UID = 'LOCAL-DEMO-USW-24-POE';

    private const string DEMO_PROFILE_NAME = 'Local demo switch 5 min';

    private const string DEMO_SESSION_UUID = '00000000-0000-4000-8000-000000000901';

    private const string DEMO_STEP_UUID = '00000000-0000-4000-8000-000000000902';

    protected $signature = 'security-devices:seed-demo-switch
        {--site= : Active Site ID to assign the demo switch to (defaults to first active Site)}';

    protected $description = 'Add one demo UniFi switch with ports to the local database only (for S&D screen checks)';

    public function handle(): int
    {
        $refusal = $this->refusalReason();
        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $siteOption = $this->option('site');
        if ($siteOption !== null && $siteOption !== '') {
            $site = Site::query()->whereKey((int) $siteOption)->first();
            if (! $site instanceof Site) {
                $this->error("Site #{$siteOption} was not found.");

                return self::FAILURE;
            }
        } else {
            $site = Site::query()
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->orderBy('id')
                ->first();

            if (! $site instanceof Site) {
                $site = Site::query()->create([
                    'name' => 'Local Screen-Check House',
                    'type' => 'house',
                    'is_active' => true,
                    'archived' => false,
                ]);
            }
        }

        $switch = DB::transaction(fn (): Device => $this->seedDemoSwitch($site), 3);

        $this->info(sprintf(
            'Demo UniFi switch ready on local database: #%d (%s) at Site #%d (%s) -> /security-devices/devices/%d',
            $switch->id,
            $switch->name,
            $site->id,
            $site->name,
            $switch->id,
        ));

        return self::SUCCESS;
    }

    private function refusalReason(): ?string
    {
        if (! $this->laravel->environment(['local', 'testing'])) {
            return 'Refused: security-devices:seed-demo-switch runs only when APP_ENV is local or testing.';
        }

        $appUrl = strtolower((string) config('app.url', ''));
        $hostName = strtolower((string) gethostname());
        if (str_contains($appUrl, 'oblivionfindings.com') || str_contains($hostName, 'oblivionfindings.com')) {
            return 'Refused: security-devices:seed-demo-switch must never run against oblivionfindings.com.';
        }

        $defaultConnection = (string) config('database.default', 'mysql');
        $driver = strtolower((string) config("database.connections.{$defaultConnection}.driver", ''));
        $dbHost = strtolower(trim((string) config("database.connections.{$defaultConnection}.host", '')));

        if ($driver !== 'sqlite' && $dbHost !== '') {
            if (str_contains($dbHost, 'oblivionfindings')
                || ! in_array($dbHost, ['127.0.0.1', 'localhost', '::1', 'mysql', 'mariadb'], true)) {
                return "Refused: database host [{$dbHost}] is not a local database.";
            }
        }

        return null;
    }

    private function seedDemoSwitch(Site $site): Device
    {
        $custodianId = $this->resolveCustodianUserId((int) $site->id);
        $actorId = $custodianId ?? User::query()->whereNotNull('approved_at')->orderBy('id')->value('id');

        $meta = [
            'local_demo_fixture' => true,
            'firmware_status' => 'updateAvailable',
            'firmware_update_version' => '6.6.65',
            'tell_it_update_ready' => true,
        ];
        if ($custodianId !== null) {
            $meta['looked_after_by_user_id'] = $custodianId;
        }

        $switch = Device::withTrashed()->firstOrNew(['device_uid' => self::DEMO_SWITCH_UID]);
        $switch->forceFill([
            'device_uid' => self::DEMO_SWITCH_UID,
            'name' => 'Comms cupboard switch (local demo)',
            'domain' => 'it_infrastructure',
            'category' => 'network',
            'subcategory' => 'switch',
            'manufacturer' => 'Ubiquiti',
            'model' => 'USW-Pro-24-PoE',
            'serial_number' => 'LOCAL-USW24POE-01',
            'mac_address' => '74:ac:b9:24:00:01',
            'ip_address' => '10.20.0.2',
            'location_description' => 'Comms cupboard',
            'status' => DeviceStatus::Active,
            'health_status' => HealthStatus::Healthy,
            'provider' => 'unifi',
            'external_ref' => 'local-demo-usw-24-poe',
            'firmware_version' => '6.6.61',
            'last_seen_at' => now()->subMinutes(2),
            'meta' => $meta,
            'config' => null,
            'created_by_user_id' => $switch->created_by_user_id ?? $actorId,
            'deleted_at' => null,
        ])->save();

        $this->ensureSiteAssignment($switch, $site, 14);

        $profile = MonitoringProfile::query()->firstOrCreate(
            ['name' => self::DEMO_PROFILE_NAME],
            [
                'description' => 'Local screen-check profile for demo UniFi switch',
                'interval_seconds' => 300,
                'failure_confirmations' => 2,
                'recovery_confirmations' => 1,
                'stale_after_seconds' => 900,
                'is_active' => true,
            ],
        );

        $ping = Monitor::query()->updateOrCreate(
            [
                'device_id' => $switch->id,
                'kind' => MonitorKind::Icmp,
                'name' => 'Ping reachability',
            ],
            [
                'profile_id' => $profile->id,
                'target' => '10.20.0.2',
                'current_state' => MonitorState::Healthy,
                'effective_state' => MonitorState::Healthy,
                'affects_availability' => true,
                'is_enabled' => true,
                'last_observation_at' => now()->subMinutes(2),
                'last_state_changed_at' => now()->subDays(3)->addMinutes(14),
            ],
        );
        $this->ensurePingObservations($ping);

        $children = $this->seedLinkedDevices($site, $actorId);
        $this->seedSwitchPorts($switch, $profile, $children, $actorId);
        $this->seedUnifiPortCheckStep($site, $switch, $actorId);
        $this->seedSwitchHistory($site, $switch, $actorId);

        return $switch->fresh();
    }

    private function resolveCustodianUserId(int $siteId): ?int
    {
        $profile = HrEmployeeProfile::query()
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->where(function ($sites) use ($siteId): void {
                $sites->where('primary_site_id', $siteId)
                    ->orWhereJsonContains('secondary_site_ids', $siteId);
            })
            ->orderBy('id')
            ->first(['user_id']);

        if (! $profile instanceof HrEmployeeProfile) {
            return null;
        }

        $approved = User::query()
            ->whereKey((int) $profile->user_id)
            ->whereNotNull('approved_at')
            ->exists();

        return $approved ? (int) $profile->user_id : null;
    }

    private function ensureSiteAssignment(Device $device, Site $site, int $daysAgo): void
    {
        $active = DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->whereNull('released_at')
            ->first();

        if ($active instanceof DeviceAssignment) {
            if ($active->assignable_type === DeviceAssignment::TARGET_SITE && (int) $active->assignable_id === (int) $site->id) {
                return;
            }
            $active->forceFill(['released_at' => now()])->saveQuietly();
        }

        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->subDays($daysAgo),
        ]);
    }

    private function ensurePingObservations(Monitor $ping): void
    {
        $recentCount = MonitorObservation::query()
            ->where('monitor_id', $ping->id)
            ->where('observed_at', '>=', now()->subHours(12))
            ->count();

        if ($recentCount >= 2) {
            return;
        }

        foreach ([[6, 3], [0, 2]] as [$hoursAgo, $latencyMs]) {
            $at = $hoursAgo > 0 ? now()->subHours($hoursAgo) : now()->subMinutes(2);
            MonitorObservation::query()->create([
                'monitor_id' => $ping->id,
                'source_key' => Str::uuid()->toString(),
                'state' => MonitorState::Healthy,
                'latency_ms' => $latencyMs,
                'metrics' => ['packet_loss_pct' => 0],
                'observed_at' => $at,
                'ingested_at' => $at,
            ]);
        }
    }

    /**
     * @return array<int, Device>
     */
    private function seedLinkedDevices(Site $site, ?int $actorId): array
    {
        $specs = [
            1 => [
                'device_uid' => 'LOCAL-DEMO-U6-PRO-01',
                'name' => 'Hallway Wi-Fi AP (local demo)',
                'domain' => 'it_infrastructure',
                'category' => 'network',
                'subcategory' => 'wireless_ap',
                'manufacturer' => 'Ubiquiti',
                'model' => 'U6-Pro',
                'ip_address' => '10.20.0.11',
                'mac_address' => '74:ac:b9:24:01:01',
                'location_description' => 'Main hallway',
            ],
            2 => [
                'device_uid' => 'LOCAL-DEMO-U6-LITE-02',
                'name' => 'Front Lounge Wi-Fi AP (local demo)',
                'domain' => 'it_infrastructure',
                'category' => 'network',
                'subcategory' => 'wireless_ap',
                'manufacturer' => 'Ubiquiti',
                'model' => 'U6-Lite',
                'ip_address' => '10.20.0.12',
                'mac_address' => '74:ac:b9:24:01:02',
                'location_description' => 'Front lounge',
            ],
            3 => [
                'device_uid' => 'LOCAL-DEMO-G4-DOME-03',
                'name' => 'Driveway camera (local demo)',
                'domain' => 'security',
                'category' => 'cctv',
                'subcategory' => 'dome',
                'manufacturer' => 'Ubiquiti',
                'model' => 'UVC-G4-Dome',
                'ip_address' => '10.20.0.21',
                'mac_address' => '74:ac:b9:24:02:01',
                'location_description' => 'Driveway',
            ],
            4 => [
                'device_uid' => 'LOCAL-DEMO-UA-HUB-04',
                'name' => 'Front door controller (local demo)',
                'domain' => 'security',
                'category' => 'access_control',
                'subcategory' => 'door_controller',
                'manufacturer' => 'Ubiquiti',
                'model' => 'UA-Hub',
                'ip_address' => '10.20.0.31',
                'mac_address' => '74:ac:b9:24:03:01',
                'location_description' => 'Front entrance',
            ],
        ];

        $out = [];
        foreach ($specs as $port => $spec) {
            $child = Device::withTrashed()->firstOrNew(['device_uid' => $spec['device_uid']]);
            $child->forceFill([
                ...$spec,
                'status' => DeviceStatus::Active,
                'health_status' => HealthStatus::Healthy,
                'provider' => 'unifi',
                'last_seen_at' => now()->subMinutes(2),
                'meta' => ['local_demo_fixture' => true],
                'created_by_user_id' => $child->created_by_user_id ?? $actorId,
                'deleted_at' => null,
            ])->save();

            $this->ensureSiteAssignment($child, $site, 14);
            $out[$port] = $child;
        }

        return $out;
    }

    /**
     * @param  array<int, Device>  $children
     */
    private function seedSwitchPorts(Device $switch, MonitoringProfile $profile, array $children, ?int $actorId): void
    {
        $portSpecs = [
            1 => ['oper' => 'up', 'speed_bps' => 1_000_000_000, 'alias' => 'Hallway Wi-Fi AP', 'err_start' => 0, 'err_end' => 0, 'changed_hours' => 72],
            2 => ['oper' => 'up', 'speed_bps' => 1_000_000_000, 'alias' => 'Front Lounge Wi-Fi AP', 'err_start' => 0, 'err_end' => 0, 'changed_hours' => 48],
            3 => ['oper' => 'up', 'speed_bps' => 100_000_000, 'alias' => 'Driveway camera', 'err_start' => 12, 'err_end' => 196, 'changed_hours' => 6],
            4 => ['oper' => 'up', 'speed_bps' => 1_000_000_000, 'alias' => 'Front door controller', 'err_start' => 0, 'err_end' => 0, 'changed_hours' => 96],
            5 => ['oper' => 'up', 'speed_bps' => 1_000_000_000, 'alias' => 'NVR uplink', 'err_start' => 0, 'err_end' => 0, 'changed_hours' => 120],
            6 => ['oper' => 'down', 'speed_bps' => 0, 'alias' => '', 'err_start' => 0, 'err_end' => 0, 'changed_hours' => 30],
            7 => ['oper' => 'down', 'speed_bps' => 0, 'alias' => '', 'err_start' => 0, 'err_end' => 0, 'changed_hours' => 168],
            8 => ['oper' => 'up', 'speed_bps' => 10_000_000_000, 'alias' => 'SFP+ Router trunk', 'err_start' => 0, 'err_end' => 0, 'changed_hours' => 240],
        ];

        foreach ($portSpecs as $index => $spec) {
            $state = $spec['oper'] === 'up' ? MonitorState::Healthy : MonitorState::Failed;
            $monitor = Monitor::query()->updateOrCreate(
                [
                    'device_id' => $switch->id,
                    'kind' => MonitorKind::SnmpInterface,
                    'name' => 'Port '.$index,
                ],
                [
                    'profile_id' => $profile->id,
                    'target' => '10.20.0.2:if'.$index,
                    'config' => ['if_index' => $index],
                    'current_state' => $state,
                    'effective_state' => $state,
                    'affects_availability' => false,
                    'is_enabled' => true,
                    'last_observation_at' => now()->subMinutes(4),
                    'last_state_changed_at' => now()->subHours($spec['changed_hours']),
                ],
            );

            $recentObservations = MonitorObservation::query()
                ->where('monitor_id', $monitor->id)
                ->where('observed_at', '>=', now()->subHours(20))
                ->count();

            if ($recentObservations < 2) {
                foreach ([[$spec['err_start'], now()->subHours(18)], [$spec['err_end'], now()->subMinutes(4)]] as [$errors, $at]) {
                    MonitorObservation::query()->create([
                        'monitor_id' => $monitor->id,
                        'source_key' => Str::uuid()->toString(),
                        'state' => $state,
                        'latency_ms' => null,
                        'metrics' => [
                            'if_index' => $index,
                            'interface_name' => 'Port '.$index,
                            'interface_alias' => $spec['alias'],
                            'admin_status' => 'up',
                            'operational_status' => $spec['oper'],
                            'speed_bps' => $spec['speed_bps'],
                            'errors' => $errors,
                        ],
                        'observed_at' => $at,
                        'ingested_at' => $at,
                    ]);
                }
            }

            if (isset($children[$index])) {
                $child = $children[$index];
                $existingLink = DeviceRelationship::query()
                    ->active()
                    ->where('parent_device_id', $switch->id)
                    ->where('child_device_id', $child->id)
                    ->first();

                if ($existingLink instanceof DeviceRelationship) {
                    $existingLink->forceFill(['port' => 'Port '.$index])->saveQuietly();
                } else {
                    DeviceRelationship::query()->create([
                        'parent_device_id' => $switch->id,
                        'child_device_id' => $child->id,
                        'relationship_type' => RelationshipType::ConnectedTo,
                        'port' => 'Port '.$index,
                        'created_by_user_id' => $actorId,
                    ]);
                }
            }
        }
    }

    private function seedUnifiPortCheckStep(Site $site, Device $switch, ?int $actorId): void
    {
        $session = NetworkCheckSession::query()->updateOrCreate(
            ['uuid' => self::DEMO_SESSION_UUID],
            [
                'site_id' => $site->id,
                'hub_label' => $site->name.' hub',
                'device_id' => $switch->id,
                'focus_device_id' => $switch->id,
                'user_id' => $actorId,
                'asked_by' => 'Local screen check',
                'question' => 'Check PoE draw and port speeds on the comms cupboard switch',
                'plan' => ['steps' => ['switch_ports']],
                'answer' => 'Checked 8 ports on Comms cupboard switch: 4 PoE ports drawing 30.5 W total; Port 3 (Driveway camera) has 184 packet errors at 100 Mbps.',
                'suggest_unifi' => false,
                'unchecked_numbers' => false,
                'state' => 'answered',
                'planned_at' => now()->subMinutes(20),
                'answered_at' => now()->subMinutes(19),
                'expires_at' => now()->addDays(7),
            ],
        );

        NetworkCheckStep::query()->updateOrCreate(
            ['uuid' => self::DEMO_STEP_UUID],
            [
                'session_id' => $session->id,
                'position' => 1,
                'kind' => 'switch_ports',
                'source' => 'hub',
                'target_device_id' => $switch->id,
                'title' => 'Read switch ports from UniFi Network',
                'params' => ['device_id' => $switch->id],
                'state' => 'done',
                'result' => [
                    'ports' => [
                        ['idx' => 1, 'name' => 'Hallway Wi-Fi AP', 'up' => true, 'speed_mbps' => 1000, 'poe_w' => 8.4, 'rx_errors' => 0, 'tx_errors' => 0],
                        ['idx' => 2, 'name' => 'Front Lounge Wi-Fi AP', 'up' => true, 'speed_mbps' => 1000, 'poe_w' => 6.1, 'rx_errors' => 0, 'tx_errors' => 0],
                        ['idx' => 3, 'name' => 'Driveway camera', 'up' => true, 'speed_mbps' => 100, 'poe_w' => 4.8, 'rx_errors' => 184, 'tx_errors' => 0],
                        ['idx' => 4, 'name' => 'Front door controller', 'up' => true, 'speed_mbps' => 1000, 'poe_w' => 11.2, 'rx_errors' => 0, 'tx_errors' => 0],
                        ['idx' => 5, 'name' => 'NVR uplink', 'up' => true, 'speed_mbps' => 1000, 'poe_w' => 0.0, 'rx_errors' => 0, 'tx_errors' => 0],
                        ['idx' => 6, 'name' => 'Port 6', 'up' => false, 'speed_mbps' => 0, 'poe_w' => 0.0, 'rx_errors' => 0, 'tx_errors' => 0],
                        ['idx' => 7, 'name' => 'Port 7', 'up' => false, 'speed_mbps' => 0, 'poe_w' => 0.0, 'rx_errors' => 0, 'tx_errors' => 0],
                        ['idx' => 8, 'name' => 'SFP+ Router trunk', 'up' => true, 'speed_mbps' => 10000, 'poe_w' => null, 'rx_errors' => 0, 'tx_errors' => 0],
                    ],
                ],
                'finding' => '6 ports up, 2 free, 30.5 W PoE across 4 ports; Port 3 has packet errors.',
                'sent_at' => now()->subMinutes(20),
                'done_at' => now()->subMinutes(19),
                'expires_at' => now()->addDays(7),
            ],
        );
    }

    private function seedSwitchHistory(Site $site, Device $switch, ?int $actorId): void
    {
        $hasAlert = DeviceAlert::query()
            ->where('device_id', $switch->id)
            ->where('site_id', $site->id)
            ->where('kind', 'offline')
            ->exists();

        if (! $hasAlert) {
            DeviceAlert::query()->create([
                'device_id' => $switch->id,
                'site_id' => $site->id,
                'kind' => 'offline',
                'severity' => 'critical',
                'title' => 'Comms cupboard switch (local demo) is offline',
                'state' => 'done',
                'opened_at' => now()->subDays(3),
                'closed_at' => now()->subDays(3)->addMinutes(14),
                'close_reason' => 'recovered',
            ]);
        }

        $hasAudit = AuditLog::query()
            ->where('auditable_type', $switch->getMorphClass())
            ->where('auditable_id', $switch->id)
            ->where('action', 'security_devices.device.settings_updated')
            ->exists();

        if (! $hasAudit) {
            $log = new AuditLog([
                'user_id' => $actorId,
                'action' => 'security_devices.device.settings_updated',
                'auditable_type' => $switch->getMorphClass(),
                'auditable_id' => $switch->id,
                'meta' => [
                    'actor_id' => $actorId,
                    'site_id' => $site->id,
                    'fields' => ['tell_it_update_ready'],
                    'after' => ['is_enabled' => true],
                ],
                'ip_address' => '127.0.0.1',
            ]);
            $log->created_at = now()->subDays(2);
            $log->save();
        }
    }
}
