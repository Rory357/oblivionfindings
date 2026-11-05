<?php

namespace App\Domain\SecurityDevices\Screens\Sites;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\Presenters\TopologyWorkspacePresenter;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Services\SiteTopologyMapService;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Network map tab's "how each link was found" (S&D drawing pg-sdr-12j): where the links come
 * from, every link the Site's hub and connected services found with how sure Oblivion is, when it
 * was last seen and whether it's on the map, what changed between checks, and the devices nothing
 * reports.
 *
 * - Only links between devices the viewer can see (TopologyWorkspacePresenter loads no other edge).
 * - A link is on the map when a person's device relationship joins its two devices: found links,
 *   and the relationships scans save on their own ("[auto-topology]"), never join the map by themselves. Adding and taking one off use the device relationship routes, which need
 *   securityDevices.devices.update.
 * - Links at least as sure as monitoring.policy.topology_dependency_minimum_confidence also decide
 *   which devices show Can't check when the one before them is offline (DependencyEvaluator).
 */
final class SiteLinksFound
{
    private const array ORDER = ['gone' => 0, 'new' => 1, 'unsure' => 2, 'on' => 3];

    public function __construct(private readonly TopologyWorkspacePresenter $topology) {}

    public static function threshold(): float
    {
        return (float) config('monitoring.policy.topology_dependency_minimum_confidence', 0.85);
    }

    /**
     * @param  Collection<int, DeviceEntry>  $entries  the viewer's devices at the Site
     * @return array<string, mixed>
     */
    public function present(User $viewer, Site $site, Collection $entries, ?MonitoringCollector $hub): array
    {
        $now = CarbonImmutable::now('UTC');
        $threshold = self::threshold();
        $hubLabel = $hub !== null ? CoveringHubResolver::label($hub) : 'The Site’s hub';
        $byId = $entries->keyBy(fn (DeviceEntry $entry): int => $entry->id());
        $devices = $entries->map(fn (DeviceEntry $entry): array => [
            'id' => $entry->id(),
            'name' => $entry->name(),
            'category' => $entry->device->category,
            'subcategory' => $entry->device->subcategory,
            'health' => $entry->state->value,
            'href' => '/security-devices/devices/'.$entry->id(),
            'site' => ['id' => (int) $site->id, 'name' => (string) $site->name],
        ])->values();
        $siteIds = $entries->mapWithKeys(fn (DeviceEntry $entry): array => [$entry->id() => (int) $site->id]);
        $projection = $devices->isEmpty() ? null : $this->topology->present($devices, $siteIds, true);
        $relationships = $this->relationships($byId->keys()->all());

        $sourceOf = collect($projection['snapshots'] ?? [])->mapWithKeys(fn (array $snapshot): array => [(int) $snapshot['id'] => (string) $snapshot['source']]);
        $found = $this->links(collect($projection['edges'] ?? []), $sourceOf, $hubLabel);
        $rows = [];
        foreach ($found as $key => $link) {
            $relationship = $relationships->get($key);
            $rows[$key] = $this->row($link, $relationship, $byId, $threshold, $now);
        }
        // Links on the map that the latest checks didn't report: either seen in an older check, or placed by hand (12j).
        $missing = $relationships->keys()->diff(array_keys($rows))->values()->all();
        $historyByPair = $this->lastSeen($site, $missing, $byId->keys()->all());
        foreach ($missing as $key) {
            $relationship = $relationships->get($key);
            if (isset($historyByPair[$key])) {
                $history = $historyByPair[$key];
                $rows[$key] = $this->row([...$history, 'by' => [self::sourceLabel($history['source'], $hubLabel)]], $relationship, $byId, $threshold, $now, true);
            } elseif ($relationship !== null) {
                $rows[$key] = $this->manualRow($relationship, $byId);
            }
        }
        $rows = collect($rows)->sortBy(fn (array $row): string => self::ORDER[$row['map']['state']].'|'.mb_strtolower($row['name']))->values();

        $canChange = $viewer->canDo('securityDevices.devices.update');
        $linked = collect($rows)->flatMap(fn (array $row): array => [$row['parent_id'], $row['child_id']])
            ->merge($relationships->flatMap(fn (DeviceRelationship $relationship): array => [(int) $relationship->parent_device_id, (int) $relationship->child_device_id]))
            ->unique()->flip();
        $unplaced = $entries
            ->filter(fn (DeviceEntry $entry): bool => ! $linked->has($entry->id()) && SiteTopologyMapService::classifyTier($entry->device) !== 'gateway')
            ->sortBy(fn (DeviceEntry $entry): string => mb_strtolower($entry->name()))
            ->values();
        $sources = collect($projection['snapshots'] ?? [])
            ->groupBy(fn (array $snapshot): string => self::sourceKey((string) $snapshot['source']))
            ->map(fn (Collection $snapshots, string $key): array => [
                'key' => $key,
                'who' => self::sourceLabel((string) $snapshots->first()['source'], $hubLabel),
                'text' => match ($key) {
                    'hub' => 'asks the gateway and switch what’s plugged into each port',
                    'unifi' => 'says which port and Wi-Fi point each of its devices uses',
                    default => 'reports which devices are joined to which',
                },
                'at' => $snapshots->max('capturedAt'),
            ])
            ->sortBy('key')
            ->values()
            ->all();

        return [
            'nothing' => $rows->isEmpty() && $relationships->isEmpty() && $sources === [],
            'sources' => $sources,
            'threshold' => (int) round($threshold * 100),
            'rows' => $rows->all(),
            'changes' => $this->changes(collect($projection['history']['entries'] ?? []), $hubLabel),
            'unplaced' => [
                'count' => $unplaced->count(),
                'names' => $unplaced->take(6)->map(fn (DeviceEntry $entry): string => $entry->name())->all(),
                'more' => max(0, $unplaced->count() - 6),
                'items' => $canChange && $entries->count() >= 2 ? $unplaced->map(fn (DeviceEntry $entry): array => [
                    'id' => $entry->id(),
                    'name' => $entry->name(),
                    'kind' => $entry->kind->value,
                    'sub' => $entry->kind->label(),
                ])->all() : [],
                'parents' => $canChange && $unplaced->isNotEmpty() && $entries->count() >= 2
                    ? $this->parentOptions($entries)
                    : [],
            ],
            'can' => ['change' => $canChange],
        ];
    }

    /**
     * Found edges joined into one link per pair of devices, the surest edge first.
     *
     * @param  Collection<int, array<string, mixed>>  $edges
     * @param  Collection<int, string>  $sourceOf  snapshot id → its source
     * @return array<string, array<string, mixed>>
     */
    private function links(Collection $edges, Collection $sourceOf, string $hubLabel): array
    {
        $links = [];
        foreach ($edges->sortByDesc('confidence') as $edge) {
            $key = self::pair((int) $edge['parentId'], (int) $edge['childId']);
            $source = (string) ($sourceOf[(int) $edge['snapshotId']] ?? '');
            $by = self::sourceLabel($source, $hubLabel);
            if (! isset($links[$key])) {
                $links[$key] = [
                    'parent_id' => (int) $edge['parentId'],
                    'child_id' => (int) $edge['childId'],
                    'kind' => (string) $edge['type'],
                    'port' => $edge['port'],
                    'confidence' => (float) $edge['confidence'],
                    'first_seen' => $edge['firstSeenAt'],
                    'last_seen' => $edge['lastSeenAt'] ?? $edge['capturedAt'],
                    'source' => $source,
                    'by' => [$by],
                ];

                continue;
            }
            $links[$key]['by'][] = $by;
            $links[$key]['first_seen'] = collect([$links[$key]['first_seen'], $edge['firstSeenAt']])->filter()->min();
            $links[$key]['last_seen'] = collect([$links[$key]['last_seen'], $edge['lastSeenAt'] ?? $edge['capturedAt']])->filter()->max();
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>  $link
     * @param  Collection<int, DeviceEntry>  $byId
     * @return array<string, mixed>
     */
    private function row(array $link, ?DeviceRelationship $relationship, Collection $byId, float $threshold, CarbonImmutable $now, bool $fromHistory = false): array
    {
        $parent = $byId->get($link['parent_id']);
        $child = $byId->get($link['child_id']);
        $lastSeen = $link['last_seen'] !== null ? CarbonImmutable::parse($link['last_seen']) : null;
        $days = $lastSeen !== null ? intdiv(max(0, $now->getTimestamp() - $lastSeen->getTimestamp()), 86_400) : 0;
        $wireless = in_array(strtolower((string) $link['kind']), ['wireless', 'wifi', 'wi-fi', 'wlan'], true);
        $confidence = (int) round((float) $link['confidence'] * 100);
        $on = $relationship !== null;
        $map = match (true) {
            $fromHistory || $days >= 1 => [
                'state' => 'gone', 'tone' => 'warning',
                'label' => 'Not seen for '.max(1, $days).' '.(max(1, $days) === 1 ? 'day' : 'days'),
                'note' => $on ? 'Still on the map · unplugged or moved?' : 'Not on the map',
            ],
            $on => ['state' => 'on', 'tone' => 'success', 'label' => 'On the map', 'note' => 'Since {date}', 'at' => $relationship->created_at?->toIso8601String()],
            (float) $link['confidence'] >= $threshold => ['state' => 'new', 'tone' => 'info', 'label' => 'Not on the map yet', 'note' => 'First found {time}', 'at' => $link['first_seen']],
            default => ['state' => 'unsure', 'tone' => 'neutral', 'label' => 'Not sure enough', 'note' => 'Under '.(int) round($threshold * 100).'%, too unsure to decide Can’t check'],
        };

        return [
            'key' => self::pair($link['parent_id'], $link['child_id']),
            'name' => ($parent?->name() ?? 'A device').' → '.($child?->name() ?? 'a device'),
            'sub' => $wireless ? 'Wi-Fi' : (in_array(strtolower((string) $link['kind']), ['uplink', 'ethernet', 'cable', 'lldp', 'cdp', 'neighbor', 'neighbour'], true) ? 'Cable' : Str::ucfirst(str_replace('_', ' ', strtolower((string) $link['kind'])))),
            'parent_id' => (int) $link['parent_id'],
            'child_id' => (int) $link['child_id'],
            'child_kind' => $child?->kind->value,
            'child_href' => '/security-devices/devices/'.$link['child_id'],
            'by' => self::joinWords(array_values(array_unique($link['by']))),
            'ports' => $link['port'] !== null && $link['port'] !== '' ? (string) $link['port'] : ($wireless ? 'Wi-Fi' : null),
            'sure' => $confidence,
            'seen_at' => $lastSeen?->toIso8601String(),
            'map' => ['at' => null, ...$map],
            'relationship' => $relationship === null ? null : ['id' => (int) $relationship->id, 'device_id' => (int) $relationship->parent_device_id],
        ];
    }

    /**
     * A link a person placed on the map by hand that no hub or service has reported (12j).
     *
     * @param  Collection<int, DeviceEntry>  $byId
     * @return array<string, mixed>
     */
    private function manualRow(DeviceRelationship $relationship, Collection $byId): array
    {
        $parentId = (int) $relationship->parent_device_id;
        $childId = (int) $relationship->child_device_id;
        $parent = $byId->get($parentId);
        $child = $byId->get($childId);
        $port = $relationship->port !== null && trim((string) $relationship->port) !== '' ? trim((string) $relationship->port) : null;
        $sub = match (true) {
            $port !== null && preg_match('/^radio\b/i', $port) === 1 => 'Radio',
            $port !== null && preg_match('/^(wi-?fi|wlan|wireless)\b/i', $port) === 1 => 'Wi-Fi',
            default => 'Placed by hand',
        };

        return [
            'key' => self::pair($parentId, $childId),
            'name' => ($parent?->name() ?? 'A device').' → '.($child?->name() ?? 'a device'),
            'sub' => $sub,
            'parent_id' => $parentId,
            'child_id' => $childId,
            'child_kind' => $child?->kind->value,
            'child_href' => '/security-devices/devices/'.$childId,
            'by' => 'Placed by hand',
            'ports' => $port,
            'sure' => null,
            'seen_at' => null,
            'map' => [
                'state' => 'on',
                'tone' => 'success',
                'label' => 'On the map',
                'note' => 'Placed by hand · {date}',
                'at' => $relationship->created_at?->toIso8601String(),
            ],
            'relationship' => ['id' => (int) $relationship->id, 'device_id' => $parentId],
        ];
    }

    /**
     * Visible devices at this Site that an unplaced device can sit under, gateways and bridges first.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return list<array{id: int, name: string, kind: string, sub: string}>
     */
    private function parentOptions(Collection $entries): array
    {
        return $entries
            ->sortBy(fn (DeviceEntry $entry): string => sprintf(
                '%d|%s',
                match ($entry->kind) {
                    DeviceKind::SensorGateway => 0,
                    DeviceKind::Switch => 1,
                    DeviceKind::WifiPoint => 2,
                    DeviceKind::Recorder => 3,
                    DeviceKind::Router => 4,
                    DeviceKind::NetworkDevice, DeviceKind::Hub => 5,
                    default => 6,
                },
                mb_strtolower($entry->name()),
            ))
            ->map(fn (DeviceEntry $entry): array => [
                'id' => $entry->id(),
                'name' => $entry->name(),
                'kind' => $entry->kind->value,
                'sub' => $entry->kind->label(),
            ])
            ->values()
            ->all();
    }

    /**
     * Active relationships between two visible devices, by pair.
     *
     * @param  list<int>  $ids
     * @return Collection<string, DeviceRelationship>
     */
    private function relationships(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return DeviceRelationship::query()->active()
            ->whereIn('parent_device_id', $ids)
            ->whereIn('child_device_id', $ids)
            ->where(fn ($query) => $query->whereNull('notes')->orWhere('notes', 'not like', SiteTopologyMapService::AUTO_NOTE_PREFIX.'%'))
            ->orderBy('id')
            ->get(['id', 'parent_device_id', 'child_device_id', 'relationship_type', 'port', 'created_at'])
            ->keyBy(fn (DeviceRelationship $relationship): string => self::pair((int) $relationship->parent_device_id, (int) $relationship->child_device_id));
    }

    /**
     * When the Site's checks last reported each of these pairs, from every check it kept.
     *
     * @param  list<string>  $pairs
     * @param  list<int>  $ids  visible devices only
     * @return array<string, array<string, mixed>>
     */
    private function lastSeen(Site $site, array $pairs, array $ids): array
    {
        if ($pairs === [] || $ids === []) {
            return [];
        }
        $rows = DB::table('monitoring_topology_edges as e')
            ->join('monitoring_topology_snapshots as s', 's.id', '=', 'e.topology_snapshot_id')
            ->join('monitoring_topology_nodes as f', 'f.id', '=', 'e.from_node_id')
            ->join('monitoring_topology_nodes as t', 't.id', '=', 'e.to_node_id')
            ->where('s.site_id', $site->id)
            ->where('s.status', 'completed')
            ->whereIn('f.canonical_device_id', $ids)
            ->whereIn('t.canonical_device_id', $ids)
            ->orderByDesc('e.last_seen_at')
            ->limit(2000)
            ->get(['f.canonical_device_id as parent', 't.canonical_device_id as child', 'e.kind', 'e.local_port', 'e.remote_port', 'e.confidence', 'e.first_seen_at', 'e.last_seen_at', 's.source', 's.captured_at']);
        $wanted = array_flip($pairs);
        $out = [];
        foreach ($rows as $row) {
            $key = self::pair((int) $row->parent, (int) $row->child);
            if (! isset($wanted[$key]) || isset($out[$key])) {
                continue;
            }
            $out[$key] = [
                'parent_id' => (int) $row->parent,
                'child_id' => (int) $row->child,
                'kind' => (string) $row->kind,
                'port' => collect([$row->local_port, $row->remote_port])->filter()->implode(' → ') ?: null,
                'confidence' => (float) $row->confidence,
                'first_seen' => $row->first_seen_at,
                'last_seen' => $row->last_seen_at ?? $row->captured_at,
                'source' => (string) $row->source,
            ];
        }

        return $out;
    }

    /**
     * What changed between the Site's kept checks, newest first.
     *
     * @param  Collection<int, array<string, mixed>>  $history
     * @return list<array<string, mixed>>
     */
    private function changes(Collection $history, string $hubLabel): array
    {
        $lines = [];
        foreach ($history as $entry) {
            $comparison = (array) ($entry['comparison'] ?? []);
            $who = self::sourceLabel((string) ($entry['source'] ?? ''), $hubLabel);
            $added = collect($comparison['addedEdges'] ?? []);
            $removed = collect($comparison['removedEdges'] ?? []);
            $changed = collect($comparison['changedEdges'] ?? []);
            $name = fn (array $edge): string => $edge['parentName'].' → '.$edge['childName'];
            $base = ['at' => $entry['capturedAt'] ?? null];
            if (($comparison['state'] ?? null) === 'baseline') {
                $count = (int) ($entry['edgeCount'] ?? 0);
                $lines[] = [...$base, 'key' => 'snap-'.$entry['id'], 'tone' => 'success',
                    'title' => $who.' made its first check and found '.$count.' '.($count === 1 ? 'link' : 'links'), 'sub' => $who];

                continue;
            }
            if ($added->isNotEmpty()) {
                $lines[] = [...$base, 'key' => 'snap-'.$entry['id'].'-added', 'tone' => 'info',
                    'title' => ($added->count() === 1 ? '1 new link found: ' : $added->count().' new links found: ').self::joinWords($added->take(3)->map($name)->all()).($added->count() > 3 ? ' and '.($added->count() - 3).' more' : ''),
                    'sub' => $who.($removed->isEmpty() && $changed->isEmpty() ? ' · nothing gone, nothing moved' : '')];
            }
            foreach ($changed->take(3) as $pair) {
                $before = (array) ($pair['before'] ?? []);
                $after = (array) ($pair['after'] ?? []);
                $lines[] = [...$base, 'key' => 'snap-'.$entry['id'].'-changed-'.$after['id'], 'tone' => 'info',
                    'title' => ($before['port'] ?? null) !== ($after['port'] ?? null) && ($after['port'] ?? null) !== null
                        ? $after['childName'].' moved from '.($before['port'] ?? 'no port').' to '.$after['port']
                        : $name($after).' changed',
                    'sub' => $who.' · '.(($pair['after']['reviewState'] ?? null) === 'reviewed' ? 'it stays on the map' : 'found by the check')];
            }
            foreach ($removed->take(3) as $edge) {
                $lines[] = [...$base, 'key' => 'snap-'.$entry['id'].'-gone-'.$edge['id'], 'tone' => 'warning',
                    'title' => $name($edge).' stopped showing up',
                    'sub' => $who.($edge['lastSeenAt'] ? ' · last seen '.SiteStream::clock(CarbonImmutable::parse($edge['lastSeenAt']), CarbonImmutable::now('UTC')) : '')];
            }
        }

        return $lines;
    }

    private static function pair(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }

    /** hub, unifi or another connected service, from a snapshot's source. */
    private static function sourceKey(string $source): string
    {
        return match (true) {
            str_starts_with($source, 'hub:'), str_starts_with($source, 'native:') => 'hub',
            str_starts_with($source, 'provider:unifi') => 'unifi',
            default => 'other:'.$source,
        };
    }

    private static function sourceLabel(string $source, string $hubLabel): string
    {
        $key = self::sourceKey($source);

        return match (true) {
            $key === 'hub' => $hubLabel,
            $key === 'unifi' => 'UniFi',
            str_starts_with($source, 'provider:') => Str::headline((string) (explode(':', $source)[1] ?? 'A connected service')),
            default => 'Oblivion',
        };
    }

    /** @param list<string> $parts */
    private static function joinWords(array $parts): string
    {
        return SiteStream::list(array_values($parts));
    }
}
