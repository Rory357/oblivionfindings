<?php

namespace App\Domain\SecurityDevices\Screens\Setup;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Setup › Change history (pg-sdr-15l): who changed Security & devices settings, what and
 * where, newest first, in plain words. Only settings-type changes are listed: never camera
 * viewing, door events or anything about a person.
 */
final class SetupHistory
{
    public const int PER_PAGE = 25;

    /** Exact actions, and prefixes ending in "_" or ".", that count as a settings change. */
    private const array ACTIONS = [
        'security_devices.settings.changed',
        'security_devices.hub.approved',
        'security_devices.hub.replaced',
        'security_devices.hub.uninstalled',
        'security_devices.hub.updates_changed',
        'security_devices.hub.update_check_requested',
        'security_devices.hub.update_installed',
        'security_devices.hub.update_failed',
        'security_devices.hub.update_rolled_back',
        'security_devices.setup_code.issued',
        'security_devices.setup_code.cancelled',
        'security_devices.sensor_login.created',
        'security_devices.sensor_login.revoked',
        'monitoring.collector.revoked',
        'monitoring.discovery.scope.created',
        'monitoring.discovery.scope.updated',
        'monitoring.discovery.scope.deactivated',
        'monitoring.maintenance.created',
        'monitoring.maintenance.updated',
        'monitoring.maintenance.cancelled',
        'monitoring.alert_route.created',
        'monitoring.alert_route.updated',
        'monitoring.alert_route.deactivated',
        'monitoring.retention.created',
        'monitoring.retention.updated',
        'monitoring.retention.deactivated',
    ];

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /**
     * @return array{rows: list<array<string, mixed>>, page: int, pages: int, total: int, kept: list<array{0: string, 1: string}>}
     */
    public function present(User $viewer, int $page = 1): array
    {
        /** @var LengthAwarePaginator<int, AuditLog> $paginator */
        $paginator = $this->query($viewer)->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
        $rows = $this->rows(collect($paginator->items()));

        return [
            'rows' => $rows,
            'page' => $paginator->currentPage(),
            'pages' => max(1, $paginator->lastPage()),
            'total' => $paginator->total(),
            'kept' => $this->kept(),
        ];
    }

    /** The newest change, for the Overview card. @return array{at: string, who: string}|null */
    public function latest(User $viewer): ?array
    {
        $row = $this->rows($this->query($viewer)->limit(1)->get())[0] ?? null;

        return $row === null ? null : ['at' => (string) $row['at'], 'who' => (string) $row['who']];
    }

    /** Up to 5,000 rows for the CSV export, newest first. @return list<array<string, mixed>> */
    public function export(User $viewer): array
    {
        return $this->rows($this->query($viewer)->limit(5000)->get());
    }

    /** @return Builder<AuditLog> */
    private function query(User $viewer): Builder
    {
        $query = AuditLog::query()
            ->whereIn('action', self::ACTIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($viewer->canDo('sites.viewAll')) {
            return $query;
        }

        $siteIds = $this->access->accessibleSiteIds($viewer);

        // Organisation-wide settings belong to no Site; everything else must be at one of the viewer's Sites.
        return $query->where(function (Builder $scope) use ($siteIds): void {
            $scope->where('action', 'security_devices.settings.changed');
            if ($siteIds !== []) {
                $scope->orWhereIn('meta->site_id', $siteIds)
                    ->orWhereIn('meta->scope->site_id', $siteIds);
            }
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AuditLog>  $logs
     * @return list<array<string, mixed>>
     */
    private function rows(\Illuminate\Support\Collection $logs): array
    {
        if ($logs->isEmpty()) {
            return [];
        }
        $users = User::query()->whereIn('id', $logs->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        $siteIds = $logs->map(fn (AuditLog $log): ?int => $this->siteId($log))->filter()->unique()->values();
        $sites = Site::query()->whereIn('id', $siteIds)->pluck('name', 'id');
        $hubs = MonitoringCollector::query()
            ->whereIn('id', $logs->where('auditable_type', (new MonitoringCollector)->getMorphClass())->pluck('auditable_id')->filter()->unique())
            ->get()
            ->keyBy('id');
        $scopes = DiscoveryScope::query()->withTrashed()
            ->whereIn('id', $logs->where('auditable_type', (new DiscoveryScope)->getMorphClass())->pluck('auditable_id')->filter()->unique())
            ->get(['id', 'cidrs', 'site_id'])
            ->keyBy('id');

        return $logs->map(function (AuditLog $log) use ($users, $sites, $hubs, $scopes): array {
            $meta = is_array($log->meta) ? $log->meta : [];
            $siteId = $this->siteId($log);
            $hub = $hubs->get($log->auditable_id);
            $hubName = $hub instanceof MonitoringCollector ? CoveringHubResolver::label($hub) : 'A hub';
            $scope = $scopes->get($log->auditable_id);
            $range = $scope instanceof DiscoveryScope ? implode(', ', array_filter((array) $scope->cidrs, 'is_string')) : 'a network';

            return [
                'id' => (int) $log->id,
                'at' => $log->created_at?->toIso8601String(),
                'who' => $log->user_id !== null ? (string) ($users[$log->user_id] ?? 'Someone who has left') : 'Oblivion',
                'what' => $this->sentence((string) $log->action, $meta, $hubName, $range),
                'where' => $siteId !== null ? (string) ($sites[$siteId] ?? 'A Site') : 'Every Site',
                'site_id' => $siteId,
            ];
        })->values()->all();
    }

    private function siteId(AuditLog $log): ?int
    {
        $meta = is_array($log->meta) ? $log->meta : [];
        $value = $meta['site_id'] ?? ($meta['scope']['site_id'] ?? null);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @param  array<string, mixed>  $meta */
    private function sentence(string $action, array $meta, string $hub, string $range): string
    {
        $to = is_string($meta['to_version'] ?? null) ? $meta['to_version'] : null;

        return match ($action) {
            'security_devices.settings.changed' => $this->settingSentence($meta),
            'security_devices.hub.approved' => "Approved {$hub} · it now gets its Sites’ saved logins",
            'security_devices.hub.replaced' => "Replaced {$hub} with a new computer",
            'security_devices.hub.uninstalled' => "{$hub} was uninstalled and removed",
            'security_devices.hub.updates_changed' => "Changed when {$hub} updates itself",
            'security_devices.hub.update_check_requested' => "Asked {$hub} to check for its update now",
            'security_devices.hub.update_installed' => $to !== null ? "{$hub} updated itself to {$to}" : "{$hub} updated itself",
            'security_devices.hub.update_failed', 'security_devices.hub.update_rolled_back' => ($to !== null ? "{$hub}’s update to {$to}" : "{$hub}’s update").' didn’t install, so it went back to the version it had',
            'security_devices.setup_code.issued' => (($meta['role'] ?? null) === 'computer' ? 'Made a computer install download' : 'Made a hub download'),
            'security_devices.setup_code.cancelled' => 'Cancelled a setup download before it was used',
            'security_devices.sensor_login.created' => 'Made a login for a sensor gateway',
            'security_devices.sensor_login.revoked' => 'Removed a sensor gateway’s login',
            'monitoring.collector.revoked' => "Removed {$hub} · its saved logins were wiped",
            'monitoring.discovery.scope.created' => "Added the network {$range}",
            'monitoring.discovery.scope.updated' => "Changed the network {$range}",
            'monitoring.discovery.scope.deactivated' => "Stopped scanning {$range}",
            'monitoring.maintenance.created' => 'Set quiet time',
            'monitoring.maintenance.updated' => 'Changed quiet time',
            'monitoring.maintenance.cancelled' => 'Ended quiet time early',
            'monitoring.alert_route.created', 'monitoring.alert_route.updated' => 'Changed who is told about alerts',
            'monitoring.alert_route.deactivated' => 'Turned off an alert route',
            'monitoring.retention.created', 'monitoring.retention.updated' => 'Changed how long readings are kept',
            'monitoring.retention.deactivated' => 'Turned off a rule for how long readings are kept',
            default => 'Changed a setting',
        };
    }

    /** @param  array<string, mixed>  $meta */
    private function settingSentence(array $meta): string
    {
        $state = ($meta['to'] ?? false) === true ? 'on' : 'off';
        $role = is_string($meta['to'] ?? null) && $meta['to'] !== ''
            ? (string) (\App\Models\Role::query()->where('name', $meta['to'])->value('label') ?: ucfirst(str_replace('_', ' ', (string) $meta['to'])))
            : 'IT for that Site';

        return match ($meta['key'] ?? null) {
            SecurityDevicesSettings::DAILY_SCANS => "Turned daily network scans {$state}",
            SecurityDevicesSettings::SERVER_SCANS => "Turned {$state} scans by the Oblivion server itself",
            SecurityDevicesSettings::DEVICES_ROLE => "When a device or hub stops answering, {$role} is now told",
            SecurityDevicesSettings::DEVICES_APP => "Turned {$state} app notices when a device or hub stops answering",
            SecurityDevicesSettings::DEVICES_EMAIL => "Turned {$state} emails when a device or hub stops answering",
            SecurityDevicesSettings::DEVICES_PUSH => "Turned {$state} push notices when a device or hub stops answering",
            SecurityDevicesSettings::NEW_DEVICES_APP => "Turned {$state} app notices when a scan finds a new device",
            SecurityDevicesSettings::NEW_DEVICES_EMAIL => "Turned {$state} emails when a scan finds a new device",
            SecurityDevicesSettings::NEW_DEVICES_PUSH => "Turned {$state} push notices when a scan finds a new device",
            SecurityDevicesSettings::SAFETY_EMAIL => "Turned {$state} safety alerts by email",
            SecurityDevicesSettings::SAFETY_PUSH => "Turned {$state} safety alerts by push",
            SecurityDevicesSettings::SAFETY_ROSTER => "Turned {$state} using the roster for safety alerts",
            SecurityDevicesSettings::WATCH_ON_ADD => "Turned {$state} watching devices as soon as they’re added",
            SecurityDevicesSettings::WATCH_PHONES => "Turned {$state} watching phones and tablets",
            default => "Turned a setting {$state}",
        };
    }

    /** @return list<array{0: string, 1: string}> */
    private function kept(): array
    {
        $years = max(1, (int) config('retention.audit_log_years', 2));
        $raw = (int) config('monitoring.retention.raw_days', 14);
        $daily = (int) config('monitoring.retention.daily_days', 1825);

        return [
            ['Settings changes', $years === 1 ? '1 year (your audit policy)' : "{$years} years (your audit policy)"],
            ['Camera viewing log', 'With the reason, as long as your audit policy says'],
            ['Readings and graphs', "Every reading for {$raw} days, then daily summaries for ".intdiv($daily, 365).' years'],
        ];
    }
}
