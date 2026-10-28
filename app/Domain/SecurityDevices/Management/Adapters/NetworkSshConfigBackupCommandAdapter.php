<?php

namespace App\Domain\SecurityDevices\Management\Adapters;

use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Drivers\Contracts\NetworkConfigBackupDriver;
use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\NetworkConfig\Contracts\NetworkCliSshTransport;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Governed `network.config.backup` execution adapter over read-only vendor SSH CLI.
 *
 * Supports MikroTik RouterOS, Cisco IOS / IOS-XE, HPE Aruba AOS-CX / ArubaOS-Switch,
 * and Fortinet FortiGate FortiOS via `DeviceDriverRegistry::resolveNetworkConfigBackupDriver()`.
 */
final class NetworkSshConfigBackupCommandAdapter implements CommandExecutionAdapter
{
    public const string CAPABILITY = 'network.config.backup';

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly DeclaredDeviceCommandCapabilities $declaredCapabilities,
        private readonly DeviceDriverRegistry $drivers,
        private readonly CommandCredentialLeaseService $credentials,
        private readonly EgressPolicy $egress,
        private readonly NetworkCliSshTransport $ssh,
        private readonly ConfigurationSnapshotService $snapshots,
        private readonly SnapshotStore $snapshotStore,
    ) {}

    public function supports(Device $device, string $capability): bool
    {
        if ($capability !== self::CAPABILITY
            || $device->domain !== 'it_infrastructure'
            || ! in_array($device->category, ['network', 'networking'], true)
            || ! $this->declaredCapabilities->supports($device, $capability)) {
            return false;
        }

        if (! $this->drivers->resolveNetworkConfigBackupDriver($device) instanceof NetworkConfigBackupDriver) {
            return false;
        }

        try {
            $siteId = $this->sites->resolve((int) $device->id);
            $target = $this->sshTarget($device, $siteId);
            unset($target);

            return $this->credentials->available($device, $siteId, $capability);
        } catch (Throwable) {
            return false;
        }
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        $driver = $this->assertContext($context);
        $target = $this->sshTarget($context->device, $context->siteId);
        $expectedHostKey = $this->configuredHostKeyFingerprint($context->device);

        $lease = $this->credentials->acquire($context);
        $material = [];

        try {
            $material = $lease->material();
            $commands = $driver->exportCommands($context->device);
            $lastReason = 'empty_output';
            $capturedOutput = null;
            $hostKeyFingerprint = null;

            foreach ($commands as $cliCommand) {
                $result = $this->ssh->runReadOnlyExport(
                    target: $target,
                    credentialMaterial: $material,
                    command: $cliCommand,
                    expectedHostKeyFingerprint: $expectedHostKey,
                    timeoutSeconds: 20,
                );

                $hostKeyFingerprint = $result->hostKeyFingerprint ?? $hostKeyFingerprint;

                if (! $result->ok) {
                    $lastReason = $result->reasonCode;
                    if (in_array($result->reasonCode, ['auth_failed', 'host_key_mismatch', 'host_key_unavailable', 'invalid_credentials'], true)) {
                        break;
                    }

                    continue;
                }

                if ($this->containsCliError($result->output, $driver->cliErrorPatterns())) {
                    $lastReason = 'cli_rejected_command';

                    continue;
                }

                $normalised = $driver->normaliseConfigOutput($result->output);
                if ($normalised !== '') {
                    $capturedOutput = $normalised;
                    break;
                }
            }

            if ($capturedOutput === null) {
                return new CommandExecutionResult(
                    status: CommandAttemptStatus::Failed,
                    safeFailureReason: $this->humanFailureReason($lastReason),
                );
            }

            $source = $driver->vendorFamily().'_ssh_backup';
            $snapshot = $this->snapshots->captureFromCommand(
                device: $context->device,
                siteId: $context->siteId,
                source: $source,
                payload: [
                    'running_config' => $capturedOutput,
                    'manufacturer' => $context->device->manufacturer,
                    'model' => $context->device->model,
                    'firmware_version' => $context->device->firmware_version,
                ],
                capturedAt: CarbonImmutable::now('UTC'),
            );

            return new CommandExecutionResult(
                status: CommandAttemptStatus::Succeeded,
                safeSummary: [
                    'driver_key' => $driver->key(),
                    'snapshot_id' => $snapshot->id,
                    'configuration_hash' => $snapshot->configuration_hash,
                    'host_key_pinned' => $expectedHostKey !== null,
                    'provider_state' => 'captured',
                ],
                providerRequestReference: 'ssh-backup:'.$snapshot->snapshot_uuid,
            );
        } finally {
            $this->erase($material);
            $this->credentials->release($lease);
        }
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        $driver = $this->assertContext($context);
        $source = $driver->vendorFamily().'_ssh_backup';

        $snapshot = ConfigurationSnapshot::query()
            ->where('device_id', $context->device->id)
            ->where('site_id', $context->siteId)
            ->where('source_kind', 'command')
            ->where('source', $source)
            ->where('storage_state', 'available')
            ->whereNull('payload_deleted_at')
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();

        if (! $snapshot instanceof ConfigurationSnapshot) {
            throw new RuntimeException('No available SSH configuration snapshot was found for reconciliation.');
        }

        if (! $this->snapshotStore->exists((string) $snapshot->storage_path)) {
            throw new RuntimeException('Stored SSH configuration snapshot payload is missing.');
        }

        $rawStored = $this->snapshotStore->read((string) $snapshot->storage_path);
        $verified = strlen($rawStored) === (int) $snapshot->content_size
            && hash_equals((string) $snapshot->content_hash, hash('sha256', $rawStored));

        return new CommandObservedState(
            state: [
                'snapshot_available' => $verified,
            ],
            observedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            observationReference: 'ssh-backup:snapshot-'.$snapshot->id,
            safeEvidenceSummary: $verified
                ? sprintf('Encrypted, secret-redacted %s configuration backup #%d is verified in private storage.', $driver->metadata()->brand, $snapshot->id)
                : 'Stored SSH configuration snapshot failed integrity verification.',
        );
    }

    private function assertContext(CommandExecutionContext $context): NetworkConfigBackupDriver
    {
        if ($context->capability !== self::CAPABILITY
            || $context->device->domain !== 'it_infrastructure'
            || ! in_array($context->device->category, ['network', 'networking'], true)
            || $this->sites->resolve((int) $context->device->id) !== $context->siteId) {
            throw new RuntimeException('Network SSH configuration backup scope is invalid.');
        }

        $driver = $this->drivers->resolveNetworkConfigBackupDriver($context->device);
        if (! $driver instanceof NetworkConfigBackupDriver) {
            throw new RuntimeException('No supported SSH configuration backup driver matches this network Device.');
        }

        return $driver;
    }

    private function sshTarget(Device $device, int $siteId): AuthorizedProbeTarget
    {
        $host = trim((string) (data_get($device->config ?? [], 'ssh.host') ?? $device->ip_address ?? ''));
        if ($host === '') {
            throw new RuntimeException('Network Device has no IP address or SSH host configured.');
        }

        $port = (int) (data_get($device->config ?? [], 'ssh.port') ?? 22);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Configured SSH port is invalid.');
        }

        return $this->egress->authorise($siteId, (int) $device->id, ProbeTarget::tcp($host, $port));
    }

    private function configuredHostKeyFingerprint(Device $device): ?string
    {
        $pin = data_get($device->config ?? [], 'ssh.host_key_fingerprint')
            ?? data_get($device->meta ?? [], 'ssh_host_key_fingerprint');

        if (! is_string($pin) || trim($pin) === '') {
            return null;
        }

        return trim($pin);
    }

    /**
     * @param  list<string>  $patterns
     */
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

    private function humanFailureReason(string $reasonCode): string
    {
        return match ($reasonCode) {
            'auth_failed' => 'The network device rejected the SSH username, password, or key.',
            'host_key_mismatch' => 'SSH host key did not match the pinned device fingerprint; connection refused.',
            'host_key_unavailable' => 'The network device did not present a valid SSH host key.',
            'cli_rejected_command' => 'The network device CLI rejected the read-only export command (check user privilege level).',
            'output_exceeded_limit' => 'Running configuration output exceeded the 2 MB safety limit.',
            'command_timed_out' => 'SSH configuration export timed out before completion.',
            default => 'Could not capture a non-empty running configuration over SSH.',
        };
    }

    /**
     * @param  array<string, scalar|null>  $material
     */
    private function erase(array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value) && $value !== '') {
                if (function_exists('sodium_memzero')) {
                    sodium_memzero($value);
                } else {
                    $value = str_repeat("\0", strlen($value));
                }
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }
}
