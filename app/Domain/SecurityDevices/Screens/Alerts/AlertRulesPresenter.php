<?php

namespace App\Domain\SecurityDevices\Screens\Alerts;

use App\Domain\Monitoring\Models\MonitoringAlertRoute;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alerts › Rules & automations (pg-sdr-13b): every rule, as when → where → then, from today's
 * alert routes (who is told when a device's check fails). Automations arrive in Phase F.
 */
final class AlertRulesPresenter
{
    private const array DOMAINS = [
        'it_infrastructure' => 'network gear and computers',
        'security' => 'cameras, alarms and doors',
        'iot_healthcare' => 'health kit',
        'facilities' => 'sensors and building systems',
    ];

    /** The kinds of network gear and computers a rule can be narrowed to (IT categories). */
    public const array CATEGORIES = [
        'network' => 'network gear',
        'endpoint' => 'computers',
        'server' => 'servers',
        'storage' => 'storage',
        'power' => 'power (UPS)',
        'printer' => 'printers',
        'voice' => 'phones',
        'recording' => 'recorders',
        'rack' => 'racks',
        'it_environmental' => 'comms room sensors',
    ];

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    public function count(User $viewer, ?int $siteId = null): int
    {
        return $this->query($viewer, $siteId)->count();
    }

    /**
     * What Add alert rule (21) chooses from: who can be told (roles), and whether this viewer may
     * make a rule for every Site (the server refuses it otherwise).
     *
     * @return array{roles: list<array{value: string, label: string}>, can_all_sites: bool}
     */
    public function formOptions(User $viewer): array
    {
        return [
            'roles' => Role::query()->orderBy('level')->orderBy('label')->get(['name', 'label'])
                ->map(fn (Role $role): array => ['value' => (string) $role->name, 'label' => (string) $role->label])->values()->all(),
            'can_all_sites' => $this->access->canViewAllSites($viewer),
        ];
    }

    /** @return array{rows: list<array<string, mixed>>} */
    public function present(User $viewer, ?int $siteId = null): array
    {
        $labels = Role::query()->pluck('label', 'name');
        $canManage = $viewer->canDo('securityDevices.monitoring.manage');

        return [
            'rows' => $this->query($viewer, $siteId)
                ->with(['site:id,name', 'device:id,name'])
                ->orderByDesc('is_active')
                ->orderBy('scope_kind')
                ->orderBy('name')
                ->get()
                ->map(function (MonitoringAlertRoute $route) use ($labels, $canManage): array {
                    $who = $route->recipient_role
                        ? (string) ($labels[$route->recipient_role] ?? ucfirst(str_replace('_', ' ', (string) $route->recipient_role)))
                        : 'IT for that Site';
                    $offline = (string) $route->monitor_kind === 'icmp';
                    $category = self::CATEGORIES[strtolower((string) $route->device_category)] ?? null;

                    return [
                        'id' => (int) $route->id,
                        'version' => (int) $route->version,
                        'name' => (string) $route->name,
                        'when' => $offline ? 'A device stops answering' : 'A check fails or needs attention',
                        'sub' => 'Rule · '.($category ?? self::DOMAINS[(string) $route->device_domain] ?? 'every kind of device'),
                        'icon' => $offline ? 'wifi-off' : 'bell',
                        'where' => match ((string) $route->scope_kind) {
                            'site' => (string) ($route->site?->name ?? 'One Site'),
                            'device', 'monitor' => (string) ($route->device?->name ?? 'One device'),
                            default => 'All Sites',
                        },
                        'then' => $this->then($route, $who),
                        'on' => (bool) $route->is_active,
                        'can_change' => $canManage,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    private function then(MonitoringAlertRoute $route, string $who): string
    {
        $how = fn (bool $app, bool $email): ?string => match (true) {
            $app && $email => 'app and email',
            $app => 'app',
            $email => 'email',
            default => null,
        };
        $critical = $how((bool) $route->critical_notify_in_app, (bool) $route->critical_notify_email);
        $warning = $how((bool) $route->warning_notify_in_app, (bool) $route->warning_notify_email);
        $parts = [];
        if ($critical !== null) {
            $parts[] = "When it fails, tell {$who} ({$critical})";
        }
        if ($warning !== null) {
            $parts[] = "when it needs attention, tell {$who} ({$warning})";
        }
        if ((bool) $route->critical_open_it_ticket || (bool) $route->warning_open_it_ticket) {
            $parts[] = 'open an IT ticket';
        }

        return $parts === [] ? 'Tell nobody' : ucfirst(implode(' · ', $parts));
    }

    /**
     * Rules the viewer may see: every-Site rules, their Sites' rules and their devices' rules.
     * With a Site chosen, only the rules that apply there.
     *
     * @return Builder<MonitoringAlertRoute>
     */
    private function query(User $viewer, ?int $siteId = null): Builder
    {
        $siteIds = $siteId !== null ? [$siteId] : $this->access->accessibleSiteIds($viewer);
        $visible = ($siteId !== null ? $this->access->visibleDevicesForSite($viewer, $siteId) : $this->access->visibleDevices($viewer))->select('devices.id');

        return MonitoringAlertRoute::query()->where(fn (Builder $scope) => $scope
            ->where('scope_kind', 'application')
            ->orWhere(fn (Builder $site) => $site->where('scope_kind', 'site')->whereIn('site_id', $siteIds ?: [0]))
            ->orWhere(fn (Builder $device) => $device->whereIn('scope_kind', ['device', 'monitor'])->whereIn('device_id', $visible)));
    }
}
