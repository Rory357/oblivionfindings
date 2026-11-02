<?php

use Oblivion\Collector\Config\SignedConfigLoader;
use Oblivion\Collector\Data\CollectorConfig;
use Oblivion\Collector\Exceptions\ConfigurationRejected;
use Oblivion\Collector\Runtime\CollectorCommandExecutor;
use Oblivion\Collector\Runtime\CollectorCommandTransport;
use Oblivion\Collector\Runtime\CommandJournal;
use Oblivion\Collector\Runtime\SshConfigBackupRunner;
use Oblivion\Collector\Runtime\SshSession;
use Oblivion\Collector\Runtime\UnifiAccessCommandRunner;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Security\ScopeGuard;
use Oblivion\Collector\Spool\CheckpointFile;
use Oblivion\Collector\Spool\EncryptedSpool;

/*
 * S&D Phase B: a router settings backup runs on the hub. The hub checks the trusted SSH key before
 * any login, runs only its own fixed read-only export commands, and sends the output back.
 */

const SSH_BACKUP_TRUSTED = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
const SSH_BACKUP_OTHER = 'SHA256:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

final class SshBackupFixtureSession implements SshSession
{
    /** @var list<string> */
    public array $calls = [];

    /** @param list<array{output: string, truncated: bool, timed_out: bool}> $outputs */
    public function __construct(public ?string $key, public array $outputs = [], public bool $loginAccepted = true) {}

    public function hostKeyFingerprint(): ?string
    {
        $this->calls[] = 'key';

        return $this->key;
    }

    public function login(string $username, array $material): bool
    {
        $this->calls[] = 'login:'.$username;

        return $this->loginAccepted;
    }

    public function exec(string $command, int $maxBytes, int $timeoutSeconds): array
    {
        $this->calls[] = 'exec:'.$command;

        return array_shift($this->outputs) ?? ['output' => '', 'truncated' => false, 'timed_out' => false];
    }

    public function close(): void
    {
        $this->calls[] = 'close';
    }
}

final class SshBackupUnusedTransport implements CollectorCommandTransport
{
    public function request(array $endpoint, string $method, string $path, array $headers, ?array $json = null): array
    {
        throw new RuntimeException('The UniFi transport must not be used for a settings backup.');
    }
}

/** @return array<string, mixed> */
function sshBackupCommandFixture(array $overrides = []): array
{
    return array_replace_recursive([
        'command_uuid' => '5a0e6c7d-2f1b-4c3a-9d8e-7f6a5b4c3d2e',
        'attempt_uuid' => '6b1f7d8e-3a2c-4d4b-8e9f-8a7b6c5d4e3f',
        'attempt_number' => 1,
        'site_id' => 9,
        'device_id' => '1',
        'capability' => 'network.config.backup',
        'provider' => 'ssh',
        'adapter' => 'ssh_config_backup_v1',
        'protocol' => 'command.ssh_config_backup',
        'target' => '10.44.0.10',
        'expires_at' => '2026-07-23T12:30:00+00:00',
        'idempotency_hash' => hash('sha256', 'router-backup-once'),
        'contract_hash' => hash('sha256', 'immutable-ssh-backup-contract'),
        'parameters' => ['export_profile' => 'mikrotik_routeros'],
        'expected_state' => ['snapshot_available' => true],
        'endpoint' => [
            'address' => '10.44.0.10',
            'port' => 22,
            'expected_host_key' => SSH_BACKUP_TRUSTED,
            'connect_timeout_seconds' => 5,
            'command_timeout_seconds' => 20,
            'max_output_bytes' => 1_048_576,
        ],
        'credential_lease' => sealedCollectorCredentialLease(
            ['username' => 'backup_ro', 'password' => 'HUB-SSH-TEST-ONLY-PASSWORD'],
            ['device_id' => '1', 'protocol' => 'command.ssh_config_backup', 'target' => '10.44.0.10'],
        ),
    ], $overrides);
}

function sshBackupConfig(array $command, string $directory): CollectorConfig
{
    return (new SignedConfigLoader(
        new EnvelopeVerifier(collectorPublicKey()),
        new CheckpointFile($directory.'/checkpoint.json'),
        '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
        9,
    ))->load(signedCollectorConfig([
        'version' => 3,
        'scope' => [
            'devices' => ['1' => ['10.44.0.10']],
            'protocols' => ['icmp', 'tcp', 'dns', 'http', 'https', 'tls', 'snmp', 'ssh', 'winrm', 'command.ssh_config_backup'],
        ],
        'commands' => [$command],
    ]), collectorNow());
}

function sshBackupRunner(CollectorConfig $config, SshBackupFixtureSession $session): SshConfigBackupRunner
{
    return new SshConfigBackupRunner(
        new ScopeGuard($config),
        new CredentialLeaseDecryptor(collectorIdentitySecretKey()),
        static fn (string $address, int $port, int $timeout): SshSession => $session,
    );
}

it('sends no login to a device whose key is not trusted yet, and reports the key it offered', function () {
    $directory = collectorTempDirectory('ssh-untrusted');
    try {
        $command = sshBackupCommandFixture(['endpoint' => ['expected_host_key' => null]]);
        $config = sshBackupConfig($command, $directory);
        $session = new SshBackupFixtureSession(SSH_BACKUP_OTHER);

        $result = sshBackupRunner($config, $session)->run($config->commands[0], collectorNow());

        expect($session->calls)->toBe(['key', 'close'])
            ->and($result['execution_status'])->toBe('failed')
            ->and($result['safe_result'])->toBe(['reason_code' => 'host_key_unconfirmed', 'offered_host_key' => SSH_BACKUP_OTHER])
            ->and($result)->not->toHaveKey('attachment')
            ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('HUB-SSH-TEST-ONLY-PASSWORD');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('refuses a changed key without logging in', function () {
    $directory = collectorTempDirectory('ssh-mismatch');
    try {
        $config = sshBackupConfig(sshBackupCommandFixture(), $directory);
        $session = new SshBackupFixtureSession(SSH_BACKUP_OTHER);

        $result = sshBackupRunner($config, $session)->run($config->commands[0], collectorNow());

        expect($session->calls)->toBe(['key', 'close'])
            ->and($result['safe_result'])->toBe(['reason_code' => 'host_key_mismatch', 'offered_host_key' => SSH_BACKUP_OTHER]);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('logs in to a trusted device, falls back past a RouterOS 7 refusal, and attaches the export', function () {
    $directory = collectorTempDirectory('ssh-success');
    try {
        $config = sshBackupConfig(sshBackupCommandFixture(), $directory);
        $export = "# by RouterOS 7.16\n/interface bridge add name=br-lan\n";
        $session = new SshBackupFixtureSession(SSH_BACKUP_TRUSTED, [
            ['output' => 'expected end of command (line 1 column 15)', 'truncated' => false, 'timed_out' => false],
            ['output' => $export, 'truncated' => false, 'timed_out' => false],
        ]);

        $result = sshBackupRunner($config, $session)->run($config->commands[0], collectorNow());

        expect($session->calls)->toBe(['key', 'login:backup_ro', 'exec:/export terse hide-sensitive', 'exec:/export terse', 'close'])
            ->and($result['execution_status'])->toBe('succeeded')
            ->and($result['safe_result'])->toBe([
                'provider_state' => 'captured',
                'export_profile' => 'mikrotik_routeros',
                'output_bytes' => strlen($export),
                'output_sha256' => hash('sha256', $export),
                'host_key_fingerprint' => SSH_BACKUP_TRUSTED,
            ])
            ->and($result['provider_request_reference'])->toBe('ssh-backup:'.$config->commands[0]['command_uuid'])
            ->and(gzdecode(base64_decode($result['attachment']['data'], true)))->toBe($export)
            ->and($result['attachment']['sha256'])->toBe(hash('sha256', $export));
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('stops at the hub’s 1 MB limit and when the device rejects the login', function () {
    $directory = collectorTempDirectory('ssh-limits');
    try {
        $config = sshBackupConfig(sshBackupCommandFixture(), $directory);
        $tooBig = new SshBackupFixtureSession(SSH_BACKUP_TRUSTED, [['output' => 'x', 'truncated' => true, 'timed_out' => false]]);
        $rejected = new SshBackupFixtureSession(SSH_BACKUP_TRUSTED, [], loginAccepted: false);

        expect(sshBackupRunner($config, $tooBig)->run($config->commands[0], collectorNow())['safe_result'])
            ->toBe(['reason_code' => 'hub_output_exceeded_limit'])
            ->and(sshBackupRunner($config, $rejected)->run($config->commands[0], collectorNow())['safe_result'])
            ->toBe(['reason_code' => 'auth_failed'])
            ->and($rejected->calls)->toBe(['key', 'login:backup_ro', 'close']);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('spools the backup result once through the command executor', function () {
    $directory = collectorTempDirectory('ssh-executor');
    try {
        $config = sshBackupConfig(sshBackupCommandFixture(), $directory);
        $session = new SshBackupFixtureSession(SSH_BACKUP_TRUSTED, [
            ['output' => "/interface bridge add name=br-lan\n", 'truncated' => false, 'timed_out' => false],
        ]);
        $scope = new ScopeGuard($config);
        $decryptor = new CredentialLeaseDecryptor(collectorIdentitySecretKey());
        $executor = new CollectorCommandExecutor(
            new CommandJournal($directory),
            new UnifiAccessCommandRunner($scope, $decryptor, new SshBackupUnusedTransport, static fn (int $seconds): null => null),
            new SshConfigBackupRunner($scope, $decryptor, static fn (string $address, int $port, int $timeout): SshSession => $session),
        );
        $spool = new EncryptedSpool($directory, new CheckpointFile($directory.'/checkpoint.json'), 4_194_304, 100, 3600);

        expect($executor->execute($config->commands[0], $spool, collectorNow()))->toBeTrue()
            ->and($executor->execute($config->commands[0], $spool, collectorNow()))->toBeFalse();
        $items = $spool->readBatch(10, collectorNow());
        expect($items)->toHaveCount(1)
            ->and($items[0]['payload']['capability'])->toBe('network.config.backup')
            ->and($items[0]['payload']['attachment']['kind'])->toBe('cli_output');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('refuses a backup job with an unknown profile, command text, a bad key or an open port range', function (array $overrides) {
    $directory = collectorTempDirectory('ssh-scope');
    try {
        expect(fn () => sshBackupConfig(sshBackupCommandFixture($overrides), $directory))->toThrow(ConfigurationRejected::class);
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'unknown profile' => [['parameters' => ['export_profile' => 'linux_shell']]],
    'command text' => [['parameters' => ['command' => 'reboot']]],
    'malformed trusted key' => [['endpoint' => ['expected_host_key' => 'md5:aa:bb']]],
    'port out of range' => [['endpoint' => ['port' => 70000]]],
    'unbounded output' => [['endpoint' => ['max_output_bytes' => 50_000_000]]],
    'wrong protocol for the job' => [['protocol' => 'command.unifi_access']],
]);
