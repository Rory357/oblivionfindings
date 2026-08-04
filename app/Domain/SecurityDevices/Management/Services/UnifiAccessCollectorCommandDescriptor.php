<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorCredentialLeaseSealer;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Integration\IntegrationSiteSecret;
use RuntimeException;
use Throwable;

final class UnifiAccessCollectorCommandDescriptor
{
    private const string CAPABILITY = 'access.door.unlock_timed';

    private const string PROTOCOL = 'command.unifi_access';

    public function __construct(
        private readonly EgressPolicy $egress,
        private readonly CommandCredentialLeaseService $credentials,
        private readonly CollectorCredentialLeaseSealer $sealer,
        private readonly CollectorCommandContract $contracts,
    ) {}

    public function supports(Device $device, MonitoringCollector $collector, string $capability): bool
    {
        try {
            $this->baseDescriptor($device, $collector, $capability);

            return $this->credentials->available($device, (int) $collector->site_id, $capability);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function build(
        DeviceCommandRequest $request,
        DeviceCommandAttempt $attempt,
        MonitoringCollector $collector,
    ): array {
        $request->loadMissing('device');
        $base = $this->baseDescriptor($request->device, $collector, $request->capability);
        $duration = $request->encrypted_parameters['duration_seconds'] ?? null;
        if (! is_int($duration) || $duration !== $base['unlock_duration_seconds']) {
            throw new RuntimeException('The approved collector command parameters no longer match the Device configuration.');
        }

        $lease = $this->credentials->acquireFor(
            $request->device,
            (int) $request->site_id,
            $request->capability,
        );

        return [
            'command_uuid' => (string) $request->command_uuid,
            'attempt_uuid' => (string) $attempt->attempt_uuid,
            'attempt_number' => (int) $attempt->attempt_number,
            'site_id' => (int) $request->site_id,
            'device_id' => (string) $request->device_id,
            'capability' => self::CAPABILITY,
            'provider' => 'unifi',
            'adapter' => 'unifi_access_timed_unlock_v1',
            'protocol' => self::PROTOCOL,
            'target' => $base['target']->addresses[0],
            'expires_at' => $request->expires_at->utc()->format(DATE_ATOM),
            'idempotency_hash' => hash('sha256', (string) $request->idempotency_key),
            'contract_hash' => $this->contracts->hash($request, $attempt, $collector),
            'parameters' => ['duration_seconds' => $duration],
            'expected_state' => ['locked' => true],
            'endpoint' => [
                'scheme' => 'https',
                'host' => $base['target']->host,
                'port' => $base['target']->port,
                'address' => $base['target']->addresses[0],
                'door_id' => $base['door_id'],
                'connect_timeout_seconds' => min(10, $base['target']->connectTimeoutSeconds),
                'response_timeout_seconds' => min(30, $base['target']->responseTimeoutSeconds),
                'max_response_bytes' => min(65_536, $base['target']->maxResponseBytes),
            ],
            'credential_lease' => $this->sealer->seal(
                $collector,
                (string) $request->device_id,
                self::PROTOCOL,
                $base['target']->addresses[0],
                $lease,
            ),
        ];
    }

    /** @return array{door_id: string, unlock_duration_seconds: int, target: AuthorizedProbeTarget} */
    private function baseDescriptor(
        Device $device,
        MonitoringCollector $collector,
        string $capability,
    ): array {
        if ($capability !== self::CAPABILITY
            || strtolower((string) $device->provider) !== 'unifi'
            || $device->category !== 'access_control'
            || $collector->revoked_at !== null
            || ! in_array((string) $collector->status, ['online', 'degraded'], true)) {
            throw new RuntimeException('The collector command route is unavailable.');
        }
        $siteId = (int) $collector->site_id;
        if ($siteId < 1) {
            throw new RuntimeException('The collector Site scope is unavailable.');
        }

        $endpoint = IntegrationSiteSecret::query()
            ->where('site_id', $siteId)
            ->where('provider', 'unifi')
            ->where('capability', 'access_api')
            ->where('is_enabled', true)
            ->whereNull('last_error')
            ->where('last_tested_at', '>=', now()->subDay())
            ->first(['base_url']);
        if (! $endpoint || ! is_string($endpoint->base_url)) {
            throw new RuntimeException('A recently tested UniFi Access endpoint is unavailable.');
        }
        $baseUrl = rtrim(trim($endpoint->base_url), '/');
        $parts = parse_url($baseUrl);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array((int) ($parts['port'] ?? 443), [443, 12445], true)
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('The UniFi Access endpoint is not securely configured.');
        }

        $external = is_array($device->external_ref) ? $device->external_ref : [];
        $doorId = $external['provider_door_id'] ?? null;
        if ($doorId === null && ($external['provider_resource_kind'] ?? null) === 'door') {
            $doorId = $external['provider_entity_id'] ?? null;
        }
        if (! is_string($doorId)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $doorId) !== 1) {
            throw new RuntimeException('The canonical Device is not mapped to one UniFi Access door.');
        }
        $duration = data_get($device->config ?? [], 'management.unifi_access.unlock_duration_seconds');
        if (! is_int($duration) || $duration < 5 || $duration > 60) {
            throw new RuntimeException('The UniFi Access unlock duration is not safely configured.');
        }

        $target = ProbeTarget::http($baseUrl.'/api/v1/developer/doors/'.strtolower($doorId));
        $authorised = null;
        $scopes = DiscoveryScope::query()
            ->where('site_id', $siteId)
            ->where('collector_id', $collector->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();
        foreach ($scopes as $scope) {
            $protocols = array_values($scope->protocols ?? []);
            $ports = data_get($scope->port_bounds ?? [], 'provider', []);
            if (! in_array('provider', $protocols, true) || ! is_array($ports)
                || array_values($scope->exclusions ?? []) !== []) {
                continue;
            }
            try {
                $authorised = $this->egress->authoriseDiscovery(
                    $siteId,
                    array_values($scope->cidrs ?? []),
                    array_values($ports),
                    $target,
                );
                break;
            } catch (Throwable) {
                continue;
            }
        }
        if (! $authorised instanceof AuthorizedProbeTarget) {
            throw new RuntimeException('The UniFi Access endpoint is outside the approved remote Site scope.');
        }

        return [
            'door_id' => strtolower($doorId),
            'unlock_duration_seconds' => $duration,
            'target' => $authorised,
        ];
    }
}
