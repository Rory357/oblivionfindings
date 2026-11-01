<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDependency;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\EffectiveMonitoringPolicyResolver;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The Checks & alerts tab every new device page shares (drawing pg-sdr-3c): each check in plain
 * words with how it's done, how often, its last result and when it alerts; any quiet time; who's
 * told (the alert rule that covers it); what it depends on; and its alerts in the last 90 days.
 */
final class DeviceChecksTab
{
    /** Kind of check => [title, question]. */
    private const array CHECK_WORDS = [
        'icmp' => ['Online', 'Does it answer?'],
        'tcp' => ['Service port', 'Does its service answer?'],
        'http' => ['Web page', 'Does its web page load?'],
        'tls' => ['Certificate', 'Is its certificate in date?'],
        'dns' => ['Name lookup', 'Does its name resolve?'],
        'snmp' => ['Readings', 'What it reports about itself'],
        'snmp_interface' => ['Port', 'Up, down and speed'],
        'ssh_inventory' => ['Settings backup', 'A copy of its settings'],
        'winrm_inventory' => ['Computer details', 'What Windows reports'],
        'provider' => ['Service reports', 'What its connected service reports'],
        'collector' => ['Hub', 'Is its hub connected?'],
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceObservations $observations,
        private readonly EffectiveMonitoringPolicyResolver $policy,
    ) {}

    /**
     * @param  Collection<int, Monitor>  $monitors  enabled checks, with profile and collector loaded
     * @return array<string, mixed>
     */
    public function present(User $viewer, Device $device, DeviceKind $kind, Collection $monitors, ?int $siteId, ?string $siteName, ?CarbonImmutable $custody, CarbonImmutable $now): array
    {
        $latest = $this->observations->latest($monitors, $custody);
        $accessible = $this->access->accessibleSiteIds($viewer);

        return [
            'noun' => $this->noun($kind),
            'rows' => $monitors->map(fn (Monitor $monitor): array => $this->row($monitor, $latest->get($monitor->id), $accessible))->values()->all(),
            'quiet_time' => $siteId === null ? null : $this->quietTime($device, $monitors, $siteId, $now),
            'told' => $siteId === null ? null : $this->told($device, $siteName),
            'depends' => $this->depends($viewer, $monitors),
            'alerts' => $this->alerts($viewer, $device, $now),
        ];
    }

    private function noun(DeviceKind $kind): string
    {
        return match ($kind) {
            DeviceKind::Switch => 'switch',
            DeviceKind::Router => 'router',
            DeviceKind::WifiPoint => 'Wi-Fi point',
            DeviceKind::Computer => 'computer',
            DeviceKind::Printer => 'printer',
            DeviceKind::Ups => 'UPS',
            DeviceKind::Recorder => 'recorder',
            DeviceKind::SensorGateway => 'gateway',
            default => 'device',
        };
    }

    /**
     * @param  list<int>  $accessible
     * @return array<string, mixed>
     */
    private function row(Monitor $monitor, ?MonitorObservation $latest, array $accessible): array
    {
        $kind = $monitor->kind?->value ?? '';
        [$title, $question] = self::CHECK_WORDS[$kind] ?? ['Check', 'A check Oblivion runs'];
        if ($monitor->kind === MonitorKind::SnmpInterface) {
            $port = data_get($latest?->metrics, 'interface_name') ?? data_get($latest?->metrics, 'if_name');
            $title = 'Port '.(is_string($port) && $port !== '' ? $port : trim((string) $monitor->name));
        }

        return [
            'id' => (int) $monitor->id,
            'kind' => $kind,
            'title' => $title,
            'question' => $question,
            'how' => $this->how($monitor, $accessible),
            'every' => $this->every($monitor->profile?->interval_seconds),
            'result' => $this->result($monitor, $latest),
            'alert_when' => $this->alertWhen($monitor),
        ];
    }

    /** @param list<int> $accessible */
    private function how(Monitor $monitor, array $accessible): string
    {
        $from = $monitor->collector instanceof MonitoringCollector
            ? (in_array((int) $monitor->collector->site_id, $accessible, true) ? CoveringHubResolver::label($monitor->collector) : 'its hub')
            : 'the Oblivion server';

        return match ($monitor->kind) {
            MonitorKind::Icmp => 'Ping from '.$from,
            MonitorKind::Tcp => 'Connects from '.$from,
            MonitorKind::Http => 'Loads the page from '.$from,
            MonitorKind::Tls => 'Reads the certificate from '.$from,
            MonitorKind::Dns => 'Looks up the name from '.$from,
            MonitorKind::Snmp, MonitorKind::SnmpInterface => 'SNMP from '.$from,
            MonitorKind::SshInventory => 'SSH from '.$from.' · saved login',
            MonitorKind::WinRmInventory => 'Windows remote reads from '.$from,
            MonitorKind::Provider => 'Its connected service',
            MonitorKind::Collector => 'The hub’s own heartbeat',
            default => 'From '.$from,
        };
    }

    private function every(?int $seconds): string
    {
        return match (true) {
            $seconds === null || $seconds <= 0 => '—',
            $seconds < 60 => 'Every '.$seconds.' s',
            $seconds === 60 => 'Every minute',
            $seconds < 3600 => 'Every '.intdiv($seconds, 60).' min',
            $seconds === 3600 => 'Every hour',
            $seconds < 86_400 => 'Every '.intdiv($seconds, 3600).' h',
            $seconds === 86_400 => 'Every day',
            default => 'Every '.intdiv($seconds, 86_400).' days',
        };
    }

    /** @return array{label: string, tone: string, at: ?string} */
    private function result(Monitor $monitor, ?MonitorObservation $latest): array
    {
        $at = ($latest?->observed_at ?? $monitor->last_observation_at)?->toIso8601String();
        $isPing = $monitor->kind === MonitorKind::Icmp;
        $latency = $latest?->latency_ms;

        return match ($monitor->current_state?->value) {
            'healthy' => [
                'label' => $isPing && $latency !== null ? 'Answered in '.($latency < 1 ? 'under 1' : $latency).' ms' : 'Passing',
                'tone' => 'success',
                'at' => $at,
            ],
            'degraded' => ['label' => $isPing ? 'Slow' : 'Needs attention', 'tone' => 'warning', 'at' => $at],
            'failed' => ['label' => $isPing ? 'No answer' : 'Not passing', 'tone' => 'critical', 'at' => $at],
            'suppressed' => ['label' => 'Can’t check', 'tone' => 'neutral', 'at' => $at],
            'stale' => ['label' => 'No recent result', 'tone' => 'neutral', 'at' => $at],
            default => ['label' => 'No result yet', 'tone' => 'neutral', 'at' => null],
        };
    }

    private function alertWhen(Monitor $monitor): string
    {
        $profile = $monitor->profile;
        if ($profile === null) {
            return '—';
        }
        $times = max(1, (int) ($profile->failure_confirmations ?? 1));
        $what = $monitor->kind === MonitorKind::Icmp ? 'missed' : 'failed';
        $duration = (int) ($profile->failure_duration_seconds ?? 0);

        if ($times > 1) {
            return $times.' '.$what.' in a row';
        }
        if ($duration >= 60) {
            return 'Not passing for '.intdiv($duration, 60).' min';
        }

        return $monitor->kind === MonitorKind::Icmp ? 'The first missed check' : 'The first failed check';
    }

    /**
     * The quiet time that covers it now, or the next one this week.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @return array<string, mixed>|null
     */
    private function quietTime(Device $device, Collection $monitors, int $siteId, CarbonImmutable $now): ?array
    {
        $monitorIds = $monitors->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $window = MonitoringMaintenanceWindow::query()
            ->where('status', 'active')
            ->where('site_id', $siteId)
            ->where('ends_at', '>', $now)
            ->where('starts_at', '<=', $now->addDays(7))
            ->where(function ($query) use ($device, $monitorIds): void {
                $query->where(fn ($site) => $site->whereNull('device_id')->whereNull('monitor_id'))
                    ->orWhere('device_id', $device->id)
                    ->orWhereIn('monitor_id', $monitorIds === [] ? [0] : $monitorIds);
            })
            ->orderBy('starts_at')
            ->first();
        if ($window === null) {
            return null;
        }
        $by = $window->created_by_user_id ? User::query()->whereKey($window->created_by_user_id)->value('name') : null;

        return [
            'name' => (string) $window->name,
            'starts_at' => $window->starts_at?->toIso8601String(),
            'ends_at' => $window->ends_at?->toIso8601String(),
            'now' => $window->starts_at !== null && $window->starts_at->lte($now),
            'whole_site' => $window->device_id === null && $window->monitor_id === null,
            'reason' => $window->reason ? (string) $window->reason : null,
            'by' => $by,
        ];
    }

    /**
     * Who's told, from the alert rule that covers this device.
     *
     * @return array{sub: string, rows: list<array{0: string, 1: string}>}|null
     */
    public function told(Device $device, ?string $siteName): ?array
    {
        try {
            $siteId = (int) $device->assignments->whereNull('released_at')->sortByDesc('assigned_at')->first()?->custody_site_id;
            $route = $siteId > 0 ? $this->policy->resolveAlertRoute($device, null, $siteId) : null;
        } catch (Throwable) {
            $route = null;
        }
        if ($route === null) {
            return null;
        }
        if ($route['locked_to_control_room']) {
            return [
                'sub' => 'Safety and security devices always go to the Control Room',
                'rows' => [['When it stops working', 'The Control Room']],
            ];
        }

        $who = $route['recipient_role']
            ? $route['recipient_role']
            : 'IT staff who look after '.($siteName ?? 'this Site');
        $channels = static function (bool $app, bool $email): string {
            $ways = array_values(array_filter([$app ? 'app' : null, $email ? 'email' : null]));

            return $ways === [] ? 'not sent' : implode(' and ', $ways);
        };
        $critical = $who.' · '.$channels($route['critical_notify_in_app'], $route['critical_notify_email'])
            .($route['critical_open_it_ticket'] ? ' · opens an IT ticket' : '')
            .($route['critical_escalate_control_room'] ? ' · and the Control Room' : '');
        $warning = $who.' · '.$channels($route['warning_notify_in_app'], $route['warning_notify_email'])
            .($route['warning_open_it_ticket'] ? ' · opens an IT ticket' : '');

        return [
            'sub' => $route['source'] === 'alert_route'
                ? 'From the alert rule “'.$route['rule_name'].'”'
                : 'From Oblivion’s standard rule for network gear',
            'rows' => [
                ['When it stops working', $critical],
                ['When it needs attention', $warning],
            ],
        ];
    }

    /**
     * What it depends on and what depends on it (the dependencies that turn devices grey
     * instead of raising an alert each). Only devices the viewer may see are named or counted.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @return array{upstream: list<string>, downstream: int}
     */
    private function depends(User $viewer, Collection $monitors): array
    {
        $ids = $monitors->pluck('id')->all();
        if ($ids === []) {
            return ['upstream' => [], 'downstream' => 0];
        }
        $upstreamMonitorIds = MonitorDependency::query()->where('is_active', true)->whereIn('downstream_monitor_id', $ids)->pluck('upstream_monitor_id');
        $downstreamMonitorIds = MonitorDependency::query()->where('is_active', true)->whereIn('upstream_monitor_id', $ids)->pluck('downstream_monitor_id');
        $upstreamDevices = $upstreamMonitorIds->isEmpty() ? collect() : Monitor::query()->whereIn('id', $upstreamMonitorIds)->pluck('device_id')->filter()->unique();
        $downstreamDevices = $downstreamMonitorIds->isEmpty() ? collect() : Monitor::query()->whereIn('id', $downstreamMonitorIds)->pluck('device_id')->filter()->unique();

        $upstream = $upstreamDevices->isEmpty()
            ? []
            : $this->access->visibleDevices($viewer)->whereKey($upstreamDevices->all())->orderBy('name')->pluck('name')->map(fn (mixed $name): string => (string) $name)->all();
        $downstream = $downstreamDevices->isEmpty() ? 0 : $this->access->visibleDevices($viewer)->whereKey($downstreamDevices->all())->count();

        return ['upstream' => $upstream, 'downstream' => $downstream];
    }

    /**
     * Its warnings and problems in the last 90 days, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function alerts(User $viewer, Device $device, CarbonImmutable $now): array
    {
        return $this->access
            ->applyTemporalEventCustodyScope(DeviceEvent::query()->where('device_id', $device->id), $viewer)
            ->where('occurred_at', '>=', $now->subDays(90))
            ->whereIn('severity', ['warning', 'critical', 'error', 'high'])
            ->latest('occurred_at')
            ->limit(8)
            ->get(['id', 'event_type', 'severity', 'occurred_at', 'source'])
            ->map(fn (DeviceEvent $event): array => DevicePageWords::event($event))
            ->values()
            ->all();
    }
}
