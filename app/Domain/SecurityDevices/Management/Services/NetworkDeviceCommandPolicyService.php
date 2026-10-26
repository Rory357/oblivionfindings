<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Topology\Models\TopologyEdge;
use App\Domain\Monitoring\Topology\Models\TopologyNode;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use Illuminate\Validation\ValidationException;
use Throwable;

final class NetworkDeviceCommandPolicyService
{
    public const array PORT_CAPABILITIES = [
        'network.port.enable',
        'network.port.disable',
        'network.port.bounce',
    ];

    public const array PROTECTED_DOMAINS = [
        'security',
        'iot_healthcare',
    ];

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $sites,
    ) {}

    public function isPortCapability(string $capability): bool
    {
        return in_array($capability, self::PORT_CAPABILITIES, true);
    }

    /**
     * @return array<string, array{name: string, label: string, protected: bool, protectionReason: string|null}>
     */
    public function knownInterfaces(Device $device): array
    {
        $entries = [];

        $configured = data_get($device->config ?? [], 'management.network.interfaces')
            ?? data_get($device->meta ?? [], 'interfaces')
            ?? [];

        if (is_array($configured)) {
            foreach ($configured as $key => $item) {
                if (is_string($item) && $this->isValidInterfaceName($item)) {
                    $name = trim($item);
                    $entries[strtolower($name)] = ['name' => $name, 'role' => null, 'description' => null];
                } elseif (is_array($item)) {
                    $name = is_string($item['name'] ?? null)
                        ? trim($item['name'])
                        : (is_string($key) ? trim($key) : '');
                    if ($this->isValidInterfaceName($name)) {
                        $entries[strtolower($name)] = [
                            'name' => $name,
                            'role' => is_string($item['role'] ?? null) ? strtolower(trim($item['role'])) : null,
                            'description' => is_string($item['label'] ?? $item['description'] ?? null)
                                ? trim((string) ($item['label'] ?? $item['description']))
                                : null,
                        ];
                    }
                }
            }
        }

        $monitors = $device->relationLoaded('monitors')
            ? $device->monitors
            : Monitor::query()->where('device_id', $device->id)->orderBy('name')->get();

        $interfaceMonitors = $monitors->filter(
            fn (Monitor $monitor): bool => in_array($monitor->kind, [MonitorKind::SnmpInterface, MonitorKind::Snmp], true),
        );

        if ($interfaceMonitors->isNotEmpty()) {
            $latestIds = MonitorObservation::query()
                ->whereIn('monitor_id', $interfaceMonitors->pluck('id'))
                ->selectRaw('MAX(id)')
                ->groupBy('monitor_id');
            $observations = MonitorObservation::query()
                ->whereIn('id', $latestIds)
                ->get()
                ->keyBy('monitor_id');

            foreach ($interfaceMonitors as $monitor) {
                $metrics = $observations->get($monitor->id)?->metrics;
                $target = is_array($monitor->target) ? $monitor->target : [];
                $candidate = null;
                foreach ([
                    is_array($metrics) ? ($metrics['interface_name'] ?? $metrics['if_name'] ?? null) : null,
                    $target['interface_name'] ?? $target['interface'] ?? null,
                    $monitor->kind === MonitorKind::SnmpInterface ? $monitor->name : null,
                ] as $raw) {
                    if (is_string($raw) && $this->isValidInterfaceName(trim($raw))) {
                        $candidate = trim($raw);
                        break;
                    }
                }

                if ($candidate !== null && ! isset($entries[strtolower($candidate)])) {
                    $entries[strtolower($candidate)] = [
                        'name' => $candidate,
                        'role' => is_array($metrics) && is_string($metrics['interface_role'] ?? null)
                            ? strtolower(trim($metrics['interface_role']))
                            : null,
                        'description' => $monitor->name !== $candidate ? $monitor->name : null,
                    ];
                }
            }
        }

        $result = [];
        foreach ($entries as $entry) {
            $name = $entry['name'];
            $reason = $this->interfaceProtectionReason($device, $name, $entry['role'], $entry['description']);
            $label = $entry['description'] ? "{$name} ({$entry['description']})" : $name;
            if ($reason !== null) {
                $label .= ' — Independent approval required';
            }
            $result[$name] = [
                'name' => $name,
                'label' => $label,
                'protected' => $reason !== null,
                'protectionReason' => $reason,
            ];
        }

        ksort($result, SORT_NATURAL | SORT_FLAG_CASE);

        return $result;
    }

    public function assertInterfaceAllowed(Device $device, string $interface): void
    {
        $trimmed = trim($interface);
        if (! $this->isValidInterfaceName($trimmed)) {
            throw ValidationException::withMessages([
                'interface' => 'Select a valid network interface identifier.',
            ]);
        }

        $known = $this->knownInterfaces($device);
        if ($known === []) {
            return;
        }

        foreach (array_keys($known) as $candidate) {
            if (strcasecmp($candidate, $trimmed) === 0) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'interface' => 'The requested interface is not in the observed or configured port inventory for this Device.',
        ]);
    }

    public function requiresIndependentApproval(Device $device, string $interface): bool
    {
        return $this->interfaceProtectionReason($device, $interface) !== null;
    }

    public function interfaceProtectionReason(
        Device $device,
        string $interface,
        ?string $explicitRole = null,
        ?string $description = null,
    ): ?string {
        $trimmed = trim($interface);
        if ($trimmed === '') {
            return null;
        }

        $uplinkList = data_get($device->config ?? [], 'management.network.uplink_interfaces', []);
        if (is_array($uplinkList)) {
            foreach ($uplinkList as $uplink) {
                if (is_string($uplink) && strcasecmp(trim($uplink), $trimmed) === 0) {
                    return 'Uplink or trunk port';
                }
            }
        }

        $configuredInterfaces = data_get($device->config ?? [], 'management.network.interfaces')
            ?? data_get($device->meta ?? [], 'interfaces')
            ?? [];
        if (is_array($configuredInterfaces)) {
            foreach ($configuredInterfaces as $key => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $itemName = is_string($item['name'] ?? null) ? trim($item['name']) : (is_string($key) ? trim($key) : '');
                if (strcasecmp($itemName, $trimmed) !== 0) {
                    continue;
                }
                $role = strtolower(trim((string) ($item['role'] ?? '')));
                if (in_array($role, ['uplink', 'trunk', 'wan', 'core', 'interconnect'], true) || ($item['is_uplink'] ?? false) === true) {
                    return 'Uplink or trunk port';
                }
                if (($item['requires_approval'] ?? false) === true || ($item['protected'] ?? false) === true) {
                    return 'Protected network port';
                }
            }
        }

        if ($explicitRole !== null && in_array($explicitRole, ['uplink', 'trunk', 'wan', 'core', 'interconnect'], true)) {
            return 'Uplink or trunk port';
        }

        $haystack = trim($trimmed.' '.($description ?? ''));
        if (preg_match('/\b(uplink|trunk|wan|port-channel|bond|lag)\b|^po\d+/i', $haystack) === 1) {
            return 'Uplink or trunk port';
        }

        // Check active canonical DeviceRelationships bound to this port.
        $relationships = DeviceRelationship::query()
            ->whereNull('unlinked_at')
            ->where(function ($query) use ($device): void {
                $query->where('parent_device_id', $device->id)
                    ->orWhere('child_device_id', $device->id);
            })
            ->whereNotNull('port')
            ->with(['parent:id,domain,category', 'child:id,domain,category'])
            ->get();

        foreach ($relationships as $relationship) {
            if (strcasecmp(trim((string) $relationship->port), $trimmed) !== 0) {
                continue;
            }
            $typeValue = $relationship->relationship_type?->value ?? (string) $relationship->relationship_type;
            if ($typeValue === 'uplinks_to') {
                return 'Uplink or trunk port';
            }
            $neighbor = (int) $relationship->parent_device_id === (int) $device->id
                ? $relationship->child
                : $relationship->parent;
            if ($neighbor instanceof Device && in_array($neighbor->domain, self::PROTECTED_DOMAINS, true)) {
                return 'Port connected to a security or care device';
            }
            if ($neighbor instanceof Device && in_array($neighbor->category, ['network', 'networking'], true)) {
                return 'Inter-switch or router link';
            }
        }

        // Check latest completed TopologySnapshot at the canonical Site.
        try {
            $siteId = $this->sites->resolve((int) $device->id);
            $latestSnapshot = TopologySnapshot::query()
                ->where('site_id', $siteId)
                ->where('status', 'completed')
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->first(['id']);

            if ($latestSnapshot !== null) {
                $node = TopologyNode::query()
                    ->where('topology_snapshot_id', $latestSnapshot->id)
                    ->where('canonical_device_id', $device->id)
                    ->first(['id']);

                if ($node !== null) {
                    $edges = TopologyEdge::query()
                        ->where('topology_snapshot_id', $latestSnapshot->id)
                        ->where(function ($query) use ($node): void {
                            $query->where('from_node_id', $node->id)
                                ->orWhere('to_node_id', $node->id);
                        })
                        ->with(['fromNode.canonicalDevice:id,domain,category', 'toNode.canonicalDevice:id,domain,category'])
                        ->get();

                    foreach ($edges as $edge) {
                        $isFrom = (int) $edge->from_node_id === (int) $node->id;
                        $matchedPort = $isFrom ? $edge->local_port : $edge->remote_port;
                        if (! is_string($matchedPort) || strcasecmp(trim($matchedPort), $trimmed) !== 0) {
                            continue;
                        }
                        if (in_array(strtolower((string) $edge->kind), ['uplink', 'trunk'], true)) {
                            return 'Uplink or trunk port';
                        }
                        $neighbor = $isFrom
                            ? $edge->toNode?->canonicalDevice
                            : $edge->fromNode?->canonicalDevice;
                        if ($neighbor instanceof Device && in_array($neighbor->domain, self::PROTECTED_DOMAINS, true)) {
                            return 'Port connected to a security or care device';
                        }
                        if ($neighbor instanceof Device && in_array($neighbor->category, ['network', 'networking'], true)) {
                            return 'Inter-switch or router link';
                        }
                    }
                }
            }
        } catch (Throwable) {
            // Canonical Site unresolved; fail closed at command routing level.
        }

        return null;
    }

    private function isValidInterfaceName(string $value): bool
    {
        return $value !== ''
            && mb_strlen($value) <= 80
            && preg_match('/^[A-Za-z0-9._:\/-]+$/', $value) === 1;
    }
}
