<?php

namespace App\Domain\SecurityDevices\Screens\Setup;

use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Setup › Hubs & networks › Networks & scans (pg-sdr-15c): every network at the viewer's
 * Sites, who checks it, when it was and will be scanned, what was found, and plain notes
 * where two Sites use the same addresses.
 */
final class SetupNetworks
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly SecurityDevicesSettings $settings,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, notes: list<array{tone: string, text: string}>}
     */
    public function present(User $viewer, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $scopes = $this->scopes($viewer);
        $latest = $this->latestRuns($scopes);
        $dailyScans = $this->settings->dailyScans();
        $canManage = $viewer->canDo('securityDevices.integrations.manage');
        $serverMayScan = $this->settings->serverMayScan();

        $rows = $scopes->map(function (DiscoveryScope $scope) use ($latest, $now, $dailyScans, $canManage, $serverMayScan): array {
            $run = $latest->get((int) $scope->id);
            $hub = $scope->collector;
            $hubWorks = $hub !== null && $hub->isApprovedForWork();

            return [
                'id' => (int) $scope->id,
                'site' => ['id' => (int) $scope->site_id, 'name' => (string) ($scope->site?->name ?? 'Site')],
                'range' => implode(', ', array_values(array_filter((array) $scope->cidrs, 'is_string'))),
                'checked_by' => $hub !== null ? CoveringHubResolver::label($hub) : 'Oblivion server',
                'hub_id' => $hub?->id === null ? null : (int) $hub->id,
                'last' => $this->lastScan($run),
                'next' => $this->nextScan($scope, $hubWorks, $dailyScans, $now),
                'found' => $run !== null && $run->status === 'completed'
                    ? (string) (int) $run->found_count.((int) $run->proposed_count > 0 ? ' · '.(int) $run->proposed_count.' new' : '')
                    : null,
                'run_id' => $run?->id === null ? null : (int) $run->id,
                'can_scan' => $canManage && ($hub !== null ? $hubWorks : $serverMayScan),
                'scan_blocked' => match (true) {
                    ! $canManage => 'Only someone who manages connected services can scan',
                    $hub !== null && ! $hubWorks => 'Its hub isn’t approved, or has been removed',
                    $hub === null && ! $serverMayScan => 'Server scans are off (see Scans below)',
                    default => null,
                },
            ];
        })->values()->all();

        return ['rows' => $rows, 'notes' => $this->clashes($scopes)];
    }

    /**
     * The networks scanned most recently that didn't finish, for the Overview.
     *
     * @return list<array{site: string, range: string, at: string|null}>
     */
    public function failedScans(User $viewer): array
    {
        $scopes = $this->scopes($viewer);

        return $this->latestRuns($scopes)
            ->filter(fn (DiscoveryRun $run): bool => $run->status === 'failed')
            ->map(function (DiscoveryRun $run) use ($scopes): array {
                $scope = $scopes->firstWhere('id', $run->discovery_scope_id);

                return [
                    'site' => (string) ($scope?->site?->name ?? 'Site'),
                    'range' => implode(', ', array_values(array_filter((array) ($scope?->cidrs ?? []), 'is_string'))),
                    'at' => ($run->started_at ?? $run->created_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /** @return Collection<int, DiscoveryScope> */
    private function scopes(User $viewer): Collection
    {
        $siteIds = $this->access->accessibleSiteIds($viewer);
        if ($siteIds === []) {
            return collect();
        }

        return DiscoveryScope::query()
            ->with(['site:id,name', 'collector'])
            ->where('status', 'active')
            ->whereIn('site_id', $siteIds)
            ->orderBy('site_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, DiscoveryScope>  $scopes
     * @return Collection<int, DiscoveryRun> keyed by scope id
     */
    private function latestRuns(Collection $scopes): Collection
    {
        if ($scopes->isEmpty()) {
            return collect();
        }
        $ids = $scopes->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        return DiscoveryRun::query()
            ->whereIn('id', DiscoveryRun::query()
                ->selectRaw('MAX(id)')
                ->whereIn('discovery_scope_id', $ids)
                ->groupBy('discovery_scope_id'))
            ->get()
            ->keyBy(fn (DiscoveryRun $run): int => (int) $run->discovery_scope_id);
    }

    /** @return array{text: string, at: string|null, tone: string|null} */
    private function lastScan(?DiscoveryRun $run): array
    {
        if ($run === null) {
            return ['text' => 'Not scanned yet', 'at' => null, 'tone' => null];
        }
        $at = ($run->started_at ?? $run->created_at)?->toIso8601String();

        return match ((string) $run->status) {
            'queued', 'running' => ['text' => 'Scanning now', 'at' => $at, 'tone' => 'info'],
            'failed' => ['text' => 'Couldn’t finish · {time}', 'at' => $at, 'tone' => 'critical'],
            'cancelled' => ['text' => 'Stopped · {time}', 'at' => $at, 'tone' => null],
            default => ['text' => '{time}'.$this->took($run), 'at' => $at, 'tone' => null],
        };
    }

    private function took(DiscoveryRun $run): string
    {
        if ($run->started_at === null || $run->completed_at === null) {
            return '';
        }
        $minutes = (int) floor(max(0, $run->completed_at->getTimestamp() - $run->started_at->getTimestamp()) / 60);

        return $minutes < 1 ? ' · under 1 min' : " · {$minutes} min";
    }

    /** @return array{text: string, at: string|null} */
    private function nextScan(DiscoveryScope $scope, bool $hubWorks, bool $dailyScans, CarbonImmutable $now): array
    {
        $time = $scope->daily_scan_at;
        if ($scope->collector_id === null || ! is_string($time) || preg_match('/\A([01]\d|2[0-3]):([0-5]\d)\z/', $time, $parts) !== 1) {
            return ['text' => 'When asked', 'at' => null];
        }
        if (! $dailyScans) {
            return ['text' => 'Daily scans are off', 'at' => null];
        }
        if (! $hubWorks) {
            return ['text' => 'Waiting for its hub', 'at' => null];
        }
        $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $local = $now->setTimezone($zone);
        $next = $local->setTime((int) $parts[1], (int) $parts[2]);
        if ($next->lte($local)) {
            $next = $next->addDay();
        }

        return ['text' => '{time}', 'at' => $next->setTimezone('UTC')->toIso8601String()];
    }

    /**
     * The same range at more than one Site: fine when each Site has its own hub, a warning
     * when one hub (or the server) checks both, because their devices could be mixed up.
     *
     * @param  Collection<int, DiscoveryScope>  $scopes
     * @return list<array{tone: string, text: string}>
     */
    private function clashes(Collection $scopes): array
    {
        $byRange = [];
        foreach ($scopes as $scope) {
            foreach (array_filter((array) $scope->cidrs, 'is_string') as $range) {
                $byRange[$range][(int) $scope->site_id] = $scope;
            }
        }

        $notes = [];
        foreach ($byRange as $range => $bySite) {
            if (count($bySite) < 2) {
                continue;
            }
            $names = array_map(fn (DiscoveryScope $scope): string => (string) ($scope->site?->name ?? 'Site'), array_values($bySite));
            $checkers = array_unique(array_map(fn (DiscoveryScope $scope): string => (string) ($scope->collector_id ?? 'server'), array_values($bySite)));
            $sites = self::andList($names);
            $notes[] = count($checkers) === count($bySite) && ! in_array('server', $checkers, true)
                ? ['tone' => 'warning', 'text' => "**{$range}** is used at {$sites}. That’s fine: each Site is checked by its own hub, so the same addresses never mix."]
                : ['tone' => 'critical', 'text' => "**{$range}** is used at {$sites}, and the same hub or the server checks more than one of them, so their devices could be mixed up. Give each Site its own hub."];
        }

        return $notes;
    }

    /** @param  list<string>  $items */
    public static function andList(array $items): string
    {
        $items = array_values($items);
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
