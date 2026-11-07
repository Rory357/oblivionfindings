<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\CollectorCredentialLeaseSealer;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Drivers\Contracts\NetworkConfigBackupDriver;
use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use App\Domain\SecurityDevices\Management\Adapters\NetworkSshConfigBackupCommandAdapter;
use App\Domain\SecurityDevices\Management\Contracts\CollectorCommandDescriptor;
use App\Domain\SecurityDevices\Management\Data\CollectorCommandOutcome;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\NetworkConfig\Services\SshHostKeyTrust;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * S&D Phase B: a router or switch settings backup, run by the hub that covers the Site.
 *
 * The server sends no command text. It names an export profile, and the hub runs its own fixed,
 * read-only commands for that profile (`collector/src/Runtime/SshExportProfiles.php`). The hub
 * checks the trusted SSH key before it sends any login; with no trusted key, or a changed one, it
 * logs nothing in and reports the key it was offered.
 */
final class NetworkSshConfigBackupCollectorDescriptor implements CollectorCommandDescriptor
{
    public const string CAPABILITY = 'network.config.backup';

    public const string PROTOCOL = 'command.ssh_config_backup';

    public const string ADAPTER = 'ssh_config_backup_v1';

    public const int MAX_OUTPUT_BYTES = 1_048_576;

    /** Reason codes the hub may send for a failed backup. */
    public const array FAILURE_CODES = [
        'host_key_unavailable', 'host_key_unconfirmed', 'host_key_mismatch', 'invalid_credentials',
        'auth_failed', 'cli_rejected_command', 'empty_output', 'hub_output_exceeded_limit',
        'command_timed_out', 'ssh_transport_error', 'precheck_failed', 'interrupted',
    ];

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly DeclaredDeviceCommandCapabilities $declaredCapabilities,
        private readonly DeviceDriverRegistry $drivers,
        private readonly CommandCredentialLeaseService $credentials,
        private readonly CollectorCredentialLeaseSealer $sealer,
        private readonly EgressPolicy $egress,
        private readonly SshHostKeyTrust $hostKeys,
        private readonly ConfigurationSnapshotService $snapshots,
        private readonly SnapshotStore $snapshotStore,
        private readonly CollectorCommandContract $contracts,
    ) {}

    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function routesThroughCoveringHub(): bool
    {
        return true;
    }

    public function supports(Device $device, MonitoringCollector $collector, int $siteId): bool
    {
        try {
            $this->driver($device, $siteId);
            $this->assertWorkingHub($collector, $siteId);
            $this->target($device, $siteId);

            return $this->credentials->available($device, $siteId, self::CAPABILITY);
        } catch (Throwable) {
            return false;
        }
    }

    public function build(DeviceCommandRequest $request, DeviceCommandAttempt $attempt, MonitoringCollector $collector): array
    {
        $request->loadMissing('device');
        $device = $request->device;
        $siteId = (int) $request->site_id;
        if ($request->capability !== self::CAPABILITY || ! $device instanceof Device) {
            throw new RuntimeException('The hub backup job no longer matches its request.');
        }
        $driver = $this->driver($device, $siteId);
        $this->assertWorkingHub($collector, $siteId);
        $target = $this->target($device, $siteId);
        $address = $target->addresses[0];
        $trustedKey = $this->hostKeys->trusted($device);
        // Never build a job the hub's scope guard would refuse: one bad job makes it refuse the whole config.
        if (($trustedKey !== null && preg_match(SshHostKeyTrust::FINGERPRINT_PATTERN, $trustedKey) !== 1)
            || $target->port < 1 || $target->port > 65535) {
            throw new RuntimeException('The backup job would not pass the hub’s checks.');
        }
        $lease = $this->credentials->acquireFor($device, $siteId, self::CAPABILITY);

        return [
            'command_uuid' => (string) $request->command_uuid,
            'attempt_uuid' => (string) $attempt->attempt_uuid,
            'attempt_number' => (int) $attempt->attempt_number,
            'site_id' => $siteId,
            'device_id' => (string) $request->device_id,
            'capability' => self::CAPABILITY,
            'provider' => 'ssh',
            'adapter' => self::ADAPTER,
            'protocol' => self::PROTOCOL,
            'target' => $address,
            'expires_at' => $request->expires_at->utc()->format(DATE_ATOM),
            'idempotency_hash' => hash('sha256', (string) $request->idempotency_key),
            'contract_hash' => $this->contracts->hash($request, $attempt, $collector),
            'parameters' => ['export_profile' => $driver->vendorFamily()],
            'expected_state' => ['snapshot_available' => true],
            'endpoint' => [
                'address' => $address,
                'port' => $target->port,
                'expected_host_key' => $trustedKey,
                'connect_timeout_seconds' => max(2, min(10, $target->connectTimeoutSeconds)),
                'command_timeout_seconds' => 20,
                'max_output_bytes' => self::MAX_OUTPUT_BYTES,
            ],
            'credential_lease' => $this->sealer->seal($collector, (string) $request->device_id, self::PROTOCOL, $address, $lease, $siteId),
        ];
    }

    public function maxAttachmentBytes(): int
    {
        // 1 MB of settings, gzip at worst incompressible, then base64.
        return 1_572_864;
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
        $device = $request->device;
        $siteId = (int) $request->site_id;
        if (! $device instanceof Device || $reconciliation !== null || $executionStatus === 'uncertain') {
            // A read-only backup can't leave the device in doubt, and the server checks what it stored itself.
            throw new RuntimePayloadInvalid('Collector backup result is not a declared typed outcome.');
        }
        $driver = $this->driver($device, $siteId);

        if ($executionStatus === 'failed') {
            return $this->failedFromHub($device, $safeResult, $providerReference, $attachment);
        }

        $trusted = $this->hostKeys->trusted($device);
        if (array_keys($safeResult) !== ['provider_state', 'export_profile', 'output_bytes', 'output_sha256', 'host_key_fingerprint']
            || $safeResult['provider_state'] !== 'captured'
            || $safeResult['export_profile'] !== $driver->vendorFamily()
            || ! is_string($attachment)
            || $safeResult['output_bytes'] !== strlen($attachment)
            || strlen($attachment) > self::MAX_OUTPUT_BYTES
            || ! is_string($safeResult['output_sha256']) || ! hash_equals(hash('sha256', $attachment), $safeResult['output_sha256'])
            // A login was only ever allowed to a device whose key someone trusted.
            || ! is_string($trusted) || ! is_string($safeResult['host_key_fingerprint'])
            || ! hash_equals($trusted, $safeResult['host_key_fingerprint'])
            || $providerReference !== 'ssh-backup:'.$request->command_uuid
            || $failure !== null) {
            throw new RuntimePayloadInvalid('Collector backup success evidence is invalid.');
        }

        if ($this->containsCliError($attachment, $driver->cliErrorPatterns())) {
            return $this->failed('cli_rejected_command');
        }
        $normalised = $driver->normaliseConfigOutput($attachment);
        if ($normalised === '') {
            return $this->failed('empty_output');
        }

        $snapshot = $this->snapshots->captureFromCommand(
            device: $device,
            siteId: $siteId,
            source: $driver->vendorFamily().'_ssh_backup',
            payload: [
                'running_config' => $normalised,
                'manufacturer' => $device->manufacturer,
                'model' => $device->model,
                'firmware_version' => $device->firmware_version,
            ],
            capturedAt: CarbonImmutable::now('UTC'),
        );
        $stored = $this->snapshotStore->exists((string) $snapshot->storage_path)
            ? $this->snapshotStore->read((string) $snapshot->storage_path) : '';
        $verified = strlen($stored) === (int) $snapshot->content_size
            && hash_equals((string) $snapshot->content_hash, hash('sha256', $stored));

        return new CollectorCommandOutcome(
            executionStatus: 'succeeded',
            safeResult: [
                'driver_key' => $driver->key(),
                'snapshot_id' => (int) $snapshot->id,
                'configuration_hash' => (string) $snapshot->configuration_hash,
                'host_key_pinned' => true,
                'provider_state' => 'captured',
                'output_sha256' => $safeResult['output_sha256'],
                'routed_through' => 'hub',
            ],
            providerReference: 'ssh-backup:'.$snapshot->snapshot_uuid,
            failure: null,
            reconciliation: [
                'outcome' => $verified ? 'matched' : 'uncertain',
                'observed_state' => $verified ? ['snapshot_available' => true] : null,
                'observation_reference' => 'ssh-backup:snapshot-'.$snapshot->id,
                'safe_evidence_summary' => $verified
                    ? sprintf('Encrypted, secret-redacted %s configuration backup #%d is verified in private storage.', $driver->metadata()->brand, $snapshot->id)
                    : 'Stored SSH configuration snapshot failed integrity verification.',
                'observed_at' => CarbonImmutable::now('UTC')->startOfSecond(),
            ],
        );
    }

    public function sameEvidence(DeviceCommandAttempt $attempt, string $executionStatus, array $safeResult, ?string $failure): bool
    {
        $stored = $attempt->safe_result_summary ?? [];
        if ($executionStatus === 'succeeded') {
            // The server may have turned a captured export into a failure (CLI error, nothing left once cleaned).
            return isset($safeResult['output_sha256'])
                && in_array($attempt->status->value, ['succeeded', 'failed'], true)
                && (($stored['output_sha256'] ?? null) === $safeResult['output_sha256']
                    || in_array($stored['reason_code'] ?? null, ['cli_rejected_command', 'empty_output'], true));
        }

        return $attempt->status->value === 'failed'
            && ($stored['reason_code'] ?? null) === ($safeResult['reason_code'] ?? false);
    }

    /** @param array<string, scalar|null> $safeResult */
    private function failedFromHub(Device $device, array $safeResult, ?string $providerReference, ?string $attachment): CollectorCommandOutcome
    {
        $code = $safeResult['reason_code'] ?? null;
        $keys = array_keys($safeResult);
        $offered = $safeResult['offered_host_key'] ?? null;
        $hostKeyCase = in_array($code, ['host_key_unconfirmed', 'host_key_mismatch'], true);
        if (! is_string($code) || ! in_array($code, self::FAILURE_CODES, true)
            || $providerReference !== null || $attachment !== null
            || ($hostKeyCase && ($keys !== ['reason_code', 'offered_host_key']
                || ! is_string($offered) || preg_match(SshHostKeyTrust::FINGERPRINT_PATTERN, $offered) !== 1))
            || (! $hostKeyCase && $keys !== ['reason_code'])) {
            throw new RuntimePayloadInvalid('Collector backup failure evidence is invalid.');
        }
        if ($hostKeyCase) {
            // Phase A, item A6, through the hub: keep the key the device offered so a person can check and trust it.
            $this->hostKeys->recordOffered($device, $offered);
        }

        return $this->failed($code, $hostKeyCase ? $offered : null);
    }

    private function failed(string $code, ?string $offeredHostKey = null): CollectorCommandOutcome
    {
        $result = ['reason_code' => $code, 'routed_through' => 'hub'];
        if ($offeredHostKey !== null) {
            $result['offered_host_key'] = $offeredHostKey;
        }

        return new CollectorCommandOutcome(
            executionStatus: 'failed',
            safeResult: $result,
            providerReference: null,
            failure: NetworkSshConfigBackupCommandAdapter::humanFailureReason($code, $offeredHostKey),
            reconciliation: null,
        );
    }

    private function driver(Device $device, int $siteId): NetworkConfigBackupDriver
    {
        if ($device->domain !== 'it_infrastructure'
            || ! in_array($device->category, ['network', 'networking'], true)
            || ! $this->declaredCapabilities->supports($device, self::CAPABILITY)
            || $this->sites->resolve((int) $device->id) !== $siteId) {
            throw new RuntimeException('Network SSH configuration backup scope is invalid.');
        }
        $driver = $this->drivers->resolveNetworkConfigBackupDriver($device);
        if (! $driver instanceof NetworkConfigBackupDriver) {
            throw new RuntimeException('No supported SSH configuration backup driver matches this network Device.');
        }

        return $driver;
    }

    private function assertWorkingHub(MonitoringCollector $collector, int $siteId): void
    {
        if (! in_array($siteId, $collector->authorisedSiteIds(), true)
            || ! $collector->isApprovedForWork()
            || $collector->revoked_at !== null
            || ! is_string($collector->public_key)
            || ! in_array((string) $collector->status, ['online', 'degraded'], true)) {
            throw new RuntimeException('The Site hub is not approved, connected and covering this Site.');
        }
    }

    /** The same approved address the server's own backup would use; only who connects changes. */
    private function target(Device $device, int $siteId): AuthorizedProbeTarget
    {
        $host = trim((string) (data_get($device->config ?? [], 'ssh.host') ?? $device->ip_address ?? ''));
        $port = (int) (data_get($device->config ?? [], 'ssh.port') ?? 22);
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException('Network Device has no usable SSH address.');
        }
        $target = $this->egress->authorise($siteId, (int) $device->id, ProbeTarget::tcp($host, $port));
        if (! isset($target->addresses[0]) || filter_var($target->addresses[0], FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('The SSH address did not resolve to one approved numeric address.');
        }

        return $target;
    }

    /** @param list<string> $patterns */
    private function containsCliError(string $output, array $patterns): bool
    {
        $head = strtolower(substr($output, 0, 2048));
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($head, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }
}
