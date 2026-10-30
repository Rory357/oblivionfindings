<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Models\Integration\IntegrationProviderConnection;
use App\Services\Integration\IntegrationSecretManager;
use App\Services\Integration\IntegrationSecretMaterialService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only Microsoft Graph Intune managed-device client.
 *
 * Official Microsoft documentation:
 * - https://learn.microsoft.com/en-us/graph/api/intune-devices-manageddevice-list?view=graph-rest-1.0
 * - https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow
 */
final class IntuneReadClient
{
    private const HOST = 'https://graph.microsoft.com/v1.0/deviceManagement/managedDevices';

    private const FIELDS = 'id,deviceName,managedDeviceOwnerType,enrolledDateTime,lastSyncDateTime,operatingSystem,osVersion,complianceState,managementAgent,manufacturer,model,serialNumber,isEncrypted';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly ?IntegrationSecretMaterialService $secrets = null,
    ) {}

    public function isConfigured(): bool
    {
        $connection = $this->connectedIntegrationConnection();
        if ($connection !== null) {
            $config = is_array($connection->config) ? $connection->config : [];
            $directory = trim((string) ($config['directory_id'] ?? ''));
            $client = trim((string) ($config['client_id'] ?? ''));

            if (preg_match(self::UUID, $directory) === 1 && preg_match(self::UUID, $client) === 1) {
                return true;
            }
        }

        if (! config('endpoint_management.intune.enabled', false)) {
            return false;
        }

        $directory = (string) config('endpoint_management.intune.directory_id');
        $client = (string) config('endpoint_management.intune.client_id');
        $secret = (string) config('endpoint_management.intune.client_secret');

        return preg_match(self::UUID, $directory) === 1
            && preg_match(self::UUID, $client) === 1
            && $secret !== '';
    }

    public function testConnection(IntegrationProviderConnection $connection): bool
    {
        try {
            $token = $this->tokenForConnection($connection);
        } catch (\Throwable) {
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(20)
                ->withoutRedirecting()
                ->get(self::HOST.'?$select=id&$top=1');

            if (! $response->successful() || strlen($response->body()) > 1_000_000) {
                return false;
            }

            $body = $response->json();

            return is_array($body) && isset($body['value']) && is_array($body['value']);
        } catch (\Throwable) {
            return false;
        } finally {
            try {
                sodium_memzero($token);
            } catch (\Throwable) {
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function devicesForConnection(IntegrationProviderConnection $connection): array
    {
        return $this->fetchDevicesWithToken($this->tokenForConnection($connection));
    }

    /** @return list<array<string, mixed>> */
    public function devices(): array
    {
        return $this->fetchDevicesWithToken($this->token());
    }

    /** @return list<array<string, mixed>> */
    private function fetchDevicesWithToken(#[\SensitiveParameter] string $token): array
    {
        $url = self::HOST.'?$select='.self::FIELDS.'&$top=100';
        $visited = [];
        $devices = [];
        try {
            while ($url !== null) {
                $this->assertPageUrl($url);
                if (isset($visited[$url]) || count($visited) >= (int) config('endpoint_management.intune.maximum_pages', 100)) {
                    throw new RuntimeException('Intune returned an incomplete or repeated page sequence.');
                }
                $visited[$url] = true;
                $response = Http::withToken($token)->acceptJson()->connectTimeout(10)->timeout(30)
                    ->withoutRedirecting()->get($url);
                if (! $response->successful() || strlen($response->body()) > 8_000_000) {
                    throw new RuntimeException('Intune device information is temporarily unavailable.');
                }
                $body = $response->json();
                if (! is_array($body) || ! isset($body['value']) || ! is_array($body['value']) || ! array_is_list($body['value'])) {
                    throw new RuntimeException('Intune returned an invalid device page.');
                }
                foreach ($body['value'] as $record) {
                    if (! is_array($record) || ! is_string($record['id'] ?? null) || ! preg_match(self::UUID, $record['id'])) {
                        throw new RuntimeException('Intune returned an invalid device identity.');
                    }
                    $record['id'] = strtolower($record['id']);
                    if (isset($devices[$record['id']])) {
                        throw new RuntimeException('Intune returned a repeated device identity.');
                    }
                    $devices[$record['id']] = $this->snapshot($record);
                    if (count($devices) > (int) config('endpoint_management.intune.maximum_devices', 50000)) {
                        throw new RuntimeException('Intune device limit was exceeded.');
                    }
                }
                $url = $body['@odata.nextLink'] ?? null;
                if ($url !== null && ! is_string($url)) {
                    throw new RuntimeException('Intune returned an invalid page reference.');
                }
            }

            return array_values($devices);
        } finally {
            try {
                sodium_memzero($token);
            } catch (\Throwable) {
            }
        }
    }

    private function token(): string
    {
        $connection = $this->connectedIntegrationConnection();
        if ($connection !== null) {
            return $this->tokenForConnection($connection);
        }

        if (! config('endpoint_management.intune.enabled')) {
            throw new RuntimeException('Intune visibility is not configured.');
        }
        $directory = (string) config('endpoint_management.intune.directory_id');
        $client = (string) config('endpoint_management.intune.client_id');
        $secret = (string) config('endpoint_management.intune.client_secret');

        return $this->requestAccessToken($directory, $client, $secret);
    }

    private function tokenForConnection(IntegrationProviderConnection $connection): string
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $directory = strtolower(trim((string) ($config['directory_id'] ?? '')));
        $client = strtolower(trim((string) ($config['client_id'] ?? '')));
        $secrets = $this->secrets ?? app(IntegrationSecretMaterialService::class);
        $secret = (string) ($secrets->application(
            $connection,
            IntegrationSecretManager::PURPOSE_PRIMARY,
            'client_secret',
        ) ?? '');

        try {
            return $this->requestAccessToken($directory, $client, $secret);
        } finally {
            try {
                sodium_memzero($secret);
            } catch (\Throwable) {
            }
        }
    }

    private function requestAccessToken(
        string $directory,
        string $client,
        #[\SensitiveParameter] string $secret,
    ): string {
        if (! preg_match(self::UUID, $directory) || ! preg_match(self::UUID, $client) || $secret === '') {
            throw new RuntimeException('Intune read-only connection details are incomplete.');
        }
        $response = Http::asForm()->acceptJson()->connectTimeout(10)->timeout(30)->withoutRedirecting()
            ->post('https://login.microsoftonline.com/'.$directory.'/oauth2/v2.0/token', [
                'client_id' => $client, 'client_secret' => $secret,
                'grant_type' => 'client_credentials', 'scope' => 'https://graph.microsoft.com/.default',
            ]);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '' || strlen($token) > 32768) {
            throw new RuntimeException('Intune read-only authentication failed.');
        }

        return $token;
    }

    private function connectedIntegrationConnection(): ?IntegrationProviderConnection
    {
        try {
            if (IntegrationProviderConnection::getConnectionResolver() === null) {
                return null;
            }

            return IntegrationProviderConnection::query()
                ->forProvider('intune')
                ->connected()
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertPageUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'graph.microsoft.com'
            || ($parts['path'] ?? '') !== '/v1.0/deviceManagement/managedDevices'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['fragment']) || strlen($url) > 16384) {
            throw new RuntimeException('Intune returned an untrusted page reference.');
        }
    }

    private function snapshot(array $record): array
    {
        $snapshot = [];
        foreach (explode(',', self::FIELDS) as $field) {
            $value = $record[$field] ?? null;
            if ($value !== null && ($field === 'isEncrypted' ? ! is_bool($value) : ! is_string($value))) {
                throw new RuntimeException('Intune returned invalid device properties.');
            }
            $snapshot[$field] = is_string($value) ? mb_substr($value, 0, 300) : $value;
        }

        return $snapshot;
    }
}
