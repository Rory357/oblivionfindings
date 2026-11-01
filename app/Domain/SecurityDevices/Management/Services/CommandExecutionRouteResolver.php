<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwareAuthority;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Management\Contracts\CollectorCommandDescriptor;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionRoute;
use App\Domain\SecurityDevices\Models\Device;
use Throwable;

final class CommandExecutionRouteResolver
{
    public function __construct(
        private readonly CommandExecutionAdapterRegistry $centralAdapters,
        private readonly CollectorCommandDescriptorRegistry $collectorCommands,
        private readonly CoveringHubResolver $coveringHubs,
    ) {}

    public function resolve(Device $device, int $siteId, string $capability): CommandExecutionRoute
    {
        if ($capability === 'endpoint.script.run') {
            // A heartbeat observation or editable device metadata cannot activate
            // the unfinished script lifecycle or fall through to a provider.
            return app(EndpointScriptAuthority::class)->supports($device)
                ? CommandExecutionRoute::endpoint() : CommandExecutionRoute::unavailable('Reviewed script execution is not available.');
        }
        if ($capability === 'endpoint.software.install') {
            return app(EndpointSoftwareAuthority::class)->supports($device) ? CommandExecutionRoute::endpoint() : CommandExecutionRoute::unavailable('Approved machine MSI installation is unavailable.');
        }
        if ($capability === 'endpoint.computer.restart') {
            return app(EndpointRestartAuthority::class)->supports($device) ? CommandExecutionRoute::endpoint() : CommandExecutionRoute::unavailable('The governed Windows restart release or installed agent is unavailable.');
        }
        if ($capability === 'endpoint.service.manage') {
            return app(EndpointServiceAuthority::class)->supports($device)
                ? CommandExecutionRoute::endpoint() : CommandExecutionRoute::unavailable('The governed native service-action release or installed agent is unavailable.');
        }
        // S&D Phase B: a job the hub that covers the Site can run goes through that hub, and only there.
        $descriptor = $this->collectorCommands->for($capability);
        if ($descriptor instanceof CollectorCommandDescriptor && $descriptor->routesThroughCoveringHub()) {
            $hubRoute = $this->throughCoveringHub($descriptor, $device, $siteId);
            if ($hubRoute instanceof CommandExecutionRoute) {
                return $hubRoute;
            }
        }
        try {
            if ($this->centralAdapters->supports($device, $capability)) {
                return CommandExecutionRoute::central($this->centralAdapters->for($device, $capability));
            }
        } catch (Throwable) {
            // Continue to the exact remote-only route check.
        }

        $paths = Monitor::query()
            ->where('device_id', $device->id)
            ->where('is_enabled', true)
            ->get(['collector_id']);
        if ($paths->isEmpty()) {
            return CommandExecutionRoute::unavailable(
                'No approved central adapter or remote-only collector path is configured for this action.',
            );
        }
        if ($paths->contains(fn (Monitor $monitor): bool => $monitor->collector_id === null)) {
            return CommandExecutionRoute::unavailable(
                'This Device still declares a central monitoring path, but no approved central execution adapter is available.',
            );
        }
        $collectorIds = $paths->pluck('collector_id')->filter()->unique()->values();
        if ($collectorIds->count() !== 1) {
            return CommandExecutionRoute::unavailable(
                'Remote execution requires exactly one current Site-scoped collector path for this Device.',
            );
        }

        $collector = MonitoringCollector::query()->find($collectorIds->first());
        if (! $collector
            // The hub that covers the Site: its home Site or one it covers over SD-WAN.
            || ! in_array($siteId, $collector->authorisedSiteIds(), true)
            || ! $collector->isApprovedForWork()
            || $collector->revoked_at !== null
            || ! is_string($collector->public_key)
            || ! is_string($collector->client_certificate_fingerprint)
            || ! in_array((string) $collector->status, ['online', 'degraded'], true)) {
            return CommandExecutionRoute::unavailable(
                'The Device remote collector is not currently enrolled, Site-matched, and available.',
            );
        }
        if (! $this->collectorCommands->for($capability)?->supports($device, $collector, $siteId)) {
            return CommandExecutionRoute::unavailable(
                'The remote collector does not have an approved endpoint, scope, credential reference, and typed adapter for this action.',
            );
        }

        return CommandExecutionRoute::collector($collector);
    }

    /**
     * The covering hub's route, or null when no hub covers the Site (then the server's own adapter
     * may run it). A covering hub that is offline or can't run the job makes the action unavailable:
     * the server never reaches into a house that has a hub.
     */
    private function throughCoveringHub(CollectorCommandDescriptor $descriptor, Device $device, int $siteId): ?CommandExecutionRoute
    {
        $hub = $this->coveringHubs->forSite($siteId);
        if (! $hub instanceof MonitoringCollector) {
            return null;
        }
        $label = CoveringHubResolver::label($hub);
        if (! in_array((string) $hub->status, ['online', 'degraded'], true)) {
            return CommandExecutionRoute::unavailable(
                "{$label} isn’t connected right now, so this can’t run until it’s back. Oblivion doesn’t reach into the house directly.",
            );
        }
        if (! $descriptor->supports($device, $hub, $siteId)) {
            return CommandExecutionRoute::unavailable(
                "{$label} can’t run this for the device yet: it needs an approved address, a saved login and a supported device type.",
            );
        }

        return CommandExecutionRoute::collector($hub);
    }

    public function matches(Device $device, int $siteId, string $capability, ?int $collectorId): bool
    {
        $route = $this->resolve($device, $siteId, $capability);
        if (! $route->available) {
            return false;
        }

        return $collectorId === null
            ? in_array($route->mode, ['central', 'endpoint'], true)
            : $route->mode === 'collector' && (int) $route->collector?->id === $collectorId;
    }
}
