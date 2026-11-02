<?php

namespace App\Domain\SecurityDevices\Screens\Sites;

use App\Domain\SecurityDevices\Automations\SafeActions;
use App\Domain\SecurityDevices\Modes\HouseModeDevices;
use App\Domain\SecurityDevices\Modes\HouseModeRunner;
use App\Domain\SecurityDevices\Modes\HouseModes;
use App\Domain\SecurityDevices\Modes\HouseModeSchedule;
use App\Domain\SecurityDevices\Modes\HouseModeWords;
use App\Domain\SecurityDevices\Modes\Models\HouseMode;
use App\Domain\SecurityDevices\Modes\Models\HouseModeEvent;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * House modes on the Site page (S&D Phase F): the header's "House mode" block and Change mode
 * (pop-up 42) on every tab, and the Modes tab (pg-sdr-12c) with each mode, its schedule and safe
 * actions, the log of switches, and what pop-up 43 needs to edit one.
 *
 * Device names come only from devices the viewer may see at this Site.
 */
final class SiteModesPresenter
{
    /**
     * The current mode, the next switch and each mode's summary, for the header and pop-up 42.
     *
     * @param  SupportCollection<int, DeviceEntry>  $entries  the viewer's devices at the Site
     * @return array<string, mixed>
     */
    public function house(Site $site, SupportCollection $entries): array
    {
        $now = CarbonImmutable::now('UTC');
        $modes = HouseModes::forSite((int) $site->id);
        if ($modes->isEmpty()) {
            return ['current' => null, 'next' => null, 'modes' => []];
        }
        $names = self::names($entries);
        $current = HouseModeRunner::currentSwitch((int) $site->id);
        $currentId = $current?->mode_id;
        $next = HouseModes::next($modes, $currentId, $now);

        return [
            'current' => $current === null || $current->mode === null ? null : [
                'id' => (int) $current->mode_id,
                'name' => (string) $current->mode->name,
                'since' => $current->at->toIso8601String(),
            ],
            'next' => $next === null ? null : ['name' => (string) $next['mode']->name, 'at' => $next['at']->toIso8601String()],
            'modes' => $modes->map(fn (HouseMode $mode): array => [
                'id' => (int) $mode->id,
                'name' => (string) $mode->name,
                'summary' => HouseModeWords::summary($mode, $names),
                'usual' => $mode->schedule === null ? null : $mode->name.' normally starts at '.HouseModeWords::clock($mode->schedule['starts']),
            ])->values()->all(),
        ];
    }

    /**
     * The Modes tab.
     *
     * @param  SupportCollection<int, DeviceEntry>  $entries  the viewer's devices at the Site
     * @return array<string, mixed>
     */
    public function present(Site $site, SupportCollection $entries, bool $canEdit): array
    {
        $now = CarbonImmutable::now('UTC');
        $modes = HouseModes::forSite((int) $site->id);
        $choosable = HouseModeDevices::fromEntries($entries);
        $names = $choosable['doors'] + $choosable['cameras'] + $choosable['panels'];
        $currentId = HouseModeRunner::currentSwitch((int) $site->id)?->mode_id;

        return [
            'items' => $modes->map(fn (HouseMode $mode): array => $this->item($mode, $modes, $names, $currentId, $now))->values()->all(),
            'log' => $this->log((int) $site->id),
            'edit' => ! $canEdit ? null : [
                'choices' => SafeActions::choices(),
                'doors' => self::options($choosable['doors']),
                'cameras' => self::options($choosable['cameras']),
                'panels' => self::options($choosable['panels']),
                'max' => HouseMode::MAX_PER_SITE,
            ],
        ];
    }

    /**
     * @param  Collection<int, HouseMode>  $modes
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function item(HouseMode $mode, Collection $modes, array $names, ?int $currentId, CarbonImmutable $now): array
    {
        $on = (int) $mode->id === $currentId;
        $nextStart = $mode->schedule === null ? null : HouseModeSchedule::nextStart($mode->schedule, $now);
        $when = HouseModeWords::when($mode, HouseModes::endsAt($mode, $modes, $now));

        return [
            'id' => (int) $mode->id,
            'name' => (string) $mode->name,
            'sub' => $on ? $when.' · on now' : $when,
            'on' => $on,
            'flag' => match (true) {
                $on => ['tone' => 'success', 'text' => 'On now', 'at' => null],
                $nextStart !== null => ['tone' => 'info', 'text' => 'Starts {time}', 'at' => $nextStart->toIso8601String()],
                default => ['tone' => 'neutral', 'text' => 'Off', 'at' => null],
            },
            'facts' => HouseModeWords::facts($mode, $names),
            // What pop-up 43 starts from.
            'schedule' => $mode->schedule,
            'actions' => $mode->actionList(),
        ];
    }

    /**
     * The "Mode changes" log, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function log(int $siteId): array
    {
        return HouseModeEvent::query()->with('actor:id,name')->where('site_id', $siteId)->done()
            ->orderByDesc('at')->orderByDesc('id')->limit(20)->get()
            ->map(function (HouseModeEvent $event): array {
                $lines = array_values(array_filter((array) $event->lines, 'is_array'));
                $warn = collect($lines)->contains(fn (array $line): bool => ($line['tone'] ?? null) === 'warning');
                $how = $event->kind === HouseModeEvent::KIND_SWITCH
                    ? [$event->source === 'hand' ? 'By hand'.($event->actor ? ' · '.$event->actor->name : '') : 'By schedule']
                    : [];

                return [
                    'id' => (int) $event->id,
                    'at' => $event->at->toIso8601String(),
                    'tone' => $event->kind === HouseModeEvent::KIND_CHECK ? ($warn ? 'warning' : 'success') : ($warn ? 'warning' : 'info'),
                    'title' => (string) $event->title,
                    'sub' => implode(' · ', [...$how, ...array_map(fn (array $line): string => (string) ($line['text'] ?? ''), $lines)]),
                ];
            })
            ->all();
    }

    /**
     * @param  SupportCollection<int, DeviceEntry>  $entries
     * @return array<int, string>
     */
    private static function names(SupportCollection $entries): array
    {
        $choosable = HouseModeDevices::fromEntries($entries);

        return $choosable['doors'] + $choosable['cameras'] + $choosable['panels'];
    }

    /**
     * @param  array<int, string>  $names
     * @return list<array{id: int, name: string}>
     */
    private static function options(array $names): array
    {
        $out = [];
        foreach ($names as $id => $name) {
            $out[] = ['id' => (int) $id, 'name' => $name];
        }

        return $out;
    }
}
