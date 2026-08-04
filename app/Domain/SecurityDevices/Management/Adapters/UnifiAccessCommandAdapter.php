<?php

namespace App\Domain\SecurityDevices\Management\Adapters;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Contracts\CommandHttpTransport;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandHttpResponse;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Integration\IntegrationSiteSecret;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class UnifiAccessCommandAdapter implements CommandExecutionAdapter
{
    private const PROVIDER = 'unifi';

    private const CAPABILITY = 'access.door.unlock_timed';

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly CommandCredentialLeaseService $credentials,
        private readonly EgressPolicy $egress,
        private readonly CommandHttpTransport $http,
    ) {}

    public function supports(Device $device, string $capability): bool
    {
        if ($capability !== self::CAPABILITY
            || strtolower((string) $device->provider) !== self::PROVIDER
            || $device->category !== 'access_control') {
            return false;
        }

        try {
            $siteId = $this->sites->resolve((int) $device->id);
            $configuration = $this->configuration($device, $siteId);
            $this->target(
                $siteId,
                (int) $device->id,
                $configuration,
                '/api/v1/developer/doors/'.$configuration['door_id'],
            );

            return $this->credentials->available($device, $siteId, $capability);
        } catch (Throwable) {
            return false;
        }
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        $configuration = $this->assertContext($context);
        if ((int) ($context->parameters['duration_seconds'] ?? 0) !== $configuration['unlock_duration_seconds']) {
            return new CommandExecutionResult(
                status: CommandAttemptStatus::Failed,
                safeFailureReason: 'The requested unlock duration no longer matches the approved UniFi Access door configuration.',
            );
        }

        $lease = $this->credentials->acquire($context);
        $material = [];
        try {
            $material = $lease->material();
            $token = $this->token($material);
            $door = $this->fetchDoor($context, $configuration, $token);
            if (($door['is_bind_hub'] ?? false) !== true) {
                return new CommandExecutionResult(
                    status: CommandAttemptStatus::Failed,
                    safeFailureReason: 'UniFi Access reports that the mapped door is not bound to an access hub.',
                );
            }
            if (($door['door_lock_relay_status'] ?? null) !== 'lock') {
                return new CommandExecutionResult(
                    status: CommandAttemptStatus::Failed,
                    safeFailureReason: 'The door was not in the approved locked state immediately before execution.',
                );
            }

            $target = $this->target(
                $context->siteId,
                (int) $context->device->id,
                $configuration,
                '/api/v1/developer/doors/'.$configuration['door_id'].'/unlock',
            );
            $response = $this->http->request($target, 'PUT', $this->headers($token), [
                'actor_id' => $context->commandUuid,
                'actor_name' => 'Oblivion Findings',
                'extra' => [
                    'command_uuid' => $context->commandUuid,
                    'attempt_uuid' => $context->attemptUuid,
                    'attempt_number' => $context->attemptNumber,
                    'duration_seconds' => $configuration['unlock_duration_seconds'],
                ],
            ]);

            if ($response->location !== null || $response->status >= 300) {
                return $this->failedResponse($response);
            }
            $payload = $response->json();
            if (($payload['code'] ?? null) !== 'SUCCESS') {
                return new CommandExecutionResult(
                    status: CommandAttemptStatus::Failed,
                    safeFailureReason: 'UniFi Access rejected the remote door action.',
                );
            }

            return new CommandExecutionResult(
                status: CommandAttemptStatus::Succeeded,
                safeSummary: [
                    'provider_state' => 'accepted',
                    'previous_lock_state' => 'locked',
                    'unlock_duration_seconds' => $configuration['unlock_duration_seconds'],
                ],
                providerRequestReference: 'unifi-access:'.$context->commandUuid,
            );
        } finally {
            $this->erase($material);
            $this->credentials->release($lease);
        }
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        $configuration = $this->assertContext($context);
        $lease = $this->credentials->acquire($context);
        $material = [];
        try {
            $material = $lease->material();
            $door = $this->fetchDoor($context, $configuration, $this->token($material));
            $relay = $door['door_lock_relay_status'] ?? null;
            if (! in_array($relay, ['lock', 'unlock'], true)) {
                throw new RuntimeException('UniFi Access door state is unavailable.');
            }
            $locked = $relay === 'lock';

            return new CommandObservedState(
                state: ['locked' => $locked],
                observedAt: CarbonImmutable::now('UTC')->startOfSecond(),
                observationReference: 'unifi-access:door-state:'.hash('sha256', $context->commandUuid.':'.$context->attemptUuid),
                safeEvidenceSummary: $locked
                    ? 'UniFi Access freshly confirmed that the door relay returned to locked.'
                    : 'UniFi Access freshly reported that the door relay remains unlocked.',
            );
        } finally {
            $this->erase($material);
            $this->credentials->release($lease);
        }
    }

    /** @return array{base_url: string, door_id: string, unlock_duration_seconds: int} */
    private function assertContext(CommandExecutionContext $context): array
    {
        if ($context->capability !== self::CAPABILITY
            || strtolower((string) $context->device->provider) !== self::PROVIDER
            || $context->device->category !== 'access_control'
            || $this->sites->resolve((int) $context->device->id) !== $context->siteId) {
            throw new RuntimeException('UniFi Access command scope is invalid.');
        }

        return $this->configuration($context->device, $context->siteId);
    }

    /** @return array{base_url: string, door_id: string, unlock_duration_seconds: int} */
    private function configuration(Device $device, int $siteId): array
    {
        $endpoint = IntegrationSiteSecret::query()
            ->where('site_id', $siteId)
            ->where('provider', self::PROVIDER)
            ->where('capability', 'access_api')
            ->where('is_enabled', true)
            ->whereNull('last_error')
            ->where('last_tested_at', '>=', now()->subDay())
            ->first(['base_url']);
        if (! $endpoint || ! is_string($endpoint->base_url)) {
            throw new RuntimeException('A recently tested UniFi Access endpoint is unavailable.');
        }
        $baseUrl = $this->baseUrl($endpoint->base_url);
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

        return [
            'base_url' => $baseUrl,
            'door_id' => strtolower($doorId),
            'unlock_duration_seconds' => $duration,
        ];
    }

    private function baseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array((int) ($parts['port'] ?? 443), [443, 12445], true)
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('The UniFi Access endpoint is not securely configured.');
        }

        return $url;
    }

    /** @param array{base_url: string, door_id: string, unlock_duration_seconds: int} $configuration */
    private function target(
        int $siteId,
        int $deviceId,
        array $configuration,
        string $path,
    ): AuthorizedProbeTarget {
        $target = ProbeTarget::http($configuration['base_url'].$path);
        if ($target->scheme !== 'https') {
            throw new RuntimeException('UniFi Access commands require HTTPS.');
        }

        return $this->egress->authorise($siteId, $deviceId, $target);
    }

    /** @param array{base_url: string, door_id: string, unlock_duration_seconds: int} $configuration @return array<string, mixed> */
    private function fetchDoor(CommandExecutionContext $context, array $configuration, string $token): array
    {
        $target = $this->target(
            $context->siteId,
            (int) $context->device->id,
            $configuration,
            '/api/v1/developer/doors/'.$configuration['door_id'],
        );
        $response = $this->http->request($target, 'GET', $this->headers($token));
        if ($response->location !== null || $response->status !== 200) {
            throw new RuntimeException('UniFi Access door state could not be confirmed.');
        }
        $payload = $response->json();
        $door = $payload['data'] ?? null;
        if (($payload['code'] ?? null) !== 'SUCCESS' || ! is_array($door)
            || ! hash_equals($configuration['door_id'], strtolower((string) ($door['id'] ?? '')))) {
            throw new RuntimeException('UniFi Access returned a mismatched door state.');
        }

        return $door;
    }

    /** @param array<string, scalar|null> $material */
    private function token(array $material): string
    {
        $token = $material['api_token'] ?? $material['token'] ?? null;
        if (! is_string($token) || strlen($token) < 8 || strlen($token) > 4090
            || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new RuntimeException('UniFi Access credential material is invalid.');
        }

        return $token;
    }

    /** @return array<string, string> */
    private function headers(string $token): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ];
    }

    private function failedResponse(CommandHttpResponse $response): CommandExecutionResult
    {
        $reason = match ($response->status) {
            401, 403 => 'UniFi Access rejected the credential or required API permission.',
            404 => 'The mapped UniFi Access door is unavailable.',
            409, 422 => 'UniFi Access rejected the door action in its current state.',
            429 => 'UniFi Access rate-limited the door action.',
            default => 'UniFi Access did not accept the remote door action.',
        };

        return new CommandExecutionResult(
            status: CommandAttemptStatus::Failed,
            safeFailureReason: $reason,
        );
    }

    /** @param array<string, scalar|null> $material */
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
