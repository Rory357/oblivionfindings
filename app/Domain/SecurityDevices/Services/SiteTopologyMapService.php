<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\Monitoring\Discovery\Models\DeviceIdentityEvidence;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Support\MacVendorLookup;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Topology\Data\TopologyEvidence;
use App\Domain\Monitoring\Topology\Models\TopologyEdge;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use App\Domain\Monitoring\Topology\Services\TopologySnapshotBuilder;
use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\RelationshipType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reconciles Site-hub discovery topology signals (IEEE 802.1AB LLDP, Cisco CDP,
 * RFC 4188/4363 Bridge FDB, RFC 4293 ARP/gateway tables, and Ubiquiti UniFi Local API)
 * into immutable TopologySnapshots + governed DeviceRelationships, and projects the
 * per-Site hierarchical network tree (`gateway -> switches -> access points -> devices`).
 */
final class SiteTopologyMapService
{
    public const string AUTO_NOTE_PREFIX = '[auto-topology]';

    private const array TIER_RANK = [
        'gateway' => 0,
        'switch' => 1,
        'access_point' => 2,
        'device' => 3,
    ];

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly TopologySnapshotBuilder $snapshotBuilder,
    ) {}

    public static function classifyTier(Device|array $device): string
    {
        $domain = is_array($device) ? (string) ($device['domain'] ?? '') : (string) $device->domain;
        $category = strtolower(is_array($device) ? (string) ($device['category'] ?? '') : (string) $device->category);
        $subcategory = strtolower(is_array($device) ? (string) ($device['subcategory'] ?? '') : (string) $device->subcategory);
        $name = strtolower(is_array($device) ? (string) ($device['name'] ?? '') : (string) $device->name);
        $model = strtolower(is_array($device) ? (string) ($device['model'] ?? '') : (string) $device->model);
        $combined = trim("{$subcategory} {$model} {$name}");

        if ($domain === DeviceDomain::ItInfrastructure->value || $category === 'network') {
            if (in_array($subcategory, ['gateway', 'router', 'firewall', 'sd_wan', 'sd-wan', 'wan', 'edge'], true)
                || ($category === 'network' && Str::contains($combined, [
                    'gateway', 'router', 'firewall', 'sd-wan', 'ux7', 'unifi express', 'udm', 'usg', 'edgerouter', 'fortigate', 'cloud gateway',
                ]))) {
                return 'gateway';
            }

            if ($subcategory === 'switch'
                || ($category === 'network' && Str::contains($combined, [
                    'switch', 'usw-', 'usw ', 'catalyst', 'procurve', 'crs3', 'css3',
                ]))) {
                return 'switch';
            }

            if (in_array($subcategory, ['access_point', 'access-point', 'wireless', 'wifi', 'wap', 'ap'], true)
                || ($category === 'network' && Str::contains($combined, [
                    'access point', 'uap', 'u6-', 'u7-', 'nanohd', 'ac-pro', 'ac-lr', 'wifi ap', 'wireless ap',
                ]))) {
                return 'access_point';
            }
        }

        return 'device';
    }

    public function reconcileSite(int $siteId, ?int $actorId = null): ?TopologySnapshot
    {
        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('archived')->orWhere('archived', false))
            ->whereNull('archived_at')
            ->first();
        if ($site === null) {
            return null;
        }

        $devices = $this->activeSiteDevices($siteId);
        if ($devices->count() < 2) {
            return null;
        }

        $indexes = $this->indexSiteDevices($devices);
        $candidates = DiscoveryCandidate::query()
            ->whereHas('run.scope', fn ($q) => $q->where('site_id', $siteId))
            ->orderByDesc('id')
            ->limit(512)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $signalsByDeviceId = [];
        foreach ($candidates as $candidate) {
            $snapshot = is_array($candidate->evidence_snapshot) ? $candidate->evidence_snapshot : [];
            $signals = is_array($snapshot['signals'] ?? null) ? $snapshot['signals'] : [];
            if ($signals === []) {
                continue;
            }

            $deviceId = $candidate->canonical_device_id !== null && $indexes['by_id']->has((int) $candidate->canonical_device_id)
                ? (int) $candidate->canonical_device_id
                : $this->matchDeviceFromSnapshot($snapshot, $indexes);
            if ($deviceId === null || isset($signalsByDeviceId[$deviceId])) {
                continue;
            }
            $signalsByDeviceId[$deviceId] = [
                'signals' => $signals,
                'observed_at' => $candidate->updated_at ? CarbonImmutable::instance($candidate->updated_at)->utc() : CarbonImmutable::now('UTC'),
            ];
        }

        if ($signalsByDeviceId === []) {
            return null;
        }

        /**
         * Best parent edge per child device id (`childId => edgeSpec`) so each child has a single deterministic primary uplink.
         *
         * @var array<int, array{
         *     parent_id: int,
         *     child_id: int,
         *     source: string,
         *     kind: string,
         *     local_port: ?string,
         *     remote_port: ?string,
         *     confidence: float,
         *     priority: int,
         *     evidence: array<string, scalar|null>,
         *     observed_at: CarbonImmutable,
         *     sync_relationship: bool,
         *     port_summary: ?string,
         *     note_summary: string
         * }> $bestByChild
         */
        $bestByChild = [];

        $considerEdge = function (array $spec) use (&$bestByChild): void {
            $parentId = $spec['parent_id'];
            $childId = $spec['child_id'];
            if ($parentId === $childId || $parentId < 1 || $childId < 1) {
                return;
            }
            // Prevent immediate 2-node cycles when symmetric evidence exists
            if (isset($bestByChild[$parentId]) && $bestByChild[$parentId]['parent_id'] === $childId) {
                if ($spec['priority'] <= $bestByChild[$parentId]['priority']) {
                    return;
                }
                unset($bestByChild[$parentId]);
            }
            if (! isset($bestByChild[$childId]) || $spec['priority'] > $bestByChild[$childId]['priority']) {
                $bestByChild[$childId] = $spec;
            }
        };

        foreach ($signalsByDeviceId as $deviceId => $entry) {
            /** @var Device $device */
            $device = $indexes['by_id']->get($deviceId);
            $signals = $entry['signals'];
            $observedAt = $entry['observed_at'];

            // 1. Ubiquiti UniFi Local API uplink (`source = provider`, `provider = unifi`, priority 100)
            if (is_array($signals['unifi_uplink'] ?? null)) {
                $u = $signals['unifi_uplink'];
                $parentId = $this->resolvePeerDeviceId(
                    mac: is_string($u['uplink_mac'] ?? null) ? $u['uplink_mac'] : null,
                    ip: is_string($u['uplink_ip'] ?? null) ? $u['uplink_ip'] : null,
                    name: null,
                    indexes: $indexes,
                );
                if ($parentId !== null && $parentId !== $deviceId) {
                    $kind = ($u['kind'] ?? 'ethernet') === 'wireless' ? 'wireless' : 'uplink';
                    $port = is_string($u['uplink_port'] ?? null) ? $u['uplink_port'] : null;
                    $considerEdge([
                        'parent_id' => $parentId,
                        'child_id' => $deviceId,
                        'source' => 'provider',
                        'kind' => $kind,
                        'local_port' => $port,
                        'remote_port' => null,
                        'confidence' => 0.96,
                        'priority' => 100,
                        'evidence' => array_filter([
                            'provider' => 'unifi',
                            'uplink_mac' => is_string($u['uplink_mac'] ?? null) ? $u['uplink_mac'] : null,
                            'ssid' => is_string($u['ssid'] ?? null) ? $u['ssid'] : null,
                        ], fn ($v) => $v !== null),
                        'observed_at' => $observedAt,
                        'sync_relationship' => true,
                        'port_summary' => $port !== null ? "{$port} (UniFi)" : 'UniFi uplink',
                        'note_summary' => self::AUTO_NOTE_PREFIX.' Discovered via UniFi local controller API',
                    ]);
                }
            }

            // 2. IEEE 802.1AB LLDP neighbours (`source = lldp`, priority 95)
            if (is_array($signals['lldp_neighbors'] ?? null)) {
                foreach ($signals['lldp_neighbors'] as $nbr) {
                    if (! is_array($nbr)) {
                        continue;
                    }
                    $peerId = $this->resolvePeerDeviceId(
                        mac: is_string($nbr['remote_mac'] ?? null) ? $nbr['remote_mac'] : null,
                        ip: null,
                        name: is_string($nbr['remote_name'] ?? null) ? $nbr['remote_name'] : null,
                        indexes: $indexes,
                    );
                    if ($peerId === null || $peerId === $deviceId) {
                        continue;
                    }
                    /** @var Device $peer */
                    $peer = $indexes['by_id']->get($peerId);
                    [$parentId, $childId, $parentPort, $childPort] = $this->orientPair(
                        $device,
                        $peer,
                        is_string($nbr['local_port'] ?? null) ? $nbr['local_port'] : null,
                        is_string($nbr['remote_port'] ?? null) ? $nbr['remote_port'] : null,
                    );
                    $portSummary = collect([$parentPort, $childPort])->filter()->implode(' -> ');
                    $considerEdge([
                        'parent_id' => $parentId,
                        'child_id' => $childId,
                        'source' => 'lldp',
                        'kind' => 'ethernet',
                        'local_port' => $parentPort,
                        'remote_port' => $childPort,
                        'confidence' => 0.95,
                        'priority' => 95,
                        'evidence' => array_filter([
                            'protocol' => 'ieee_802_1ab_lldp',
                            'remote_mac' => is_string($nbr['remote_mac'] ?? null) ? $nbr['remote_mac'] : null,
                            'remote_name' => is_string($nbr['remote_name'] ?? null) ? $nbr['remote_name'] : null,
                        ], fn ($v) => $v !== null),
                        'observed_at' => $observedAt,
                        'sync_relationship' => true,
                        'port_summary' => $portSummary !== '' ? "{$portSummary} (LLDP)" : 'LLDP',
                        'note_summary' => self::AUTO_NOTE_PREFIX.' Discovered via IEEE 802.1AB LLDP',
                    ]);
                }
            }

            // 3. Cisco CDP neighbours (`source = cdp`, priority 90)
            if (is_array($signals['cdp_neighbors'] ?? null)) {
                foreach ($signals['cdp_neighbors'] as $nbr) {
                    if (! is_array($nbr)) {
                        continue;
                    }
                    $peerId = $this->resolvePeerDeviceId(
                        mac: null,
                        ip: is_string($nbr['remote_ip'] ?? null) ? $nbr['remote_ip'] : null,
                        name: is_string($nbr['remote_name'] ?? null) ? $nbr['remote_name'] : null,
                        indexes: $indexes,
                    );
                    if ($peerId === null || $peerId === $deviceId) {
                        continue;
                    }
                    /** @var Device $peer */
                    $peer = $indexes['by_id']->get($peerId);
                    [$parentId, $childId, $parentPort, $childPort] = $this->orientPair(
                        $device,
                        $peer,
                        is_string($nbr['local_port'] ?? null) ? $nbr['local_port'] : null,
                        is_string($nbr['remote_port'] ?? null) ? $nbr['remote_port'] : null,
                    );
                    $portSummary = collect([$parentPort, $childPort])->filter()->implode(' -> ');
                    $considerEdge([
                        'parent_id' => $parentId,
                        'child_id' => $childId,
                        'source' => 'cdp',
                        'kind' => 'ethernet',
                        'local_port' => $parentPort,
                        'remote_port' => $childPort,
                        'confidence' => 0.93,
                        'priority' => 90,
                        'evidence' => array_filter([
                            'protocol' => 'cisco_cdp',
                            'remote_ip' => is_string($nbr['remote_ip'] ?? null) ? $nbr['remote_ip'] : null,
                            'platform' => is_string($nbr['platform'] ?? null) ? $nbr['platform'] : null,
                        ], fn ($v) => $v !== null),
                        'observed_at' => $observedAt,
                        'sync_relationship' => true,
                        'port_summary' => $portSummary !== '' ? "{$portSummary} (CDP)" : 'CDP',
                        'note_summary' => self::AUTO_NOTE_PREFIX.' Discovered via Cisco CDP',
                    ]);
                }
            }

            // 4. RFC 4188 / RFC 4363 Bridge FDB MAC table (`source = forwarding_table`, priority 75)
            if (is_array($signals['bridge_fdb'] ?? null) && in_array(self::classifyTier($device), ['gateway', 'switch', 'access_point'], true)) {
                $myTierRank = self::TIER_RANK[self::classifyTier($device)];
                foreach ($signals['bridge_fdb'] as $fdb) {
                    if (! is_array($fdb)) {
                        continue;
                    }
                    $peerId = $this->resolvePeerDeviceId(
                        mac: is_string($fdb['mac'] ?? null) ? $fdb['mac'] : null,
                        ip: null,
                        name: null,
                        indexes: $indexes,
                    );
                    if ($peerId === null || $peerId === $deviceId) {
                        continue;
                    }
                    /** @var Device $peer */
                    $peer = $indexes['by_id']->get($peerId);
                    $peerTierRank = self::TIER_RANK[self::classifyTier($peer)];
                    if ($peerTierRank < $myTierRank) {
                        // Do not invert an uplink port where the upstream gateway MAC was learned.
                        continue;
                    }
                    $port = is_string($fdb['port'] ?? null) ? $fdb['port'] : null;
                    $considerEdge([
                        'parent_id' => $deviceId,
                        'child_id' => $peerId,
                        'source' => 'forwarding_table',
                        'kind' => 'ethernet',
                        'local_port' => $port,
                        'remote_port' => null,
                        'confidence' => 0.82,
                        'priority' => 75,
                        'evidence' => array_filter([
                            'protocol' => 'bridge_mib_fdb',
                            'learned_mac' => is_string($fdb['mac'] ?? null) ? $fdb['mac'] : null,
                        ], fn ($v) => $v !== null),
                        'observed_at' => $observedAt,
                        'sync_relationship' => true,
                        'port_summary' => $port !== null ? "{$port} (Bridge FDB)" : 'Bridge FDB',
                        'note_summary' => self::AUTO_NOTE_PREFIX.' Discovered via SNMP Bridge FDB table',
                    ]);
                }
            }

            // 5. ARP / Subnet Default Gateway (`source = arp`, priority 40)
            $gwIp = is_string($signals['arp_gateway_ip'] ?? null) ? $signals['arp_gateway_ip'] : null;
            $gwMac = is_string($signals['arp_gateway_mac'] ?? null) ? $signals['arp_gateway_mac'] : null;
            if ($gwIp !== null || $gwMac !== null) {
                $gwId = $this->resolvePeerDeviceId(mac: $gwMac, ip: $gwIp, name: null, indexes: $indexes);
                if ($gwId !== null && $gwId !== $deviceId) {
                    $considerEdge([
                        'parent_id' => $gwId,
                        'child_id' => $deviceId,
                        'source' => 'arp',
                        'kind' => 'observed_path',
                        'local_port' => null,
                        'remote_port' => null,
                        'confidence' => 0.65,
                        'priority' => 40,
                        'evidence' => array_filter([
                            'gateway_ip' => $gwIp,
                            'gateway_mac' => $gwMac,
                        ], fn ($v) => $v !== null),
                        'observed_at' => $observedAt,
                        'sync_relationship' => false,
                        'port_summary' => null,
                        'note_summary' => self::AUTO_NOTE_PREFIX.' Discovered via Site LAN ARP / default gateway table',
                    ]);
                }
            }
        }

        if ($bestByChild === []) {
            return null;
        }

        ksort($bestByChild);
        $evidenceItems = [];
        $checkpointPayload = [];
        foreach ($bestByChild as $spec) {
            $evidenceItems[] = new TopologyEvidence(
                source: $spec['source'],
                fromDeviceId: $spec['parent_id'],
                toDeviceId: $spec['child_id'],
                kind: $spec['kind'],
                localPort: $spec['local_port'],
                remotePort: $spec['remote_port'],
                confidence: $spec['confidence'],
                evidence: $spec['evidence'],
                observedAt: $spec['observed_at'],
            );
            $checkpointPayload[] = [
                'p' => $spec['parent_id'],
                'c' => $spec['child_id'],
                's' => $spec['source'],
                'k' => $spec['kind'],
                'lp' => $spec['local_port'],
                'rp' => $spec['remote_port'],
            ];
        }

        $checkpoint = 'hub-discovery:'.hash('sha256', json_encode($checkpointPayload, JSON_THROW_ON_ERROR));
        $snapshot = null;
        try {
            $snapshot = $this->snapshotBuilder->build(
                $site,
                $evidenceItems,
                source: 'hub:discovery',
                sourceCheckpoint: $checkpoint,
            );
        } catch (Throwable) {
            $snapshot = null;
        }

        if ($actorId !== null && User::query()->whereKey($actorId)->exists()) {
            $this->syncAutoDeviceRelationships($siteId, $bestByChild, $actorId);
        }

        return $snapshot;
    }

    /**
     * Build the hierarchical Site network tree (`gateway -> switches -> access points -> devices`)
     * for `EstateOperationsPresenter::site()`.
     *
     * @param  Collection<int, Device>  $devices
     * @param  Collection<int, array<string, mixed>>  $deviceRows
     * @param  Collection<int, DeviceRelationship>  $relationships
     * @return array<string, mixed>
     */
    public function buildSiteTree(
        Site $site,
        Collection $devices,
        Collection $deviceRows,
        Collection $relationships,
    ): array {
        $rowsById = $deviceRows->keyBy('id');
        $devicesById = $devices->keyBy('id');
        $deviceIds = $devices->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $tiersById = [];
        $tierCounts = [
            'gateways' => 0,
            'switches' => 0,
            'access_points' => 0,
            'devices' => 0,
        ];

        foreach ($devices as $device) {
            $id = (int) $device->id;
            $tier = self::classifyTier($device);
            $tiersById[$id] = $tier;
            match ($tier) {
                'gateway' => $tierCounts['gateways']++,
                'switch' => $tierCounts['switches']++,
                'access_point' => $tierCounts['access_points']++,
                default => $tierCounts['devices']++,
            };
        }

        /** @var array<int, array{parent_id: int, protocol: string, label: string, port: ?string, priority: int}> $uplinkByChild */
        $uplinkByChild = [];
        $sourcesSeen = [];

        // 1. Explicit DeviceRelationship edges (manual or auto-mapped)
        foreach ($relationships as $rel) {
            $parentId = (int) $rel->parent_device_id;
            $childId = (int) $rel->child_device_id;
            if (! isset($devicesById[$parentId], $devicesById[$childId]) || $parentId === $childId) {
                continue;
            }
            $isAuto = is_string($rel->notes) && str_starts_with($rel->notes, self::AUTO_NOTE_PREFIX);
            $protocol = $isAuto ? $this->protocolFromAutoNote($rel->notes, $rel->port) : 'manual';
            $priority = $isAuto ? 90 : 110;
            $sourcesSeen[$protocol] = true;

            if (! isset($uplinkByChild[$childId]) || $priority > $uplinkByChild[$childId]['priority']) {
                $uplinkByChild[$childId] = [
                    'parent_id' => $parentId,
                    'protocol' => $protocol,
                    'label' => $rel->port ?: ($rel->relationship_type?->label() ?? 'Connected'),
                    'port' => $rel->port,
                    'priority' => $priority,
                ];
            }
        }

        // 2. Latest completed TopologySnapshot edges for this Site
        $latestSnapshots = $deviceIds === []
            ? collect()
            : TopologySnapshot::query()
                ->where('site_id', $site->id)
                ->where('status', 'completed')
                ->where('source', 'not like', 'native:snmp:%:device:%')
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->limit(8)
                ->with([
                    'edges' => fn ($q) => $q
                        ->whereHas('fromNode', fn ($n) => $n->whereIn('canonical_device_id', $deviceIds))
                        ->whereHas('toNode', fn ($n) => $n->whereIn('canonical_device_id', $deviceIds))
                        ->with(['fromNode:id,canonical_device_id', 'toNode:id,canonical_device_id']),
                ])
                ->get();

        $lastCapturedAt = $latestSnapshots->first()?->captured_at?->toIso8601String();
        $seenSnapshotSources = [];
        foreach ($latestSnapshots as $snap) {
            if (isset($seenSnapshotSources[$snap->source])) {
                continue;
            }
            $seenSnapshotSources[$snap->source] = true;
            foreach ($snap->edges as $edge) {
                $parentId = (int) ($edge->fromNode?->canonical_device_id ?? 0);
                $childId = (int) ($edge->toNode?->canonical_device_id ?? 0);
                if (! isset($devicesById[$parentId], $devicesById[$childId]) || $parentId === $childId) {
                    continue;
                }
                $provider = is_string(data_get($edge->evidence, 'provider')) ? data_get($edge->evidence, 'provider') : null;
                $protocol = $edge->source === 'provider' && $provider === 'unifi'
                    ? 'unifi'
                    : ($edge->source === 'forwarding_table' ? 'bridge' : (string) $edge->source);
                $priority = match ($protocol) {
                    'unifi' => 98,
                    'lldp' => 95,
                    'cdp' => 92,
                    'bridge' => 80,
                    'arp', 'route' => 50,
                    default => 60,
                };
                $port = collect([$edge->local_port, $edge->remote_port])->filter()->implode(' -> ') ?: null;
                $label = match ($protocol) {
                    'unifi' => $port !== null ? "UniFi · {$port}" : 'UniFi uplink',
                    'lldp' => $port !== null ? "LLDP · {$port}" : 'LLDP neighbour',
                    'cdp' => $port !== null ? "CDP · {$port}" : 'CDP neighbour',
                    'bridge' => $port !== null ? "Switch port · {$port}" : 'Bridge MAC table',
                    'arp', 'route' => 'LAN via gateway (ARP)',
                    default => $port ?? Str::headline($protocol),
                };
                $sourcesSeen[$protocol] = true;

                if (! isset($uplinkByChild[$childId]) || $priority > $uplinkByChild[$childId]['priority']) {
                    $uplinkByChild[$childId] = [
                        'parent_id' => $parentId,
                        'protocol' => $protocol,
                        'label' => $label,
                        'port' => $port,
                        'priority' => $priority,
                    ];
                }
            }
        }

        // 3. Honest L2 /24 subnet-gateway placement when a Site gateway shares a private IPv4 /24 with unlinked Site devices
        $gatewaysBySubnet24 = [];
        foreach ($devices as $device) {
            if (($tiersById[(int) $device->id] ?? 'device') !== 'gateway') {
                continue;
            }
            $prefix24 = $this->ipv4Prefix24($device->ip_address);
            if ($prefix24 !== null) {
                $gatewaysBySubnet24[$prefix24][] = (int) $device->id;
            }
        }

        foreach ($devices as $device) {
            $id = (int) $device->id;
            if (isset($uplinkByChild[$id]) || ($tiersById[$id] ?? 'device') === 'gateway') {
                continue;
            }
            $prefix24 = $this->ipv4Prefix24($device->ip_address);
            if ($prefix24 !== null && count($gatewaysBySubnet24[$prefix24] ?? []) === 1) {
                $gwId = $gatewaysBySubnet24[$prefix24][0];
                if ($gwId !== $id) {
                    $sourcesSeen['lan_subnet'] = true;
                    $uplinkByChild[$id] = [
                        'parent_id' => $gwId,
                        'protocol' => 'lan_subnet',
                        'label' => "LAN subnet ({$prefix24}.0/24)",
                        'port' => null,
                        'priority' => 20,
                    ];
                }
            }
        }

        // Break any accidental multi-hop cycle deterministically
        foreach (array_keys($uplinkByChild) as $childId) {
            $cursor = $uplinkByChild[$childId]['parent_id'] ?? null;
            $seenChain = [$childId => true];
            while ($cursor !== null) {
                if (isset($seenChain[$cursor])) {
                    unset($uplinkByChild[$childId]);
                    break;
                }
                $seenChain[$cursor] = true;
                $cursor = $uplinkByChild[$cursor]['parent_id'] ?? null;
            }
        }

        $childrenByParent = [];
        foreach ($uplinkByChild as $childId => $uplink) {
            $childrenByParent[$uplink['parent_id']][] = $childId;
        }

        $sortIds = function (array $ids) use ($tiersById, $devicesById): array {
            usort($ids, function (int $a, int $b) use ($tiersById, $devicesById): int {
                $rankCmp = (self::TIER_RANK[$tiersById[$a] ?? 'device'] ?? 3) <=> (self::TIER_RANK[$tiersById[$b] ?? 'device'] ?? 3);
                if ($rankCmp !== 0) {
                    return $rankCmp;
                }

                return strcasecmp((string) ($devicesById[$a]?->name ?? ''), (string) ($devicesById[$b]?->name ?? ''));
            });

            return $ids;
        };

        $visited = [];
        $buildNode = function (int $id) use (&$buildNode, &$visited, $childrenByParent, $sortIds, $devicesById, $rowsById, $tiersById, $uplinkByChild): ?array {
            if (isset($visited[$id]) || ! isset($devicesById[$id])) {
                return null;
            }
            $visited[$id] = true;
            /** @var Device $dev */
            $dev = $devicesById[$id];
            $row = $rowsById->get($id, []);
            $childIds = $sortIds($childrenByParent[$id] ?? []);
            $children = [];
            foreach ($childIds as $cid) {
                $built = $buildNode($cid);
                if ($built !== null) {
                    $children[] = $built;
                }
            }

            $uplink = $uplinkByChild[$id] ?? null;

            return [
                'id' => $id,
                'device_uid' => $dev->device_uid,
                'name' => $dev->name,
                'tier' => $tiersById[$id] ?? 'device',
                'domain' => $dev->domain,
                'category' => $dev->category,
                'subcategory' => $dev->subcategory,
                'manufacturer' => $dev->manufacturer,
                'model' => $dev->model,
                'ip_address' => $dev->ip_address,
                'mac_address' => $dev->mac_address,
                'status' => $row['status'] ?? $dev->status?->value ?? 'unknown',
                'health' => $row['health'] ?? $dev->health_status?->value ?? 'unknown',
                'href' => "/security-devices/devices/{$id}",
                'uplink' => $uplink === null ? null : [
                    'protocol' => $uplink['protocol'],
                    'label' => $uplink['label'],
                    'port' => $uplink['port'],
                ],
                'children' => $children,
            ];
        };

        $rootCandidateIds = [];
        $unplacedCandidateIds = [];
        foreach ($devices as $device) {
            $id = (int) $device->id;
            if (isset($uplinkByChild[$id])) {
                continue;
            }
            $tier = $tiersById[$id] ?? 'device';
            $hasChildren = ! empty($childrenByParent[$id]);
            if (in_array($tier, ['gateway', 'switch', 'access_point'], true) || $hasChildren) {
                $rootCandidateIds[] = $id;
            } else {
                $unplacedCandidateIds[] = $id;
            }
        }

        $roots = [];
        foreach ($sortIds($rootCandidateIds) as $rootId) {
            $built = $buildNode($rootId);
            if ($built !== null) {
                $roots[] = $built;
            }
        }

        $unplaced = [];
        foreach ($sortIds($unplacedCandidateIds) as $unplacedId) {
            $built = $buildNode($unplacedId);
            if ($built !== null) {
                $unplaced[] = $built;
            }
        }

        return [
            'tier_counts' => $tierCounts,
            'sources' => array_keys($sourcesSeen),
            'last_captured_at' => $lastCapturedAt,
            'mapped_count' => count($devices) - count($unplaced),
            'roots' => $roots,
            'unplaced' => $unplaced,
        ];
    }

    /**
     * Return upstream and downstream topology neighbours for a Network device profile,
     * filtered strictly to `$visibleDeviceIds`.
     *
     * @param  Collection<int, int>  $visibleDeviceIds
     * @return array{
     *     upstream: list<array{id: int, name: string, category: ?string, subcategory: ?string, ip_address: ?string, status: string, port: ?string, protocol: string, href: string}>,
     *     downstream: list<array{id: int, name: string, category: ?string, subcategory: ?string, ip_address: ?string, status: string, port: ?string, protocol: string, href: string}>
     * }
     */
    public function neighboursForDevice(Device $device, Collection $visibleDeviceIds, ?int $canonicalSiteId): array
    {
        $visibleLookup = $visibleDeviceIds->flip();
        $upstream = [];
        $downstream = [];

        if ($device->relationLoaded('parentRelationships')) {
            foreach ($device->parentRelationships as $rel) {
                $pid = (int) $rel->parent_device_id;
                if (! $visibleLookup->has($pid) || $rel->parent === null) {
                    continue;
                }
                $isAuto = is_string($rel->notes) && str_starts_with($rel->notes, self::AUTO_NOTE_PREFIX);
                $upstream[$pid] = [
                    'id' => $pid,
                    'name' => $rel->parent->name,
                    'category' => $rel->parent->category,
                    'subcategory' => $rel->parent->subcategory,
                    'ip_address' => $rel->parent->ip_address,
                    'status' => $rel->parent->status?->value ?? 'unknown',
                    'port' => $rel->port,
                    'protocol' => $isAuto ? $this->protocolFromAutoNote($rel->notes, $rel->port) : 'manual',
                    'href' => "/security-devices/devices/{$pid}",
                ];
            }
        }

        if ($device->relationLoaded('childRelationships')) {
            foreach ($device->childRelationships as $rel) {
                $cid = (int) $rel->child_device_id;
                if (! $visibleLookup->has($cid) || $rel->child === null) {
                    continue;
                }
                $isAuto = is_string($rel->notes) && str_starts_with($rel->notes, self::AUTO_NOTE_PREFIX);
                $downstream[$cid] = [
                    'id' => $cid,
                    'name' => $rel->child->name,
                    'category' => $rel->child->category,
                    'subcategory' => $rel->child->subcategory,
                    'ip_address' => $rel->child->ip_address,
                    'status' => $rel->child->status?->value ?? 'unknown',
                    'port' => $rel->port,
                    'protocol' => $isAuto ? $this->protocolFromAutoNote($rel->notes, $rel->port) : 'manual',
                    'href' => "/security-devices/devices/{$cid}",
                ];
            }
        }

        if ($canonicalSiteId !== null) {
            $latestSnapshot = TopologySnapshot::query()
                ->where('site_id', $canonicalSiteId)
                ->where('status', 'completed')
                ->where('source', 'not like', 'native:snmp:%:device:%')
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->first();

            if ($latestSnapshot !== null) {
                $edges = TopologyEdge::query()
                    ->where('topology_snapshot_id', $latestSnapshot->id)
                    ->where(fn ($q) => $q
                        ->whereHas('fromNode', fn ($n) => $n->where('canonical_device_id', $device->id))
                        ->orWhereHas('toNode', fn ($n) => $n->where('canonical_device_id', $device->id)))
                    ->with(['fromNode:id,canonical_device_id', 'toNode:id,canonical_device_id'])
                    ->limit(64)
                    ->get();

                $peerIds = $edges
                    ->flatMap(fn (TopologyEdge $e): array => [
                        (int) ($e->fromNode?->canonical_device_id ?? 0),
                        (int) ($e->toNode?->canonical_device_id ?? 0),
                    ])
                    ->filter(fn (int $id): bool => $id > 0 && $id !== (int) $device->id && $visibleLookup->has($id))
                    ->unique()
                    ->values();

                if ($peerIds->isNotEmpty()) {
                    $peers = Device::query()
                        ->whereIn('id', $peerIds)
                        ->get(['id', 'name', 'category', 'subcategory', 'ip_address', 'status'])
                        ->keyBy('id');

                    foreach ($edges as $edge) {
                        $fromId = (int) ($edge->fromNode?->canonical_device_id ?? 0);
                        $toId = (int) ($edge->toNode?->canonical_device_id ?? 0);
                        $provider = is_string(data_get($edge->evidence, 'provider')) ? data_get($edge->evidence, 'provider') : null;
                        $protocol = $edge->source === 'provider' && $provider === 'unifi'
                            ? 'unifi'
                            : ($edge->source === 'forwarding_table' ? 'bridge' : (string) $edge->source);
                        $port = collect([$edge->local_port, $edge->remote_port])->filter()->implode(' -> ') ?: null;

                        if ($toId === (int) $device->id && ! isset($upstream[$fromId]) && $peers->has($fromId)) {
                            $p = $peers->get($fromId);
                            $upstream[$fromId] = [
                                'id' => $fromId,
                                'name' => $p->name,
                                'category' => $p->category,
                                'subcategory' => $p->subcategory,
                                'ip_address' => $p->ip_address,
                                'status' => $p->status?->value ?? 'unknown',
                                'port' => $port,
                                'protocol' => $protocol,
                                'href' => "/security-devices/devices/{$fromId}",
                            ];
                        } elseif ($fromId === (int) $device->id && ! isset($downstream[$toId]) && $peers->has($toId)) {
                            $c = $peers->get($toId);
                            $downstream[$toId] = [
                                'id' => $toId,
                                'name' => $c->name,
                                'category' => $c->category,
                                'subcategory' => $c->subcategory,
                                'ip_address' => $c->ip_address,
                                'status' => $c->status?->value ?? 'unknown',
                                'port' => $port,
                                'protocol' => $protocol,
                                'href' => "/security-devices/devices/{$toId}",
                            ];
                        }
                    }
                }
            }

            $devicePrefix = $this->ipv4Prefix24($device->ip_address);
            if ($devicePrefix !== null) {
                $myTier = self::classifyTier($device);
                $sitePeers = $this->activeSiteDevices($canonicalSiteId)
                    ->filter(fn (Device $peer): bool => (int) $peer->id !== (int) $device->id && $visibleLookup->has((int) $peer->id));

                if ($myTier === 'gateway') {
                    $explicitlyParentedChildIds = DeviceRelationship::query()
                        ->active()
                        ->whereIn('child_device_id', $sitePeers->pluck('id')->all())
                        ->pluck('child_device_id')
                        ->map(fn ($id): int => (int) $id)
                        ->flip();

                    foreach ($sitePeers as $peer) {
                        $pid = (int) $peer->id;
                        if (isset($downstream[$pid]) || isset($upstream[$pid]) || $explicitlyParentedChildIds->has($pid)) {
                            continue;
                        }
                        if (self::classifyTier($peer) === 'gateway' || $this->ipv4Prefix24($peer->ip_address) !== $devicePrefix) {
                            continue;
                        }
                        $downstream[$pid] = [
                            'id' => $pid,
                            'name' => $peer->name,
                            'category' => $peer->category,
                            'subcategory' => $peer->subcategory,
                            'ip_address' => $peer->ip_address,
                            'status' => $peer->status?->value ?? 'unknown',
                            'port' => "{$devicePrefix}.0/24",
                            'protocol' => 'lan_subnet',
                            'href' => "/security-devices/devices/{$pid}",
                        ];
                    }
                } elseif ($upstream === []) {
                    $gatewayPeer = $sitePeers->first(
                        fn (Device $peer): bool => self::classifyTier($peer) === 'gateway'
                            && $this->ipv4Prefix24($peer->ip_address) === $devicePrefix,
                    );
                    if ($gatewayPeer !== null) {
                        $gid = (int) $gatewayPeer->id;
                        $upstream[$gid] = [
                            'id' => $gid,
                            'name' => $gatewayPeer->name,
                            'category' => $gatewayPeer->category,
                            'subcategory' => $gatewayPeer->subcategory,
                            'ip_address' => $gatewayPeer->ip_address,
                            'status' => $gatewayPeer->status?->value ?? 'unknown',
                            'port' => "{$devicePrefix}.0/24",
                            'protocol' => 'lan_subnet',
                            'href' => "/security-devices/devices/{$gid}",
                        ];
                    }
                }
            }
        }

        return [
            'upstream' => array_values($upstream),
            'downstream' => array_values($downstream),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $bestByChild
     */
    private function syncAutoDeviceRelationships(int $siteId, array $bestByChild, int $actorId): void
    {
        foreach ($bestByChild as $childId => $spec) {
            if (! ($spec['sync_relationship'] ?? false)) {
                continue;
            }
            $parentId = (int) $spec['parent_id'];
            $desiredPort = $spec['port_summary'] !== null ? Str::limit($spec['port_summary'], 64, '') : null;

            $existingActive = DeviceRelationship::query()
                ->active()
                ->where('child_device_id', $childId)
                ->get();

            $hasManualParent = $existingActive->contains(
                fn (DeviceRelationship $r): bool => ! is_string($r->notes) || ! str_starts_with($r->notes, self::AUTO_NOTE_PREFIX),
            );
            if ($hasManualParent) {
                continue;
            }

            $exactMatch = $existingActive->first(
                fn (DeviceRelationship $r): bool => (int) $r->parent_device_id === $parentId
                    && $r->relationship_type === RelationshipType::ConnectedTo
                    && (string) ($r->port ?? '') === (string) ($desiredPort ?? ''),
            );
            if ($exactMatch !== null) {
                continue;
            }

            foreach ($existingActive as $stale) {
                if (is_string($stale->notes) && str_starts_with($stale->notes, self::AUTO_NOTE_PREFIX)) {
                    $stale->update([
                        'unlinked_at' => now(),
                        'unlinked_by_user_id' => $actorId,
                        'unlink_reason' => 'Superseded by updated Site hub topology scan.',
                    ]);
                }
            }

            $created = DeviceRelationship::query()->create([
                'parent_device_id' => $parentId,
                'child_device_id' => $childId,
                'relationship_type' => RelationshipType::ConnectedTo,
                'port' => $desiredPort,
                'notes' => $spec['note_summary'],
                'created_by_user_id' => $actorId,
            ]);

            AuditLogger::logOrFail('security_devices.topology.relationship_synced', $created, [
                'actor_id' => $actorId,
                'site_id' => $siteId,
                'parent_device_id' => $parentId,
                'child_device_id' => $childId,
                'source' => $spec['source'],
                'port' => $desiredPort,
            ]);
        }
    }

    /** @return Collection<int, Device> */
    private function activeSiteDevices(int $siteId): Collection
    {
        $roomIds = SiteRoom::query()->where('site_id', $siteId)->pluck('id')->all();

        $candidates = Device::query()
            ->where('status', '!=', DeviceStatus::Decommissioned->value)
            ->whereHas('assignments', function ($q) use ($siteId, $roomIds): void {
                $q->whereNull('released_at')
                    ->where(function ($sub) use ($siteId, $roomIds): void {
                        $sub->where(fn ($s) => $s->where('assignable_type', 'site')->where('assignable_id', $siteId));
                        if ($roomIds !== []) {
                            $sub->orWhere(fn ($r) => $r->where('assignable_type', 'room')->whereIn('assignable_id', $roomIds));
                        }
                    });
            })
            ->get();

        return $candidates->filter(function (Device $device) use ($siteId): bool {
            try {
                return $this->siteResolver->resolve((int) $device->id) === $siteId;
            } catch (Throwable) {
                return false;
            }
        })->values();
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array{
     *     by_id: Collection<int, Device>,
     *     by_mac: array<string, int>,
     *     by_ip: array<string, int>,
     *     by_name: array<string, int>,
     *     by_evidence_hash: array<string, int>
     * }
     */
    private function indexSiteDevices(Collection $devices): array
    {
        $byId = $devices->keyBy(fn (Device $d): int => (int) $d->id);
        $byMac = [];
        $byIp = [];
        $byName = [];
        $byEvidenceHash = [];

        foreach ($devices as $device) {
            $id = (int) $device->id;
            $mac = MacVendorLookup::normaliseUnicastMac($device->mac_address);
            if ($mac !== null) {
                $byMac[$mac] = $id;
            }
            if (is_string($device->ip_address) && filter_var($device->ip_address, FILTER_VALIDATE_IP) !== false) {
                $byIp[$device->ip_address] = $id;
            }
            $normName = $this->normaliseHostnameKey($device->name);
            if ($normName !== null) {
                $byName[$normName] ??= $id;
            }
        }

        $evidenceRows = DeviceIdentityEvidence::query()
            ->whereIn('canonical_device_id', $byId->keys()->all())
            ->where('is_active', true)
            ->whereIn('evidence_type', ['mac_address', 'address_history', 'hostname'])
            ->get(['canonical_device_id', 'evidence_type', 'value_hash']);

        foreach ($evidenceRows as $ev) {
            $devId = (int) $ev->canonical_device_id;
            $hash = (string) $ev->value_hash;
            if ($hash !== '') {
                $byEvidenceHash["{$ev->evidence_type}:{$hash}"] ??= $devId;
            }
        }

        return [
            'by_id' => $byId,
            'by_mac' => $byMac,
            'by_ip' => $byIp,
            'by_name' => $byName,
            'by_evidence_hash' => $byEvidenceHash,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{by_id: Collection<int, Device>, by_mac: array<string, int>, by_ip: array<string, int>, by_name: array<string, int>, by_evidence_hash: array<string, int>}  $indexes
     */
    private function matchDeviceFromSnapshot(array $snapshot, array $indexes): ?int
    {
        foreach (is_array($snapshot['mac_addresses'] ?? null) ? $snapshot['mac_addresses'] : [] as $rawMac) {
            $mac = MacVendorLookup::normaliseUnicastMac(is_string($rawMac) ? $rawMac : null);
            if ($mac !== null) {
                if (isset($indexes['by_mac'][$mac])) {
                    return $indexes['by_mac'][$mac];
                }
                $evId = $this->lookupEvidenceDeviceId('mac_address', $mac, $indexes['by_evidence_hash']);
                if ($evId !== null) {
                    return $evId;
                }
            }
        }
        foreach (is_array($snapshot['addresses'] ?? null) ? $snapshot['addresses'] : [] as $addr) {
            if (is_string($addr)) {
                if (isset($indexes['by_ip'][$addr])) {
                    return $indexes['by_ip'][$addr];
                }
                $evId = $this->lookupEvidenceDeviceId('address_history', $addr, $indexes['by_evidence_hash']);
                if ($evId !== null) {
                    return $evId;
                }
            }
        }
        if (is_string($snapshot['hostname'] ?? null)) {
            $key = $this->normaliseHostnameKey($snapshot['hostname']);
            if ($key !== null && isset($indexes['by_name'][$key])) {
                return $indexes['by_name'][$key];
            }
            $evId = $this->lookupEvidenceDeviceId('hostname', $snapshot['hostname'], $indexes['by_evidence_hash']);
            if ($evId !== null) {
                return $evId;
            }
        }

        return null;
    }

    /**
     * @param  array{by_id: Collection<int, Device>, by_mac: array<string, int>, by_ip: array<string, int>, by_name: array<string, int>, by_evidence_hash: array<string, int>}  $indexes
     */
    private function resolvePeerDeviceId(?string $mac, ?string $ip, ?string $name, array $indexes): ?int
    {
        if ($mac !== null) {
            $normMac = MacVendorLookup::normaliseUnicastMac($mac);
            if ($normMac !== null) {
                if (isset($indexes['by_mac'][$normMac])) {
                    return $indexes['by_mac'][$normMac];
                }
                $evId = $this->lookupEvidenceDeviceId('mac_address', $normMac, $indexes['by_evidence_hash']);
                if ($evId !== null) {
                    return $evId;
                }
            }
        }
        if ($ip !== null) {
            if (isset($indexes['by_ip'][$ip])) {
                return $indexes['by_ip'][$ip];
            }
            $evId = $this->lookupEvidenceDeviceId('address_history', $ip, $indexes['by_evidence_hash']);
            if ($evId !== null) {
                return $evId;
            }
        }
        if ($name !== null) {
            $normName = $this->normaliseHostnameKey($name);
            if ($normName !== null && isset($indexes['by_name'][$normName])) {
                return $indexes['by_name'][$normName];
            }
            $evId = $this->lookupEvidenceDeviceId('hostname', $name, $indexes['by_evidence_hash']);
            if ($evId !== null) {
                return $evId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $byEvidenceHash
     */
    private function lookupEvidenceDeviceId(string $type, string $value, array $byEvidenceHash): ?int
    {
        if ($byEvidenceHash === []) {
            return null;
        }
        try {
            $hash = DeviceIdentityEvidence::hashValue($type, $value);

            return $byEvidenceHash["{$type}:{$hash}"] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: int, 1: int, 2: ?string, 3: ?string}
     */
    private function orientPair(Device $a, Device $b, ?string $portA, ?string $portB): array
    {
        $rankA = self::TIER_RANK[self::classifyTier($a)] ?? 3;
        $rankB = self::TIER_RANK[self::classifyTier($b)] ?? 3;
        if ($rankA < $rankB || ($rankA === $rankB && (int) $a->id < (int) $b->id)) {
            return [(int) $a->id, (int) $b->id, $portA, $portB];
        }

        return [(int) $b->id, (int) $a->id, $portB, $portA];
    }

    private function normaliseHostnameKey(?string $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $clean = strtolower(trim($raw));
        $short = explode('.', $clean, 2)[0];

        return preg_match('/^[a-z0-9][a-z0-9_-]{1,62}$/', $short) === 1 ? $short : null;
    }

    private function ipv4Prefix24(?string $ip): ?string
    {
        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $parts = explode('.', $ip);

        return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.{$parts[2]}" : null;
    }

    private function protocolFromAutoNote(?string $notes, ?string $port): string
    {
        $haystack = strtolower(($notes ?? '').' '.($port ?? ''));
        if (str_contains($haystack, 'unifi')) {
            return 'unifi';
        }
        if (str_contains($haystack, 'lldp')) {
            return 'lldp';
        }
        if (str_contains($haystack, 'cdp')) {
            return 'cdp';
        }
        if (str_contains($haystack, 'bridge') || str_contains($haystack, 'fdb')) {
            return 'bridge';
        }

        return 'auto';
    }
}
