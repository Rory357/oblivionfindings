<?php

use App\Domain\Monitoring\Adapters\SshInventoryProbeAdapter;
use App\Domain\Monitoring\Adapters\WinRmInventoryProbeAdapter;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Protocols\RemoteInventory\InventoryQuery;
use App\Domain\Monitoring\Protocols\RemoteInventory\SshCommandResponse;
use App\Domain\Monitoring\Protocols\RemoteInventory\SshConnection;
use App\Domain\Monitoring\Protocols\RemoteInventory\SshConnectionFactory;
use App\Domain\Monitoring\Protocols\RemoteInventory\SshInventoryTransport;
use App\Domain\Monitoring\Protocols\RemoteInventory\WinRmHttpClient;
use App\Domain\Monitoring\Protocols\RemoteInventory\WinRmHttpResponse;
use App\Domain\Monitoring\Protocols\RemoteInventory\WinRmInventoryTransport;
use App\Domain\Monitoring\Protocols\RemoteInventory\WinRmTransportException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

/** @return array<string, array<string, mixed>> */
function taskElevenInventoryProfiles(): array
{
    return [
        'linux.basic' => [
            'platform' => 'linux',
            'operations' => [
                ['uname', '-sr'],
                ['uptime', '-s'],
                ['df', '-P', '-B1'],
                ['systemctl', 'list-units', '--type=service', '--state=failed', '--no-legend'],
            ],
        ],
        'windows.basic' => [
            'platform' => 'windows',
            'operations' => [
                ['class' => 'Win32_OperatingSystem', 'properties' => ['Caption', 'Version', 'LastBootUpTime']],
                ['class' => 'Win32_LogicalDisk', 'properties' => ['Size', 'FreeSpace']],
                ['class' => 'Win32_Service', 'properties' => ['State', 'StartMode']],
            ],
        ],
    ];
}

function taskElevenTarget(string $scheme, int $maxResponseBytes = 1_048_576): AuthorizedProbeTarget
{
    return AuthorizedProbeTarget::fromEgressPolicy(
        siteId: 9,
        deviceId: 81,
        scheme: $scheme,
        host: 'server-01.example.test',
        port: $scheme === 'ssh' ? 22 : 5986,
        path: $scheme === 'winrm' ? '/wsman' : null,
        addresses: ['10.44.0.10'],
        connectTimeoutSeconds: 5,
        responseTimeoutSeconds: 15,
        maxResponseBytes: $maxResponseBytes,
    );
}

function taskElevenLease(array $material, bool $expired = false): CredentialLease
{
    $now = CarbonImmutable::parse('2026-07-23T05:00:00Z');

    return new CredentialLease(
        leaseId: 'task-11-lease',
        expiresAt: $expired ? $now->subSecond() : $now->addMinute(),
        material: $material,
        clockNow: $now,
    );
}

final class TaskElevenSshConnection implements SshConnection
{
    /** @var array<string, SshCommandResponse> */
    public array $responses = [];

    /** @var list<list<string>> */
    public array $executed = [];

    public bool $authenticated = false;

    public bool $closed = false;

    public string $hostFingerprint;

    public function __construct(
        ?string $hostFingerprint = null,
        public bool $acceptAuthentication = true,
    ) {
        $this->hostFingerprint = $hostFingerprint ?? 'SHA256:'.'A'.str_repeat('B', 42);
    }

    public function fingerprint(): string
    {
        return $this->hostFingerprint;
    }

    public function authenticate(array $material): bool
    {
        $this->authenticated = true;

        return $this->acceptAuthentication;
    }

    public function execute(array $command, int $timeoutSeconds, int $maxOutputBytes): SshCommandResponse
    {
        $this->executed[] = $command;

        return $this->responses[$command[0]] ?? new SshCommandResponse('', 0, false, false, 1);
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

final class TaskElevenSshFactory implements SshConnectionFactory
{
    public function __construct(public TaskElevenSshConnection $connection) {}

    public function connect(AuthorizedProbeTarget $target, string $address): SshConnection
    {
        return $this->connection;
    }
}

final class TaskElevenWinRmClient implements WinRmHttpClient
{
    /** @var list<WinRmHttpResponse|Throwable> */
    public array $responses = [];

    /** @var list<string> */
    public array $soapRequests = [];

    public function exchange(
        AuthorizedProbeTarget $target,
        string $address,
        string $soap,
        array $material,
        int $maxResponseBytes,
    ): WinRmHttpResponse {
        $this->soapRequests[] = $soap;
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response ?? new WinRmHttpResponse(500, '', 1, false);
    }
}

final class TaskElevenCredentialProvider implements CredentialLeaseProvider
{
    /** @var list<string> */
    public array $capabilities = [];

    /** @param array<string, scalar|null> $material */
    public function __construct(public array $material) {}

    public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
    {
        $this->capabilities = $capabilities;

        return taskElevenLease($this->material);
    }
}

function taskElevenWinRmXml(string $class, array $rows): string
{
    $items = '';
    foreach ($rows as $row) {
        $properties = '';
        foreach ($row as $key => $value) {
            $properties .= sprintf('<p:%1$s>%2$s</p:%1$s>', $key, htmlspecialchars((string) $value, ENT_XML1));
        }
        $items .= "<p:{$class}>{$properties}</p:{$class}>";
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" '
        .'xmlns:p="http://schemas.microsoft.com/wbem/wsman/1/wmi/root/cimv2/Win32">'
        ."<s:Body>{$items}</s:Body></s:Envelope>";
}

it('allows named fixed read-only profiles and rejects arbitrary commands', function () {
    $linux = InventoryQuery::fromProfile('linux.basic', taskElevenInventoryProfiles());
    $windows = InventoryQuery::fromProfile('windows.basic', taskElevenInventoryProfiles());

    expect($linux->platform)->toBe('linux')
        ->and($linux->operations)->toBe([
            ['uname', '-sr'],
            ['uptime', '-s'],
            ['df', '-P', '-B1'],
            ['systemctl', 'list-units', '--type=service', '--state=failed', '--no-legend'],
        ])->and($windows->platform)->toBe('windows')
        ->and($windows->operations[0]['class'])->toBe('Win32_OperatingSystem')
        ->and(fn () => InventoryQuery::fromArbitraryCommand('reboot'))
        ->toThrow(LogicException::class, 'Arbitrary remote commands are forbidden.');
});

it('rejects sudo shell metacharacters and unapproved CIM fields in profile definitions', function (array $profile) {
    expect(fn () => InventoryQuery::fromProfile('unsafe', ['unsafe' => $profile]))
        ->toThrow(LogicException::class, 'Inventory profile is not approved.');
})->with([
    'sudo' => [['platform' => 'linux', 'operations' => [['sudo', 'uname', '-a']]]],
    'metacharacter' => [['platform' => 'linux', 'operations' => [['uname', ';reboot']]]],
    'CIM class' => [['platform' => 'windows', 'operations' => [['class' => 'Win32_Process', 'properties' => ['CommandLine']]]]],
]);

it('checks the pinned SSH host key before exposing lease material', function () {
    $connection = new TaskElevenSshConnection(hostFingerprint: 'SHA256:'.str_repeat('C', 43));
    $result = (new SshInventoryTransport(new TaskElevenSshFactory($connection)))->collect(
        taskElevenTarget('ssh'),
        taskElevenLease(['username' => 'monitor', 'password' => 'ephemeral-password']),
        InventoryQuery::fromProfile('linux.basic', taskElevenInventoryProfiles()),
        'SHA256:'.'A'.str_repeat('B', 42),
    );

    expect($result->status)->toBe('host_key_mismatch')
        ->and($connection->authenticated)->toBeFalse()
        ->and($connection->executed)->toBe([])
        ->and($connection->closed)->toBeTrue();
});

it('fails an expired one-use SSH lease without executing a query', function () {
    $connection = new TaskElevenSshConnection;
    $transport = new SshInventoryTransport(new TaskElevenSshFactory($connection));

    expect(fn () => $transport->collect(
        taskElevenTarget('ssh'),
        taskElevenLease(['username' => 'monitor', 'password' => 'ephemeral-password'], expired: true),
        InventoryQuery::fromProfile('linux.basic', taskElevenInventoryProfiles()),
        $connection->hostFingerprint,
    ))->toThrow(RuntimeException::class, 'Credential lease expired.')
        ->and($connection->executed)->toBe([])
        ->and($connection->closed)->toBeTrue();
});

it('returns bounded parsed SSH facts and marks a partial query without retaining raw output', function () {
    $connection = new TaskElevenSshConnection;
    $connection->responses = [
        'uname' => new SshCommandResponse("Linux 6.8.0\n", 0, false, false, 4),
        'uptime' => new SshCommandResponse("2026-07-20 01:02:03\n", 0, false, false, 3),
        'df' => new SshCommandResponse("Filesystem 1-blocks Used Available Capacity Mounted on\n/dev/sda 1000 400 600 40% /\n", 0, false, false, 5),
        'systemctl' => new SshCommandResponse('', 1, false, false, 2),
    ];

    $result = (new SshInventoryTransport(new TaskElevenSshFactory($connection)))->collect(
        taskElevenTarget('ssh'),
        taskElevenLease(['username' => 'monitor', 'password' => 'ephemeral-password']),
        InventoryQuery::fromProfile('linux.basic', taskElevenInventoryProfiles()),
        $connection->hostFingerprint,
    );

    expect($result->status)->toBe('partial')
        ->and($result->facts)->toMatchArray([
            'os_name' => 'Linux 6.8.0',
            'boot_time' => '2026-07-20T01:02:03.000000Z',
            'disk_bytes_total' => 1000,
            'disk_bytes_free' => 600,
            'disk_usage_percent_max' => 40,
            'volume_count' => 1,
        ])->and(array_keys($result->facts))->not->toContain('raw_output', 'password', 'secret')
        ->and($connection->executed)->toBe(InventoryQuery::fromProfile('linux.basic', taskElevenInventoryProfiles())->operations)
        ->and($connection->closed)->toBeTrue();
});

it('enforces SSH output and timeout bounds', function (SshCommandResponse $response, string $status) {
    $connection = new TaskElevenSshConnection;
    $connection->responses['uname'] = $response;
    $result = (new SshInventoryTransport(new TaskElevenSshFactory($connection)))->collect(
        taskElevenTarget('ssh', 1024),
        taskElevenLease(['username' => 'monitor', 'password' => 'ephemeral-password']),
        InventoryQuery::fromProfile('linux.basic', taskElevenInventoryProfiles()),
        $connection->hostFingerprint,
    );

    expect($result->status)->toBe($status);
})->with([
    'output cap' => [new SshCommandResponse(str_repeat('x', 1025), 0, false, true, 2), 'response_too_large'],
    'timeout' => [new SshCommandResponse('', null, true, false, 15_000), 'timeout'],
]);

it('requires WinRM HTTPS and reports certificate mismatch without retrying insecurely', function () {
    $client = new TaskElevenWinRmClient;
    $client->responses[] = new WinRmTransportException('certificate_mismatch');
    $transport = new WinRmInventoryTransport($client);
    $query = InventoryQuery::fromProfile('windows.basic', taskElevenInventoryProfiles());
    $material = ['auth_mode' => 'kerberos', 'username' => 'monitor@example.test', 'password' => 'ephemeral-password'];

    expect(fn () => $transport->collect(taskElevenTarget('http'), taskElevenLease($material), $query))
        ->toThrow(RuntimeException::class, 'WinRM requires an approved HTTPS target.')
        ->and($transport->collect(taskElevenTarget('winrm'), taskElevenLease($material), $query)->status)
        ->toBe('certificate_mismatch')
        ->and($client->soapRequests)->toHaveCount(1);
});

it('caps WinRM SOAP and never projects unapproved or sensitive response fields', function () {
    $client = new TaskElevenWinRmClient;
    $client->responses = [
        new WinRmHttpResponse(200, taskElevenWinRmXml('Win32_OperatingSystem', [[
            'Caption' => 'Windows Server 2025',
            'Version' => '10.0.26100',
            'LastBootUpTime' => '20260721010203.000000+000',
            'Password' => 'must-never-project',
        ]]), 8, false),
        new WinRmHttpResponse(500, '<fault>denied</fault>', 3, false),
        new WinRmHttpResponse(200, taskElevenWinRmXml('Win32_Service', [
            ['State' => 'Stopped', 'StartMode' => 'Auto', 'Secret' => 'hidden'],
            ['State' => 'Running', 'StartMode' => 'Auto'],
        ]), 4, false),
    ];
    $result = (new WinRmInventoryTransport($client))->collect(
        taskElevenTarget('winrm'),
        taskElevenLease(['auth_mode' => 'kerberos', 'username' => 'monitor@example.test', 'password' => 'ephemeral-password']),
        InventoryQuery::fromProfile('windows.basic', taskElevenInventoryProfiles()),
    );

    expect($result->status)->toBe('partial')
        ->and($result->facts)->toMatchArray([
            'os_name' => 'Windows Server 2025',
            'os_version' => '10.0.26100',
            'failed_service_count' => 1,
        ])->and(json_encode($result->facts, JSON_THROW_ON_ERROR))->not->toContain('must-never-project', 'hidden', 'Password', 'Secret')
        ->and(implode(' ', $client->soapRequests))->not->toContain('Password', 'Secret', 'CommandLine');
});

it('follows bounded WS-Man enumeration pages before normalising CIM facts', function () {
    $client = new TaskElevenWinRmClient;
    $client->responses = [
        new WinRmHttpResponse(200,
            '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:n="http://schemas.xmlsoap.org/ws/2004/09/enumeration"><s:Body><n:EnumerateResponse><n:EnumerationContext>ctx-1</n:EnumerationContext></n:EnumerateResponse></s:Body></s:Envelope>',
            1,
            false,
        ),
        new WinRmHttpResponse(200,
            str_replace('</s:Body>', '<n:EndOfSequence xmlns:n="http://schemas.xmlsoap.org/ws/2004/09/enumeration"/></s:Body>', taskElevenWinRmXml('Win32_OperatingSystem', [[
                'Caption' => 'Windows Server 2025',
                'Version' => '10.0.26100',
                'LastBootUpTime' => '20260721010203.000000+000',
            ]])),
            1,
            false,
        ),
        new WinRmHttpResponse(200, taskElevenWinRmXml('Win32_LogicalDisk', [['Size' => '1000', 'FreeSpace' => '600']]), 1, false),
        new WinRmHttpResponse(200, taskElevenWinRmXml('Win32_Service', [['State' => 'Running', 'StartMode' => 'Auto']]), 1, false),
    ];

    $result = (new WinRmInventoryTransport($client))->collect(
        taskElevenTarget('winrm'),
        taskElevenLease(['auth_mode' => 'kerberos', 'username' => 'monitor@example.test', 'password' => 'ephemeral-password']),
        InventoryQuery::fromProfile('windows.basic', taskElevenInventoryProfiles()),
    );

    expect($result->status)->toBe('ok')
        ->and($result->facts['os_name'])->toBe('Windows Server 2025')
        ->and($client->soapRequests)->toHaveCount(4)
        ->and($client->soapRequests[1])->toContain('/Pull', '<n:EnumerationContext>ctx-1</n:EnumerationContext>');
});

it('rejects oversized WinRM SOAP responses', function () {
    $client = new TaskElevenWinRmClient;
    $client->responses[] = new WinRmHttpResponse(200, str_repeat('x', 1025), 2, true);
    $result = (new WinRmInventoryTransport($client))->collect(
        taskElevenTarget('winrm', 1024),
        taskElevenLease(['auth_mode' => 'certificate', 'certificate_pem' => 'certificate', 'private_key_pem' => 'key']),
        InventoryQuery::fromProfile('windows.basic', taskElevenInventoryProfiles()),
    );

    expect($result->status)->toBe('response_too_large');
});

it('maps fixed SSH and WinRM inventory into scalar monitoring observations', function () {
    config()->set('monitoring-inventory.profiles', taskElevenInventoryProfiles());

    $sshConnection = new TaskElevenSshConnection;
    $sshConnection->responses = [
        'uname' => new SshCommandResponse("Linux 6.8.0\n", 0, false, false, 1),
        'uptime' => new SshCommandResponse("2026-07-20 01:02:03\n", 0, false, false, 1),
        'df' => new SshCommandResponse("Filesystem 1-blocks Used Available Capacity Mounted on\n/dev/sda 1000 400 600 40% /\n", 0, false, false, 1),
        'systemctl' => new SshCommandResponse('', 0, false, false, 1),
    ];
    $sshCredentials = new TaskElevenCredentialProvider(['username' => 'monitor', 'password' => 'ephemeral-password']);
    $ssh = new SshInventoryProbeAdapter(
        $sshCredentials,
        new SshInventoryTransport(new TaskElevenSshFactory($sshConnection)),
    );
    $sshObservation = $ssh->probe(new AuthorisedProbeContext(
        monitorId: 1,
        siteId: 9,
        deviceId: 81,
        kind: MonitorKind::SshInventory,
        target: taskElevenTarget('ssh'),
        config: [
            'profile' => 'linux.basic',
            'credential_reference' => 'vault:ssh/site-9/server-01',
            'host_key_fingerprint' => $sshConnection->hostFingerprint,
        ],
    ));

    $winRmClient = new TaskElevenWinRmClient;
    $winRmClient->responses = [
        new WinRmHttpResponse(200, taskElevenWinRmXml('Win32_OperatingSystem', [[
            'Caption' => 'Windows Server 2025', 'Version' => '10.0.26100', 'LastBootUpTime' => '20260721010203.000000+000',
        ]]), 1, false),
        new WinRmHttpResponse(200, taskElevenWinRmXml('Win32_LogicalDisk', [[
            'Size' => '1000', 'FreeSpace' => '600',
        ]]), 1, false),
        new WinRmHttpResponse(200, taskElevenWinRmXml('Win32_Service', [[
            'State' => 'Running', 'StartMode' => 'Auto',
        ]]), 1, false),
    ];
    $winRmCredentials = new TaskElevenCredentialProvider([
        'auth_mode' => 'kerberos', 'username' => 'monitor@example.test', 'password' => 'ephemeral-password',
    ]);
    $winRm = new WinRmInventoryProbeAdapter(
        $winRmCredentials,
        new WinRmInventoryTransport($winRmClient),
    );
    $winRmObservation = $winRm->probe(new AuthorisedProbeContext(
        monitorId: 2,
        siteId: 9,
        deviceId: 81,
        kind: MonitorKind::WinRmInventory,
        target: taskElevenTarget('winrm'),
        config: [
            'profile' => 'windows.basic',
            'credential_reference' => 'vault:winrm/site-9/server-01',
        ],
    ));

    expect($sshObservation->state)->toBe(MonitorState::Healthy)
        ->and($sshObservation->reasonCode)->toBe('ssh_inventory_ok')
        ->and($sshCredentials->capabilities)->toBe(['inventory:ssh:read_only'])
        ->and($winRmObservation->state)->toBe(MonitorState::Healthy)
        ->and($winRmObservation->reasonCode)->toBe('winrm_inventory_ok')
        ->and($winRmCredentials->capabilities)->toBe(['inventory:winrm:read_only'])
        ->and(json_encode([$sshObservation->evidence, $winRmObservation->evidence], JSON_THROW_ON_ERROR))
        ->not->toContain('ephemeral-password', 'raw_output', 'Password', 'Secret');
});

it('constructs only pinned SSH and HTTPS wsman targets', function () {
    expect(ProbeTarget::ssh('10.44.0.10', 22)->scheme)->toBe('ssh')
        ->and(ProbeTarget::winrm('https://server-01.example.test:5986/wsman')->scheme)->toBe('winrm')
        ->and(fn () => ProbeTarget::winrm('http://server-01.example.test:5985/wsman'))
        ->toThrow(RuntimeException::class, 'WinRM requires an HTTPS /wsman target')
        ->and(fn () => ProbeTarget::winrm('https://server-01.example.test:5986/PowerShell'))
        ->toThrow(RuntimeException::class, 'WinRM requires an HTTPS /wsman target');
});

it('keeps remote inventory outside the device-command boundary', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 3));
    $sources = file_get_contents($root.'/app/Domain/Monitoring/Protocols/RemoteInventory/SshInventoryTransport.php')
        .file_get_contents($root.'/app/Domain/Monitoring/Protocols/RemoteInventory/WinRmInventoryTransport.php');

    expect($sources)->not->toContain(
        'CommandDispatchPort',
        'shell_exec(',
        'proc_open(',
        'sudo ',
        "'allow_redirects' => true",
        "'verify' => false",
    );
});
