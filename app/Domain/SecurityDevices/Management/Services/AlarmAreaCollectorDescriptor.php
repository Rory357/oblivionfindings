<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorCredentialLeaseSealer;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Drivers\Alarms\GallagherCommandCentreDriver;
use App\Domain\SecurityDevices\Drivers\Alarms\InnerRangeInceptionDriver;
use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use App\Domain\SecurityDevices\Management\Contracts\CollectorCommandDescriptor;
use App\Domain\SecurityDevices\Management\Data\CollectorCommandOutcome;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Integration\IntegrationSiteSecret;
use RuntimeException;
use Throwable;

/**
 * R6 (S&D Phase E): arm or disarm one area of an Inner Range Inception or Gallagher Command Centre
 * panel through the hub that covers its Site. The server never connects into the house itself.
 * Ajax and other SIA panels only report, so they're never here. The hub reads the area back
 * straight after, so the screen shows the panel's own answer.
 */
abstract class AlarmAreaCollectorDescriptor implements CollectorCommandDescriptor
{
    public const string PROTOCOL = 'command.alarm_rest';

    public const string ADAPTER = 'alarm_area_control_v1';

    public const array FAILURES = [
        'The alarm panel rejected the saved login.',
        'The alarm panel doesn’t know that area.',
        'The alarm panel refused the change in its current state.',
        'The alarm panel did not accept the change.',
        'The hub did not receive a confirmed answer from the alarm panel. Check the area’s state before trying again.',
        'The hub could not complete the checks before sending the change.',
        'The hub restarted after starting the change without a final answer. It was not sent again.',
    ];

    public const array RECONCILIATION_SUMMARIES = [
        'The hub read the area straight after and it shows the state asked for.',
        'The hub read the area straight after and it does not show the state asked for.',
        'The hub could not read the area’s state straight after. Check it before trying again.',
        'Fresh state is required before any new attempt.',
    ];

    public function __construct(
        private readonly EgressPolicy $egress,
        private readonly CommandCredentialLeaseService $credentials,
        private readonly CollectorCredentialLeaseSealer $sealer,
        private readonly CollectorCommandContract $contracts,
        private readonly DeviceDriverRegistry $drivers,
    ) {}

    /** arm | disarm */
    abstract protected function operation(): string;

    public function routesThroughCoveringHub(): bool
    {
        return true;
    }

    public function maxAttachmentBytes(): int
    {
        return 0;
    }

    public function supports(Device $device, MonitoringCollector $collector, int $siteId): bool
    {
        try {
            if (! $collector->isApprovedForWork() || ! in_array($siteId, $collector->authorisedSiteIds(), true)
                || ! self::hubRunsIt($collector)) {
                return false;
            }
            $this->endpoint($device, $siteId);

            return $this->credentials->available($device, $siteId, $this->capability());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A hub refuses its whole config when it sees a job type it doesn't know, so alarm jobs only go
     * to hubs whose heartbeat lists this job type. Older hubs keep working; alarms wait for the update.
     */
    public static function hubRunsIt(MonitoringCollector $collector): bool
    {
        return HubJobTypes::runs($collector, self::ADAPTER);
    }

    public function interpret(
        DeviceCommandRequest $request,
        MonitoringCollector $collector,
        string $executionStatus,
        array $safeResult,
        ?string $providerReference,
        ?string $failure,
        ?array $reconciliation,
        ?string $attachment,
    ): CollectorCommandOutcome {
        if ($executionStatus === 'succeeded') {
            if (array_keys($safeResult) !== ['area', 'provider_state']
                || $safeResult['provider_state'] !== 'accepted'
                || ! is_string($safeResult['area']) || preg_match('/\A[A-Za-z0-9._:-]{1,80}\z/', $safeResult['area']) !== 1
                || $providerReference !== 'alarm:'.$request->command_uuid
                || $failure !== null) {
                throw new RuntimePayloadInvalid('Collector alarm success evidence is invalid.');
            }
        } elseif ($safeResult !== [] || $providerReference !== null || ! is_string($failure) || ! in_array($failure, self::FAILURES, true)) {
            throw new RuntimePayloadInvalid('Collector alarm failure evidence is invalid.');
        }
        if ($executionStatus !== 'failed' && $reconciliation === null) {
            throw new RuntimePayloadInvalid('Collector command reconciliation is invalid.');
        }
        if ($reconciliation !== null) {
            $wanted = $this->operation() === 'arm';
            $observed = $reconciliation['observed_state'];
            if (($observed !== null && (array_keys($observed) !== ['armed'] || ! is_bool($observed['armed'])))
                || ! in_array($reconciliation['safe_evidence_summary'], self::RECONCILIATION_SUMMARIES, true)
                || ($reconciliation['outcome'] === 'matched' && $observed !== ['armed' => $wanted])
                || ($reconciliation['outcome'] === 'mismatch' && $observed !== ['armed' => ! $wanted])) {
                throw new RuntimePayloadInvalid('Collector command reconciliation evidence is not a declared typed outcome.');
            }
        }

        return new CollectorCommandOutcome($executionStatus, $safeResult, $providerReference, $failure, $reconciliation);
    }

    public function sameEvidence(DeviceCommandAttempt $attempt, string $executionStatus, array $safeResult, ?string $failure): bool
    {
        return $attempt->status->value === $executionStatus
            && ($attempt->safe_result_summary ?? []) === $safeResult
            && $attempt->safe_failure_reason === $failure;
    }

    /** @return array<string, mixed> */
    public function build(DeviceCommandRequest $request, DeviceCommandAttempt $attempt, MonitoringCollector $collector): array
    {
        $request->loadMissing('device');
        $siteId = (int) $request->site_id;
        if ($request->capability !== $this->capability() || ! in_array($siteId, $collector->authorisedSiteIds(), true)
            || ! self::hubRunsIt($collector)) {
            throw new RuntimeException('The collector command route is unavailable.');
        }
        $area = $request->encrypted_parameters['area'] ?? null;
        if (! is_string($area) || preg_match('/\A[A-Za-z0-9._:-]{1,80}\z/', trim($area)) !== 1) {
            throw new RuntimeException('The approved alarm area is invalid.');
        }
        ['driver' => $driver, 'target' => $target] = $this->endpoint($request->device, $siteId);
        $lease = $this->credentials->acquireFor($request->device, $siteId, $request->capability);

        return [
            'command_uuid' => (string) $request->command_uuid,
            'attempt_uuid' => (string) $attempt->attempt_uuid,
            'attempt_number' => (int) $attempt->attempt_number,
            'site_id' => $siteId,
            'device_id' => (string) $request->device_id,
            'capability' => $this->capability(),
            'provider' => $driver,
            'adapter' => self::ADAPTER,
            'protocol' => self::PROTOCOL,
            'target' => $target->addresses[0],
            'expires_at' => $request->expires_at->utc()->format(DATE_ATOM),
            'idempotency_hash' => hash('sha256', (string) $request->idempotency_key),
            'contract_hash' => $this->contracts->hash($request, $attempt, $collector),
            'parameters' => ['area' => trim($area), 'operation' => $this->operation()],
            'expected_state' => ['armed' => $this->operation() === 'arm'],
            'endpoint' => [
                'driver' => $driver,
                'scheme' => 'https',
                'host' => $target->host,
                'port' => $target->port,
                'address' => $target->addresses[0],
                'connect_timeout_seconds' => min(10, $target->connectTimeoutSeconds),
                'response_timeout_seconds' => min(30, $target->responseTimeoutSeconds),
                'max_response_bytes' => min(262_144, $target->maxResponseBytes),
            ],
            'credential_lease' => $this->sealer->seal($collector, (string) $request->device_id, self::PROTOCOL, $target->addresses[0], $lease, $siteId),
        ];
    }

    /**
     * The panel's HTTPS address (its own setting, the Site's saved connection, or its IP), checked
     * against the Site's network scope, and which maker it is.
     *
     * @return array{driver: string, target: AuthorizedProbeTarget}
     */
    private function endpoint(Device $device, int $siteId): array
    {
        $alarmDriver = $this->drivers->resolveAlarmDriver($device);
        $driver = match (true) {
            $alarmDriver instanceof InnerRangeInceptionDriver => 'inception',
            $alarmDriver instanceof GallagherCommandCentreDriver => 'gallagher',
            default => throw new RuntimeException('Only Inner Range Inception and Gallagher panels take commands.'),
        };
        $url = data_get($device->config ?? [], 'alarm.base_url') ?? data_get($device->config ?? [], 'management.alarm_api.base_url');
        if (! is_string($url) || trim($url) === '') {
            $url = IntegrationSiteSecret::query()
                ->where('site_id', $siteId)
                ->whereIn('provider', $driver === 'inception' ? ['innerrange', 'inner_range', 'inception'] : ['gallagher'])
                ->where('is_enabled', true)
                ->value('base_url');
        }
        if ((! is_string($url) || trim($url) === '') && is_string($device->ip_address) && trim($device->ip_address) !== '') {
            $port = (int) (data_get($device->config ?? [], 'alarm.port') ?? ($driver === 'gallagher' ? 8904 : 443));
            $url = 'https://'.trim($device->ip_address).($port === 443 ? '' : ':'.$port);
        }
        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException('The alarm panel’s address is not set.');
        }
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        $allowedPorts = $driver === 'gallagher' ? [443, 8443, 8904] : [443, 8443];
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || ! in_array((int) ($parts['port'] ?? 443), $allowedPorts, true)) {
            throw new RuntimeException('The alarm panel’s HTTPS address is not securely set.');
        }
        $target = $this->egress->authorise($siteId, (int) $device->id, ProbeTarget::http($url));
        if ($target->scheme !== 'https' || $target->addresses === []) {
            throw new RuntimeException('The alarm panel’s address is outside the Site’s networks.');
        }

        return ['driver' => $driver, 'target' => $target];
    }
}
