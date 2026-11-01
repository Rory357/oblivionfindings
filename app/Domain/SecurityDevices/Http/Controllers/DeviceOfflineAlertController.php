<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DiscoveryScopeValidator;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\Monitoring\Services\NativeMonitoringDefinitionService;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnexpectedValueException;

final class DeviceOfflineAlertController extends Controller
{
    private const HOST_SCOPE_NAME = 'Registered device hosts';

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly NativeMonitoringDefinitionService $definitions,
        private readonly DiscoveryScopeValidator $scopeValidator,
        private readonly DnsResolver $dnsResolver,
        private readonly CidrMatcher $cidrMatcher,
        private readonly EgressPolicy $egressPolicy,
        private readonly CoveringHubResolver $hubs,
    ) {}

    public function __invoke(Request $request, Device $device): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('securityDevices.monitoring.manage'), 403);
        $this->access->assertCanViewDevice($user, $device);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);
        $enabled = (bool) $validated['enabled'];

        if (! $enabled) {
            // Off means off, whether the server or a hub was doing the checking.
            $monitors = Monitor::query()
                ->where('device_id', $device->id)
                ->where('kind', MonitorKind::Icmp->value)
                ->where('affects_availability', true)
                ->where('is_enabled', true)
                ->orderBy('id')
                ->get();

            foreach ($monitors as $monitor) {
                $this->definitions->deactivateMonitor($user, $monitor, 'coverage_removed');
            }

            if ($request->wantsJson() && ! $request->header('X-Inertia')) {
                return response()->json([
                    'enabled' => false,
                    'monitor_id' => null,
                    'message' => 'Offline alerts turned off for this device.',
                ]);
            }

            return back()->with('success', 'Offline alerts turned off for this device.');
        }

        try {
            $siteId = $this->siteResolver->resolve((int) $device->id);
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages([
                'offline_alert' => 'Assign this device to a Site first so offline alerts know where it lives.',
            ]);
        }

        abort_unless(in_array($siteId, $this->access->accessibleSiteIds($user), true), 403);

        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->first();

        if (! $site) {
            throw ValidationException::withMessages([
                'offline_alert' => 'Assign this device to an active Site before turning on offline alerts.',
            ]);
        }

        $rawTarget = trim((string) ($device->ip_address ?? ''));
        if ($rawTarget === '') {
            throw ValidationException::withMessages([
                'offline_alert' => 'Add an IP address or hostname to this device before turning on offline alerts.',
            ]);
        }

        try {
            $probeTarget = ProbeTarget::icmp($rawTarget);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'offline_alert' => 'Save a valid IP address or hostname on this device first.',
            ]);
        }

        // S&D Phase B: a hub that covers the Site does the checking; the server only when none does.
        $hub = $this->hubs->forSite($siteId);
        if ($hub !== null) {
            $this->prepareHubCoverage($user, $site, $hub, $probeTarget);
            $checkedBy = ['mode' => 'hub', 'hub_id' => (int) $hub->id, 'label' => 'Checked by '.CoveringHubResolver::label($hub)];
        } else {
            $this->prepareServerCoverage($user, $site, $probeTarget);
            $checkedBy = ['mode' => 'server', 'hub_id' => null, 'label' => 'Checked from the server'];
        }

        $monitor = $this->saveMonitor($user, $device, $rawTarget, $siteId, $hub);
        $message = "Offline alerts turned on for this device. {$checkedBy['label']}.";

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'enabled' => true,
                'monitor_id' => $monitor->id,
                'checked_by' => $checkedBy,
                'message' => $message,
            ]);
        }

        return back()->with('success', $message);
    }

    /** The server pings the device itself: the address must pass the server's egress rules. */
    private function prepareServerCoverage(User $user, Site $site, ProbeTarget $probeTarget): void
    {
        $canonicalAddresses = $this->resolveAddresses($probeTarget->host);
        if ($canonicalAddresses === []) {
            throw ValidationException::withMessages([
                'offline_alert' => 'Could not look up that hostname in DNS.',
            ]);
        }

        $hostCidrs = array_values(array_unique(array_map(
            fn (string $ip): string => str_contains($ip, ':') ? "{$ip}/128" : "{$ip}/32",
            $canonicalAddresses,
        )));

        try {
            $authorized = $this->egressPolicy->authoriseDiscovery(
                siteId: (int) $site->id,
                approvedCidrs: $hostCidrs,
                allowedPorts: [443],
                target: $probeTarget,
            );
        } catch (EgressDenied) {
            throw ValidationException::withMessages([
                'offline_alert' => 'That IP address is reserved or private to the server and cannot be monitored.',
            ]);
        }

        $this->ensureHostScopeCoverage($user, $site, $authorized->addresses);
    }

    /** The hub pings a literal address on its own network, inside a scan area it may use. */
    private function prepareHubCoverage(User $user, Site $site, MonitoringCollector $hub, ProbeTarget $probeTarget): void
    {
        $label = CoveringHubResolver::label($hub);
        if (filter_var($probeTarget->host, FILTER_VALIDATE_IP) === false) {
            throw ValidationException::withMessages([
                'offline_alert' => "Save this device's IP address, not a hostname, so {$label} can check it.",
            ]);
        }

        // Giving a hub a check needs access to the hub's own Site, as on the Checks page.
        if (! in_array((int) $hub->site_id, $this->access->accessibleSiteIds($user), true)) {
            throw ValidationException::withMessages([
                'offline_alert' => "{$label} checks this Site. Ask someone who can see that hub's Site to turn on offline alerts.",
            ]);
        }

        // Same reserved-address rule NativeMonitoringDefinitionService applies to hub checks.
        try {
            $address = $this->cidrMatcher->canonicalAddress($probeTarget->host);
            $denied = collect((array) config('monitoring.egress.deny_cidrs', []))->contains(
                fn (mixed $cidr): bool => is_string($cidr) && $cidr !== '' && $this->cidrMatcher->contains($cidr, $address),
            );
        } catch (Throwable) {
            $denied = true;
        }
        if ($denied) {
            throw ValidationException::withMessages([
                'offline_alert' => 'That IP address is reserved and cannot be monitored.',
            ]);
        }

        $this->ensureHostScopeCoverage($user, $site, [$address], $hub);
    }

    /**
     * Turn on (or move) the device's ping check: on the covering hub, or on the server when
     * no hub covers the Site.
     */
    private function saveMonitor(User $user, Device $device, string $rawTarget, int $siteId, ?MonitoringCollector $hub): Monitor
    {
        $profile = $this->resolveDefaultAvailabilityProfile();
        $onSamePath = fn (Monitor $monitor): bool => $hub === null
            ? $monitor->collector_id === null
            : (int) $monitor->collector_id === (int) $hub->id;

        $existing = Monitor::query()
            ->where('device_id', $device->id)
            ->where('kind', MonitorKind::Icmp->value)
            ->orderBy('id')
            ->get()
            ->sortBy(fn (Monitor $monitor): array => [
                $onSamePath($monitor) ? 0 : 1,
                $monitor->is_enabled ? 0 : 1,
                $monitor->affects_availability ? 0 : 1,
                (int) $monitor->id,
            ])
            ->first();

        if ($existing) {
            return DB::transaction(function () use ($user, $existing, $profile, $rawTarget, $siteId, $hub): Monitor {
                $updated = $this->definitions->updateMonitor($user, $existing, [
                    'profile_id' => $existing->profile_id ?: $profile->id,
                    'name' => $existing->name ?: 'Offline check (ping)',
                    'target' => $rawTarget,
                    'affects_availability' => true,
                    'collector_id' => $hub?->id,
                ]);

                if (! $updated->is_enabled) {
                    $locked = Monitor::query()->whereKey($updated->id)->lockForUpdate()->firstOrFail();
                    $locked->forceFill([
                        'is_enabled' => true,
                        'pending_state' => null,
                        'pending_count' => 0,
                        'pending_since_at' => null,
                    ])->save();

                    AuditLogger::logOrFail('monitoring.monitor.reactivated', $locked, [
                        'actor_id' => (int) $user->id,
                        'site_id' => $siteId,
                        'device_id' => (int) $locked->device_id,
                        'kind' => MonitorKind::Icmp->value,
                    ]);

                    return $locked->fresh(['device', 'profile']);
                }

                return $updated;
            }, 3);
        }

        return $this->definitions->createMonitor($user, $device, [
            'profile_id' => $profile->id,
            'kind' => MonitorKind::Icmp->value,
            'name' => 'Offline check (ping)',
            'target' => $rawTarget,
            'affects_availability' => true,
            'collector_id' => $hub?->id,
        ]);
    }

    /** @return list<string> */
    private function resolveAddresses(string $host): array
    {
        try {
            $answers = filter_var($host, FILTER_VALIDATE_IP) !== false
                ? [$host]
                : $this->dnsResolver->resolve($host);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($answers) || $answers === []) {
            return [];
        }

        $canonical = [];
        foreach ($answers as $answer) {
            if (! is_string($answer) || trim($answer) === '') {
                continue;
            }
            try {
                $canonical[] = $this->cidrMatcher->canonicalAddress($answer);
            } catch (Throwable) {
                continue;
            }
        }

        return array_values(array_unique($canonical));
    }

    /**
     * Make sure a scan area on the Site allows pinging these addresses. For a hub check that
     * is a server scan area or one of that hub's own; otherwise a server scan area.
     *
     * @param  list<string>  $addresses
     */
    private function ensureHostScopeCoverage(User $actor, Site $site, array $addresses, ?MonitoringCollector $hub = null): void
    {
        $activeScopes = DiscoveryScope::query()
            ->where('site_id', $site->id)
            ->where(fn ($query) => $hub === null
                ? $query->whereNull('collector_id')
                : $query->whereNull('collector_id')->orWhere('collector_id', $hub->id))
            ->where('status', 'active')
            ->get();

        $uncoveredAddresses = array_values(array_filter(
            $addresses,
            fn (string $address): bool => ! $activeScopes->contains(
                fn (DiscoveryScope $scope): bool => $this->scopeCoversIcmpAddress($scope, $address),
            ),
        ));

        if ($uncoveredAddresses === []) {
            return;
        }

        $newCidrs = array_map(
            fn (string $ip): string => str_contains($ip, ':') ? "{$ip}/128" : "{$ip}/32",
            $uncoveredAddresses,
        );

        $hostScopeName = $hub === null ? self::HOST_SCOPE_NAME : self::HOST_SCOPE_NAME." (hub {$hub->id})";
        $hostScope = DiscoveryScope::query()
            ->withTrashed()
            ->where('site_id', $site->id)
            ->when(
                $hub === null,
                fn ($query) => $query->whereNull('collector_id'),
                fn ($query) => $query->where('collector_id', $hub->id),
            )
            ->where('name', $hostScopeName)
            ->first();

        if ($hostScope) {
            DB::transaction(function () use ($actor, $hostScope, $newCidrs, $hub): void {
                $locked = DiscoveryScope::query()
                    ->withTrashed()
                    ->whereKey($hostScope->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->trashed()) {
                    $locked->restore();
                }

                $mergedCidrs = array_values(array_unique([
                    ...array_values(array_filter($locked->cidrs ?? [], fn (mixed $c): bool => is_string($c) && trim($c) !== '')),
                    ...$newCidrs,
                ]));
                if (count($mergedCidrs) > 64) {
                    throw ValidationException::withMessages([
                        'offline_alert' => 'Add your Site subnet on Setup so new device addresses are covered automatically.',
                    ]);
                }

                $protocols = array_values(array_unique([
                    ...array_values(array_filter($locked->protocols ?? [], fn (mixed $p): bool => is_string($p))),
                    'icmp',
                    'tcp',
                ]));
                $portBounds = is_array($locked->port_bounds) ? $locked->port_bounds : [];
                $tcpPorts = array_values(array_unique([
                    ...array_map('intval', is_array($portBounds['tcp'] ?? null) ? $portBounds['tcp'] : []),
                    443,
                ]));
                sort($tcpPorts, SORT_NUMERIC);
                $portBounds['tcp'] = $tcpPorts;

                $locked->forceFill([
                    'cidrs' => $mergedCidrs,
                    'protocols' => $protocols,
                    'exclusions' => [],
                    'port_bounds' => $portBounds,
                    'status' => 'active',
                ])->save();

                AuditLogger::logOrFail('monitoring.discovery.scope.updated', $locked, [
                    'actor_id' => (int) $actor->id,
                    'site_id' => (int) $locked->site_id,
                    'collection_mode' => $hub === null ? 'central_direct' : 'remote_collector',
                    ...($hub === null ? [] : ['collector_id' => (int) $hub->id]),
                    'reason' => 'offline_alert_host_coverage',
                ]);
            }, 3);

            return;
        }

        $scopeName = $hostScopeName;
        if (DiscoveryScope::query()->withTrashed()->where('site_id', $site->id)->where('name', $scopeName)->exists()) {
            $scopeName = $hostScopeName.($hub === null ? ' (direct)' : ' (offline checks)');
        }

        $this->definitions->createScope($actor, $site, [
            'collector_id' => $hub?->id,
            'name' => $scopeName,
            'cidrs' => array_slice(array_values(array_unique($newCidrs)), 0, 64),
            'seed_hosts' => [],
            'protocols' => ['icmp', 'tcp'],
            'exclusions' => [],
            'port_bounds' => ['tcp' => [443]],
            'max_targets_per_run' => 256,
            'packets_per_second' => 10,
        ]);
    }

    private function scopeCoversIcmpAddress(DiscoveryScope $scope, string $address): bool
    {
        if (($scope->exclusions ?? []) !== []
            || $this->scopeValidator->validateScope($scope) !== null
            || ! in_array('icmp', array_map('strtolower', $scope->protocols ?? []), true)) {
            return false;
        }

        $hasUsablePort = collect($scope->port_bounds ?? [])->contains(
            fn (mixed $ports, mixed $protocol): bool => is_string($protocol)
                && in_array(strtolower($protocol), array_map('strtolower', $scope->protocols ?? []), true)
                && is_array($ports)
                && $ports !== [],
        );
        if (! $hasUsablePort) {
            return false;
        }

        return collect($scope->cidrs ?? [])->contains(
            fn (string $cidr): bool => $this->cidrMatcher->contains($cidr, $address)
                && ! $this->cidrMatcher->isIpv4NetworkOrBroadcast($cidr, $address),
        );
    }

    private function resolveDefaultAvailabilityProfile(): MonitoringProfile
    {
        $profile = MonitoringProfile::query()
            ->where('name', MonitoringProfile::DEFAULT_AVAILABILITY_NAME)
            ->where('is_active', true)
            ->first();

        if ($profile) {
            return $profile;
        }

        $anyActive = MonitoringProfile::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($anyActive) {
            return $anyActive;
        }

        return MonitoringProfile::query()->updateOrCreate(
            ['name' => MonitoringProfile::DEFAULT_AVAILABILITY_NAME],
            [
                'interval_seconds' => 60,
                'failure_confirmations' => 3,
                'recovery_confirmations' => 2,
                'stale_after_seconds' => 300,
                'threshold_direction' => 'above',
                'is_active' => true,
                'version' => 1,
            ],
        );
    }
}
