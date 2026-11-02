<?php

namespace App\Domain\SecurityDevices\NetworkChecks;

use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Screens\Devices\Pages\DeviceObservations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The "stored" checks: what Oblivion already keeps about a Site's network, read on the server.
 * The same sources as the Site page's Internet tab (outages), its Network map (links and ports, from
 * UniFi, switch and scan data) and a device's own checks. Read only.
 */
final class NetworkCheckStoredData
{
    /**
     * Drop-outs of the Site's router or gateway in the last 14 days, as on the Internet tab.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array{gateways: int, count: int, last_at: ?string, events: list<array{at: ?string, what: string, device: string}>}
     */
    public function outages(Collection $entries, CarbonImmutable $now): array
    {
        $gateways = $entries->filter(fn (DeviceEntry $entry): bool => self::isGateway($entry))->values();
        $names = $gateways->mapWithKeys(fn (DeviceEntry $entry): array => [$entry->id() => $entry->name()]);
        $events = $gateways->isEmpty() ? collect() : DeviceEvent::query()
            ->whereIn('device_id', $names->keys()->all())
            ->where('occurred_at', '>=', $now->subDays(14))
            ->where(fn ($query) => $query->where('event_type', 'like', '%offline%')->orWhere('event_type', 'like', '%wan%')->orWhere('event_type', 'like', '%failover%'))
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get(['device_id', 'event_type', 'occurred_at']);
        $drops = $events->filter(fn (DeviceEvent $event): bool => str_contains((string) $event->event_type, 'offline') || str_contains((string) $event->event_type, 'down') || str_contains((string) $event->event_type, 'failover'));

        return [
            'gateways' => $gateways->count(),
            'count' => $drops->count(),
            'last_at' => $drops->first()?->occurred_at?->toIso8601String(),
            'events' => $events->take(8)->map(fn (DeviceEvent $event): array => [
                'at' => $event->occurred_at?->toIso8601String(),
                'what' => mb_substr(str_replace(['.', '_'], ' ', (string) $event->event_type), 0, 48),
                'device' => (string) ($names[(int) $event->device_id] ?? ''),
            ])->values()->all(),
        ];
    }

    /**
     * Links on the Site's network map: for one device, what it's linked to; otherwise the map's
     * size and its links between network gear.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array{placed: int, links: list<array{parent: string, child: string, port: ?string}>}
     */
    public function map(Collection $entries, ?int $deviceId): array
    {
        $ids = $entries->map(fn (DeviceEntry $entry): int => $entry->id())->all();
        $names = $entries->mapWithKeys(fn (DeviceEntry $entry): array => [$entry->id() => $entry->name()]);
        $network = $entries->filter(fn (DeviceEntry $entry): bool => in_array($entry->kind, [DeviceKind::Router, DeviceKind::Switch, DeviceKind::WifiPoint, DeviceKind::NetworkDevice], true))
            ->map(fn (DeviceEntry $entry): int => $entry->id())->all();
        $relationships = $ids === [] ? collect() : DeviceRelationship::query()->active()
            ->whereIn('parent_device_id', $ids)->whereIn('child_device_id', $ids)
            ->when($deviceId !== null, fn ($query) => $query->where(fn ($inner) => $inner->where('parent_device_id', $deviceId)->orWhere('child_device_id', $deviceId)))
            ->when($deviceId === null, fn ($query) => $query->whereIn('child_device_id', $network))
            ->orderBy('id')->limit(24)
            ->get(['parent_device_id', 'child_device_id', 'port']);
        $placed = $ids === [] ? 0 : DeviceRelationship::query()->active()->whereIn('child_device_id', $ids)->distinct()->count('child_device_id');

        return [
            'placed' => $placed,
            'links' => $relationships->map(fn (DeviceRelationship $link): array => [
                'parent' => (string) ($names[(int) $link->parent_device_id] ?? 'a device'),
                'child' => (string) ($names[(int) $link->child_device_id] ?? 'a device'),
                'port' => is_string($link->port) && trim($link->port) !== '' ? mb_substr(trim($link->port), 0, 32) : null,
            ])->values()->all(),
        ];
    }

    /**
     * Switch port number → the device Oblivion knows is on it ("Port 7" links on the map).
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array<int, string>
     */
    public function portNames(Collection $entries, int $switchId): array
    {
        $names = $entries->mapWithKeys(fn (DeviceEntry $entry): array => [$entry->id() => $entry->name()]);
        $out = [];
        DeviceRelationship::query()->active()->where('parent_device_id', $switchId)->whereIn('child_device_id', $names->keys()->all())
            ->limit(64)->get(['child_device_id', 'port'])
            ->each(function (DeviceRelationship $link) use ($names, &$out): void {
                if (is_string($link->port) && preg_match('/\b(\d{1,3})\b/', $link->port, $match) === 1) {
                    $out[(int) $match[1]] = (string) $names[(int) $link->child_device_id];
                }
            });

        return $out;
    }

    /**
     * How a device answered Oblivion's own checks in the last 24 hours.
     *
     * @return array{checks: int, answered_pct: ?float, typical_ms: ?int, last_at: ?string}
     */
    public function checks(Device $device, CarbonImmutable $now): array
    {
        $monitors = $device->monitors()->where('is_enabled', true)->get();
        $ids = DeviceObservations::availabilityMonitors($monitors)->pluck('id')->all();
        $custody = DeviceObservations::custodyStart($device);
        $since = $custody !== null && $custody->greaterThan($now->subDay()) ? $custody : $now->subDay();
        if ($ids === []) {
            return ['checks' => 0, 'answered_pct' => null, 'typical_ms' => null, 'last_at' => null];
        }
        $rows = MonitorObservation::query()->whereIn('monitor_id', $ids)->where('observed_at', '>=', $since)->where('observed_at', '<', $now)
            ->whereIn('state', ['healthy', 'degraded', 'failed'])
            ->orderByDesc('observed_at')->limit(5000)
            ->get(['state', 'latency_ms', 'observed_at']);
        if ($rows->isEmpty()) {
            return ['checks' => 0, 'answered_pct' => null, 'typical_ms' => null, 'last_at' => null];
        }
        $answered = $rows->filter(fn (MonitorObservation $row): bool => in_array($row->state instanceof \BackedEnum ? $row->state->value : (string) $row->state, ['healthy', 'degraded'], true));
        $latencies = $answered->pluck('latency_ms')->filter(fn ($value): bool => is_int($value) && $value >= 0)->sort()->values();

        return [
            'checks' => $rows->count(),
            'answered_pct' => round($answered->count() / $rows->count() * 100, 1),
            'typical_ms' => $latencies->isEmpty() ? null : (int) $latencies[intdiv($latencies->count(), 2)],
            'last_at' => CarbonImmutable::parse($rows->first()->observed_at)->toIso8601String(),
        ];
    }

    public static function isGateway(DeviceEntry $entry): bool
    {
        return $entry->kind === DeviceKind::Router
            || ($entry->kind === DeviceKind::NetworkDevice && preg_match('/router|gateway|firewall|wan/i', implode(' ', [
                (string) $entry->device->subcategory, (string) $entry->device->category, $entry->name(),
            ])) === 1);
    }
}
