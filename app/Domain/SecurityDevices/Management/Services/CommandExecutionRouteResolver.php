<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwareAuthority;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionRoute;
use App\Domain\SecurityDevices\Models\Device;
use Throwable;

final class CommandExecutionRouteResolver
{
    public function __construct(
        private readonly CommandExecutionAdapterRegistry $centralAdapters,
        private readonly UnifiAccessCollectorCommandDescriptor $collectorCommands,
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
            || (int) $collector->site_id !== $siteId
            || $collector->revoked_at !== null
            || ! is_string($collector->public_key)
            || ! is_string($collector->client_certificate_fingerprint)
            || ! in_array((string) $collector->status, ['online', 'degraded'], true)) {
            return CommandExecutionRoute::unavailable(
                'The Device remote collector is not currently enrolled, Site-matched, and available.',
            );
        }
        if (! $this->collectorCommands->supports($device, $collector, $capability)) {
            return CommandExecutionRoute::unavailable(
                'The remote collector does not have an approved endpoint, scope, credential reference, and typed adapter for this action.',
            );
        }

        return CommandExecutionRoute::collector($collector);
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
