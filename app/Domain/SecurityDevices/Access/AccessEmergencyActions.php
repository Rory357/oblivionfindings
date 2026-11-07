<?php

namespace App\Domain\SecurityDevices\Access;

use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Screens\Devices\Pages\SafetyKitCommands;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * S&D Phase H (pop-up 86): what the Access page needs to offer lockdown, evacuation and ending
 * either, per Site the viewer can act on. Each action is the governed command's own summary
 * (available, why not, step-up, approval, the typed name, which hub).
 */
final class AccessEmergencyActions
{
    public const array CAPABILITIES = ['access.site.lockdown', 'access.site.evacuate', 'access.site.emergency_end'];

    public function __construct(
        private readonly SafetyKitCommands $commands,
        private readonly AccessConsoles $consoles,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** @return list<array<string, mixed>> */
    public function present(User $viewer, ?CarbonImmutable $stepUpAt = null, ?CarbonImmutable $now = null): array
    {
        if (! $viewer->canDo('securityDevices.access.lockdown')) {
            return [];
        }
        $now ??= CarbonImmutable::now('UTC');
        $all = $this->access->canViewAllSites($viewer);
        $siteIds = $all ? [] : $this->access->accessibleSiteIds($viewer);

        return AccessConsole::query()
            ->when(! $all, fn ($query) => $query->whereIn('site_id', $siteIds))
            ->whereIn('state', ['ok', 'failed'])
            ->orderBy('site_id')
            ->limit(50)
            ->get()
            ->map(function (AccessConsole $console) use ($viewer, $stepUpAt, $now): array {
                $device = $this->consoles->device($console);
                $siteId = (int) $console->site_id;
                $lockdown = $this->commands->action($viewer, $device, $siteId, 'access.site.lockdown', $stepUpAt);
                if ($lockdown !== null && $console->exit_buttons_confirmed_at === null) {
                    $lockdown = [...$lockdown, 'available' => false, 'reason' => 'Lockdown is offered once someone confirms every door has an exit button and a handle (Setup › Doors & access).'];
                }

                return [
                    'console_id' => (int) $console->id,
                    'device_id' => (int) $device->id,
                    'site_id' => $siteId,
                    'site' => (string) \App\Models\Site::query()->whereKey($siteId)->value('name'),
                    'mode' => (string) $console->emergency_mode,
                    'type_name' => (string) $device->name,
                    'lockdown' => $lockdown,
                    'evacuate' => $this->commands->action($viewer, $device, $siteId, 'access.site.evacuate', $stepUpAt),
                    'end' => $this->commands->action($viewer, $device, $siteId, 'access.site.emergency_end', $stepUpAt),
                    'commands' => $this->commands->recent($viewer, $device, self::CAPABILITIES, $now),
                    'confirm_identity_url' => route('security-devices.devices.safety.confirm-identity', ['device' => $device->id], false),
                ];
            })
            ->values()
            ->all();
    }
}
