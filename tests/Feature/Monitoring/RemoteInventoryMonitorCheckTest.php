<?php

use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Data\ProbeScope;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Jobs\RunMonitorCheck;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Protocols\RemoteInventory\SshCommandResponse;
use App\Domain\Monitoring\Protocols\RemoteInventory\SshConnection;
use App\Domain\Monitoring\Protocols\RemoteInventory\SshConnectionFactory;
use App\Domain\Monitoring\Protocols\RemoteInventory\WinRmHttpClient;
use App\Domain\Monitoring\Protocols\RemoteInventory\WinRmHttpResponse;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\Monitoring\Services\MonitorCheckRunner;
use App\Domain\Monitoring\Services\MonitorScheduler;
use App\Domain\Monitoring\Services\ProbeAdapterRegistry;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

final class TaskElevenFeatureScopeProvider implements ApprovedProbeScopeProvider
{
    public function forDeviceAtSite(int $siteId, int $deviceId): ProbeScope
    {
        return new ProbeScope(
            siteId: $siteId,
            deviceId: $deviceId,
            approvedCidrs: ['10.44.0.0/16'],
            allowedPorts: [22, 5986],
            connectTimeoutSeconds: 2,
            responseTimeoutSeconds: 15,
            maxResponseBytes: 1_048_576,
        );
    }
}

final class TaskElevenFeatureCredentialProvider implements CredentialLeaseProvider
{
    /** @var list<list<string>> */
    public array $capabilityRequests = [];

    public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
    {
        $this->capabilityRequests[] = $capabilities;
        $material = in_array('inventory:ssh:read_only', $capabilities, true)
            ? ['username' => 'monitor', 'password' => 'ephemeral-ssh-password']
            : ['auth_mode' => 'kerberos', 'username' => 'monitor@example.test', 'password' => 'ephemeral-winrm-password'];

        return new CredentialLease(
            leaseId: 'feature-'.count($this->capabilityRequests),
            expiresAt: CarbonImmutable::now('UTC')->addMinute(),
            material: $material,
        );
    }
}

final class TaskElevenFeatureSshConnection implements SshConnection
{
    public int $executions = 0;

    public function fingerprint(): string
    {
        return 'SHA256:'.'A'.str_repeat('B', 42);
    }

    public function authenticate(array $material): bool
    {
        return ($material['username'] ?? null) === 'monitor';
    }

    public function execute(array $command, int $timeoutSeconds, int $maxOutputBytes): SshCommandResponse
    {
        $this->executions++;
        $output = match ($command[0]) {
            'uname' => "Linux 6.8.0\n",
            'uptime' => "2026-07-20 01:02:03\n",
            'df' => "Filesystem 1-blocks Used Available Capacity Mounted on\n/dev/sda 1000 400 600 40% /\n",
            'systemctl' => '',
        };

        return new SshCommandResponse($output, 0, false, false, 2);
    }

    public function close(): void {}
}

final class TaskElevenFeatureSshFactory implements SshConnectionFactory
{
    public TaskElevenFeatureSshConnection $connection;

    public function __construct()
    {
        $this->connection = new TaskElevenFeatureSshConnection;
    }

    public function connect(AuthorizedProbeTarget $target, string $address): SshConnection
    {
        return $this->connection;
    }
}

final class TaskElevenFeatureWinRmClient implements WinRmHttpClient
{
    public int $requests = 0;

    public function exchange(
        AuthorizedProbeTarget $target,
        string $address,
        string $soap,
        array $material,
        int $maxResponseBytes,
    ): WinRmHttpResponse {
        $this->requests++;
        $body = match (true) {
            str_contains($soap, 'Win32_OperatingSystem') => $this->xml('Win32_OperatingSystem', [
                'Caption' => 'Windows Server 2025',
                'Version' => '10.0.26100',
                'LastBootUpTime' => '20260721010203.000000+000',
            ]),
            str_contains($soap, 'Win32_LogicalDisk') => $this->xml('Win32_LogicalDisk', [
                'Size' => '2000',
                'FreeSpace' => '1500',
            ]),
            default => $this->xml('Win32_Service', ['State' => 'Running', 'StartMode' => 'Auto']),
        };

        return new WinRmHttpResponse(200, $body, 3, false);
    }

    /** @param array<string, string> $fields */
    private function xml(string $class, array $fields): string
    {
        $properties = '';
        foreach ($fields as $key => $value) {
            $properties .= sprintf('<p:%1$s>%2$s</p:%1$s>', $key, htmlspecialchars($value, ENT_XML1));
        }

        return '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" '
            .'xmlns:p="http://schemas.microsoft.com/wbem/wsman/1/wmi/root/cimv2/Win32">'
            ."<s:Body><p:{$class}>{$properties}</p:{$class}></s:Body></s:Envelope>";
    }
}

/** @return array{site: Site, device: Device, profile: MonitoringProfile, ssh: Monitor, winrm: Monitor} */
function taskElevenFeatureMonitors(): array
{
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $device = Device::factory()->itInfrastructure()->create();
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subMinute(),
        'released_at' => null,
    ]);
    $profile = MonitoringProfile::factory()->create([
        'failure_confirmations' => 1,
        'recovery_confirmations' => 1,
        'interval_seconds' => 60,
        'is_active' => true,
    ]);
    $base = [
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'collector_id' => null,
        'current_state' => MonitorState::Unknown,
        'is_enabled' => true,
        'last_observation_at' => null,
    ];
    $ssh = Monitor::factory()->create([
        ...$base,
        'kind' => MonitorKind::SshInventory,
        'target' => '10.44.0.10',
        'config' => [
            'host' => '10.44.0.10',
            'port' => 22,
            'profile' => 'linux.basic',
            'credential_reference' => 'vault:ssh/site-device',
            'host_key_fingerprint' => 'SHA256:'.'A'.str_repeat('B', 42),
        ],
    ]);
    $winrm = Monitor::factory()->create([
        ...$base,
        'kind' => MonitorKind::WinRmInventory,
        'target' => 'https://10.44.0.10:5986/wsman',
        'config' => [
            'url' => 'https://10.44.0.10:5986/wsman',
            'profile' => 'windows.basic',
            'credential_reference' => 'vault:winrm/site-device',
        ],
    ]);

    return compact('site', 'device', 'profile', 'ssh', 'winrm');
}

beforeEach(function () {
    config()->set('monitoring.delivery.sequence_lock_store', 'array');
    config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', true);
    config()->set('monitoring.storage.snapshots.disk', 'monitoring-snapshots');
    config()->set('filesystems.disks.monitoring-snapshots', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/monitoring-snapshots'),
        'serve' => false,
        'throw' => true,
    ]);
    Storage::fake('monitoring-snapshots');
});

it('runs approved SSH and WinRM inventory through the canonical observation path', function () {
    $record = taskElevenFeatureMonitors();
    $credentials = new TaskElevenFeatureCredentialProvider;
    $ssh = new TaskElevenFeatureSshFactory;
    $winrm = new TaskElevenFeatureWinRmClient;
    app()->instance(ApprovedProbeScopeProvider::class, new TaskElevenFeatureScopeProvider);
    app()->instance(CredentialLeaseProvider::class, $credentials);
    app()->instance(SshConnectionFactory::class, $ssh);
    app()->instance(WinRmHttpClient::class, $winrm);
    app()->forgetInstance(EgressPolicy::class);
    app()->forgetInstance(ProbeAdapterRegistry::class);

    $runner = app(MonitorCheckRunner::class);
    $runner->run($record['ssh']->id, 'scheduled:ssh');
    $runner->run($record['winrm']->id, 'scheduled:winrm');

    $sshObservation = $record['ssh']->observations()->sole();
    $winRmObservation = $record['winrm']->observations()->sole();
    expect($sshObservation->state)->toBe(MonitorState::Healthy)
        ->and($sshObservation->message)->toBe('ssh_inventory_ok')
        ->and($sshObservation->metrics)->toMatchArray([
            'protocol_kind' => 'ssh_inventory',
            'inventory_profile' => 'linux.basic',
            'disk_bytes_total' => 1000,
        ])->and($winRmObservation->state)->toBe(MonitorState::Healthy)
        ->and($winRmObservation->message)->toBe('winrm_inventory_ok')
        ->and($winRmObservation->metrics)->toMatchArray([
            'protocol_kind' => 'winrm_inventory',
            'inventory_profile' => 'windows.basic',
            'os_name' => 'Windows Server 2025',
        ])->and($ssh->connection->executions)->toBe(4)
        ->and($winrm->requests)->toBe(3)
        ->and($credentials->capabilityRequests)->toBe([
            ['inventory:ssh:read_only'],
            ['inventory:winrm:read_only'],
        ])->and(json_encode([$sshObservation->metrics, $winRmObservation->metrics], JSON_THROW_ON_ERROR))
        ->not->toContain('ephemeral-', 'raw_output', 'CommandLine', 'Password');
});

it('schedules both approved inventory monitor kinds as central direct checks', function () {
    $record = taskElevenFeatureMonitors();
    Queue::fake();

    $result = app(MonitorScheduler::class)->dispatchDue(CarbonImmutable::parse('2026-07-23T12:07:00Z'));

    expect($result->directDispatched)->toBe(2);
    Queue::assertPushed(RunMonitorCheck::class, 2);
    Queue::assertPushed(RunMonitorCheck::class, fn (RunMonitorCheck $job): bool => $job->monitorId === $record['ssh']->id);
    Queue::assertPushed(RunMonitorCheck::class, fn (RunMonitorCheck $job): bool => $job->monitorId === $record['winrm']->id);
});
