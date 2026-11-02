<?php

namespace App\Domain\SecurityDevices\Screens\Devices;

use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DiscoveryRunner;
use App\Domain\Monitoring\Services\NativeMonitoringDefinitionService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/**
 * Pop-ups 3 (Scan a network) and 75 (Add a network) on today's discovery scopes and runs:
 * a network is a scan area at a Site, checked by the hub chosen for it. Nothing on the network
 * is changed. "Look for" picks which services we knock on.
 */
final class NetworkScans
{
    public const array LOOK_FOR = ['everything', 'cameras', 'network', 'printers', 'computers', 'sensors'];

    /** @var array<string, array<string, list<int>>> protocol => ports ([] for ping and ARP) */
    private const array PROBES = [
        'everything' => [
            'icmp' => [], 'arp' => [],
            'tcp' => [22, 80, 161, 443, 515, 554, 631, 1883, 3389, 5000, 5001, 5985, 5986, 8000, 8080, 8443, 9100],
            'dns' => [53], 'http' => [80, 443, 5000, 5001, 8000, 8080, 8443], 'tls' => [443, 8443],
            'snmp' => [161], 'mdns' => [5353], 'ssdp' => [1900], 'onvif' => [3702],
        ],
        'cameras' => ['icmp' => [], 'arp' => [], 'tcp' => [80, 443, 554, 8000, 8080], 'http' => [80, 443, 8000, 8080], 'onvif' => [3702]],
        'network' => ['icmp' => [], 'arp' => [], 'tcp' => [22, 80, 161, 443], 'http' => [80, 443], 'snmp' => [161]],
        'printers' => ['icmp' => [], 'arp' => [], 'tcp' => [515, 631, 9100], 'snmp' => [161], 'mdns' => [5353]],
        'computers' => ['icmp' => [], 'arp' => [], 'tcp' => [22, 3389, 5985, 5986], 'mdns' => [5353]],
        'sensors' => ['icmp' => [], 'arp' => [], 'tcp' => [80, 443, 1883], 'mdns' => [5353]],
    ];

    public function __construct(
        private readonly NativeMonitoringDefinitionService $definitions,
        private readonly DiscoveryRunner $runner,
        private readonly SecurityDevicesAccessService $access,
        private readonly SecurityDevicesSettings $settings,
    ) {}

    /**
     * Find or make the network, then start a scan of it now.
     *
     * @param  list<string>  $lookFor
     */
    public function start(User $actor, int $siteId, string $range, ?int $hubId, array $lookFor): DiscoveryRun
    {
        $scope = $this->network($actor, $siteId, $range, $hubId, $lookFor, null);

        try {
            return DB::transaction(function () use ($actor, $scope): DiscoveryRun {
                $run = $this->runner->start($scope, "manual:user:{$actor->id}");
                AuditLogger::logOrFail('monitoring.discovery.scope.applied', $scope, [
                    'actor_id' => (int) $actor->id,
                    'site_id' => (int) $scope->site_id,
                    'run_id' => (int) $run->id,
                    'run_uuid' => $run->run_uuid,
                    'run_status' => $run->status,
                    'planned_targets' => (int) $run->planned_targets,
                    'collection_mode' => $scope->collector_id === null ? 'central_direct' : 'remote_collector',
                    ...($scope->collector_id === null ? [] : ['collector_id' => (int) $scope->collector_id]),
                ]);

                return $run;
            }, 3);
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages([
                'range' => 'This network can’t be scanned yet. Check the range and the hub, then try again.',
            ]);
        }
    }

    /**
     * Add a network (pop-up 75): its Site, range, hub and when it is scanned. Scanning every
     * day needs a hub; with "only when I ask" nothing runs until someone starts a scan.
     *
     * @param  list<string>  $lookFor
     */
    public function add(User $actor, int $siteId, string $range, ?int $hubId, array $lookFor, ?string $dailyAt): DiscoveryScope
    {
        if ($dailyAt !== null && $hubId === null) {
            throw ValidationException::withMessages([
                'schedule' => 'A daily scan runs on a hub. Choose the hub that checks this network.',
            ]);
        }

        return $this->network($actor, $siteId, $range, $hubId, $lookFor, $dailyAt);
    }

    /**
     * Other Sites that already use this range, for the warning before it's saved.
     *
     * @return list<string>
     */
    public function sitesUsingRange(User $actor, int $siteId, string $range): array
    {
        $siteIds = $this->access->accessibleSiteIds($actor);

        return DiscoveryScope::query()
            ->with('site:id,name')
            ->where('site_id', '!=', $siteId)
            ->where('status', 'active')
            ->get(['id', 'site_id', 'cidrs'])
            ->filter(fn (DiscoveryScope $scope): bool => in_array($range, (array) $scope->cidrs, true))
            ->map(fn (DiscoveryScope $scope): string => in_array((int) $scope->site_id, $siteIds, true)
                ? (string) ($scope->site?->name ?? 'another Site')
                : 'another Site')
            ->unique()
            ->values()
            ->all();
    }

    /** @param  list<string>  $lookFor */
    private function network(User $actor, int $siteId, string $range, ?int $hubId, array $lookFor, ?string $dailyAt): DiscoveryScope
    {
        $this->access->assertCanViewSite($actor, $siteId);
        if ($hubId === null && ! $this->settings->serverMayScan()) {
            throw ValidationException::withMessages([
                'hub_id' => 'Choose the hub that checks this network. The Oblivion server scans networks itself only when Setup › Networks & scans allows it.',
            ]);
        }
        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->firstOrFail();

        $lookFor = array_values(array_intersect(self::LOOK_FOR, $lookFor));
        if ($lookFor === [] || in_array('everything', $lookFor, true)) {
            $lookFor = ['everything'];
        }

        $probes = [];
        foreach ($lookFor as $choice) {
            foreach (self::PROBES[$choice] as $protocol => $ports) {
                $probes[$protocol] = array_values(array_unique([...($probes[$protocol] ?? []), ...$ports]));
            }
        }
        $protocols = array_values(array_intersect(array_keys($probes), NativeMonitoringDefinitionService::discoveryProtocols()));
        $portBounds = collect($probes)
            ->only($protocols)
            ->filter(fn (array $ports): bool => $ports !== [])
            ->map(function (array $ports): array {
                sort($ports);

                return $ports;
            })
            ->all();

        $name = 'Scan of '.$range.($lookFor === ['everything'] ? '' : ' ('.implode(', ', $lookFor).')');
        $existing = DiscoveryScope::query()
            ->where('site_id', $siteId)
            ->where('name', $name)
            ->where('status', 'active')
            ->first();

        $data = [
            'collector_id' => $hubId,
            'cidrs' => [$range],
            'protocols' => $protocols,
            'port_bounds' => $portBounds,
            ...($dailyAt !== null ? ['daily_scan_at' => $dailyAt] : []),
        ];

        if ($existing !== null) {
            return $this->definitions->updateScope($actor, $existing, $data);
        }

        $taken = DiscoveryScope::query()->withTrashed()->where('site_id', $siteId)->where('name', 'like', $name.'%')->pluck('name')->all();
        $unique = $name;
        for ($n = 2; in_array($unique, $taken, true); $n++) {
            $unique = $name.' · '.$n;
        }

        return $this->definitions->createScope($actor, $site, [
            ...$data,
            'name' => mb_substr($unique, 0, 128),
            'seed_hosts' => [],
            'exclusions' => [],
            'max_targets_per_run' => 1024,
            'packets_per_second' => 20,
        ]);
    }
}
