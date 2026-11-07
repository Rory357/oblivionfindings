<?php

namespace App\Domain\SecurityDevices\Screens\Sites;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * What the Sites screens say about one Site: its devices in plain words, the hub that watches it,
 * and the one thing that matters most. Shared by the Sites list and the Site page.
 *
 * Fact lines use "**bold**" for the figure and "{time}" / "{ago}" for a time the page writes in
 * NZ time (`timedText` in device-time.ts).
 */
final class SiteFacts
{
    /** "12 Kōwhai Street, Hamilton". */
    public static function address(Site $site): string
    {
        return collect([$site->address_line_1, $site->suburb, $site->city])
            ->map(fn (mixed $part): string => trim((string) $part))
            ->filter()
            ->implode(', ');
    }

    /**
     * How the Site is watched, in words: its own hub, or a hub elsewhere over the link.
     *
     * @return array{tone: string, text: string}|null null when no hub covers it
     */
    public static function hubLine(?MonitoringCollector $hub, int $siteId): ?array
    {
        if ($hub === null) {
            return null;
        }
        $label = CoveringHubResolver::label($hub);
        $working = in_array((string) $hub->status, ['online', 'degraded'], true);
        $text = (int) $hub->site_id === $siteId
            ? "Own hub: **{$label}**"
            : "Watched by **{$label}**, over the link to its Site";

        return $working
            ? ['tone' => 'brand', 'text' => $text]
            : ['tone' => 'critical', 'text' => "**{$label}** has stopped answering"];
    }

    /**
     * The Site's flag: the worst thing about it, in two words, with its tone.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @param  list<string>  $alerts
     * @return array{tone: string, text: string}
     */
    public static function flag(Collection $entries, ?MonitoringCollector $hub, array $alerts): array
    {
        if ($hub !== null && ! in_array((string) $hub->status, ['online', 'degraded'], true)) {
            return ['tone' => 'critical', 'text' => 'Hub not answering'];
        }
        $routerDown = self::routerDown($entries);
        if ($routerDown !== null) {
            return ['tone' => 'critical', 'text' => 'Router offline'];
        }
        $offline = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Offline)->count();
        if ($offline > 0) {
            return ['tone' => 'critical', 'text' => $offline === 1 ? '1 offline' : "{$offline} offline"];
        }
        if ($alerts !== []) {
            return ['tone' => 'warning', 'text' => $alerts[0]];
        }
        $problems = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Problem)->count();
        if ($problems > 0) {
            return ['tone' => 'warning', 'text' => $problems === 1 ? '1 needs attention' : "{$problems} need attention"];
        }
        if ($entries->contains(fn (DeviceEntry $entry): bool => $entry->batteryLow())) {
            return ['tone' => 'warning', 'text' => 'Battery low'];
        }
        if ($hub === null) {
            return ['tone' => 'neutral', 'text' => 'No hub yet'];
        }
        $watched = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state !== DeviceState::NotWatched);

        return $watched->isEmpty()
            ? ['tone' => 'neutral', 'text' => 'Nothing watched yet']
            : ['tone' => 'success', 'text' => 'All online'];
    }

    /**
     * Up to four fact lines for the Site's card.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @param  list<string>  $alerts
     * @return list<array{tone: string, text: string, at?: string|null}>
     */
    public static function cardFacts(Collection $entries, ?MonitoringCollector $hub, int $siteId, array $alerts): array
    {
        $facts = [];
        $watched = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state !== DeviceState::NotWatched);
        $online = $watched->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Online);
        $routerDown = self::routerDown($entries);
        $cantCheck = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::CantCheck)->count();

        if ($routerDown !== null) {
            $facts[] = $routerDown->offlineSince !== null
                ? ['tone' => 'critical', 'text' => '**'.$routerDown->name().'** offline since **{time}**', 'at' => $routerDown->offlineSince->toIso8601String()]
                : ['tone' => 'critical', 'text' => '**'.$routerDown->name().'** offline'];
        } elseif ($watched->isNotEmpty()) {
            $checked = $watched->map(fn (DeviceEntry $entry) => $entry->lastCheckedAt)->filter()->max();
            $facts[] = [
                'tone' => $online->count() === $watched->count() ? 'success' : 'warning',
                'text' => '**'.$online->count().' of '.$watched->count().'** online'.($checked !== null ? ' · checked {ago}' : ''),
                'at' => $checked?->toIso8601String(),
            ];
        } else {
            $facts[] = ['tone' => 'none', 'text' => $entries->isEmpty() ? 'No devices added yet' : 'No device is watched yet'];
        }
        if ($cantCheck > 0) {
            $facts[] = ['tone' => 'none', 'text' => '**'.$cantCheck.'** '.($cantCheck === 1 ? 'device' : 'devices').' behind it can’t be checked'];
        }

        $hubLine = self::hubLine($hub, $siteId);
        $facts[] = $hubLine ?? ['tone' => 'none', 'text' => 'No hub watches this Site yet'];

        foreach (array_slice($alerts, 0, 1) as $alert) {
            $facts[] = ['tone' => 'warning', 'text' => $alert];
        }
        $low = $entries->filter(fn (DeviceEntry $entry): bool => $entry->batteryLow())->values();
        if ($low->isNotEmpty()) {
            $facts[] = [
                'tone' => 'warning',
                'text' => '**'.$low->count().'** '.($low->count() === 1 ? 'battery' : 'batteries').' low ('.$low->first()->name().($low->count() > 1 ? ' and others' : '').')',
            ];
        }

        return array_slice($facts, 0, 4);
    }

    /**
     * The Site's router or gateway, when it's offline: everything behind it can't be checked.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     */
    public static function routerDown(Collection $entries): ?DeviceEntry
    {
        return $entries->first(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Offline
            && ($entry->kind === DeviceKind::Router
                || ($entry->kind === DeviceKind::NetworkDevice
                    && preg_match('/router|gateway|firewall|wan/i', implode(' ', [
                        (string) $entry->device->subcategory, (string) $entry->device->category, $entry->name(),
                    ])) === 1)));
    }

    /**
     * Counts by state for the Site's devices donut, in the order the page draws them.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return list<array{state: string, count: int}>
     */
    public static function stateCounts(Collection $entries): array
    {
        $order = [DeviceState::Online, DeviceState::Problem, DeviceState::Offline, DeviceState::CantCheck, DeviceState::NotWatched];

        return array_values(array_filter(array_map(fn (DeviceState $state): array => [
            'state' => $state->value,
            'count' => $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === $state)->count(),
        ], $order), fn (array $row): bool => $row['count'] > 0));
    }
}
