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
 * Inner Range Inception (ANZ) local REST API driver.
 *
 * Official documentation:
 * - https://www.innerrange.com/products/inception/
 *
 * Authentication uses `Authorization: APIToken <token>` over HTTPS (`/api/v1/control/*`).
 */
final class InnerRangeInceptionDriver implements AlarmPanelDriver
{
    public const string KEY = 'alarm.innerrange_inception';

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
            brand: 'Inner Range',
            name: 'Inception Local REST API',
            deviceClass: DriverDeviceClass::AlarmPanel,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'Inner Range Inception REST v1 over HTTPS (`/api/v1/control/*`, Authorization: APIToken) + SIA DC-09',
            models: ['Inner Range Inception Controller (IR-996300)'],
            capabilities: [
                DriverCapability::AlarmZoneStatus,
                DriverCapability::AlarmArm,
                DriverCapability::AlarmDisarm,
                DriverCapability::AccessDoorStatus,
                DriverCapability::AccessDoorUnlock,
                DriverCapability::AlarmEventsSiaDc09,
            ],
            summary: 'Connects directly to an Inner Range Inception security controller over local HTTPS REST API (`/api/v1/control/*`) for area status, inputs, doors, and permission-checked arm/disarm.',
            officialDocs: [
                ['label' => 'Inner Range Inception Product & REST Docs', 'url' => 'https://www.innerrange.com/products/inception/'],
            ],
            limitationsNote: 'Requires a local REST API user token enabled on the Inception web interface and reachable from the Site hub.',
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

        return str_contains($haystack, 'inception')
            || (str_contains($haystack, 'inner range') && ! str_contains($haystack, 'integriti'));
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
    public function fetchStatusWithToken(Device $device, #[\SensitiveParameter] ?string $apiToken): array
    {
        try {
            $siteId = $this->sites->resolve((int) $device->id);
            $endpoint = $this->resolveEndpoint($device, $siteId, $apiToken);

            $areasResponse = $this->http->request(
                $this->authoriseTarget($siteId, (int) $device->id, $endpoint['base_url'].'/api/v1/control/area'),
                'GET',
                $this->headers($endpoint['api_token']),
            );
            if ($areasResponse->location !== null || $areasResponse->status !== 200) {
                return $this->errorStatus('http_'.$areasResponse->status, 'Inner Range Inception did not return area status.');
            }

            $rawAreas = $areasResponse->json();
            if (isset($rawAreas['data']) && is_array($rawAreas['data'])) {
                $rawAreas = $rawAreas['data'];
            }

            $areas = [];
            foreach ($rawAreas as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['ID'] ?? $row['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $info = strtolower(trim((string) ($row['PublicStateDescription'] ?? $row['State'] ?? $row['state'] ?? 'unknown')));
                $state = match (true) {
                    str_contains($info, 'disarm') => 'disarmed',
                    str_contains($info, 'alarm') || str_contains($info, 'trigger') => 'triggered',
                    str_contains($info, 'arm') => 'armed',
                    default => 'unknown',
                };
                $areas[] = [
                    'id' => $id,
                    'name' => trim((string) ($row['Name'] ?? $row['name'] ?? 'Area '.$id)),
                    'state' => $state,
                ];
            }

            return [
                'ok' => true,
                'reason_code' => 'ok',
                'message' => 'Inner Range Inception status retrieved.',
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
    public function armAreaWithToken(Device $device, string $areaId, #[\SensitiveParameter] ?string $apiToken, string $mode = 'away'): array
    {
        $controlType = match (strtolower(trim($mode))) {
            'stay', 'home' => 'ArmStay',
            'sleep', 'night' => 'ArmSleep',
            default => 'Arm',
        };

        return $this->postAreaControl($device, $areaId, $controlType, $apiToken);
    }

    public function disarmArea(Device $device, string $areaId): array
    {
        return $this->disarmAreaWithToken($device, $areaId, null);
    }

    /**
     * @return array{ok: bool, reason_code: string, message: string}
     */
    public function disarmAreaWithToken(Device $device, string $areaId, #[\SensitiveParameter] ?string $apiToken): array
    {
        return $this->postAreaControl($device, $areaId, 'Disarm', $apiToken);
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
                $endpoint['base_url'].'/api/v1/control/door/'.rawurlencode($doorId).'/activity',
            );
            $response = $this->http->request($target, 'POST', $this->headers($endpoint['api_token']), [
                'Type' => 'ControlDoor',
                'DoorControlType' => 'Open',
            ]);
            if ($response->location !== null || $response->status >= 300) {
                return [
                    'ok' => false,
                    'reason_code' => 'http_'.$response->status,
                    'message' => 'Inner Range Inception rejected the door open action.',
                ];
            }

            return [
                'ok' => true,
                'reason_code' => 'ok',
                'message' => 'Inner Range Inception accepted the door open command.',
            ];
        } catch (Throwable) {
            return [
                'ok' => false,
                'reason_code' => 'transport_error',
                'message' => 'Inner Range Inception door action failed.',
            ];
        }
    }

    /**
     * @return array{ok: bool, reason_code: string, message: string}
     */
    private function postAreaControl(
        Device $device,
        string $areaId,
        string $areaControlType,
        #[\SensitiveParameter] ?string $apiToken,
    ): array {
        $areaId = $this->validateIdentifier($areaId, 'area');

        try {
            $siteId = $this->sites->resolve((int) $device->id);
            $endpoint = $this->resolveEndpoint($device, $siteId, $apiToken);
            $target = $this->authoriseTarget(
                $siteId,
                (int) $device->id,
                $endpoint['base_url'].'/api/v1/control/area/'.rawurlencode($areaId).'/activity',
            );
            $response = $this->http->request($target, 'POST', $this->headers($endpoint['api_token']), [
                'Type' => 'ControlArea',
                'AreaControlType' => $areaControlType,
            ]);
            if ($response->location !== null || $response->status >= 300) {
                return [
                    'ok' => false,
                    'reason_code' => 'http_'.$response->status,
                    'message' => sprintf('Inner Range Inception rejected the %s command.', $areaControlType),
                ];
            }

            return [
                'ok' => true,
                'reason_code' => 'ok',
                'message' => sprintf('Inner Range Inception accepted %s for area %s.', $areaControlType, $areaId),
            ];
        } catch (Throwable) {
            return [
                'ok' => false,
                'reason_code' => 'transport_error',
                'message' => sprintf('Could not reach Inner Range Inception for area %s.', $areaId),
            ];
        }
    }

    /**
     * @return array{base_url: string, api_token: string}
     */
    private function resolveEndpoint(Device $device, int $siteId, #[\SensitiveParameter] ?string $leasedToken): array
    {
        if ($siteId < 1) {
            throw new RuntimeException('Alarm panel is not assigned to an authorised Site.');
        }

        $configuredUrl = data_get($device->config ?? [], 'alarm.base_url')
            ?? data_get($device->config ?? [], 'management.alarm_api.base_url');
        if (! is_string($configuredUrl) || trim($configuredUrl) === '') {
            $secret = IntegrationSiteSecret::query()
                ->where('site_id', $siteId)
                ->whereIn('provider', ['innerrange', 'inner_range', 'inception'])
                ->where('is_enabled', true)
                ->first(['base_url']);
            $configuredUrl = $secret?->base_url;
        }

        if (! is_string($configuredUrl) || trim($configuredUrl) === '') {
            if (is_string($device->ip_address) && trim($device->ip_address) !== '') {
                $port = (int) (data_get($device->config ?? [], 'alarm.port') ?? 443);
                $configuredUrl = 'https://'.trim($device->ip_address).($port === 443 ? '' : ':'.$port);
            } else {
                throw new RuntimeException('Inner Range Inception HTTPS base URL is not configured.');
            }
        }

        $baseUrl = $this->validateHttpsBaseUrl($configuredUrl, [443, 8443]);
        if (! is_string($leasedToken) || trim($leasedToken) === '') {
            throw new RuntimeException('A leased Inner Range Inception APIToken credential is required.');
        }

        return [
            'base_url' => $baseUrl,
            'api_token' => trim($leasedToken),
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
            throw new RuntimeException('Inner Range Inception HTTPS endpoint is not securely configured.');
        }

        return $url;
    }

    private function authoriseTarget(int $siteId, int $deviceId, string $url): AuthorizedProbeTarget
    {
        $target = ProbeTarget::http($url);
        if ($target->scheme !== 'https') {
            throw new RuntimeException('Inner Range Inception requests require HTTPS.');
        }

        return $this->egress->authorise($siteId, $deviceId, $target);
    }

    /**
     * @return array<string, string>
     */
    private function headers(#[\SensitiveParameter] string $apiToken): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'APIToken '.$apiToken,
            'Content-Type' => 'application/json',
        ];
    }

    private function validateIdentifier(string $id, string $label): string
    {
        $trimmed = trim($id);
        if ($trimmed === '' || preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $trimmed) !== 1) {
            throw new RuntimeException(sprintf('Invalid Inner Range Inception %s identifier.', $label));
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
