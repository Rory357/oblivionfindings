<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DiscoveryScopeValidator;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDependency;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Protocols\RemoteInventory\InventoryQuery;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnexpectedValueException;

final readonly class NativeMonitoringDefinitionService
{
    /** @var list<MonitorKind> */
    private const array DIRECT_KINDS = [
        MonitorKind::Icmp,
        MonitorKind::Tcp,
        MonitorKind::Dns,
        MonitorKind::Http,
        MonitorKind::Tls,
        MonitorKind::Snmp,
        MonitorKind::SshInventory,
        MonitorKind::WinRmInventory,
    ];

    /** @var list<string> */
    private const array DISCOVERY_PROTOCOLS = ['icmp', 'tcp', 'dns', 'http', 'tls', 'snmp'];

    public function __construct(
        private CanonicalDeviceSiteResolver $siteResolver,
        private EgressPolicy $egress,
        private DiscoveryScopeValidator $scopeValidator,
        private CidrMatcher $cidrMatcher,
    ) {}

    /** @return list<array{value: string, label: string}> */
    public static function directKindOptions(): array
    {
        return collect(self::DIRECT_KINDS)
            ->map(fn (MonitorKind $kind): array => [
                'value' => $kind->value,
                'label' => match ($kind) {
                    MonitorKind::Icmp => 'ICMP availability',
                    MonitorKind::Tcp => 'TCP port',
                    MonitorKind::Dns => 'DNS answer',
                    MonitorKind::Http => 'HTTP / HTTPS',
                    MonitorKind::Tls => 'TLS certificate',
                    MonitorKind::Snmp => 'SNMPv3 inventory and health',
                    MonitorKind::SshInventory => 'Read-only SSH inventory',
                    MonitorKind::WinRmInventory => 'Read-only WinRM inventory',
                    default => $kind->value,
                },
            ])
            ->values()
            ->all();
    }

    /** @return list<string> */
    public static function discoveryProtocols(): array
    {
        return self::DISCOVERY_PROTOCOLS;
    }

    /** @return list<string> */
    public static function directKindValues(): array
    {
        return array_map(fn (MonitorKind $kind): string => $kind->value, self::DIRECT_KINDS);
    }

    /** @param array<string, mixed> $data */
    public function createMonitor(User $actor, Device $device, array $data): Monitor
    {
        return DB::transaction(function () use ($actor, $device, $data): Monitor {
            $lockedDevice = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $kind = $this->directKind($data['kind'] ?? null);
            $siteId = $this->canonicalSite((int) $lockedDevice->id);
            $profile = $this->activeProfile($data['profile_id'] ?? null);
            [$target, $config] = $this->monitorDefinition($kind, $data, null, $siteId);
            $this->authoriseTarget($siteId, (int) $lockedDevice->id, $kind, $target, $config);
            $monitor = Monitor::query()->create([
                'device_id' => $lockedDevice->id,
                'profile_id' => $profile->id,
                'collector_id' => null,
                'kind' => $kind,
                'name' => trim((string) $data['name']),
                'target' => $target,
                'config' => $config,
                'current_state' => MonitorState::Unknown,
                'effective_state' => MonitorState::Unknown,
                'affects_availability' => (bool) ($data['affects_availability'] ?? false),
                'is_enabled' => true,
            ]);
            AuditLogger::logOrFail('monitoring.monitor.created', $monitor, [
                'actor_id' => (int) $actor->id,
                'site_id' => $siteId,
                'device_id' => (int) $lockedDevice->id,
                'profile_id' => (int) $profile->id,
                'kind' => $kind->value,
                'collection_mode' => 'central_direct',
                'affects_availability' => (bool) $monitor->affects_availability,
            ]);

            return $monitor->fresh(['device', 'profile']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateMonitor(User $actor, Monitor $monitor, array $data): Monitor
    {
        return DB::transaction(function () use ($actor, $monitor, $data): Monitor {
            $locked = Monitor::query()
                ->whereKey($monitor->id)
                ->whereNull('collector_id')
                ->whereIn('kind', self::directKindValues())
                ->lockForUpdate()
                ->firstOrFail();
            $siteId = $this->canonicalSite((int) $locked->device_id);
            $kind = $locked->kind instanceof MonitorKind
                ? $locked->kind
                : $this->directKind($locked->getRawOriginal('kind'));
            $profile = array_key_exists('profile_id', $data)
                ? $this->activeProfile($data['profile_id'])
                : $this->activeProfile($locked->profile_id);
            [$target, $config] = $this->monitorDefinition($kind, $data, $locked, $siteId);
            $this->authoriseTarget($siteId, (int) $locked->device_id, $kind, $target, $config);
            $targetChanged = ! hash_equals((string) $locked->target, $target);
            $locked->forceFill([
                'profile_id' => $profile->id,
                'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : $locked->name,
                'target' => $target,
                'config' => $config,
                'affects_availability' => array_key_exists('affects_availability', $data)
                    ? (bool) $data['affects_availability']
                    : $locked->affects_availability,
            ])->save();
            AuditLogger::logOrFail('monitoring.monitor.updated', $locked, [
                'actor_id' => (int) $actor->id,
                'site_id' => $siteId,
                'device_id' => (int) $locked->device_id,
                'profile_id' => (int) $profile->id,
                'kind' => $kind->value,
                'collection_mode' => 'central_direct',
                'target_changed' => $targetChanged,
                'affects_availability' => (bool) $locked->affects_availability,
            ]);

            return $locked->fresh(['device', 'profile']);
        }, 3);
    }

    public function deactivateMonitor(User $actor, Monitor $monitor, string $reasonCode): Monitor
    {
        if (! in_array($reasonCode, ['replaced', 'obsolete', 'coverage_removed', 'device_retired'], true)) {
            throw ValidationException::withMessages(['reason_code' => 'Select an approved deactivation reason.']);
        }

        return DB::transaction(function () use ($actor, $monitor, $reasonCode): Monitor {
            $locked = Monitor::query()
                ->whereKey($monitor->id)
                ->whereNull('collector_id')
                ->whereIn('kind', self::directKindValues())
                ->lockForUpdate()
                ->firstOrFail();
            Device::query()->whereKey($locked->device_id)->lockForUpdate()->firstOrFail();
            $siteId = $this->canonicalSite((int) $locked->device_id, true);
            $dependencyCount = MonitorDependency::query()
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->where('upstream_monitor_id', $locked->id)
                    ->orWhere('downstream_monitor_id', $locked->id))
                ->lockForUpdate()
                ->get(['id'])
                ->count();
            if ($dependencyCount > 0) {
                throw ValidationException::withMessages([
                    'monitor' => 'Remove or replace this monitor’s active dependencies before deactivation.',
                ]);
            }
            if ($locked->is_enabled) {
                $locked->forceFill([
                    'is_enabled' => false,
                    'pending_state' => null,
                    'pending_count' => 0,
                    'pending_since_at' => null,
                    'root_cause_monitor_id' => null,
                    'suppression_reason' => null,
                    'suppressed_at' => null,
                ])->save();
                AuditLogger::logOrFail('monitoring.monitor.deactivated', $locked, [
                    'actor_id' => (int) $actor->id,
                    'site_id' => $siteId,
                    'device_id' => (int) $locked->device_id,
                    'kind' => $locked->kind?->value ?? (string) $locked->kind,
                    'reason_code' => $reasonCode,
                ]);
            }

            return $locked->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createScope(User $actor, Site $site, array $data): DiscoveryScope
    {
        return DB::transaction(function () use ($actor, $site, $data): DiscoveryScope {
            Site::query()
                ->whereKey($site->id)
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->firstOrFail();
            $attributes = $this->scopeAttributes($data, null, (int) $site->id);
            $scope = new DiscoveryScope($attributes);
            $this->assertScopeValid($scope);
            $this->assertScopeNameAvailable((int) $site->id, $attributes['name']);
            $scope = DiscoveryScope::query()->create($attributes);
            $this->auditScope('monitoring.discovery.scope.created', $scope, $actor);

            return $scope->fresh(['site']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateScope(User $actor, DiscoveryScope $scope, array $data): DiscoveryScope
    {
        return DB::transaction(function () use ($actor, $scope, $data): DiscoveryScope {
            $locked = DiscoveryScope::query()
                ->whereKey($scope->id)
                ->whereNull('collector_id')
                ->lockForUpdate()
                ->firstOrFail();
            $attributes = $this->scopeAttributes($data, $locked, (int) $locked->site_id);
            $this->assertScopeNameAvailable((int) $locked->site_id, $attributes['name'], (int) $locked->id);
            $candidate = $locked->replicate();
            $candidate->forceFill($attributes);
            $this->assertScopeValid($candidate);
            $locked->forceFill($attributes)->save();
            $this->auditScope('monitoring.discovery.scope.updated', $locked, $actor);

            return $locked->fresh(['site']);
        }, 3);
    }

    public function deactivateScope(User $actor, DiscoveryScope $scope, string $reasonCode): DiscoveryScope
    {
        if (! in_array($reasonCode, ['network_retired', 'scope_replaced', 'duplicate_scope', 'site_connectivity_changed'], true)) {
            throw ValidationException::withMessages(['reason_code' => 'Select an approved deactivation reason.']);
        }

        return DB::transaction(function () use ($actor, $scope, $reasonCode): DiscoveryScope {
            $locked = DiscoveryScope::query()
                ->whereKey($scope->id)
                ->whereNull('collector_id')
                ->lockForUpdate()
                ->firstOrFail();
            $activeRun = DiscoveryRun::query()
                ->where('discovery_scope_id', $locked->id)
                ->whereIn('status', ['queued', 'running'])
                ->lockForUpdate()
                ->get(['id'])
                ->isNotEmpty();
            if ($activeRun) {
                throw ValidationException::withMessages([
                    'scope' => 'Wait for the active discovery run to finish before deactivating this scope.',
                ]);
            }
            if ($locked->status === 'active') {
                $locked->forceFill(['status' => 'inactive'])->save();
                AuditLogger::logOrFail('monitoring.discovery.scope.deactivated', $locked, [
                    'actor_id' => (int) $actor->id,
                    'site_id' => (int) $locked->site_id,
                    'reason_code' => $reasonCode,
                ]);
            }

            return $locked->fresh();
        }, 3);
    }

    private function directKind(mixed $value): MonitorKind
    {
        $kind = is_string($value) ? MonitorKind::tryFrom($value) : null;
        if ($kind === null || ! in_array($kind, self::DIRECT_KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Select a supported native direct-check adapter.']);
        }

        return $kind;
    }

    private function activeProfile(mixed $id): MonitoringProfile
    {
        $profile = is_numeric($id)
            ? MonitoringProfile::query()
                ->whereKey((int) $id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first()
            : null;
        if ($profile === null) {
            throw ValidationException::withMessages(['profile_id' => 'Select an active monitoring profile.']);
        }

        return $profile;
    }

    /** @param array<string, mixed> $data @return array{string, array<string, mixed>} */
    private function monitorDefinition(MonitorKind $kind, array $data, ?Monitor $existing, int $siteId): array
    {
        $currentConfig = is_array($existing?->config) ? $existing->config : [];
        $submittedTarget = $data['target'] ?? null;
        $target = is_string($submittedTarget) && trim($submittedTarget) !== ''
            ? trim($submittedTarget)
            : trim((string) ($existing?->target ?? ''));
        if ($target === '') {
            throw ValidationException::withMessages(['target' => 'Enter the approved probe target.']);
        }
        $value = fn (string $key, mixed $default = null): mixed => array_key_exists($key, $data)
            && $data[$key] !== null && $data[$key] !== ''
                ? $data[$key]
                : ($currentConfig[$key] ?? $default);

        $config = match ($kind) {
            MonitorKind::Icmp => ['host' => $target],
            MonitorKind::Tcp => ['host' => $target, 'port' => $this->port($value('port'))],
            MonitorKind::Dns => [
                'server' => $target,
                'port' => $this->port($value('port', 53)),
                'name' => $this->dnsName($value('dns_name', $currentConfig['name'] ?? null)),
                'type' => $this->dnsType($value('dns_type', $currentConfig['type'] ?? 'A')),
                'expected_answers' => $this->stringList($value('expected_answers', []), 64, 1024, 'expected_answers'),
            ],
            MonitorKind::Http => [
                'url' => $target,
                'expected_status' => $this->statusList($value('expected_status', [200])),
            ],
            MonitorKind::Tls => [
                'host' => $target,
                'port' => $this->port($value('port', 443)),
                'warn_days' => $this->boundedInteger($value('warn_days', 30), 1, 365, 'warn_days'),
            ],
            MonitorKind::Snmp => [
                'host' => $target,
                'port' => $this->port($value('port', 161)),
                'version' => 'v3',
                'credential_reference' => $this->credentialReference(
                    $siteId,
                    $value('credential_reference'),
                    ['snmp:v3:auth_priv'],
                ),
            ],
            MonitorKind::SshInventory => [
                'host' => $target,
                'port' => $this->port($value('port', 22)),
                'profile' => $this->inventoryProfile($value('inventory_profile', $currentConfig['profile'] ?? null), 'linux'),
                'credential_reference' => $this->credentialReference(
                    $siteId,
                    $value('credential_reference'),
                    ['inventory:ssh:read_only'],
                ),
                'host_key_fingerprint' => $this->sshFingerprint($value('host_key_fingerprint')),
            ],
            MonitorKind::WinRmInventory => [
                'url' => $target,
                'profile' => $this->inventoryProfile($value('inventory_profile', $currentConfig['profile'] ?? null), 'windows'),
                'credential_reference' => $this->credentialReference(
                    $siteId,
                    $value('credential_reference'),
                    ['inventory:winrm:read_only'],
                ),
            ],
            default => throw ValidationException::withMessages(['kind' => 'Select a supported native direct-check adapter.']),
        };

        return [$target, $config];
    }

    /** @param array<string, mixed> $config */
    private function authoriseTarget(int $siteId, int $deviceId, MonitorKind $kind, string $target, array $config): void
    {
        try {
            $probe = match ($kind) {
                MonitorKind::Icmp => ProbeTarget::icmp($target),
                MonitorKind::Tcp => ProbeTarget::tcp($target, $config['port']),
                MonitorKind::Dns => ProbeTarget::dns($target, $config['port']),
                MonitorKind::Http => ProbeTarget::http($target),
                MonitorKind::Tls => ProbeTarget::tls($target, $config['port']),
                MonitorKind::Snmp => ProbeTarget::snmp($target, $config['port']),
                MonitorKind::SshInventory => ProbeTarget::ssh($target, $config['port']),
                MonitorKind::WinRmInventory => ProbeTarget::winrm($target),
                default => throw new \LogicException('Unsupported adapter.'),
            };
            $authorised = $this->egress->authorise($siteId, $deviceId, $probe);
            $protocol = match ($kind) {
                MonitorKind::SshInventory, MonitorKind::WinRmInventory => 'tcp',
                default => $kind->value,
            };
            $scopes = DiscoveryScope::query()
                ->where('site_id', $siteId)
                ->whereNull('collector_id')
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();
            $definitionIsAuthorised = collect($authorised->addresses)->every(
                fn (string $address): bool => $scopes->contains(
                    fn (DiscoveryScope $scope): bool => $this->scopeAuthorisesAddress(
                        $scope,
                        $protocol,
                        $authorised->port,
                        $address,
                    ),
                ),
            );
            if (! $definitionIsAuthorised) {
                throw new UnexpectedValueException('Adapter scope mismatch.');
            }
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'target' => 'The target is not inside an active central Site network scope and adapter allowlist.',
            ]);
        }
    }

    private function canonicalSite(int $deviceId, bool $forContext = false): int
    {
        try {
            return $forContext
                ? $this->siteResolver->resolveForContext($deviceId)
                : $this->siteResolver->resolve($deviceId);
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages([
                'device' => 'The Device does not currently resolve to one canonical active Site.',
            ]);
        }
    }

    private function scopeAuthorisesAddress(
        DiscoveryScope $scope,
        string $protocol,
        int $port,
        string $address,
    ): bool {
        if (($scope->exclusions ?? []) !== []
            || $this->scopeValidator->validateScope($scope) !== null
            || ! in_array($protocol, $scope->protocols ?? [], true)) {
            return false;
        }
        $inside = collect($scope->cidrs ?? [])->contains(
            fn (string $cidr): bool => $this->cidrMatcher->contains($cidr, $address)
                && ! $this->cidrMatcher->isIpv4NetworkOrBroadcast($cidr, $address),
        );
        if (! $inside || $protocol === 'icmp') {
            return $inside;
        }

        return in_array($port, ($scope->port_bounds ?? [])[$protocol] ?? [], true);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function scopeAttributes(array $data, ?DiscoveryScope $existing, int $siteId): array
    {
        $value = fn (string $key, mixed $default): mixed => array_key_exists($key, $data)
            && $data[$key] !== null
                ? $data[$key]
                : ($existing?->{$key} ?? $default);
        $protocols = collect($value('protocols', []))
            ->filter(fn (mixed $protocol): bool => is_string($protocol))
            ->map(fn (string $protocol): string => strtolower(trim($protocol)))
            ->unique()
            ->values()
            ->all();
        if ($protocols === [] || array_diff($protocols, self::DISCOVERY_PROTOCOLS) !== []) {
            throw ValidationException::withMessages(['protocols' => 'Select one or more supported native discovery protocols.']);
        }
        $snmpReference = in_array('snmp', $protocols, true)
            ? $this->credentialReference($siteId, $value('snmp_credential_reference', null), ['snmp:v3:auth_priv'])
            : null;

        return [
            'site_id' => $siteId,
            'collector_id' => null,
            'name' => trim((string) $value('name', '')),
            'cidrs' => $this->stringList($value('cidrs', []), 64, 2048, 'cidrs'),
            'seed_hosts' => $this->stringList($value('seed_hosts', []), 256, 253, 'seed_hosts'),
            'protocols' => $protocols,
            'snmp_credential_reference' => $snmpReference,
            'exclusions' => $this->stringList($value('exclusions', []), 1024, 2048, 'exclusions'),
            'port_bounds' => $this->portBounds($value('port_bounds', []), $protocols),
            'max_targets_per_run' => $this->boundedInteger($value('max_targets_per_run', 1024), 1, 65536, 'max_targets_per_run'),
            'packets_per_second' => $this->boundedInteger($value('packets_per_second', 20), 1, 1000, 'packets_per_second'),
            'schedule_cron' => null,
            'status' => $existing?->status === 'inactive' ? 'inactive' : 'active',
        ];
    }

    private function assertScopeValid(DiscoveryScope $scope): void
    {
        $validation = $this->scopeValidator->validateScope($scope);
        if ($validation !== null) {
            throw ValidationException::withMessages([
                'scope' => 'The Site network scope is outside the governed discovery allowlist.',
            ]);
        }
    }

    private function assertScopeNameAvailable(int $siteId, string $name, ?int $exceptId = null): void
    {
        $exists = DiscoveryScope::query()
            ->withTrashed()
            ->where('site_id', $siteId)
            ->where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Use a unique discovery scope name within this Site.',
            ]);
        }
    }

    private function auditScope(string $action, DiscoveryScope $scope, User $actor): void
    {
        AuditLogger::logOrFail($action, $scope, [
            'actor_id' => (int) $actor->id,
            'site_id' => (int) $scope->site_id,
            'collection_mode' => 'central_direct',
            'protocols' => array_values($scope->protocols ?? []),
            'network_range_count' => count($scope->cidrs ?? []),
            'seed_host_count' => count($scope->seed_hosts ?? []),
            'exclusion_count' => count($scope->exclusions ?? []),
            'port_count' => collect($scope->port_bounds ?? [])->flatten()->count(),
            'max_targets_per_run' => (int) $scope->max_targets_per_run,
            'packets_per_second' => (int) $scope->packets_per_second,
        ]);
    }

    private function port(mixed $value): int
    {
        return $this->boundedInteger($value, 1, 65535, 'port');
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum, string $key): int
    {
        if (! is_numeric($value) || (int) $value != $value || (int) $value < $minimum || (int) $value > $maximum) {
            throw ValidationException::withMessages([$key => 'Enter a value inside the approved operating range.']);
        }

        return (int) $value;
    }

    private function dnsName(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || strlen($value) > 253
            || preg_match('/^[a-z0-9_*-]+(?:\.[a-z0-9_-]+)*\.?$/i', $value) !== 1) {
            throw ValidationException::withMessages(['dns_name' => 'Enter an approved DNS query name.']);
        }

        return $value;
    }

    private function dnsType(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));
        if (! in_array($value, ['A', 'AAAA', 'CNAME', 'MX', 'TXT'], true)) {
            throw ValidationException::withMessages(['dns_type' => 'Select an approved DNS record type.']);
        }

        return $value;
    }

    /** @return list<int> */
    private function statusList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 20) {
            throw ValidationException::withMessages(['expected_status' => 'Enter one or more approved HTTP status codes.']);
        }
        $statuses = collect($value)->map(fn (mixed $status): int => $this->boundedInteger(
            $status,
            100,
            599,
            'expected_status',
        ))->unique()->values()->all();

        return $statuses;
    }

    /** @return list<string> */
    private function stringList(mixed $value, int $maximumItems, int $maximumLength, string $key): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maximumItems) {
            throw ValidationException::withMessages([$key => 'The submitted list is outside the approved bounds.']);
        }
        $values = collect($value)
            ->map(fn (mixed $item): string => is_string($item) ? trim($item) : '')
            ->filter()
            ->unique()
            ->values()
            ->all();
        if (collect($values)->contains(fn (string $item): bool => strlen($item) > $maximumLength
            || preg_match('/[\x00-\x1f\x7f]/', $item) === 1)) {
            throw ValidationException::withMessages([$key => 'The submitted list contains an invalid value.']);
        }

        return $values;
    }

    /** @param list<string> $protocols @return array<string, list<int>> */
    private function portBounds(mixed $value, array $protocols): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw ValidationException::withMessages(['port_bounds' => 'Enter approved ports by protocol.']);
        }
        $bounds = [];
        foreach ($value as $protocol => $ports) {
            if (! is_string($protocol) || ! in_array($protocol, $protocols, true)
                || ! is_array($ports) || ! array_is_list($ports)) {
                throw ValidationException::withMessages(['port_bounds' => 'Enter approved ports by selected protocol.']);
            }
            $bounds[$protocol] = collect($ports)
                ->map(fn (mixed $port): int => $this->port($port))
                ->unique()
                ->sort()
                ->values()
                ->all();
        }
        if (collect($bounds)->flatten()->count() > 128) {
            throw ValidationException::withMessages(['port_bounds' => 'The combined port allowlist is too large.']);
        }

        return $bounds;
    }

    /** @param list<string> $capabilities */
    private function credentialReference(int $siteId, mixed $value, array $capabilities): string
    {
        $reference = is_string($value) ? trim($value) : '';
        if ($reference === '' || preg_match('/^[a-z][a-z0-9._-]{1,31}:[A-Za-z0-9._\/:@-]{1,158}$/', $reference) !== 1
            || str_contains($reference, '://')) {
            throw ValidationException::withMessages(['credential_reference' => 'Select an active Site credential reference.']);
        }
        $record = CredentialReference::query()
            ->where('site_id', $siteId)
            ->where('reference_key', $reference)
            ->where('status', CredentialReferenceStatus::Active->value)
            ->whereNotIn('rotation_status', [
                CredentialRotationStatus::Overdue->value,
                CredentialRotationStatus::Failed->value,
            ])
            ->lockForUpdate()
            ->first(['id', 'capabilities']);
        if ($record === null || array_diff($capabilities, $record->capabilities ?? []) !== []) {
            throw ValidationException::withMessages(['credential_reference' => 'Select an active Site credential reference with the required capability.']);
        }

        return $reference;
    }

    private function inventoryProfile(mixed $value, string $platform): string
    {
        try {
            $profile = InventoryQuery::fromProfile(trim((string) $value));
        } catch (Throwable) {
            throw ValidationException::withMessages(['inventory_profile' => 'Select an approved read-only inventory profile.']);
        }
        if ($profile->platform !== $platform) {
            throw ValidationException::withMessages(['inventory_profile' => 'The inventory profile does not match this protocol.']);
        }

        return $profile->profile;
    }

    private function sshFingerprint(mixed $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/', $value) !== 1) {
            throw ValidationException::withMessages(['host_key_fingerprint' => 'Enter the pinned SHA-256 host-key fingerprint.']);
        }

        return $value;
    }
}
