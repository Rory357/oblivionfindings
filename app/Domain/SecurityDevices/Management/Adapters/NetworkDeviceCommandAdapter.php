<?php

namespace App\Domain\SecurityDevices\Management\Adapters;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Contracts\CommandHttpTransport;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandHttpResponse;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Management\Services\NetworkDeviceCommandPolicyService;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Integration\IntegrationSiteSecret;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class NetworkDeviceCommandAdapter implements CommandExecutionAdapter
{
    public const array CAPABILITIES = [
        'network.port.enable',
        'network.port.disable',
        'network.port.bounce',
        'network.config.backup',
    ];

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly DeclaredDeviceCommandCapabilities $declaredCapabilities,
        private readonly NetworkDeviceCommandPolicyService $networkPolicy,
        private readonly CommandCredentialLeaseService $credentials,
        private readonly EgressPolicy $egress,
        private readonly CommandHttpTransport $http,
        private readonly ConfigurationSnapshotService $snapshots,
    ) {}

    public function supports(Device $device, string $capability): bool
    {
        if (! in_array($capability, self::CAPABILITIES, true)
            || $device->domain !== 'it_infrastructure'
            || ! in_array($device->category, ['network', 'networking'], true)
            || ! $this->declaredCapabilities->supports($device, $capability)) {
            return false;
        }

        try {
            $siteId = $this->sites->resolve((int) $device->id);
            $configuration = $this->configuration($device, $siteId);
            $this->target(
                $siteId,
                (int) $device->id,
                $configuration,
                '/api/v1/network/devices/'.rawurlencode($configuration['device_ref']),
            );

            return $this->credentials->available($device, $siteId, $capability);
        } catch (Throwable) {
            return false;
        }
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        $configuration = $this->assertContext($context);
        $lease = $this->credentials->acquire($context);
        $material = [];

        try {
            $material = $lease->material();
            $token = $this->token($material);

            if ($context->capability === 'network.config.backup') {
                return $this->executeConfigurationBackup($context, $configuration, $token);
            }

            return $this->executePortAction($context, $configuration, $token);
        } finally {
            $this->erase($material);
            $this->credentials->release($lease);
        }
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        $configuration = $this->assertContext($context);

        if ($context->capability === 'network.config.backup') {
            $snapshot = ConfigurationSnapshot::query()
                ->where('device_id', $context->device->id)
                ->where('site_id', $context->siteId)
                ->where('source_kind', 'command')
                ->where('storage_state', 'available')
                ->whereNull('payload_deleted_at')
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->first();

            if (! $snapshot instanceof ConfigurationSnapshot) {
                throw new RuntimeException('No verified configuration backup snapshot is available for reconciliation.');
            }

            return new CommandObservedState(
                state: [
                    'snapshot_available' => true,
                ],
                observedAt: CarbonImmutable::instance($snapshot->captured_at)->startOfSecond(),
                observationReference: 'configuration-snapshot:'.$snapshot->id,
                safeEvidenceSummary: 'Verified encrypted, secret-redacted configuration snapshot in private storage.',
            );
        }

        $interface = trim((string) ($context->parameters['interface'] ?? ''));
        $this->networkPolicy->assertInterfaceAllowed($context->device, $interface);

        $lease = $this->credentials->acquire($context);
        $material = [];
        try {
            $material = $lease->material();
            $token = $this->token($material);
            $target = $this->target(
                $context->siteId,
                (int) $context->device->id,
                $configuration,
                '/api/v1/network/devices/'.rawurlencode($configuration['device_ref']).'/interfaces/'.rawurlencode($interface),
            );
            $response = $this->http->request($target, 'GET', $this->headers($token));
            if ($response->location !== null || $response->status !== 200) {
                throw new RuntimeException('Network interface state could not be confirmed.');
            }

            $payload = $response->json();
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
            $adminRaw = strtolower(trim((string) ($data['admin_status'] ?? $data['enabled'] ?? '')));
            if ($adminRaw === '' && is_bool($data['enabled'] ?? null)) {
                $enabled = $data['enabled'];
            } elseif (in_array($adminRaw, ['up', 'enabled', 'true', '1'], true)) {
                $enabled = true;
            } elseif (in_array($adminRaw, ['down', 'disabled', 'false', '0'], true)) {
                $enabled = false;
            } else {
                throw new RuntimeException('Network interface administrative state is unavailable.');
            }

            $state = [
                'interface' => $interface,
                'admin_state' => $enabled ? 'enabled' : 'disabled',
            ];
            if ($context->capability === 'network.port.bounce') {
                $bounced = ($data['bounced'] ?? null) === true
                    || (($data['last_command_uuid'] ?? null) === $context->commandUuid)
                    || $enabled === true;
                $state['bounced'] = $bounced && $enabled;
            }

            return new CommandObservedState(
                state: $state,
                observedAt: CarbonImmutable::now('UTC')->startOfSecond(),
                observationReference: 'network-mgmt:interface-state:'.hash('sha256', $context->commandUuid.':'.$context->attemptUuid),
                safeEvidenceSummary: $enabled
                    ? sprintf('Network controller freshly confirmed interface %s is enabled.', $interface)
                    : sprintf('Network controller freshly confirmed interface %s is disabled.', $interface),
            );
        } finally {
            $this->erase($material);
            $this->credentials->release($lease);
        }
    }

    /**
     * @param  array{base_url: string, device_ref: string, provider: string}  $configuration
     */
    private function executePortAction(
        CommandExecutionContext $context,
        array $configuration,
        string $token,
    ): CommandExecutionResult {
        $interface = trim((string) ($context->parameters['interface'] ?? ''));
        $this->networkPolicy->assertInterfaceAllowed($context->device, $interface);

        $operation = match ($context->capability) {
            'network.port.enable' => 'enable',
            'network.port.disable' => 'disable',
            'network.port.bounce' => 'bounce',
            default => throw new RuntimeException('Unsupported network port operation.'),
        };

        $target = $this->target(
            $context->siteId,
            (int) $context->device->id,
            $configuration,
            '/api/v1/network/devices/'.rawurlencode($configuration['device_ref'])
                .'/interfaces/'.rawurlencode($interface).'/'.$operation,
        );

        $response = $this->http->request($target, 'POST', $this->headers($token), [
            'command_uuid' => $context->commandUuid,
            'attempt_uuid' => $context->attemptUuid,
            'attempt_number' => $context->attemptNumber,
            'idempotency_key' => $context->idempotencyKey,
            'interface' => $interface,
            'operation' => $operation,
        ]);

        if ($response->location !== null || $response->status >= 300) {
            return $this->failedResponse($response, 'port action');
        }

        $payload = $response->json();
        $status = strtolower(trim((string) ($payload['status'] ?? $payload['code'] ?? 'ok')));
        if (! in_array($status, ['ok', 'accepted', 'success'], true)) {
            return new CommandExecutionResult(
                status: CommandAttemptStatus::Failed,
                safeFailureReason: 'The network controller rejected the interface action.',
            );
        }

        return new CommandExecutionResult(
            status: CommandAttemptStatus::Succeeded,
            safeSummary: [
                'interface' => $interface,
                'operation' => $operation,
                'provider_state' => 'accepted',
            ],
            providerRequestReference: 'network-mgmt:'.$context->commandUuid,
        );
    }

    /**
     * @param  array{base_url: string, device_ref: string, provider: string}  $configuration
     */
    private function executeConfigurationBackup(
        CommandExecutionContext $context,
        array $configuration,
        string $token,
    ): CommandExecutionResult {
        $target = $this->target(
            $context->siteId,
            (int) $context->device->id,
            $configuration,
            '/api/v1/network/devices/'.rawurlencode($configuration['device_ref']).'/configuration',
        );

        $response = $this->http->request($target, 'GET', $this->headers($token));
        if ($response->location !== null || $response->status !== 200) {
            return $this->failedResponse($response, 'configuration backup');
        }

        $payload = $response->json();
        $configData = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if (! is_array($configData) || $configData === []) {
            return new CommandExecutionResult(
                status: CommandAttemptStatus::Failed,
                safeFailureReason: 'The network controller returned an empty or unsupported configuration payload.',
            );
        }

        $snapshot = $this->snapshots->captureFromCommand(
            device: $context->device,
            siteId: $context->siteId,
            source: $configuration['provider'],
            payload: $configData,
            capturedAt: CarbonImmutable::now('UTC')->startOfSecond(),
        );

        return new CommandExecutionResult(
            status: CommandAttemptStatus::Succeeded,
            safeSummary: [
                'snapshot_id' => $snapshot->id,
                'configuration_hash' => $snapshot->configuration_hash,
                'redacted' => true,
            ],
            providerRequestReference: 'network-backup:'.$context->commandUuid,
            evidenceReference: 'configuration-snapshot:'.$snapshot->id,
        );
    }

    /**
     * @return array{base_url: string, device_ref: string, provider: string}
     */
    private function assertContext(CommandExecutionContext $context): array
    {
        if (! in_array($context->capability, self::CAPABILITIES, true)
            || $context->device->domain !== 'it_infrastructure'
            || ! in_array($context->device->category, ['network', 'networking'], true)
            || $this->sites->resolve((int) $context->device->id) !== $context->siteId) {
            throw new RuntimeException('Network device command scope is invalid.');
        }

        return $this->configuration($context->device, $context->siteId);
    }

    /**
     * @return array{base_url: string, device_ref: string, provider: string}
     */
    private function configuration(Device $device, int $siteId): array
    {
        $provider = strtolower(trim((string) $device->provider));
        if ($provider === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $provider) !== 1) {
            throw new RuntimeException('A valid network management provider is required.');
        }

        $configuredUrl = data_get($device->config ?? [], 'management.network_api.base_url');
        if (! is_string($configuredUrl) || trim($configuredUrl) === '') {
            $secret = IntegrationSiteSecret::query()
                ->where('site_id', $siteId)
                ->where('provider', $provider)
                ->whereIn('capability', ['network_management_api', 'network_api'])
                ->where('is_enabled', true)
                ->whereNull('last_error')
                ->where('last_tested_at', '>=', now()->subDay())
                ->first(['base_url']);
            $configuredUrl = $secret?->base_url;
        }

        if (! is_string($configuredUrl) || trim($configuredUrl) === '') {
            throw new RuntimeException('An approved HTTPS network management endpoint is unavailable.');
        }

        $baseUrl = $this->baseUrl($configuredUrl);
        $external = is_array($device->external_ref) ? $device->external_ref : [];
        $deviceRef = data_get($device->config ?? [], 'management.network_api.device_ref')
            ?? $external['provider_device_id']
            ?? $external['provider_entity_id']
            ?? null;

        if (! is_string($deviceRef) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', trim($deviceRef)) !== 1) {
            throw new RuntimeException('The canonical Device is not mapped to an approved network controller identifier.');
        }

        return [
            'base_url' => $baseUrl,
            'device_ref' => trim($deviceRef),
            'provider' => $provider,
        ];
    }

    private function baseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array((int) ($parts['port'] ?? 443), [443, 8443, 10443], true)
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('The network management endpoint is not securely configured.');
        }

        return $url;
    }

    /**
     * @param  array{base_url: string, device_ref: string, provider: string}  $configuration
     */
    private function target(
        int $siteId,
        int $deviceId,
        array $configuration,
        string $path,
    ): AuthorizedProbeTarget {
        $target = ProbeTarget::http($configuration['base_url'].$path);
        if ($target->scheme !== 'https') {
            throw new RuntimeException('Network management commands require HTTPS.');
        }

        return $this->egress->authorise($siteId, $deviceId, $target);
    }

    /**
     * @param  array<string, scalar|null>  $material
     */
    private function token(array $material): string
    {
        $token = $material['api_token'] ?? $material['token'] ?? null;
        if (! is_string($token)
            || strlen($token) < 8
            || strlen($token) > 4090
            || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new RuntimeException('Network management credential material is invalid.');
        }

        return $token;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ];
    }

    private function failedResponse(CommandHttpResponse $response, string $operationLabel): CommandExecutionResult
    {
        $reason = match ($response->status) {
            401, 403 => 'The network controller rejected the credential or required API permission.',
            404 => 'The mapped network device or interface was not found on the controller.',
            409, 422 => sprintf('The network controller rejected the %s in its current state.', $operationLabel),
            429 => sprintf('The network controller rate-limited the %s.', $operationLabel),
            default => sprintf('The network controller did not accept the %s.', $operationLabel),
        };

        return new CommandExecutionResult(
            status: CommandAttemptStatus::Failed,
            safeFailureReason: $reason,
        );
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
