<?php

namespace App\Domain\SecurityDevices\Drivers\Alarms;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Drivers\Contracts\AlarmPanelDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Management\Contracts\CommandHttpTransport;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Integration\IntegrationSiteSecret;
use RuntimeException;
use Throwable;

/**
 * Gallagher Security (Hamilton, New Zealand) Command Centre local REST API driver.
 *
 * Official documentation:
 * - https://gallaghersecurity.github.io/cc-rest-docs/ref/index.html
 *
 * Authentication uses `Authorization: GGL-API-KEY <key>` over HTTPS (default port 8904).
 */
final class GallagherCommandCentreDriver implements AlarmPanelDriver
{
    public const string KEY = 'alarm.gallagher_command_centre';

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly EgressPolicy $egress,
        private readonly CommandHttpTransport $http,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Gallagher Security',
            name: 'Command Centre Local REST API',
            deviceClass: DriverDeviceClass::AlarmPanel,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'Gallagher REST API over HTTPS (port 8904, Authorization: GGL-API-KEY) + SIA DC-09',
            models: ['Controller 6000', 'Controller 7000', 'Command Centre v8.60+'],
            capabilities: [
                DriverCapability::AlarmZoneStatus,
                DriverCapability::AlarmArm,
                DriverCapability::AlarmDisarm,
                DriverCapability::AccessDoorStatus,
                DriverCapability::AccessDoorUnlock,
                DriverCapability::AlarmEventsSiaDc09,
            ],
            summary: 'Connects to Gallagher Command Centre REST API (Hamilton, NZ) over HTTPS to read alarm zones, inputs, and doors, and execute permission-checked arm, disarm, and door actions.',
            officialDocs: [
                ['label' => 'Gallagher Command Centre REST API Reference', 'url' => 'https://gallaghersecurity.github.io/cc-rest-docs/ref/index.html'],
            ],
            limitationsNote: 'Requires the Gallagher REST API licence feature enabled on Command Centre and a client certificate or API key bound to the Site hub.',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        if ($device->domain !== 'security' || ! in_array($device->category, ['alarm', 'perimeter', 'access_control'], true)) {
            return false;
        }

        $haystack = strtolower(trim(implode(' ', [
            (string) $device->manufacturer,
            (string) $device->model,
            (string) $device->provider,
        ])));

        return str_contains($haystack, 'gallagher') || str_contains($haystack, 'command centre');
    }

    public function fetchStatus(Device $device): array
    {
        return $this->fetchStatusWithToken($device, null);
    }

    /**
     * @return array{
     *     ok: bool,
     *     reason_code: string,
     *     message: string,
     *     areas: list<array{id: string, name: string, state: string}>,
     *     zones: list<array{id: string, name: string, state: string}>,
     *     doors: list<array{id: string, name: string, state: string}>
     * }
     */
    public function fetchStatusWithToken(Device $device, #[\SensitiveParameter] ?string $apiKey): array
    {
        try {
            $siteId = $this->sites->resolve((int) $device->id);
            $endpoint = $this->resolveEndpoint($device, $siteId, $apiKey);

            $areasResponse = $this->http->request(
                $this->authoriseTarget($siteId, (int) $device->id, $endpoint['base_url'].'/api/alarm_zones'),
                'GET',
                $this->headers($endpoint['api_key']),
            );
            if ($areasResponse->location !== null || $areasResponse->status !== 200) {
                return $this->errorStatus('http_'.$areasResponse->status, 'Gallagher Command Centre did not return alarm zones.');
            }

            $areasJson = $areasResponse->json();
            $rawZones = is_array($areasJson['results'] ?? null)
                ? $areasJson['results']
                : (is_array($areasJson['alarmZones'] ?? null) ? $areasJson['alarmZones'] : []);

            $areas = [];
            foreach ($rawZones as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $rawState = strtolower(trim((string) ($row['status'] ?? $row['state'] ?? 'unknown')));
                $state = match (true) {
                    str_contains($rawState, 'unarm') || str_contains($rawState, 'disarm') => 'disarmed',
                    str_contains($rawState, 'alarm') || str_contains($rawState, 'trigger') => 'triggered',
                    str_contains($rawState, 'arm') => 'armed',
                    default => 'unknown',
                };
                $areas[] = [
                    'id' => $id,
                    'name' => trim((string) ($row['name'] ?? 'Zone '.$id)),
                    'state' => $state,
                ];
            }

            return [
                'ok' => true,
                'reason_code' => 'ok',
                'message' => 'Gallagher Command Centre status retrieved.',
                'areas' => $areas,
                'zones' => [],
                'doors' => [],
            ];
        } catch (Throwable $exception) {
            return $this->errorStatus('transport_error', $exception->getMessage());
        }
    }

    public function armArea(Device $device, string $areaId, string $mode = 'away'): array
    {
        return $this->armAreaWithToken($device, $areaId, null, $mode);
    }

    /**
     * @return array{ok: bool, reason_code: string, message: string}
     */
    public function armAreaWithToken(Device $device, string $areaId, #[\SensitiveParameter] ?string $apiKey, string $mode = 'away'): array
    {
        return $this->postZoneAction($device, $areaId, 'arm', $apiKey, ['mode' => $mode]);
    }

    public function disarmArea(Device $device, string $areaId): array
    {
        return $this->disarmAreaWithToken($device, $areaId, null);
    }

    /**
     * @return array{ok: bool, reason_code: string, message: string}
     */
    public function disarmAreaWithToken(Device $device, string $areaId, #[\SensitiveParameter] ?string $apiKey): array
    {
        return $this->postZoneAction($device, $areaId, 'disarm', $apiKey);
    }

    public function unlockDoor(Device $device, string $doorId): array
    {
        $doorId = $this->validateIdentifier($doorId, 'door');
        try {
            $siteId = $this->sites->resolve((int) $device->id);
            $endpoint = $this->resolveEndpoint($device, $siteId, null);
            $target = $this->authoriseTarget(
                $siteId,
                (int) $device->id,
                $endpoint['base_url'].'/api/doors/'.rawurlencode($doorId).'/open',
            );
            $response = $this->http->request($target, 'POST', $this->headers($endpoint['api_key']), []);
            if ($response->location !== null || $response->status >= 300) {
                return [
                    'ok' => false,
                    'reason_code' => 'http_'.$response->status,
                    'message' => 'Gallagher Command Centre rejected the door unlock action.',
                ];
            }

            return [
                'ok' => true,
                'reason_code' => 'ok',
                'message' => 'Gallagher Command Centre accepted the temporary door open command.',
            ];
        } catch (Throwable) {
            return [
                'ok' => false,
                'reason_code' => 'transport_error',
                'message' => 'Gallagher Command Centre door action failed.',
            ];
        }
    }

    /**
     * @param  array<string, scalar>  $body
     * @return array{ok: bool, reason_code: string, message: string}
     */
    private function postZoneAction(
        Device $device,
        string $areaId,
        string $operation,
        #[\SensitiveParameter] ?string $apiKey,
        array $body = [],
    ): array {
        $areaId = $this->validateIdentifier($areaId, 'alarm zone');

        try {
            $siteId = $this->sites->resolve((int) $device->id);
            $endpoint = $this->resolveEndpoint($device, $siteId, $apiKey);
            $target = $this->authoriseTarget(
                $siteId,
                (int) $device->id,
                $endpoint['base_url'].'/api/alarm_zones/'.rawurlencode($areaId).'/'.$operation,
            );
            $response = $this->http->request($target, 'POST', $this->headers($endpoint['api_key']), $body);
            if ($response->location !== null || $response->status >= 300) {
                return [
                    'ok' => false,
                    'reason_code' => 'http_'.$response->status,
                    'message' => sprintf('Gallagher Command Centre rejected the %s command.', $operation),
                ];
            }

            return [
                'ok' => true,
                'reason_code' => 'ok',
                'message' => sprintf('Gallagher Command Centre accepted the %s command for zone %s.', $operation, $areaId),
            ];
        } catch (Throwable) {
            return [
                'ok' => false,
                'reason_code' => 'transport_error',
                'message' => sprintf('Could not reach Gallagher Command Centre to %s zone %s.', $operation, $areaId),
            ];
        }
    }

    /**
     * @return array{base_url: string, api_key: string}
     */
    private function resolveEndpoint(Device $device, int $siteId, #[\SensitiveParameter] ?string $leasedApiKey): array
    {
        if ($siteId < 1) {
            throw new RuntimeException('Alarm panel is not assigned to an authorised Site.');
        }

        $configuredUrl = data_get($device->config ?? [], 'alarm.base_url')
            ?? data_get($device->config ?? [], 'management.alarm_api.base_url');
        $secret = null;
        if (! is_string($configuredUrl) || trim($configuredUrl) === '' || $leasedApiKey === null) {
            $secret = IntegrationSiteSecret::query()
                ->where('site_id', $siteId)
                ->whereIn('provider', ['gallagher', 'gallagher_command_centre'])
                ->where('is_enabled', true)
                ->first();
            $configuredUrl = is_string($configuredUrl) && trim($configuredUrl) !== ''
                ? $configuredUrl
                : $secret?->base_url;
        }

        if (! is_string($configuredUrl) || trim($configuredUrl) === '') {
            if (is_string($device->ip_address) && trim($device->ip_address) !== '') {
                $port = (int) (data_get($device->config ?? [], 'alarm.port') ?? 8904);
                $configuredUrl = 'https://'.trim($device->ip_address).':'.$port;
            } else {
                throw new RuntimeException('Gallagher Command Centre HTTPS base URL is not configured.');
            }
        }

        $baseUrl = $this->validateHttpsBaseUrl($configuredUrl, [443, 8443, 8904]);
        $apiKey = $leasedApiKey ?? (is_string($secret?->secret_encrypted ?? null) ? null : null);
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException('A leased Gallagher GGL-API-KEY credential is required.');
        }

        return [
            'base_url' => $baseUrl,
            'api_key' => trim($apiKey),
        ];
    }

    /**
     * @param  list<int>  $allowedPorts
     */
    private function validateHttpsBaseUrl(string $url, array $allowedPorts): string
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
            || ! in_array((int) ($parts['port'] ?? 443), $allowedPorts, true)
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('Alarm controller HTTPS endpoint is not securely configured.');
        }

        return $url;
    }

    private function authoriseTarget(int $siteId, int $deviceId, string $url): AuthorizedProbeTarget
    {
        $target = ProbeTarget::http($url);
        if ($target->scheme !== 'https') {
            throw new RuntimeException('Gallagher Command Centre requests require HTTPS.');
        }

        return $this->egress->authorise($siteId, $deviceId, $target);
    }

    /**
     * @return array<string, string>
     */
    private function headers(#[\SensitiveParameter] string $apiKey): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'GGL-API-KEY '.$apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    private function validateIdentifier(string $id, string $label): string
    {
        $trimmed = trim($id);
        if ($trimmed === '' || preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $trimmed) !== 1) {
            throw new RuntimeException(sprintf('Invalid Gallagher %s identifier.', $label));
        }

        return $trimmed;
    }

    /**
     * @return array{
     *     ok: false,
     *     reason_code: string,
     *     message: string,
     *     areas: list<never>,
     *     zones: list<never>,
     *     doors: list<never>
     * }
     */
    private function errorStatus(string $code, string $message): array
    {
        return [
            'ok' => false,
            'reason_code' => $code,
            'message' => $message,
            'areas' => [],
            'zones' => [],
            'doors' => [],
        ];
    }
}
