<?php

namespace App\Domain\SecurityDevices\Screens\Setup;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseGrant;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Setup › Services & logins › Saved logins (pg-sdr-15e): every saved login by Site, the hub or
 * service that may use it, when it was last used and whether it still works. Nothing secret
 * leaves the server: no password, key, ending or vault path.
 */
final class SetupLogins
{
    private const array BRANDS = [
        'unifi' => 'UniFi', 'unifi_protect' => 'UniFi Protect', 'unifi_access' => 'UniFi Access',
        'mikrotik' => 'MikroTik', 'cisco' => 'Cisco', 'cisco_ios' => 'Cisco', 'aruba' => 'Aruba',
        'fortinet' => 'Fortinet', 'fortigate' => 'FortiGate', 'hikvision' => 'Hikvision',
        'hikvision_recorder' => 'Hikvision recorder', 'dahua' => 'Dahua', 'onvif' => 'ONVIF camera',
        'milesight' => 'Milesight', 'inception' => 'Inner Range Inception', 'inner_range' => 'Inner Range',
        'gallagher' => 'Gallagher', 'queclink' => 'Queclink', 'intune' => 'Microsoft Intune', 'ssh' => 'Command line',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CoveringHubResolver $hubs,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, kept: list<array{0: string, 1: string}>, failed: list<array<string, mixed>>, due: int}
     */
    public function present(User $viewer): array
    {
        $siteIds = $this->access->accessibleSiteIds($viewer);
        $hubFor = [];
        $hub = function (int $siteId) use (&$hubFor): ?MonitoringCollector {
            return array_key_exists($siteId, $hubFor) ? $hubFor[$siteId] : ($hubFor[$siteId] = $this->hubs->forSite($siteId));
        };
        $rows = [];

        $references = $siteIds === [] ? collect() : CredentialReference::query()
            ->with('site:id,name')
            ->whereIn('site_id', $siteIds)
            ->where('status', '!=', CredentialReferenceStatus::Revoked->value)
            ->whereNull('revoked_at')
            ->orderBy('site_id')
            ->orderBy('id')
            ->get();
        $lastLease = $references->isEmpty() ? collect() : CredentialLeaseGrant::query()
            ->whereIn('credential_reference_id', $references->pluck('id'))
            ->selectRaw('credential_reference_id, MAX(issued_at) as last_issued')
            ->groupBy('credential_reference_id')
            ->pluck('last_issued', 'credential_reference_id');

        foreach ($references as $reference) {
            $siteId = (int) $reference->site_id;
            $siteHub = $hub($siteId);
            $hubDown = $siteHub !== null && ! in_array((string) $siteHub->status, ['online', 'degraded'], true);
            $used = $this->latest($reference->last_tested_at, $lastLease->get($reference->id));
            $rows[] = [
                'key' => 'login-'.$reference->id,
                'used_for' => self::brand((string) $reference->provider).' login',
                'sub' => Str::of((string) $reference->purpose)->replace(['_', '.', '-'], ' ')->lower()->ucfirst()->toString(),
                'icon' => 'key',
                'site' => ['id' => $siteId, 'name' => (string) ($reference->site?->name ?? 'Site')],
                'used_by' => $siteHub !== null ? CoveringHubResolver::label($siteHub) : 'Oblivion server',
                'last_used' => $used === null ? null : $used->toIso8601String(),
                'state' => match (true) {
                    $reference->status === CredentialReferenceStatus::Suspended => ['tone' => 'warning', 'text' => 'Paused'],
                    $reference->test_status === CredentialTestStatus::Failed => ['tone' => 'critical', 'text' => 'Login failed'],
                    $hubDown => ['tone' => 'neutral', 'text' => 'Can’t check'],
                    $reference->test_status === CredentialTestStatus::Passed => ['tone' => 'success', 'text' => 'Working'],
                    default => ['tone' => 'neutral', 'text' => 'Not tested yet'],
                },
            ];
        }

        $secrets = $siteIds === [] ? collect() : IntegrationSiteSecret::query()
            ->with('site:id,name')
            ->whereIn('site_id', $siteIds)
            ->orderBy('site_id')
            ->orderBy('id')
            ->get(['id', 'site_id', 'provider', 'capability', 'is_enabled', 'last_tested_at', 'last_error']);
        foreach ($secrets as $secret) {
            $rows[] = [
                'key' => 'site-secret-'.$secret->id,
                'used_for' => self::brand((string) $secret->provider).' account',
                'sub' => filled($secret->capability)
                    ? Str::of((string) $secret->capability)->replace(['_', '.', '-'], ' ')->lower()->ucfirst()->toString()
                    : 'Site login',
                'icon' => 'key',
                'site' => ['id' => (int) $secret->site_id, 'name' => (string) ($secret->site?->name ?? 'Site')],
                'used_by' => 'Oblivion server',
                'last_used' => $secret->last_tested_at?->toIso8601String(),
                'state' => match (true) {
                    filled($secret->last_error) => ['tone' => 'critical', 'text' => 'Login failed'],
                    ! $secret->is_enabled => ['tone' => 'neutral', 'text' => 'Switched off'],
                    $secret->last_tested_at === null => ['tone' => 'neutral', 'text' => 'Not tested yet'],
                    default => ['tone' => 'success', 'text' => 'Working'],
                },
            ];
        }

        // Organisation-wide accounts (an API key for every Site) belong to no one Site.
        if ($viewer->canDo('securityDevices.integrations.view')) {
            $connections = IntegrationProviderConnection::query()
                ->whereNotNull('secret_encrypted')
                ->orderBy('provider')
                ->get(['id', 'provider', 'status', 'last_tested_at', 'rotated_at', 'created_at', 'requires_credential_replacement']);
            foreach ($connections as $connection) {
                $since = $connection->rotated_at ?? $connection->created_at;
                $rows[] = [
                    'key' => 'account-'.$connection->id,
                    'used_for' => self::brand((string) $connection->provider).' account',
                    'sub' => 'API key · every Site',
                    'icon' => 'cloud',
                    'site' => null,
                    'used_by' => 'Oblivion server',
                    'last_used' => $connection->last_tested_at?->toIso8601String(),
                    'state' => match (true) {
                        $connection->status === IntegrationProviderConnection::STATUS_ERROR => ['tone' => 'critical', 'text' => 'Login failed'],
                        (bool) $connection->requires_credential_replacement => ['tone' => 'warning', 'text' => 'Needs replacing'],
                        $since !== null && $since->lte(now()->subDays(90)) => ['tone' => 'warning', 'text' => 'Due to be replaced'],
                        $connection->status === IntegrationProviderConnection::STATUS_CONNECTED => ['tone' => 'success', 'text' => 'Working'],
                        default => ['tone' => 'neutral', 'text' => 'Not connected'],
                    },
                ];
            }
        }

        $vault = (string) config('monitoring.credentials.driver', 'database') === 'vault';

        return [
            'rows' => $rows,
            'kept' => [
                ['Stored', $vault ? 'In your HashiCorp Vault, never in the Oblivion database' : 'Encrypted in the Oblivion database'],
                ['On a hub', 'Only that hub’s Sites, encrypted on the hub, after an admin approves the hub'],
                ['Removing a hub', 'Wipes every login it held'],
                ['Reading a password back', 'Not possible; IT admins can replace one'],
                ['Your own vault', $vault ? 'On · HashiCorp Vault' : 'Off · optional, for clients who use HashiCorp Vault'],
            ],
            'failed' => array_values(array_filter($rows, fn (array $row): bool => $row['state']['text'] === 'Login failed')),
            'due' => count(array_filter($rows, fn (array $row): bool => in_array($row['state']['text'], ['Needs replacing', 'Due to be replaced'], true))),
        ];
    }

    public static function brand(string $provider): string
    {
        $key = strtolower(trim($provider));

        return self::BRANDS[$key] ?? Str::of($key)->replace(['_', '-', '.'], ' ')->title()->toString();
    }

    private function latest(?CarbonInterface $tested, mixed $leased): ?CarbonInterface
    {
        $lease = is_string($leased) && $leased !== '' ? \Carbon\CarbonImmutable::parse($leased, 'UTC') : null;
        if ($tested === null) {
            return $lease;
        }
        if ($lease === null) {
            return $tested;
        }

        return $lease->gt($tested) ? $lease : $tested;
    }
}
