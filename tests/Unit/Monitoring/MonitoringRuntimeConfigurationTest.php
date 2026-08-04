<?php

use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\CommandDispatchPort;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Contracts\ProbeScopeResolver;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use App\Domain\Monitoring\Protocols\RemoteInventory\NativeSshConnectionFactory;
use App\Domain\Monitoring\Protocols\RemoteInventory\NativeWinRmHttpClient;
use App\Domain\Monitoring\Protocols\RemoteInventory\SshConnectionFactory;
use App\Domain\Monitoring\Protocols\RemoteInventory\WinRmHttpClient;
use App\Domain\Monitoring\Services\CanonicalProbeScopeResolver;
use App\Domain\Monitoring\Services\DiscoveryApprovedProbeScopeProvider;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\Monitoring\Services\NativeDnsResolver;
use App\Domain\Monitoring\Services\ProbeAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

/** @return array<string, mixed> */
function monitoringConfigurationWithSigningKeys(?string $encodedKeyRing): array
{
    $original = getenv('MONITORING_SIGNING_KEYS');

    try {
        $encodedKeyRing === null
            ? putenv('MONITORING_SIGNING_KEYS')
            : putenv('MONITORING_SIGNING_KEYS='.$encodedKeyRing);

        return require base_path('config/monitoring.php');
    } finally {
        $original === false
            ? putenv('MONITORING_SIGNING_KEYS')
            : putenv('MONITORING_SIGNING_KEYS='.$original);
    }
}

it('defines isolated runtime queues and binds governed device commands', function () {
    expect(config('monitoring.queues'))->toBe([
        'events' => 'monitoring-events',
        'checks' => 'monitoring-checks',
        'discovery' => 'monitoring-discovery',
        'provider' => 'monitoring-provider',
        'topology' => 'monitoring-topology',
        'maintenance' => 'monitoring-maintenance',
        'orchestration' => 'monitoring',
        'commands' => 'monitoring-commands',
    ])->and(app(CommandDispatchPort::class))->toBeInstanceOf(GovernedCommandDispatchService::class);
});

it('defines runtime contract egress and retention defaults', function () {
    expect(config('monitoring.contracts'))->toBe([
        'current' => 2,
        'accepted' => [1, 2],
        'payloads' => [
            'observation' => ['current' => 2, 'accepted' => [1, 2]],
            'event' => ['current' => 2, 'accepted' => [1, 2]],
            'configuration' => ['current' => 2, 'accepted' => [1, 2]],
            'projection' => ['current' => 2, 'accepted' => [1, 2]],
        ],
        'commands' => [
            'standard_current' => 6,
            'break_glass_current' => 7,
            'accepted' => [2, 3, 4, 5, 6, 7],
            'retry_policy' => 'reconcile_before_retry',
        ],
    ])
        ->and(config('monitoring.signing.keys'))->toBe([])
        ->and(config('monitoring.egress'))->toBe([
            'connect_timeout_seconds' => 5,
            'response_timeout_seconds' => 15,
            'max_response_bytes' => 1048576,
            'deny_cidrs' => ['0.0.0.0/8', '127.0.0.0/8', '100.100.100.200/32', '169.254.0.0/16', '224.0.0.0/4', '240.0.0.0/4', '::/128', '::1/128', 'fe80::/10', 'fd00:ec2::254/128', 'ff00::/8'],
        ])->and(config('monitoring.retention'))->toBe([
            'raw_days' => 14,
            'hourly_days' => 180,
            'daily_days' => 1825,
        ])->and(config('monitoring.runtime.worker_heartbeat_stale_seconds'))->toBe(180)
        ->and(config('monitoring.external_heartbeat'))->toMatchArray([
            'enabled' => false,
            'url' => null,
            'allowed_hosts' => [],
            'connect_timeout_seconds' => 3,
            'response_timeout_seconds' => 5,
            'listener_stale_seconds' => 30,
            'stale_seconds' => 180,
        ]);
});

it('binds probe egress to canonical approved Site scopes and pinned native DNS', function () {
    expect(app(ApprovedProbeScopeProvider::class))->toBeInstanceOf(DiscoveryApprovedProbeScopeProvider::class)
        ->and(app(DnsResolver::class))->toBeInstanceOf(NativeDnsResolver::class)
        ->and(app(ProbeScopeResolver::class))->toBeInstanceOf(CanonicalProbeScopeResolver::class)
        ->and(app(EgressPolicy::class))->toBeInstanceOf(EgressPolicy::class);

    expect(fn () => app(EgressPolicy::class)->authorise(9, 81, ProbeTarget::tcp('10.44.1.8', 443)))
        ->toThrow(EgressDenied::class);
});

it('binds fixed read-only remote inventory transports and direct adapters', function () {
    $profiles = config('monitoring-inventory.profiles');
    expect(array_keys($profiles))->toBe(['linux.basic', 'windows.basic'])
        ->and($profiles['linux.basic']['operations'])->toBe([
            ['uname', '-sr'],
            ['uptime', '-s'],
            ['df', '-P', '-B1'],
            ['systemctl', 'list-units', '--type=service', '--state=failed', '--no-legend'],
        ])->and(app(SshConnectionFactory::class))->toBeInstanceOf(NativeSshConnectionFactory::class)
        ->and(app(WinRmHttpClient::class))->toBeInstanceOf(NativeWinRmHttpClient::class)
        ->and(app(ProbeAdapterRegistry::class)->for(MonitorKind::SshInventory)->kind())->toBe(MonitorKind::SshInventory)
        ->and(app(ProbeAdapterRegistry::class)->for(MonitorKind::WinRmInventory)->kind())->toBe(MonitorKind::WinRmInventory)
        ->and(app(CommandDispatchPort::class))->toBeInstanceOf(GovernedCommandDispatchService::class);
});

it('fails closed when the signing key ring is absent or invalid', function (string $encodedKeyRing) {
    expect(monitoringConfigurationWithSigningKeys($encodedKeyRing)['signing']['keys'])->toBe([]);
})->with([
    'empty' => '',
    'malformed json' => '{',
    'non-object json' => '["not-a-key-ring"]',
    'non-string key' => '{"primary":42}',
    'invalid base64 key' => '{"primary":"not-base64"}',
    'wrong sodium key length' => '{"primary":"aw=="}',
]);

it('loads a JSON signing key ring without exposing its key material', function () {
    $encodedKey = base64_encode(str_repeat('k', SODIUM_CRYPTO_AUTH_KEYBYTES));
    $configuration = monitoringConfigurationWithSigningKeys(json_encode([
        'primary' => $encodedKey,
    ], JSON_THROW_ON_ERROR));
    $loadedKeys = $configuration['signing']['keys'];

    expect(array_keys($loadedKeys))->toBe(['primary'])
        ->and(hash_equals(
            hash('sha256', $encodedKey),
            hash('sha256', $loadedKeys['primary'] ?? ''),
        ))->toBeTrue();
});
