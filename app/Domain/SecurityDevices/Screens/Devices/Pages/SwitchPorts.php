<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\NetworkChecks\Models\NetworkCheckSession;
use App\Domain\SecurityDevices\NetworkChecks\Models\NetworkCheckStep;
use App\Domain\SecurityDevices\NetworkChecks\NetworkChecks;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A switch's Ports tab (S&D v13, pg-sdr-3d): what's plugged into each port, its speed, the power it
 * draws, its errors and its last change, problems first, then changes newest first. Read-only:
 * Oblivion doesn't turn a port's power off or on, or restart the switch (GOAL R15).
 *
 * The data is what's really stored, nothing more:
 *  - its port checks (SNMP interface monitors): up or down, speed, error counters, last change;
 *  - the devices linked to its ports (device relationships with a port), visible ones only;
 *  - the last UniFi port check Obli ran through the Site's hub (pop-up 97), which is the only
 *    place power per port (PoE) is read, for people who may ask Obli and see the Site.
 * A column with none of these says "Not available", never 0.
 */
final class SwitchPorts
{
    /** Errors are counted over this window from the port counters. */
    private const int ERROR_HOURS = 24;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
    ) {}

    /**
     * @param  Collection<int, Monitor>  $monitors  its enabled checks
     * @param  Collection<int, MonitorObservation>  $latest  each check's newest observation at this Site
     * @return array<string, mixed>
     */
    public function present(User $viewer, Device $device, Collection $monitors, Collection $latest, ?CarbonImmutable $custody, CarbonImmutable $now): array
    {
        $interfaces = $monitors->filter(fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::SnmpInterface)->values();
        $errorsFrom = $now->subHours(self::ERROR_HOURS);
        $windowStart = $custody !== null && $custody->greaterThan($errorsFrom) ? $custody : $errorsFrom;
        $earliest = $this->earliestInWindow($interfaces, $windowStart);
        $unifi = $this->unifi($viewer, $device);
        $links = $this->links($viewer, $device);

        /** @var array<int, array<string, mixed>> $ports */
        $ports = [];
        $blank = fn (int $number): array => [
            'port' => $number,
            'device' => null,
            'label' => null,
            'up' => null,
            'speed' => null,
            'poe_w' => null,
            'errors' => null,
            // UniFi's own counters, since the switch last started (only when no port check counts them).
            'errors_total' => null,
            'changed_at' => null,
            'change' => null,
            'problem' => false,
        ];
        $readAt = null;

        foreach ($interfaces as $monitor) {
            $observation = $latest->get($monitor->id);
            $metrics = is_array($observation?->metrics) ? $observation->metrics : [];
            $number = $this->portNumber($monitor, $metrics);
            if ($number === null) {
                continue;
            }
            $port = $ports[$number] ?? $blank($number);
            $admin = strtolower((string) ($metrics['admin_status'] ?? ''));
            $oper = strtolower((string) ($metrics['operational_status'] ?? $metrics['oper_status'] ?? ''));
            $port['up'] = $oper === '' ? null : $oper === 'up';
            $speed = $metrics['speed_bps'] ?? null;
            $port['speed'] = $port['up'] === true && is_numeric($speed) && (int) $speed > 0 ? self::speed((int) $speed / 1_000_000) : null;
            $alias = trim((string) ($metrics['interface_alias'] ?? ''));
            $port['label'] = $alias !== '' ? $alias : null;
            $start = $earliest[$monitor->id] ?? null;
            if (is_numeric($metrics['errors'] ?? null) && $start !== null && is_numeric($start['errors'] ?? null) && empty($metrics['counter_discontinuity'])) {
                $port['errors'] = max(0, (int) $metrics['errors'] - (int) $start['errors']);
            }
            if ($monitor->last_state_changed_at !== null) {
                $port['changed_at'] = CarbonImmutable::parse($monitor->last_state_changed_at)->toIso8601String();
                $port['change'] = match (true) {
                    $admin === 'down' => 'turned off in the switch',
                    $port['up'] === true => 'came up',
                    $port['up'] === false => 'went down',
                    default => 'changed',
                };
            }
            if ($observation?->observed_at !== null && ($readAt === null || $observation->observed_at->greaterThan($readAt))) {
                $readAt = CarbonImmutable::parse($observation->observed_at);
            }
            // Errors are a problem; a port that's down is one only when a device is linked to it (below).
            $port['problem'] = (int) ($port['errors'] ?? 0) > 0;
            $ports[$number] = $port;
        }

        // The last UniFi port check: power per port, and errors and speed where SNMP has none.
        foreach ($unifi['ports'] ?? [] as $reading) {
            $number = (int) $reading['idx'];
            $port = $ports[$number] ?? $blank($number);
            $port['poe_w'] = $reading['poe_w'];
            $port['up'] ??= $reading['up'];
            if ($port['speed'] === null && $reading['up'] && is_int($reading['speed_mbps']) && $reading['speed_mbps'] > 0) {
                $port['speed'] = self::speed($reading['speed_mbps']);
            }
            if ($port['errors'] === null && ($reading['rx_errors'] !== null || $reading['tx_errors'] !== null)) {
                $port['errors_total'] = (int) ($reading['rx_errors'] ?? 0) + (int) ($reading['tx_errors'] ?? 0);
            }
            $port['label'] ??= $reading['name'];
            $ports[$number] = $port;
        }

        // What's plugged in, from the devices linked to its ports.
        foreach ($links as $number => $link) {
            $port = $ports[$number] ?? $blank($number);
            $port['device'] = $link['device'];
            if ($port['changed_at'] === null && $link['linked_at'] !== null) {
                $port['changed_at'] = $link['linked_at'];
                $port['change'] = 'linked to '.$link['device']['name'];
            }
            $ports[$number] = $port;
        }

        ksort($ports);
        $rows = collect($ports)->map(function (array $port): array {
            $port['free'] = $port['device'] === null && $port['up'] === false;
            $port['problem'] = $port['problem'] || ($port['device'] !== null && $port['up'] === false);

            return $port;
        });
        $weekAgo = $now->subDays(7);
        $sorted = $rows
            ->sortBy(fn (array $port): string => ($port['problem'] ? '0' : '1')
                .'|'.($port['changed_at'] !== null && CarbonImmutable::parse($port['changed_at'])->greaterThanOrEqualTo($weekAgo)
                    ? (string) (9_999_999_999 - CarbonImmutable::parse($port['changed_at'])->getTimestamp())
                    : '9999999999')
                .'|'.str_pad((string) $port['port'], 4, '0', STR_PAD_LEFT))
            ->values();

        // Errors over 24 hours where the checks can tell; UniFi's count since the switch started otherwise.
        // Unknown everywhere means the tally says nothing, never 0.
        $knownErrors = $rows->filter(fn (array $port): bool => $port['errors'] !== null || $port['errors_total'] !== null);
        $withErrors = $rows->filter(fn (array $port): bool => (int) ($port['errors'] ?? 0) > 0 || ($port['errors'] === null && (int) ($port['errors_total'] ?? 0) > 0))->values();
        $changed = $rows->filter(fn (array $port): bool => $port['changed_at'] !== null && CarbonImmutable::parse($port['changed_at'])->greaterThanOrEqualTo($weekAgo))->values();
        $poe = $rows->pluck('poe_w')->filter(fn (mixed $watts): bool => $watts !== null);

        return [
            'rows' => $sorted->all(),
            'total' => $rows->count(),
            'tally' => [
                'free' => $interfaces->isEmpty() && $unifi === null ? null : $rows->where('free', true)->count(),
                'poe_w' => $poe->isEmpty() ? null : round((float) $poe->sum(), 1),
                'powering' => $poe->filter(fn (float $watts): bool => $watts > 0)->count(),
                'errors' => $knownErrors->isEmpty() ? null : $withErrors->count(),
                'errors_first' => $withErrors->first() === null ? null : self::portWords($withErrors->first()),
                'changed' => $changed->count(),
                'changed_ports' => $changed->pluck('port')->sort()->values()->all(),
            ],
            'source' => [
                'checks' => $interfaces->count(),
                'every' => $this->every($interfaces),
                'by' => $this->checkedBy($viewer, $interfaces),
                'read_at' => $readAt?->toIso8601String(),
                'unifi_at' => $unifi['at'] ?? null,
                'unifi_hub' => $unifi['hub'] ?? null,
                'error_hours' => self::ERROR_HOURS,
            ],
        ];
    }

    private static function speed(float|int $mbps): string
    {
        return $mbps >= 1000
            ? rtrim(rtrim(number_format($mbps / 1000, 1, '.', ''), '0'), '.').' Gbps'
            : ((int) round($mbps)).' Mbps';
    }

    /** @param array<string, mixed> $port */
    private static function portWords(array $port): string
    {
        return 'Port '.$port['port'].($port['device'] !== null ? ' · '.$port['device']['name'] : ($port['label'] !== null ? ' · '.$port['label'] : ''));
    }

    /**
     * The port number: the interface index the check reads, or the number in its name.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function portNumber(Monitor $monitor, array $metrics): ?int
    {
        $config = is_array($monitor->config) ? $monitor->config : [];
        foreach ([$metrics['if_index'] ?? null, $config['if_index'] ?? null, $config['interface_index'] ?? null] as $value) {
            if (is_numeric($value) && (int) $value > 0 && (int) $value < 10_000) {
                return (int) $value;
            }
        }
        if (preg_match('/(\d{1,4})\s*$/', (string) $monitor->name, $match) === 1 && (int) $match[1] > 0) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * Each port check's oldest observation in the error window, for counting errors since then.
     *
     * @param  Collection<int, Monitor>  $interfaces
     * @return array<int, array<string, mixed>>
     */
    private function earliestInWindow(Collection $interfaces, CarbonImmutable $from): array
    {
        $ids = $interfaces->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $firstIds = MonitorObservation::query()
            ->whereIn('monitor_id', $ids)
            ->where('observed_at', '>=', $from)
            ->selectRaw('MIN(id)')
            ->groupBy('monitor_id');

        return MonitorObservation::query()
            ->whereIn('id', $firstIds)
            ->get(['id', 'monitor_id', 'metrics'])
            ->mapWithKeys(fn (MonitorObservation $observation): array => [(int) $observation->monitor_id => is_array($observation->metrics) ? $observation->metrics : []])
            ->all();
    }

    /**
     * Devices linked to its ports ("Port 7"), only the ones the viewer may see.
     *
     * @return array<int, array{device: array<string, mixed>, linked_at: ?string}>
     */
    private function links(User $viewer, Device $device): array
    {
        $links = DeviceRelationship::query()
            ->active()
            ->where('parent_device_id', $device->id)
            ->whereNotNull('port')
            ->orderBy('id')
            ->limit(256)
            ->get(['child_device_id', 'port', 'created_at']);
        if ($links->isEmpty()) {
            return [];
        }
        $entries = $this->directory->entries($viewer, null, $links->pluck('child_device_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all())
            ->keyBy(fn (DeviceEntry $entry): int => $entry->id());
        $out = [];
        foreach ($links as $link) {
            $entry = $entries->get((int) $link->child_device_id);
            if ($entry === null || preg_match('/(\d{1,4})/', (string) $link->port, $match) !== 1) {
                continue;
            }
            $out[(int) $match[1]] ??= [
                'device' => [
                    'id' => $entry->id(),
                    'name' => $entry->name(),
                    'kind' => $entry->kind->value,
                    'model' => $entry->model(),
                    'state' => $entry->state->value,
                    'href' => '/security-devices/devices/'.$entry->id(),
                ],
                'linked_at' => $link->created_at?->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * The newest UniFi port check Obli ran on this switch, for people who may ask Obli and can see
     * the Site it ran at. Null when there's none.
     *
     * @return array{at: ?string, hub: ?string, ports: list<array<string, mixed>>}|null
     */
    private function unifi(User $viewer, Device $device): ?array
    {
        if (! NetworkChecks::mayAsk($viewer)) {
            return null;
        }
        $sites = $this->access->accessibleSiteIds($viewer);
        if ($sites === []) {
            return null;
        }
        $step = NetworkCheckStep::query()
            ->where('kind', 'switch_ports')
            ->where('target_device_id', $device->id)
            ->where('state', 'done')
            ->whereNotNull('done_at')
            ->whereHas('session', fn ($session) => $session->whereIn('site_id', $sites))
            ->latest('done_at')
            ->with(['session' => fn ($session) => $session->select(['id', 'site_id', 'hub_label'])])
            ->first(['id', 'session_id', 'result', 'done_at']);
        $ports = is_array($step?->result['ports'] ?? null) ? $step->result['ports'] : null;
        if ($step === null || $ports === null) {
            return null;
        }

        return [
            'at' => $step->done_at?->toIso8601String(),
            'hub' => $step->session instanceof NetworkCheckSession && $step->session->hub_label ? (string) $step->session->hub_label : null,
            'ports' => array_values(array_filter($ports, fn (mixed $port): bool => is_array($port) && is_int($port['idx'] ?? null))),
        ];
    }

    /** @param Collection<int, Monitor> $interfaces */
    private function every(Collection $interfaces): ?string
    {
        $seconds = $interfaces->map(fn (Monitor $monitor): ?int => $monitor->profile?->interval_seconds)->filter()->min();
        if (! is_int($seconds) || $seconds <= 0) {
            return null;
        }

        return match (true) {
            $seconds < 60 => 'every '.$seconds.' s',
            $seconds === 60 => 'every minute',
            $seconds < 3600 => 'every '.intdiv($seconds, 60).' min',
            default => 'every '.intdiv($seconds, 3600).' h',
        };
    }

    /** @param Collection<int, Monitor> $interfaces */
    private function checkedBy(User $viewer, Collection $interfaces): ?string
    {
        $monitor = $interfaces->first();
        if ($monitor === null) {
            return null;
        }
        if (! $monitor->collector instanceof MonitoringCollector) {
            return 'the Oblivion server';
        }

        return in_array((int) $monitor->collector->site_id, $this->access->accessibleSiteIds($viewer), true)
            ? CoveringHubResolver::label($monitor->collector)
            : 'its hub';
    }
}
