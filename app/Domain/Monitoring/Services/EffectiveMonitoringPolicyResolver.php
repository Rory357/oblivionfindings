<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringAlertRoute;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Models\MonitoringThresholdRule;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class EffectiveMonitoringPolicyResolver
{
    public const array LIFE_SAFETY_DOMAINS = [
        'security',
        'iot_healthcare',
    ];

    public function __construct(
        private readonly MaintenanceEvaluator $maintenance,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
    ) {}

    /**
     * @return array{
     *     source: string,
     *     rule_id: ?int,
     *     rule_name: ?string,
     *     scope_kind: ?string,
     *     metric_key: ?string,
     *     direction: string,
     *     warning_rising: ?float,
     *     warning_falling: ?float,
     *     critical_rising: ?float,
     *     critical_falling: ?float
     * }
     */
    public function resolveThreshold(Monitor $monitor, ?int $siteId = null): array
    {
        $monitor->loadMissing(['profile', 'device']);
        $winningRule = $this->findWinningThresholdRule($monitor, $siteId);

        if ($winningRule !== null) {
            return [
                'source' => 'threshold_rule',
                'rule_id' => (int) $winningRule->id,
                'rule_name' => (string) $winningRule->name,
                'scope_kind' => (string) $winningRule->scope_kind,
                'metric_key' => $winningRule->metric_key !== null && $winningRule->metric_key !== ''
                    ? (string) $winningRule->metric_key
                    : null,
                'direction' => in_array($winningRule->threshold_direction, ['above', 'below'], true)
                    ? $winningRule->threshold_direction
                    : 'above',
                'warning_rising' => $winningRule->warning_rising_threshold === null ? null : (float) $winningRule->warning_rising_threshold,
                'warning_falling' => $winningRule->warning_falling_threshold === null ? null : (float) $winningRule->warning_falling_threshold,
                'critical_rising' => $winningRule->critical_rising_threshold === null ? null : (float) $winningRule->critical_rising_threshold,
                'critical_falling' => $winningRule->critical_falling_threshold === null ? null : (float) $winningRule->critical_falling_threshold,
            ];
        }

        $profile = $monitor->profile;
        if ($profile !== null && ($profile->rising_threshold !== null || $profile->warning_rising_threshold !== null)) {
            $direction = in_array($profile->threshold_direction ?? 'above', ['above', 'below'], true)
                ? (string) ($profile->threshold_direction ?? 'above')
                : 'above';

            return [
                'source' => 'profile',
                'rule_id' => (int) $profile->id,
                'rule_name' => (string) $profile->name,
                'scope_kind' => 'profile',
                'metric_key' => null,
                'direction' => $direction,
                'warning_rising' => $profile->warning_rising_threshold === null ? null : (float) $profile->warning_rising_threshold,
                'warning_falling' => $profile->warning_falling_threshold === null ? null : (float) $profile->warning_falling_threshold,
                'critical_rising' => $profile->rising_threshold === null ? null : (float) $profile->rising_threshold,
                'critical_falling' => $profile->falling_threshold === null ? null : (float) $profile->falling_threshold,
            ];
        }

        return [
            'source' => 'none',
            'rule_id' => null,
            'rule_name' => null,
            'scope_kind' => null,
            'metric_key' => null,
            'direction' => 'above',
            'warning_rising' => null,
            'warning_falling' => null,
            'critical_rising' => null,
            'critical_falling' => null,
        ];
    }

    /**
     * @return array{
     *     source: string,
     *     locked_to_control_room: bool,
     *     rule_id: ?int,
     *     rule_name: string,
     *     scope_kind: string,
     *     warning_notify_in_app: bool,
     *     warning_notify_email: bool,
     *     warning_open_it_ticket: bool,
     *     critical_notify_in_app: bool,
     *     critical_notify_email: bool,
     *     critical_open_it_ticket: bool,
     *     critical_escalate_control_room: bool,
     *     recipient_role: ?string
     * }
     */
    public function resolveAlertRoute(Device $device, ?Monitor $monitor, int $siteId): array
    {
        if ((string) $device->domain !== 'it_infrastructure') {
            return [
                'source' => 'life_safety_floor',
                'locked_to_control_room' => true,
                'rule_id' => null,
                'rule_name' => 'Life-safety and security floor (Control Room locked)',
                'scope_kind' => 'application',
                'warning_notify_in_app' => false,
                'warning_notify_email' => false,
                'warning_open_it_ticket' => false,
                'critical_notify_in_app' => false,
                'critical_notify_email' => false,
                'critical_open_it_ticket' => false,
                'critical_escalate_control_room' => true,
                'recipient_role' => null,
            ];
        }

        $winning = $this->findWinningAlertRoute($device, $monitor, $siteId);
        if ($winning !== null) {
            return [
                'source' => 'alert_route',
                'locked_to_control_room' => false,
                'rule_id' => (int) $winning->id,
                'rule_name' => (string) $winning->name,
                'scope_kind' => (string) $winning->scope_kind,
                'warning_notify_in_app' => (bool) $winning->warning_notify_in_app,
                'warning_notify_email' => (bool) $winning->warning_notify_email,
                'warning_open_it_ticket' => (bool) $winning->warning_open_it_ticket,
                'critical_notify_in_app' => (bool) $winning->critical_notify_in_app,
                'critical_notify_email' => (bool) $winning->critical_notify_email,
                'critical_open_it_ticket' => (bool) $winning->critical_open_it_ticket,
                'critical_escalate_control_room' => (bool) $winning->critical_escalate_control_room,
                'recipient_role' => $winning->recipient_role,
            ];
        }

        return [
            'source' => 'default',
            'locked_to_control_room' => false,
            'rule_id' => null,
            'rule_name' => 'Default technical network routing',
            'scope_kind' => 'application',
            'warning_notify_in_app' => true,
            'warning_notify_email' => false,
            'warning_open_it_ticket' => false,
            'critical_notify_in_app' => true,
            'critical_notify_email' => true,
            'critical_open_it_ticket' => true,
            'critical_escalate_control_room' => false,
            'recipient_role' => null,
        ];
    }

    /**
     * @return array{
     *     active: ?array{id: int, name: string, scope: string, starts_at: ?string, ends_at: ?string, reason: string},
     *     upcoming: ?array{id: int, name: string, scope: string, starts_at: ?string, ends_at: ?string, reason: string},
     *     life_safety_exempt: bool
     * }
     */
    public function resolveMaintenanceContext(
        ?Monitor $monitor,
        ?Device $device,
        int $siteId,
        ?CarbonImmutable $at = null,
    ): array {
        $at ??= CarbonImmutable::now('UTC');
        $domain = (string) ($device?->domain ?? $monitor?->device?->domain ?? 'it_infrastructure');
        $lifeSafetyExempt = $domain !== 'it_infrastructure';

        if (! $this->databaseAvailable('monitoring_maintenance_windows')) {
            return ['active' => null, 'upcoming' => null, 'life_safety_exempt' => $lifeSafetyExempt];
        }

        $activeWindow = null;
        if ($monitor !== null && $monitor->exists) {
            try {
                $resolved = $this->maintenance->resolve($monitor, $at);
                $activeWindow = $resolved['window'];
            } catch (Throwable) {
                $activeWindow = null;
            }
        } elseif ($device !== null && $device->exists) {
            $activeWindow = MonitoringMaintenanceWindow::query()
                ->where('site_id', $siteId)
                ->where('status', 'active')
                ->where('starts_at', '<=', $at)
                ->where(fn ($q) => $q->whereNull('recurrence_until')->orWhere('recurrence_until', '>', $at))
                ->where(fn ($q) => $q
                    ->where('device_id', $device->id)
                    ->orWhere(fn ($s) => $s->whereNull('device_id')->whereNull('monitor_id')))
                ->orderByRaw('CASE WHEN device_id IS NOT NULL THEN 0 ELSE 1 END')
                ->orderBy('id')
                ->get()
                ->first(fn (MonitoringMaintenanceWindow $window): bool => $this->maintenance->containsOccurrence($window, $at));
        }

        $upcomingWindow = MonitoringMaintenanceWindow::query()
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->where('starts_at', '>', $at)
            ->where('starts_at', '<=', $at->addDays(7))
            ->where(function ($q) use ($monitor, $device): void {
                $q->where(fn ($s) => $s->whereNull('device_id')->whereNull('monitor_id'));
                if ($device !== null && $device->exists) {
                    $q->orWhere('device_id', $device->id);
                }
                if ($monitor !== null && $monitor->exists) {
                    $q->orWhere('monitor_id', $monitor->id);
                }
            })
            ->orderBy('starts_at')
            ->orderBy('id')
            ->first();

        return [
            'active' => $activeWindow ? $this->formatMaintenanceWindow($activeWindow) : null,
            'upcoming' => $upcomingWindow ? $this->formatMaintenanceWindow($upcomingWindow) : null,
            'life_safety_exempt' => $lifeSafetyExempt,
        ];
    }

    /** @return array<string, mixed> */
    public function previewForMonitor(Monitor $monitor, int $siteId, ?CarbonImmutable $at = null): array
    {
        $monitor->loadMissing(['profile', 'device']);
        $device = $monitor->device;
        $kind = $monitor->kind instanceof MonitorKind ? $monitor->kind->value : (string) $monitor->kind;
        $threshold = $this->resolveThreshold($monitor, $siteId);
        $alertRoute = $device !== null
            ? $this->resolveAlertRoute($device, $monitor, $siteId)
            : $this->resolveAlertRoute(new Device(['domain' => 'it_infrastructure']), $monitor, $siteId);
        $maintenance = $this->resolveMaintenanceContext($monitor, $device, $siteId, $at);
        $recipientCount = $alertRoute['locked_to_control_room']
            ? 0
            : $this->resolveItRecipients($siteId, $alertRoute['recipient_role'])->count();

        $mapWindow = static fn (?array $window): ?array => $window === null ? null : [
            'id' => $window['id'],
            'name' => $window['name'],
            'starts_at' => $window['starts_at'],
            'ends_at' => $window['ends_at'],
            'timezone' => $window['timezone'] ?? 'Pacific/Auckland',
            'life_safety_protected' => $maintenance['life_safety_exempt'],
        ];

        return [
            'monitor' => [
                'id' => (int) $monitor->id,
                'name' => (string) $monitor->name,
                'kind' => $kind,
                'metric_key' => $threshold['metric_key'] ?? 'value',
            ],
            'device' => [
                'id' => (int) $monitor->device_id,
                'name' => (string) ($device?->name ?? 'Device #'.$monitor->device_id),
                'domain' => $device?->domain,
                'category' => $device?->category,
                'site_id' => $siteId,
            ],
            'threshold' => [
                'source_kind' => $threshold['scope_kind'] ?? $threshold['source'],
                'rule_id' => $threshold['rule_id'],
                'rule_name' => $threshold['rule_name'],
                'metric_key' => $threshold['metric_key'] ?? 'value',
                'direction' => $threshold['direction'],
                'warning_rising' => $threshold['warning_rising'],
                'warning_falling' => $threshold['warning_falling'],
                'critical_rising' => $threshold['critical_rising'],
                'critical_falling' => $threshold['critical_falling'],
            ],
            'alert_route' => [
                'source_kind' => $alertRoute['source'] === 'alert_route'
                    ? $alertRoute['scope_kind']
                    : $alertRoute['source'],
                'rule_id' => $alertRoute['rule_id'],
                'rule_name' => $alertRoute['rule_name'],
                'life_safety_floor' => $alertRoute['locked_to_control_room'],
                'recipient_role' => $alertRoute['recipient_role'] ?? 'it_staff',
                'warning' => [
                    'notify_in_app' => $alertRoute['warning_notify_in_app'],
                    'notify_email' => $alertRoute['warning_notify_email'],
                    'open_it_ticket' => $alertRoute['warning_open_it_ticket'],
                ],
                'critical' => [
                    'notify_in_app' => $alertRoute['critical_notify_in_app'],
                    'notify_email' => $alertRoute['critical_notify_email'],
                    'open_it_ticket' => $alertRoute['critical_open_it_ticket'],
                    'escalate_control_room' => $alertRoute['critical_escalate_control_room'],
                ],
            ],
            'maintenance' => [
                'active_window' => $mapWindow($maintenance['active']),
                'upcoming_window' => $mapWindow($maintenance['upcoming']),
            ],
            'recipient_count' => $recipientCount,
        ];
    }

    /** @return Collection<int, User> */
    public function resolveItRecipients(int $siteId, ?string $recipientRole = null): Collection
    {
        if (! $this->databaseAvailable('users')) {
            return collect();
        }

        $access = app(SecurityDevicesAccessService::class);
        $query = User::query()
            ->whereNotNull('approved_at')
            ->with(['roles.permissions']);

        if ($recipientRole !== null && $recipientRole !== '') {
            $query->whereHas('roles', fn ($q) => $q->where('name', $recipientRole));
        }

        return $query
            ->limit(100)
            ->get()
            ->filter(function (User $user) use ($access, $siteId): bool {
                if (! ($user->canDo('it.view') || $user->canDo('it.manage') || $user->canDo('securityDevices.monitoring.manage'))) {
                    return false;
                }
                if ($access->canViewAllSites($user) || $user->canDo('it.manage')) {
                    return true;
                }

                return in_array($siteId, $access->accessibleSiteIds($user), true);
            })
            ->values();
    }

    private function findWinningThresholdRule(Monitor $monitor, ?int $siteId): ?MonitoringThresholdRule
    {
        if (! $monitor->exists || ! $this->databaseAvailable('monitoring_threshold_rules')) {
            return null;
        }

        $resolvedSiteId = $siteId ?? $this->safeResolveSiteId((int) $monitor->device_id);
        $device = $monitor->device;
        $domain = (string) ($device?->domain ?? '');
        $category = strtolower((string) ($device?->category ?? ''));
        $kind = $monitor->kind instanceof MonitorKind ? $monitor->kind->value : (string) $monitor->kind;

        $candidates = MonitoringThresholdRule::query()
            ->where('is_active', true)
            ->where(function ($q) use ($monitor, $resolvedSiteId): void {
                $q->where('scope_kind', 'application')
                    ->orWhere(fn ($m) => $m->where('scope_kind', 'monitor')->where('monitor_id', $monitor->id))
                    ->orWhere(fn ($d) => $d->where('scope_kind', 'device')->where('device_id', $monitor->device_id));
                if ($resolvedSiteId !== null) {
                    $q->orWhere(fn ($s) => $s->where('scope_kind', 'site')->where('site_id', $resolvedSiteId));
                }
            })
            ->get()
            ->filter(function (MonitoringThresholdRule $rule) use ($domain, $category, $kind): bool {
                if ($rule->device_domain !== null && $rule->device_domain !== '' && $rule->device_domain !== $domain) {
                    return false;
                }
                if ($rule->device_category !== null && $rule->device_category !== '' && strtolower($rule->device_category) !== $category) {
                    return false;
                }
                if ($rule->monitor_kind !== null && $rule->monitor_kind !== '' && $rule->monitor_kind !== $kind) {
                    return false;
                }

                return true;
            });

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sort(function (MonitoringThresholdRule $a, MonitoringThresholdRule $b): int {
                $scoreA = $this->scoreScope($a->scope_kind, $a->monitor_kind, $a->device_category, $a->device_domain, $a->metric_key);
                $scoreB = $this->scoreScope($b->scope_kind, $b->monitor_kind, $b->device_category, $b->device_domain, $b->metric_key);

                return $scoreB <=> $scoreA ?: ((int) $b->id <=> (int) $a->id);
            })
            ->first();
    }

    public function findWinningAlertRoute(Device $device, ?Monitor $monitor, int $siteId): ?MonitoringAlertRoute
    {
        if (! $device->exists || (string) $device->domain !== 'it_infrastructure' || ! $this->databaseAvailable('monitoring_alert_routes')) {
            return null;
        }

        $domain = (string) $device->domain;
        $category = strtolower((string) ($device->category ?? ''));
        $kind = $monitor === null
            ? null
            : ($monitor->kind instanceof MonitorKind ? $monitor->kind->value : (string) $monitor->kind);

        $candidates = MonitoringAlertRoute::query()
            ->where('is_active', true)
            ->where(function ($q) use ($device, $monitor, $siteId): void {
                $q->where('scope_kind', 'application')
                    ->orWhere(fn ($s) => $s->where('scope_kind', 'site')->where('site_id', $siteId))
                    ->orWhere(fn ($d) => $d->where('scope_kind', 'device')->where('device_id', $device->id));
                if ($monitor !== null && $monitor->exists) {
                    $q->orWhere(fn ($m) => $m->where('scope_kind', 'monitor')->where('monitor_id', $monitor->id));
                }
            })
            ->get()
            ->filter(function (MonitoringAlertRoute $route) use ($domain, $category, $kind): bool {
                if ($route->device_domain !== null && $route->device_domain !== '' && $route->device_domain !== $domain) {
                    return false;
                }
                if ($route->device_category !== null && $route->device_category !== '' && strtolower($route->device_category) !== $category) {
                    return false;
                }
                if ($route->monitor_kind !== null && $route->monitor_kind !== '') {
                    if ($kind === null || $route->monitor_kind !== $kind) {
                        return false;
                    }
                }

                return true;
            });

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sort(function (MonitoringAlertRoute $a, MonitoringAlertRoute $b): int {
                $scoreA = $this->scoreScope($a->scope_kind, $a->monitor_kind, $a->device_category, $a->device_domain, null);
                $scoreB = $this->scoreScope($b->scope_kind, $b->monitor_kind, $b->device_category, $b->device_domain, null);

                return $scoreB <=> $scoreA ?: ((int) $b->id <=> (int) $a->id);
            })
            ->first();
    }

    private function scoreScope(
        string $scopeKind,
        ?string $monitorKind,
        ?string $deviceCategory,
        ?string $deviceDomain,
        ?string $metricKey,
    ): int {
        $base = match ($scopeKind) {
            'monitor' => 400,
            'device' => 300,
            'site' => 200,
            default => 100,
        };

        return $base
            + ($monitorKind !== null && $monitorKind !== '' ? 8 : 0)
            + ($deviceCategory !== null && $deviceCategory !== '' ? 4 : 0)
            + ($deviceDomain !== null && $deviceDomain !== '' ? 2 : 0)
            + ($metricKey !== null && $metricKey !== '' ? 1 : 0);
    }

    /**
     * @return array{id: int, name: string, scope: string, starts_at: ?string, ends_at: ?string, reason: string}
     */
    private function formatMaintenanceWindow(MonitoringMaintenanceWindow $window): array
    {
        return [
            'id' => (int) $window->id,
            'name' => (string) $window->name,
            'scope' => $window->monitor_id !== null
                ? 'monitor'
                : ($window->device_id !== null ? 'device' : 'site'),
            'starts_at' => $window->starts_at?->toIso8601String(),
            'ends_at' => $window->ends_at?->toIso8601String(),
            'reason' => (string) $window->reason,
        ];
    }

    private function safeResolveSiteId(int $deviceId): ?int
    {
        if ($deviceId < 1) {
            return null;
        }
        try {
            return $this->siteResolver->resolve($deviceId);
        } catch (Throwable) {
            return null;
        }
    }

    private function databaseAvailable(string $table): bool
    {
        if (Monitor::getConnectionResolver() === null) {
            return false;
        }
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
