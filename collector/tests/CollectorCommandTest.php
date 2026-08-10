<?php

use Oblivion\Collector\Config\SignedConfigLoader;
use Oblivion\Collector\Exceptions\ConfigurationRejected;
use Oblivion\Collector\Runtime\CollectorCommandExecutor;
use Oblivion\Collector\Runtime\CollectorCommandTransport;
use Oblivion\Collector\Runtime\CommandJournal;
use Oblivion\Collector\Runtime\UnifiAccessCommandRunner;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Security\ScopeGuard;
use Oblivion\Collector\Spool\CheckpointFile;
use Oblivion\Collector\Spool\EncryptedSpool;

final class CollectorCommandFixtureTransport implements CollectorCommandTransport
{
    /** @var list<array{status: int, body: string, location: ?string}|Throwable> */
    public array $responses;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @param list<array{status: int, body: string, location: ?string}|Throwable> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request(
        array $endpoint,
        string $method,
        string $path,
        array $headers,
        ?array $json = null,
    ): array {
        $this->calls[] = compact('endpoint', 'method', 'path', 'headers', 'json');
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        if (! is_array($response)) {
            throw new RuntimeException('No collector command fixture response remains.');
        }

        return $response;
    }
}

/** @return array<string, mixed> */
function collectorCommandFixture(array $overrides = []): array
{
    $command = [
        'command_uuid' => '3f179156-f43b-4a7d-a9bc-5477cf57f3d1',
        'attempt_uuid' => '7b85ca23-a62f-41d7-96f4-e09f113331ad',
        'attempt_number' => 1,
        'site_id' => 9,
        'device_id' => '1',
        'capability' => 'access.door.unlock_timed',
        'provider' => 'unifi',
        'adapter' => 'unifi_access_timed_unlock_v1',
        'protocol' => 'command.unifi_access',
        'target' => '10.44.0.10',
        'expires_at' => '2026-07-23T12:30:00+00:00',
        'idempotency_hash' => hash('sha256', 'door-unlock-once'),
        'contract_hash' => hash('sha256', 'immutable-collector-command-contract'),
        'parameters' => ['duration_seconds' => 15],
        'expected_state' => ['locked' => true],
        'endpoint' => [
            'scheme' => 'https',
            'host' => 'access.remote.example.test',
            'port' => 12445,
            'address' => '10.44.0.10',
            'door_id' => '0ed545f8-2fcd-4839-9021-b39e707f6aa9',
            'connect_timeout_seconds' => 5,
            'response_timeout_seconds' => 15,
            'max_response_bytes' => 65_536,
        ],
        'credential_lease' => sealedCollectorCredentialLease(
            ['api_token' => 'COLLECTOR-UNIFI-SECRET-TOKEN'],
            [
                'device_id' => '1',
                'protocol' => 'command.unifi_access',
                'target' => '10.44.0.10',
            ],
        ),
    ];

    return array_replace_recursive($command, $overrides);
}

function collectorCommandEnvelope(array $command): string
{
    return collectorCommandBatchEnvelope([$command]);
}

/** @param list<array<string, mixed>> $commands */
function collectorCommandBatchEnvelope(array $commands): string
{
    return signedCollectorConfig([
        'version' => 3,
        'scope' => [
            'devices' => [
                '1' => ['10.44.0.10'],
            ],
            'protocols' => [
                'icmp', 'tcp', 'dns', 'http', 'https', 'tls', 'snmp', 'ssh', 'winrm',
                'command.unifi_access',
            ],
        ],
        'commands' => $commands,
    ]);
}

function collectorCommandResponse(string $relay = 'lock'): array
{
    return [
        'status' => 200,
        'body' => json_encode([
            'code' => 'SUCCESS',
            'data' => [
                'id' => '0ed545f8-2fcd-4839-9021-b39e707f6aa9',
                'is_bind_hub' => true,
                'door_lock_relay_status' => $relay,
            ],
        ], JSON_THROW_ON_ERROR),
        'location' => null,
    ];
}

it('executes one exact signed typed command and durably spools a reconciled result once', function () {
    $directory = collectorTempDirectory('command-once');
    try {
        $command = collectorCommandFixture();
        $checkpoint = new CheckpointFile($directory.'/checkpoint.json');
        $config = (new SignedConfigLoader(
            new EnvelopeVerifier(collectorPublicKey()),
            $checkpoint,
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            9,
        ))->load(collectorCommandEnvelope($command), collectorNow());
        $transport = new CollectorCommandFixtureTransport([
            collectorCommandResponse('lock'),
            ['status' => 200, 'body' => json_encode(['code' => 'SUCCESS'], JSON_THROW_ON_ERROR), 'location' => null],
            collectorCommandResponse('lock'),
        ]);
        $spool = new EncryptedSpool($directory, $checkpoint, 262_144, 100, 3600);
        $executor = new CollectorCommandExecutor(
            new CommandJournal($directory),
            new UnifiAccessCommandRunner(
                new ScopeGuard($config),
                new CredentialLeaseDecryptor(collectorIdentitySecretKey()),
                $transport,
                static fn (int $seconds): null => null,
            ),
        );

        expect($executor->execute($config->commands[0], $spool, collectorNow()))->toBeTrue()
            ->and($executor->execute($config->commands[0], $spool, collectorNow()))->toBeFalse()
            ->and($transport->calls)->toHaveCount(3);

        $items = $spool->readBatch(10, collectorNow());
        expect($items)->toHaveCount(1)
            ->and($items[0]['id'])->toBe('command-result:'.$command['attempt_uuid'])
            ->and($items[0]['payload']['item_type'])->toBe('command_result')
            ->and($items[0]['payload']['execution_status'])->toBe('succeeded')
            ->and($items[0]['payload']['reconciliation']['outcome'])->toBe('matched')
            ->and($items[0]['payload']['reconciliation']['observed_state'])->toBe(['locked' => true])
            ->and(json_encode($items, JSON_THROW_ON_ERROR))->not->toContain(
                'COLLECTOR-UNIFI-SECRET-TOKEN',
                'credential_lease',
                'sealed_material',
            );
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('soaks one hundred signed commands through buffered restart ordered return and replay protection', function () {
    $directory = collectorTempDirectory('command-soak');
    try {
        $commands = [];
        $responses = [];
        foreach (range(1, 100) as $index) {
            $commands[] = collectorCommandFixture([
                'command_uuid' => sprintf('00000000-0000-4000-8000-%012d', $index),
                'attempt_uuid' => sprintf('10000000-0000-4000-8000-%012d', $index),
                'idempotency_hash' => hash('sha256', 'collector-command-soak-'.$index),
                'contract_hash' => hash('sha256', 'collector-command-contract-'.$index),
            ]);
            $responses[] = collectorCommandResponse('lock');
            $responses[] = [
                'status' => 200,
                'body' => json_encode(['code' => 'SUCCESS'], JSON_THROW_ON_ERROR),
                'location' => null,
            ];
            $responses[] = collectorCommandResponse('lock');
        }

        $checkpoint = new CheckpointFile($directory.'/checkpoint.json');
        $config = (new SignedConfigLoader(
            new EnvelopeVerifier(collectorPublicKey()),
            $checkpoint,
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            9,
        ))->load(collectorCommandBatchEnvelope($commands), collectorNow());
        $transport = new CollectorCommandFixtureTransport($responses);
        $executor = new CollectorCommandExecutor(
            new CommandJournal($directory),
            new UnifiAccessCommandRunner(
                new ScopeGuard($config),
                new CredentialLeaseDecryptor(collectorIdentitySecretKey()),
                $transport,
                static fn (int $seconds): null => null,
            ),
        );
        $spool = new EncryptedSpool($directory, $checkpoint, 4_194_304, 100, 3600);

        foreach (array_slice($config->commands, 0, 50) as $command) {
            expect($executor->execute($command, $spool, collectorNow()))->toBeTrue();
        }
        expect($spool->count(collectorNow()))->toBe(50);

        $spool = new EncryptedSpool(
            $directory,
            new CheckpointFile($directory.'/checkpoint.json'),
            4_194_304,
            100,
            3600,
        );
        $executor = new CollectorCommandExecutor(
            new CommandJournal($directory),
            new UnifiAccessCommandRunner(
                new ScopeGuard($config),
                new CredentialLeaseDecryptor(collectorIdentitySecretKey()),
                $transport,
                static fn (int $seconds): null => null,
            ),
        );
        foreach (array_slice($config->commands, 50) as $command) {
            expect($executor->execute($command, $spool, collectorNow()))->toBeTrue();
        }

        $items = $spool->readBatch(100, collectorNow());
        expect($items)->toHaveCount(100)
            ->and(array_column($items, 'source_sequence'))->toBe(range(1, 100))
            ->and(array_unique(array_column($items, 'id')))->toHaveCount(100)
            ->and(array_unique(array_column(array_column($items, 'payload'), 'execution_status')))->toBe(['succeeded'])
            ->and($transport->calls)->toHaveCount(300);

        $spool->acknowledge(array_column($items, 'id'), 100);
        expect($spool->count(collectorNow()))->toBe(0)
            ->and($executor->execute($config->commands[0], $spool, collectorNow()))->toBeFalse()
            ->and($transport->calls)->toHaveCount(300);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('never repeats a side effect after restart with only durable execution intent', function () {
    $directory = collectorTempDirectory('command-interrupted');
    try {
        $command = collectorCommandFixture();
        $checkpoint = new CheckpointFile($directory.'/checkpoint.json');
        $config = (new SignedConfigLoader(
            new EnvelopeVerifier(collectorPublicKey()),
            $checkpoint,
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            9,
        ))->load(collectorCommandEnvelope($command), collectorNow());
        $journal = new CommandJournal($directory);
        expect($journal->begin($config->commands[0])['created'])->toBeTrue();

        $transport = new CollectorCommandFixtureTransport([]);
        $spool = new EncryptedSpool($directory, $checkpoint, 262_144, 100, 3600);
        $executor = new CollectorCommandExecutor(
            new CommandJournal($directory),
            new UnifiAccessCommandRunner(
                new ScopeGuard($config),
                new CredentialLeaseDecryptor(collectorIdentitySecretKey()),
                $transport,
                static fn (int $seconds): null => null,
            ),
        );

        expect($executor->execute($config->commands[0], $spool, collectorNow()))->toBeTrue()
            ->and($transport->calls)->toBe([]);
        $result = $spool->readBatch(1, collectorNow())[0]['payload'];
        expect($result['execution_status'])->toBe('uncertain')
            ->and($result['reconciliation']['outcome'])->toBe('uncertain')
            ->and($result['safe_failure_reason'])->toContain('was not repeated');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('surfaces an ambiguous provider response as uncertain and observes before any retry', function () {
    $directory = collectorTempDirectory('command-ambiguous');
    try {
        $command = collectorCommandFixture();
        $checkpoint = new CheckpointFile($directory.'/checkpoint.json');
        $config = (new SignedConfigLoader(
            new EnvelopeVerifier(collectorPublicKey()),
            $checkpoint,
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            9,
        ))->load(collectorCommandEnvelope($command), collectorNow());
        $transport = new CollectorCommandFixtureTransport([
            collectorCommandResponse('lock'),
            new RuntimeException('response lost after write'),
            collectorCommandResponse('lock'),
        ]);
        $spool = new EncryptedSpool($directory, $checkpoint, 262_144, 100, 3600);
        $executor = new CollectorCommandExecutor(
            new CommandJournal($directory),
            new UnifiAccessCommandRunner(
                new ScopeGuard($config),
                new CredentialLeaseDecryptor(collectorIdentitySecretKey()),
                $transport,
                static fn (int $seconds): null => null,
            ),
        );

        expect($executor->execute($config->commands[0], $spool, collectorNow()))->toBeTrue()
            ->and($transport->calls)->toHaveCount(3);
        $result = $spool->readBatch(1, collectorNow())[0]['payload'];
        expect($result['execution_status'])->toBe('uncertain')
            ->and($result['reconciliation']['outcome'])->toBe('matched')
            ->and($result['reconciliation']['observed_state'])->toBe(['locked' => true])
            ->and($result['safe_failure_reason'])->toContain('before any retry');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('rejects changed expired or executable command work before execution', function () {
    $directory = collectorTempDirectory('command-rejected');
    try {
        $loader = new SignedConfigLoader(
            new EnvelopeVerifier(collectorPublicKey()),
            new CheckpointFile($directory.'/checkpoint.json'),
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            9,
        );

        expect(fn () => $loader->load(collectorCommandEnvelope(collectorCommandFixture([
            'expires_at' => '2026-07-23T11:59:59+00:00',
        ])), collectorNow()))->toThrow(ConfigurationRejected::class, 'expired')
            ->and(fn () => $loader->load(collectorCommandEnvelope(collectorCommandFixture([
                'parameters' => ['duration_seconds' => 15, 'shell' => 'unlock all'],
            ])), collectorNow(), false))->toThrow(ConfigurationRejected::class);
    } finally {
        removeCollectorDirectory($directory);
    }
});
