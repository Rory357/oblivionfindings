<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CameraSnapshotCollectorDescriptor;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * S&D Phase B: "Capture snapshot" on a camera whose Site has a hub. The hub takes the picture; the
 * camera card asks for it, then checks back until it arrives.
 */
final class HubCameraPictureService
{
    public function __construct(
        private readonly CameraSnapshotCollectorDescriptor $descriptor,
        private readonly CommandExecutionRouteResolver $routes,
        private readonly DeviceCommandRequestService $requests,
        private readonly GovernedCommandDispatchService $dispatch,
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly CoveringHubResolver $hubs,
    ) {}

    /**
     * Null when no hub covers the camera's Site: the server takes the picture itself, as before.
     *
     * @return array{state: 'pending'|'unavailable', message: string, command_id?: int}|null
     */
    public function start(User $user, Device $device): ?array
    {
        if (! $this->descriptor->eligible($device)) {
            return null;
        }
        $siteId = $this->sites->resolve((int) $device->id);
        $hub = $this->hubs->forSite($siteId);
        $label = $hub instanceof MonitoringCollector ? CoveringHubResolver::label($hub) : 'the Site hub';
        $route = $this->routes->resolve($device, $siteId, CameraSnapshotCollectorDescriptor::CAPABILITY);
        if (! $route->available) {
            return ['state' => 'unavailable', 'message' => $route->reason];
        }

        try {
            $request = $this->requests->request($device, $user, new CommandRequestInput(
                capability: CameraSnapshotCollectorDescriptor::CAPABILITY,
                parameters: [],
                reason: 'Camera picture check from the device page.',
                idempotencyKey: 'camera-picture-'.Str::uuid(),
                impactAcknowledged: true,
            ));
            $this->dispatch->dispatch($request->fresh(), $user);
        } catch (Throwable) {
            return ['state' => 'unavailable', 'message' => "{$label} couldn’t take the picture just now. Try again in a minute."];
        }

        return ['state' => 'pending', 'message' => "Checking through {$label}…", 'command_id' => (int) $request->id];
    }

    /**
     * A camera action the server would run by connecting straight to the camera. At a Site a hub
     * covers it never does; this says so in words, or returns null where no hub covers the Site.
     */
    public function notThroughHubYet(Device $device, string $what): ?string
    {
        try {
            $hub = $this->hubs->forSite($this->sites->resolve((int) $device->id));
        } catch (Throwable) {
            return null;
        }

        return $hub instanceof MonitoringCollector
            ? CoveringHubResolver::label($hub)." covers this Site, and {$what} through a hub isn’t built yet. Oblivion doesn’t reach into the house directly."
            : null;
    }

    /**
     * @return array{state: 'pending'|'done'|'failed', message?: string, data_uri?: string, captured_at?: string, source?: string, tls_trust?: array<string, int|string>}
     */
    public function status(User $user, Device $device, DeviceCommandRequest $request): array
    {
        abort_unless(
            (int) $request->device_id === (int) $device->id
            && $request->capability === CameraSnapshotCollectorDescriptor::CAPABILITY
            && (int) $request->requested_by_user_id === (int) $user->id,
            404,
        );

        if (in_array($request->status, [CommandStatus::Dispatching, CommandStatus::Accepted, CommandStatus::Running], true)) {
            if ($request->expires_at !== null && $request->expires_at->isPast()) {
                return ['state' => 'failed', 'message' => 'The Site hub didn’t send the picture in time. It may be offline; check the camera again.'];
            }

            return ['state' => 'pending', 'message' => 'Waiting for the Site hub…'];
        }

        if (in_array($request->status, [CommandStatus::Reconciled, CommandStatus::Reconciling], true)) {
            $sealed = Cache::pull(CameraSnapshotCollectorDescriptor::pictureCacheKey((string) $request->command_uuid));
            $picture = is_string($sealed) ? json_decode(Crypt::decryptString($sealed), true) : null;
            if (! is_array($picture) || ! is_string($picture['data_uri'] ?? null)) {
                return ['state' => 'failed', 'message' => 'This picture has already been shown or has expired. Check the camera again for a new one.'];
            }

            return [
                'state' => 'done',
                'data_uri' => $picture['data_uri'],
                'captured_at' => (string) ($picture['captured_at'] ?? ''),
                'source' => 'UniFi Protect, through '.($picture['hub'] ?? 'the Site hub'),
            ];
        }

        $result = ['state' => 'failed', 'message' => (string) ($request->safe_failure_reason ?? 'The Site hub couldn’t take the picture.')];
        $summary = json_decode((string) $request->safe_result_summary, true);
        $reason = is_array($summary) ? ($summary['reason_code'] ?? null) : null;
        if (in_array($reason, ['camera_certificate_untrusted', 'camera_certificate_changed'], true)) {
            $target = $this->descriptor->trustTarget($device);
            if ($target !== null) {
                $result['tls_trust'] = $target;
            }
        }

        return $result;
    }
}
