<?php

namespace App\Domain\SecurityDevices\Screens\Hubs;

use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Hubs\Models\SetupCode;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Hubs\Services\HubActionRules;
use App\Domain\SecurityDevices\Hubs\Services\HubReplacementService;
use App\Domain\SecurityDevices\Hubs\Services\HubUpdatePolicy;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The hub panel (S&D rebuild drawing sdr-13): is the hub OK, its version and updates, the networks
 * it covers and what it did last, from what the server knows. Anything the hub doesn't report
 * (its computer's name, address and load) is left for the screen to say "Not available".
 *
 * Scoped by SecurityDevicesAccessService: a hub whose home Site the viewer can't see is "not
 * found", and Sites it covers that the viewer can't see are never named or counted.
 */
final class HubPanelPresenter
{
    private const int EVENT_LIMIT = 30;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
        private readonly CoveringHubResolver $covering,
        private readonly HubReplacementService $replacements,
        private readonly HubUpdatePolicy $updates,
    ) {}

    /** Who may open a hub panel at all (the Site and the hub's own Site are checked in present()). */
    public static function mayView(?User $viewer): bool
    {
        return $viewer !== null
            && $viewer->canDo('securityDevices.devices.view')
            && ($viewer->canDo('securityDevices.integrations.view')
                || $viewer->canDo('securityDevices.integrations.manage')
                || $viewer->canDo('securityDevices.hubs.approve'));
    }

    /** @return array<string, mixed> */
    public function present(User $viewer, MonitoringCollector $hub): array
    {
        abort_unless(self::mayView($viewer), 403);
        $this->access->assertCanViewSite($viewer, (int) $hub->site_id);

        $visibleIds = $this->access->accessibleSiteIds($viewer);
        $authorised = $hub->authorisedSiteIds();
        $seesEverySite = array_diff($authorised, $visibleIds) === [];
        $sites = Site::query()
            ->whereIn('id', array_values(array_intersect($authorised, $visibleIds)))
            ->get(['id', 'name'])
            ->sortBy(fn (Site $site): string => ((int) $site->id === (int) $hub->site_id ? '0' : '1').mb_strtolower((string) $site->name))
            ->values();
        $siteNames = $sites->mapWithKeys(fn (Site $site): array => [(int) $site->id => (string) $site->name]);
        $home = $siteNames->get((int) $hub->site_id) ?? 'its Site';

        $removed = $hub->revoked_at !== null;
        $approved = $hub->approved_at !== null;
        $manage = $seesEverySite && ! $removed;
        $mayApprove = HubActionRules::allows($viewer, 'securityDevices.hubs.approve');

        return [
            'hub' => [
                'id' => (int) $hub->id,
                'name' => (string) $hub->name,
                'label' => CoveringHubResolver::label($hub),
                'site' => ['id' => (int) $hub->site_id, 'name' => $home],
                'platform' => $this->platform($hub),
                'state' => $this->state($hub),
                'heard_at' => ($hub->last_heartbeat_at ?? $hub->last_seen_at)?->toIso8601String(),
                'version' => $hub->softwareVersion(),
                'installed' => $this->installed($hub),
                'replaces' => $approved || $removed ? null : $this->replaces($hub, $visibleIds),
                'removed_at' => $hub->revoked_at?->toIso8601String(),
            ],
            'updates' => $this->updateFacts($hub),
            'watching' => $this->watching($viewer, $hub, $sites),
            'sites' => $sites->map(fn (Site $site): array => [
                'id' => (int) $site->id,
                'name' => (string) $site->name,
                'own' => (int) $site->id === (int) $hub->site_id,
            ])->all(),
            'networks' => $this->networks($hub, $siteNames),
            'events' => $this->events($hub, $siteNames),
            'can' => [
                'edit' => $manage && HubActionRules::allows($viewer, 'securityDevices.integrations.manage'),
                'approve' => $manage && $mayApprove && ! $approved && $hub->enrolled_at !== null,
                'remove' => $manage && $mayApprove,
                'replace' => $manage && $mayApprove && $approved,
                'check_update' => $manage && $mayApprove && $approved,
            ],
        ];
    }

    /** online · not_answering · starting (approved, first check-in to come) · waiting_for_approval · removed */
    private function state(MonitoringCollector $hub): string
    {
        return match (true) {
            $hub->revoked_at !== null => 'removed',
            $hub->approved_at === null => 'waiting_for_approval',
            in_array((string) $hub->status, ['online', 'degraded'], true) => 'online',
            in_array((string) $hub->status, ['offline', 'unavailable'], true) => 'not_answering',
            default => 'starting',
        };
    }

    /** windows | linux | null, from what the hub reports or else the download it was set up from. */
    private function platform(MonitoringCollector $hub): ?string
    {
        $reported = is_array($hub->runtime_status) ? ($hub->runtime_status['platform'] ?? null) : null;
        if (in_array($reported, ['windows', 'linux'], true)) {
            return $reported;
        }
        $code = $this->setupCode($hub);

        return in_array($code?->platform, ['windows', 'linux'], true) ? $code->platform : null;
    }

    private function setupCode(MonitoringCollector $hub): ?SetupCode
    {
        return SetupCode::query()
            ->where('role', 'hub')
            ->whereHas('enrollment', fn ($query) => $query->where('consumed_collector_id', $hub->id))
            ->with('issuedBy:id,name')
            ->latest('id')
            ->first();
    }

    /** @return array{by: string|null, at: string|null} who made the download it came from, and when it first said hello */
    private function installed(MonitoringCollector $hub): array
    {
        $code = $this->setupCode($hub);

        return [
            'by' => $code?->issuedBy?->name !== null ? (string) $code->issuedBy->name : null,
            'at' => ($hub->enrolled_at ?? $code?->consumed_at)?->toIso8601String(),
        ];
    }

    /** The hub this one was set up to replace, named only when the viewer can see its Site. */
    private function replaces(MonitoringCollector $hub, array $visibleIds): ?string
    {
        $old = $this->replacements->replacedBy($hub);
        if ($old === null || $old->revoked_at !== null) {
            return null;
        }

        return in_array((int) $old->site_id, $visibleIds, true) ? CoveringHubResolver::label($old) : 'the hub it replaces';
    }

    /** @return array<string, mixed> */
    private function updateFacts(MonitoringCollector $hub): array
    {
        $last = is_array($hub->update_state) ? $hub->update_state : null;
        $hold = $this->updates->hold();

        return [
            'ring' => in_array($hub->update_ring, HubUpdatePolicy::RINGS, true) ? (string) $hub->update_ring : 'everyone',
            'window_start' => $this->clock($hub->update_window_start, '02:00'),
            'window_end' => $this->clock($hub->update_window_end, '05:00'),
            'last' => $last === null || ! in_array($last['outcome'] ?? null, ['installed', 'rolled_back', 'failed'], true) ? null : [
                'outcome' => (string) $last['outcome'],
                'from' => is_string($last['from_version'] ?? null) ? $last['from_version'] : null,
                'to' => is_string($last['to_version'] ?? null) ? $last['to_version'] : null,
                'at' => is_string($last['reported_at'] ?? null) ? $last['reported_at'] : null,
            ],
            'check_requested_at' => $hub->update_check_requested_at?->toIso8601String(),
            'held' => $hold['held'],
            'held_at' => $hold['held_at'],
        ];
    }

    private function clock(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', substr($value, 0, 5)) === 1 ? substr($value, 0, 5) : $fallback;
    }

    /**
     * The devices this hub checks: the viewer's devices at the Sites it is the covering hub for.
     *
     * @param  Collection<int, Site>  $sites
     * @return array{devices: int, sites: int, cant_check: int}
     */
    private function watching(User $viewer, MonitoringCollector $hub, Collection $sites): array
    {
        $watchedSiteIds = $sites
            ->filter(fn (Site $site): bool => (int) $this->covering->forSite((int) $site->id)?->id === (int) $hub->id)
            ->map(fn (Site $site): int => (int) $site->id)
            ->values()
            ->all();
        if ($watchedSiteIds === []) {
            return ['devices' => 0, 'sites' => 0, 'cant_check' => 0];
        }
        $entries = $this->directory->entries($viewer)
            ->filter(fn (DeviceEntry $entry): bool => in_array((int) $entry->siteId, $watchedSiteIds, true)
                && ! in_array($entry->state, [DeviceState::NotWatched, DeviceState::Retired], true));

        return [
            'devices' => $entries->count(),
            'sites' => count($watchedSiteIds),
            'cant_check' => $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::CantCheck)->count(),
        ];
    }

    /**
     * Each Site it covers with the networks it checks there. A Site with no network yet has one row
     * with no range.
     *
     * @param  Collection<int, string>  $siteNames
     * @return list<array<string, mixed>>
     */
    private function networks(MonitoringCollector $hub, Collection $siteNames): array
    {
        $scopes = DiscoveryScope::query()
            ->where('collector_id', $hub->id)
            ->where('status', 'active')
            ->whereIn('site_id', $siteNames->keys()->all())
            ->orderBy('id')
            ->get(['id', 'site_id', 'cidrs', 'daily_scan_at']);

        $rows = [];
        foreach ($siteNames as $siteId => $name) {
            $own = (int) $siteId === (int) $hub->site_id;
            $siteScopes = $scopes->where('site_id', $siteId);
            if ($siteScopes->isEmpty()) {
                $rows[] = ['key' => 'site-'.$siteId, 'site' => $name, 'own' => $own, 'range' => null, 'daily_at' => null];

                continue;
            }
            foreach ($siteScopes as $scope) {
                $range = collect((array) $scope->cidrs)->first(fn (mixed $cidr): bool => is_string($cidr) && str_contains($cidr, '/'));
                $rows[] = [
                    'key' => 'scope-'.$scope->id,
                    'site' => $name,
                    'own' => $own,
                    'range' => is_string($range) ? $range : null,
                    'daily_at' => is_string($scope->daily_scan_at) && $scope->daily_scan_at !== '' ? substr($scope->daily_scan_at, 0, 5) : null,
                ];
            }
        }

        return $rows;
    }

    /**
     * What it did last, newest first: its own history (approved, changed, updates, removed), the
     * scans it ran at Sites the viewer can see, and when it stopped or started answering.
     *
     * @param  Collection<int, string>  $siteNames
     * @return list<array{key: string, at: string, tone: string, text: string}>
     */
    private function events(MonitoringCollector $hub, Collection $siteNames): array
    {
        $events = collect();

        $audits = AuditLog::query()
            ->where('auditable_type', $hub->getMorphClass())
            ->where('auditable_id', $hub->id)
            ->latest('id')
            ->limit(self::EVENT_LIMIT)
            ->get(['id', 'user_id', 'action', 'meta', 'created_at']);
        $names = User::query()->whereIn('id', $audits->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id');
        foreach ($audits as $audit) {
            $line = $this->auditLine($audit, $names->get($audit->user_id));
            if ($line !== null && $audit->created_at !== null) {
                $events->push(['key' => 'audit-'.$audit->id, 'at' => $audit->created_at, ...$line]);
            }
        }

        $runs = DiscoveryRun::query()
            ->whereHas('scope', fn ($scope) => $scope->withTrashed()->where('collector_id', $hub->id)->whereIn('site_id', $siteNames->keys()->all()))
            ->with(['scope' => fn ($scope) => $scope->withTrashed()->select(['id', 'site_id'])])
            ->latest('id')
            ->limit(self::EVENT_LIMIT)
            ->get(['id', 'discovery_scope_id', 'status', 'found_count', 'proposed_count', 'started_at', 'completed_at', 'cancelled_at']);
        foreach ($runs as $run) {
            $site = $siteNames->get((int) $run->scope?->site_id) ?? 'a Site';
            $at = $run->completed_at ?? $run->cancelled_at ?? $run->started_at;
            if ($at === null) {
                continue;
            }
            $found = (int) $run->found_count.' found'.((int) $run->proposed_count > 0 ? ', '.(int) $run->proposed_count.' new' : '');
            $events->push(['key' => 'scan-'.$run->id, 'at' => $at, ...match (true) {
                $run->cancelled_at !== null || $run->status === 'cancelled' => ['tone' => 'none', 'text' => "Scan of {$site} stopped"],
                $run->status === 'failed' => ['tone' => 'warning', 'text' => "Scan of {$site} didn’t finish"],
                $run->completed_at !== null => ['tone' => 'success', 'text' => "Scan of {$site} finished · {$found}"],
                default => ['tone' => 'info', 'text' => "Scan of {$site} started"],
            }]);
        }

        if ($hub->collector_device_id !== null) {
            DeviceEvent::query()
                ->where('device_id', $hub->collector_device_id)
                ->whereIn('event_type', ['offline', 'online'])
                ->where('source', 'oblivion_monitoring')
                ->latest('occurred_at')
                ->limit(self::EVENT_LIMIT)
                ->get(['id', 'event_type', 'occurred_at'])
                ->each(function (DeviceEvent $event) use ($events): void {
                    if ($event->occurred_at !== null) {
                        $events->push([
                            'key' => 'event-'.$event->id,
                            'at' => $event->occurred_at,
                            ...($event->event_type === 'offline'
                                ? ['tone' => 'critical', 'text' => 'Stopped answering']
                                : ['tone' => 'success', 'text' => 'Answering again']),
                        ]);
                    }
                });
        }

        return $events
            ->sortByDesc(fn (array $event): int => $event['at'] instanceof CarbonInterface ? $event['at']->getTimestamp() : 0)
            ->take(self::EVENT_LIMIT)
            ->map(fn (array $event): array => [...$event, 'at' => $event['at']->toIso8601String()])
            ->values()
            ->all();
    }

    /** @return array{tone: string, text: string}|null */
    private function auditLine(AuditLog $audit, ?string $who): ?array
    {
        $meta = is_array($audit->meta) ? $audit->meta : [];
        $to = is_string($meta['to_version'] ?? null) ? $meta['to_version'] : null;
        $from = is_string($meta['from_version'] ?? null) ? $meta['from_version'] : null;
        $by = $who !== null ? " by {$who}" : '';

        return match ((string) $audit->action) {
            'monitoring.collector.enrolment.consumed' => ['tone' => 'info', 'text' => 'Said hello for the first time'],
            'security_devices.hub.approved' => ['tone' => 'success', 'text' => "Approved{$by}"],
            'security_devices.hub.replaced' => ['tone' => 'info', 'text' => 'Took over from the hub it replaced'],
            'monitoring.collector.updated' => ['tone' => 'info', 'text' => "Name or Sites changed{$by}"],
            'security_devices.hub.updates_changed' => ['tone' => 'info', 'text' => "Update settings changed{$by}"],
            'security_devices.hub.update_check_requested' => ['tone' => 'info', 'text' => $who !== null ? "{$who} asked it to look for an update" : 'Asked to look for an update'],
            'security_devices.hub.update_installed' => ['tone' => 'success', 'text' => $to !== null ? "Updated to {$to}" : 'Updated'],
            'security_devices.hub.update_rolled_back' => ['tone' => 'warning', 'text' => 'Update'.($to !== null ? " to {$to}" : '').' didn’t work, so it went back'.($from !== null ? " to {$from}" : '')],
            'security_devices.hub.update_failed' => ['tone' => 'warning', 'text' => 'Update'.($to !== null ? " to {$to}" : '').' didn’t install'.($from !== null ? ", still on {$from}" : '')],
            'monitoring.collector.revoked' => ['tone' => 'critical', 'text' => ($meta['removed_by'] ?? null) === 'hub_uninstalled' ? 'Removed when Oblivion was uninstalled from its computer' : "Removed{$by}"],
            default => null,
        };
    }
}
