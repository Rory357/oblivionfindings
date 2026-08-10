<?php

use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Contracts\HttpTransport;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\HttpTransportResponse;
use App\Domain\Monitoring\Models\MonitoringExternalHeartbeatState;
use App\Domain\Monitoring\Models\MonitoringRuntimeHeartbeat;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\Monitoring\Services\ExternalMonitoringHeartbeatService;
use App\Domain\Monitoring\Services\ListenerHeartbeatReporter;
use App\Domain\Monitoring\Services\MonitoringRuntimeHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

final class ExternalHeartbeatDnsResolver implements DnsResolver
{
    /** @param list<string> $addresses */
    public function __construct(public array $addresses = ['93.184.216.34']) {}

    public function resolve(string $host): array
    {
        return $this->addresses;
    }
}

final class ExternalHeartbeatHttpTransport implements HttpTransport
{
    public int $calls = 0;

    public ?AuthorizedProbeTarget $target = null;

    public ?Throwable $exception = null;

    public function __construct(public HttpTransportResponse $response) {}

    public function request(AuthorizedProbeTarget $target): HttpTransportResponse
    {
        $this->calls++;
        $this->target = $target;
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->response;
    }
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-03T01:00:00Z');
    config()->set('cache.default', 'array');
    config()->set('monitoring.inbound.listener_state_store', 'array');
    config()->set('monitoring.inbound.allow_local_state_store_for_tests', true);
    config()->set('monitoring.external_heartbeat', [
        'enabled' => true,
        'url' => 'https://watchdog.example.test/ping/runtime-secret-token',
        'allowed_hosts' => ['watchdog.example.test'],
        'connect_timeout_seconds' => 3,
        'response_timeout_seconds' => 5,
        'listener_stale_seconds' => 30,
        'stale_seconds' => 180,
        'deny_cidrs' => [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
            '169.254.0.0/16', '172.16.0.0/12', '192.168.0.0/16', '224.0.0.0/4',
            '240.0.0.0/4', '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8',
        ],
    ]);

    app()->instance(DnsResolver::class, new ExternalHeartbeatDnsResolver);
    $this->transport = new ExternalHeartbeatHttpTransport(new HttpTransportResponse(
        status: 204,
        body: 'watchdog-response-secret',
        location: null,
        latencyMs: 12,
        truncated: false,
    ));
    app()->instance(HttpTransport::class, $this->transport);
    app()->forgetInstance(EgressPolicy::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('sends one value-free heartbeat only after all workers and listeners are current', function (): void {
    seedExternalHeartbeatRuntime();

    $state = app(ExternalMonitoringHeartbeatService::class)->send();

    expect($state->state)->toBe(MonitoringExternalHeartbeatState::STATE_SENT)
        ->and($state->reason_code)->toBeNull()
        ->and($state->response_status)->toBe(204)
        ->and($this->transport->calls)->toBe(1)
        ->and($this->transport->target?->url())->toBe('https://watchdog.example.test/ping/runtime-secret-token')
        ->and($this->transport->target?->addresses)->toBe(['93.184.216.34'])
        ->and(MonitoringExternalHeartbeatState::query()->count())->toBe(1);

    $stored = json_encode(MonitoringExternalHeartbeatState::query()->sole()->getAttributes(), JSON_THROW_ON_ERROR);
    expect($stored)
        ->not->toContain('watchdog.example.test', 'runtime-secret-token', 'watchdog-response-secret');
});

it('withholds the heartbeat for stale workers or listeners without touching the endpoint', function (): void {
    seedExternalHeartbeatRuntime();
    MonitoringRuntimeHeartbeat::query()->where('component', 'checks')->update([
        'last_consumed_at' => now()->subMinutes(4),
        'last_consumed_dispatch_at' => now()->subMinutes(4),
    ]);

    $workerState = app(ExternalMonitoringHeartbeatService::class)->send();
    expect($workerState->state)->toBe(MonitoringExternalHeartbeatState::STATE_SUPPRESSED)
        ->and($workerState->reason_code)->toBe('worker_unavailable')
        ->and($this->transport->calls)->toBe(0);

    MonitoringRuntimeHeartbeat::query()->update([
        'last_consumed_at' => now(),
        'last_consumed_dispatch_at' => now(),
    ]);
    Carbon::setTestNow(now()->addSeconds(31));
    $listenerState = app(ExternalMonitoringHeartbeatService::class)->send();
    expect($listenerState->state)->toBe(MonitoringExternalHeartbeatState::STATE_SUPPRESSED)
        ->and($listenerState->reason_code)->toBe('listener_unavailable')
        ->and($this->transport->calls)->toBe(0)
        ->and(MonitoringExternalHeartbeatState::query()->count())->toBe(1);
});

it('rejects non-public targets and records only bounded delivery failures', function (): void {
    seedExternalHeartbeatRuntime();
    app()->instance(DnsResolver::class, new ExternalHeartbeatDnsResolver(['127.0.0.1']));
    app()->forgetInstance(EgressPolicy::class);

    $configuration = app(ExternalMonitoringHeartbeatService::class)->send();
    expect($configuration->state)->toBe(MonitoringExternalHeartbeatState::STATE_SUPPRESSED)
        ->and($configuration->reason_code)->toBe('configuration_invalid')
        ->and($this->transport->calls)->toBe(0);

    app()->instance(DnsResolver::class, new ExternalHeartbeatDnsResolver);
    app()->forgetInstance(EgressPolicy::class);
    $this->transport->response = new HttpTransportResponse(
        status: 503,
        body: 'upstream-body-secret',
        location: null,
        latencyMs: 40,
        truncated: false,
    );
    $failed = app(ExternalMonitoringHeartbeatService::class)->send();
    expect($failed->state)->toBe(MonitoringExternalHeartbeatState::STATE_FAILED)
        ->and($failed->reason_code)->toBe('non_success_response')
        ->and($failed->response_status)->toBe(503)
        ->and($this->transport->calls)->toBe(1);

    expect(json_encode($failed->getAttributes(), JSON_THROW_ON_ERROR))
        ->not->toContain('upstream-body-secret', 'watchdog.example.test', 'runtime-secret-token');
});

function seedExternalHeartbeatRuntime(): void
{
    foreach (app(MonitoringRuntimeHeartbeatService::class)->components() as $component => $queue) {
        $token = (string) Str::uuid();
        MonitoringRuntimeHeartbeat::query()->create([
            'component' => $component,
            'queue' => $queue,
            'last_dispatched_token' => $token,
            'last_dispatched_at' => now(),
            'last_consumed_token' => $token,
            'last_consumed_dispatch_at' => now(),
            'last_consumed_at' => now(),
        ]);
    }

    $reporter = app(ListenerHeartbeatReporter::class);
    foreach (['snmp_traps', 'syslog', 'flow'] as $listener) {
        $reporter->started($listener);
        $reporter->alive($listener);
    }
}
