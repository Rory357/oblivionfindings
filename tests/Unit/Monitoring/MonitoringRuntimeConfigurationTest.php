<?php

use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\CommandDispatchPort;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Contracts\ProbeScopeResolver;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use App\Domain\Monitoring\Services\CanonicalProbeScopeResolver;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\Monitoring\Services\RejectingApprovedProbeScopeProvider;
use App\Domain\Monitoring\Services\RejectingCommandDispatchPort;
use App\Domain\Monitoring\Services\RejectingDnsResolver;
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

it('defines isolated runtime queues and rejects device commands', function () {
    expect(config('monitoring.queues'))->toBe([
        'events' => 'monitoring-events',
        'checks' => 'monitoring-checks',
        'discovery' => 'monitoring-discovery',
        'provider' => 'monitoring-provider',
        'topology' => 'monitoring-topology',
        'maintenance' => 'monitoring-maintenance',
        'orchestration' => 'monitoring',
    ])->and(app(CommandDispatchPort::class))->toBeInstanceOf(RejectingCommandDispatchPort::class);

    expect(fn () => app(CommandDispatchPort::class)->dispatch('door.unlock', 42, []))
        ->toThrow(LogicException::class, 'Device commands are outside the native monitoring runtime plan.');
});

it('defines runtime contract egress and retention defaults', function () {
    expect(config('monitoring.contracts'))->toBe(['current' => 1, 'accepted' => [1]])
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
        ]);
});

it('binds probe egress to canonical scope and rejecting network dependencies by default', function () {
    expect(app(ApprovedProbeScopeProvider::class))->toBeInstanceOf(RejectingApprovedProbeScopeProvider::class)
        ->and(app(DnsResolver::class))->toBeInstanceOf(RejectingDnsResolver::class)
        ->and(app(ProbeScopeResolver::class))->toBeInstanceOf(CanonicalProbeScopeResolver::class)
        ->and(app(EgressPolicy::class))->toBeInstanceOf(EgressPolicy::class);

    expect(fn () => app(EgressPolicy::class)->authorise(9, 81, ProbeTarget::tcp('10.44.1.8', 443)))
        ->toThrow(EgressDenied::class);
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
