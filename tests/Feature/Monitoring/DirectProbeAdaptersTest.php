<?php

use App\Domain\Monitoring\Adapters\DnsProbeAdapter;
use App\Domain\Monitoring\Adapters\HttpProbeAdapter;
use App\Domain\Monitoring\Adapters\IcmpProbeAdapter;
use App\Domain\Monitoring\Adapters\SnmpV3ProbeAdapter;
use App\Domain\Monitoring\Adapters\TcpProbeAdapter;
use App\Domain\Monitoring\Adapters\TlsProbeAdapter;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Contracts\DnsTransport;
use App\Domain\Monitoring\Contracts\HttpTransport;
use App\Domain\Monitoring\Contracts\IcmpTransport;
use App\Domain\Monitoring\Contracts\ProbeScopeResolver;
use App\Domain\Monitoring\Contracts\TcpTransport;
use App\Domain\Monitoring\Contracts\TlsTransport;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\DnsTransportResult;
use App\Domain\Monitoring\Data\HttpTransportResponse;
use App\Domain\Monitoring\Data\IcmpTransportResult;
use App\Domain\Monitoring\Data\ProbeScope;
use App\Domain\Monitoring\Data\TcpTransportResult;
use App\Domain\Monitoring\Data\TlsTransportResult;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\Monitoring\Services\ProbeAdapterRegistry;
use Carbon\CarbonImmutable;

final class TaskFiveIcmpTransport implements IcmpTransport
{
    public function __construct(public IcmpTransportResult $result) {}

    public function probe(AuthorizedProbeTarget $target): IcmpTransportResult
    {
        return $this->result;
    }
}

final class TaskFiveTcpTransport implements TcpTransport
{
    public function __construct(public TcpTransportResult $result) {}

    public function probe(AuthorizedProbeTarget $target): TcpTransportResult
    {
        return $this->result;
    }
}

final class TaskFiveDnsTransport implements DnsTransport
{
    public function __construct(public DnsTransportResult $result) {}

    public function query(AuthorizedProbeTarget $target, string $name, string $type): DnsTransportResult
    {
        return $this->result;
    }
}

final class TaskFiveHttpTransport implements HttpTransport
{
    /** @var list<HttpTransportResponse> */
    private array $responses;

    /** @var list<AuthorizedProbeTarget> */
    public array $targets = [];

    /** @param list<HttpTransportResponse> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request(AuthorizedProbeTarget $target): HttpTransportResponse
    {
        $this->targets[] = $target;

        return array_shift($this->responses) ?? throw new RuntimeException('No fake HTTP response.');
    }
}

final class TaskFiveTlsTransport implements TlsTransport
{
    public function __construct(public TlsTransportResult $result) {}

    public function probe(AuthorizedProbeTarget $target): TlsTransportResult
    {
        return $this->result;
    }
}

final class TaskFiveDnsResolver implements DnsResolver
{
    /** @param array<string, list<string>> $answers */
    public function __construct(private readonly array $answers) {}

    public function resolve(string $host): array
    {
        return $this->answers[$host] ?? [];
    }
}

final class TaskFiveScopeResolver implements ProbeScopeResolver
{
    public function resolve(int $siteId, int $deviceId): ProbeScope
    {
        return new ProbeScope(
            siteId: $siteId,
            deviceId: $deviceId,
            approvedCidrs: ['10.44.0.0/16'],
            allowedPorts: [53, 80, 443, 8443],
            maxResponseBytes: 64,
        );
    }
}

function taskFiveEgressPolicy(array $answers = []): EgressPolicy
{
    return new EgressPolicy(
        new CidrMatcher,
        new TaskFiveDnsResolver($answers),
        new TaskFiveScopeResolver,
        [
            'connect_timeout_seconds' => 5,
            'response_timeout_seconds' => 15,
            'max_response_bytes' => 1024,
            'deny_cidrs' => [
                '0.0.0.0/8',
                '127.0.0.0/8',
                '100.100.100.200/32',
                '169.254.0.0/16',
                '224.0.0.0/4',
                '240.0.0.0/4',
                '::/128',
                '::1/128',
                'fe80::/10',
                'fd00:ec2::254/128',
                'ff00::/8',
            ],
        ],
    );
}

/** @param array<string, mixed> $config */
function taskFiveContext(
    MonitorKind $kind,
    AuthorizedProbeTarget $target,
    array $config = [],
): AuthorisedProbeContext {
    return new AuthorisedProbeContext(
        monitorId: 501,
        siteId: 9,
        deviceId: 81,
        kind: $kind,
        target: $target,
        config: $config,
    );
}

function taskFiveAuthorisedTarget(
    string $scheme,
    string $host,
    int $port,
    ?string $path = null,
): AuthorizedProbeTarget {
    return AuthorizedProbeTarget::fromEgressPolicy(
        siteId: 9,
        deviceId: 81,
        scheme: $scheme,
        host: $host,
        port: $port,
        path: $path,
        addresses: ['10.44.1.8'],
        connectTimeoutSeconds: 2,
        responseTimeoutSeconds: 5,
        maxResponseBytes: 64,
    );
}

it('normalises healthy ICMP and TCP results without transport detail leakage', function () {
    $icmp = new IcmpProbeAdapter(new TaskFiveIcmpTransport(new IcmpTransportResult(
        reachable: true,
        latencyMs: 12,
        packetLossPercent: 0,
        reasonCode: 'reply',
    )));
    $tcp = new TcpProbeAdapter(new TaskFiveTcpTransport(new TcpTransportResult(
        connected: true,
        latencyMs: 8,
        reasonCode: 'connected',
    )));

    $icmpObservation = $icmp->probe(taskFiveContext(
        MonitorKind::Icmp,
        taskFiveAuthorisedTarget('icmp', 'switch.site.example', 0),
    ));
    $tcpObservation = $tcp->probe(taskFiveContext(
        MonitorKind::Tcp,
        taskFiveAuthorisedTarget('tcp', 'switch.site.example', 443),
    ));

    expect($icmpObservation->state)->toBe(MonitorState::Healthy)
        ->and($icmpObservation->value)->toBe(12)
        ->and($icmpObservation->unit)->toBe('ms')
        ->and($icmpObservation->reasonCode)->toBe('reply')
        ->and($tcpObservation->state)->toBe(MonitorState::Healthy)
        ->and($tcpObservation->value)->toBe(8)
        ->and($tcpObservation->unit)->toBe('ms')
        ->and(json_encode([$icmpObservation->evidence, $tcpObservation->evidence]))
        ->not->toContain('body', 'authorization', 'cookie');
});

it('reports ICMP loss and TCP refusal as bounded failures', function () {
    $icmp = new IcmpProbeAdapter(new TaskFiveIcmpTransport(new IcmpTransportResult(
        reachable: false,
        latencyMs: null,
        packetLossPercent: 100,
        reasonCode: 'packet_loss',
    )));
    $tcp = new TcpProbeAdapter(new TaskFiveTcpTransport(new TcpTransportResult(
        connected: false,
        latencyMs: null,
        reasonCode: 'connection_refused',
    )));

    expect($icmp->probe(taskFiveContext(
        MonitorKind::Icmp,
        taskFiveAuthorisedTarget('icmp', '10.44.1.8', 0),
    )))->state->toBe(MonitorState::Failed)
        ->reasonCode->toBe('packet_loss')
        ->evidence->toBe(['packet_loss_percent' => 100.0])
        ->and($tcp->probe(taskFiveContext(
            MonitorKind::Tcp,
            taskFiveAuthorisedTarget('tcp', '10.44.1.8', 443),
        )))->state->toBe(MonitorState::Failed)
        ->reasonCode->toBe('connection_refused');
});

it('matches bounded DNS answers and reports a deterministic mismatch', function () {
    $healthy = new DnsProbeAdapter(new TaskFiveDnsTransport(new DnsTransportResult(
        answered: true,
        answers: ['10.44.5.20'],
        latencyMs: 4,
        reasonCode: 'answer',
    )));
    $mismatch = new DnsProbeAdapter(new TaskFiveDnsTransport(new DnsTransportResult(
        answered: true,
        answers: ['10.44.5.21'],
        latencyMs: 4,
        reasonCode: 'answer',
    )));
    $context = taskFiveContext(
        MonitorKind::Dns,
        taskFiveAuthorisedTarget('dns', '10.44.0.53', 53),
        ['name' => 'service.example', 'type' => 'A', 'expected_answers' => ['10.44.5.20']],
    );

    expect($healthy->probe($context))->state->toBe(MonitorState::Healthy)
        ->value->toBe(1)
        ->unit->toBe('answers')
        ->reasonCode->toBe('answer_match')
        ->and($mismatch->probe($context))->state->toBe(MonitorState::Failed)
        ->reasonCode->toBe('answer_mismatch')
        ->evidence->toMatchArray(['answer_count' => 1, 'matched' => false]);
});

it('fails closed on malformed DNS expectation values', function () {
    $adapter = new DnsProbeAdapter(new TaskFiveDnsTransport(new DnsTransportResult(
        answered: true,
        answers: ['10.44.5.20'],
        latencyMs: 4,
        reasonCode: 'answer',
    )));
    $context = taskFiveContext(
        MonitorKind::Dns,
        taskFiveAuthorisedTarget('dns', '10.44.0.53', 53),
        ['name' => 'service.example', 'type' => 'A', 'expected_answers' => ['10.44.5.20', 42]],
    );

    expect($adapter->probe($context))->state->toBe(MonitorState::Unknown)
        ->reasonCode->toBe('invalid_configuration');
});

it('reauthorises HTTP redirects and never retains a response body', function () {
    $policy = taskFiveEgressPolicy([
        'ready.site.example' => ['10.44.1.9'],
    ]);
    $transport = new TaskFiveHttpTransport([
        new HttpTransportResponse(302, '', '/ready', 5, false),
        new HttpTransportResponse(200, 'healthy but private response', null, 7, false),
    ]);
    $adapter = new HttpProbeAdapter($transport, $policy);
    $observation = $adapter->probe(taskFiveContext(
        MonitorKind::Http,
        taskFiveAuthorisedTarget('https', 'ready.site.example', 443, '/health'),
        ['expected_status' => [200], 'content_contains' => 'healthy'],
    ));

    expect($observation->state)->toBe(MonitorState::Healthy)
        ->and($observation->reasonCode)->toBe('status_and_content_match')
        ->and($observation->evidence)->toMatchArray([
            'status' => 200,
            'content_matched' => true,
            'redirects' => 1,
            'response_bytes' => 28,
        ])
        ->and(json_encode($observation))->not->toContain('private response')
        ->and($transport->targets)->toHaveCount(2)
        ->and($transport->targets[1]->path)->toBe('/ready');
});

it('fails HTTP checks on denied redirects content mismatch and oversized bodies', function (
    HttpTransportResponse $response,
    array $config,
    string $reason,
) {
    $policy = taskFiveEgressPolicy([
        'metadata.site.example' => ['169.254.169.254'],
    ]);
    $adapter = new HttpProbeAdapter(new TaskFiveHttpTransport([$response]), $policy);
    $observation = $adapter->probe(taskFiveContext(
        MonitorKind::Http,
        taskFiveAuthorisedTarget('https', 'service.site.example', 443, '/health'),
        $config,
    ));

    expect($observation->state)->toBe(MonitorState::Failed)
        ->and($observation->reasonCode)->toBe($reason)
        ->and(json_encode($observation->evidence))->not->toContain('secret-body');
})->with([
    'denied redirect' => [
        new HttpTransportResponse(302, '', 'http://metadata.site.example/latest', 2, false),
        ['expected_status' => [200]],
        'redirect_denied',
    ],
    'content mismatch' => [
        new HttpTransportResponse(200, 'secret-body', null, 2, false),
        ['expected_status' => [200], 'content_contains' => 'ready'],
        'content_mismatch',
    ],
    'oversized body' => [
        new HttpTransportResponse(200, 'secret-body', null, 2, true),
        ['expected_status' => [200]],
        'response_too_large',
    ],
]);

it('reports TLS health warning expiry and hostname mismatch without certificate material', function (
    TlsTransportResult $result,
    MonitorState $state,
    string $reason,
) {
    $adapter = new TlsProbeAdapter(new TaskFiveTlsTransport($result));
    $observation = $adapter->probe(taskFiveContext(
        MonitorKind::Tls,
        taskFiveAuthorisedTarget('tls', 'service.site.example', 443),
        ['warn_days' => 30],
    ));

    expect($observation->state)->toBe($state)
        ->and($observation->reasonCode)->toBe($reason)
        ->and($observation->unit)->toBe('days')
        ->and(json_encode($observation->evidence))
        ->not->toContain('certificate', 'BEGIN', 'service-private-key');
})->with([
    'healthy' => [
        new TlsTransportResult(true, 10, CarbonImmutable::now()->addDays(90), 'issuer-hash', true, 'TLSv1.3', 'verified'),
        MonitorState::Healthy,
        'certificate_valid',
    ],
    'expiring' => [
        new TlsTransportResult(true, 10, CarbonImmutable::now()->addDays(10), 'issuer-hash', true, 'TLSv1.3', 'verified'),
        MonitorState::Degraded,
        'certificate_expiring',
    ],
    'expired' => [
        new TlsTransportResult(true, 10, CarbonImmutable::now()->subDay(), 'issuer-hash', true, 'TLSv1.2', 'verified'),
        MonitorState::Failed,
        'certificate_expired',
    ],
    'hostname mismatch' => [
        new TlsTransportResult(false, 10, CarbonImmutable::now()->addDays(90), 'issuer-hash', false, 'TLSv1.3', 'hostname_mismatch'),
        MonitorState::Failed,
        'hostname_mismatch',
    ],
]);

it('selects exactly one adapter per direct monitor kind', function () {
    $policy = taskFiveEgressPolicy();
    $registry = new ProbeAdapterRegistry(
        new IcmpProbeAdapter(new TaskFiveIcmpTransport(new IcmpTransportResult(true, 1, 0, 'reply'))),
        new TcpProbeAdapter(new TaskFiveTcpTransport(new TcpTransportResult(true, 1, 'connected'))),
        new DnsProbeAdapter(new TaskFiveDnsTransport(new DnsTransportResult(true, [], 1, 'answer'))),
        new HttpProbeAdapter(new TaskFiveHttpTransport([]), $policy),
        new TlsProbeAdapter(new TaskFiveTlsTransport(new TlsTransportResult(
            true,
            1,
            CarbonImmutable::now()->addYear(),
            'issuer',
            true,
            'TLSv1.3',
            'verified',
        ))),
        app(SnmpV3ProbeAdapter::class),
    );

    foreach ([MonitorKind::Icmp, MonitorKind::Tcp, MonitorKind::Dns, MonitorKind::Http, MonitorKind::Tls, MonitorKind::Snmp] as $kind) {
        expect($registry->for($kind)->kind())->toBe($kind);
    }

    expect(fn () => $registry->for(MonitorKind::Provider))
        ->toThrow(LogicException::class, 'No direct probe adapter');
});
