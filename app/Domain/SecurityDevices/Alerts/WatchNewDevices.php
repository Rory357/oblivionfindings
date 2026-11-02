<?php

namespace App\Domain\SecurityDevices\Alerts;

use App\Domain\SecurityDevices\Http\Controllers\DeviceOfflineAlertController;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Found devices someone adds start being watched for going offline (S&D Phase D, Setup ›
 * Alerts & checks › Checks: "Watch devices as soon as they're added"), the same way as the
 * device page's "Alert me if it goes offline": through the Site's hub, or the server.
 *
 * Phones and tablets are found but never watched unless Setup says so. A device without an
 * address, or one the check can't reach yet, is simply added without watching.
 */
final class WatchNewDevices
{
    public function __construct(private readonly SecurityDevicesSettings $settings) {}

    /**
     * @param  iterable<Device>  $devices
     * @return int how many are now watched
     */
    public function watch(User $actor, iterable $devices): int
    {
        if (! $this->settings->bool(SecurityDevicesSettings::WATCH_ON_ADD) || ! $actor->canDo('securityDevices.monitoring.manage')) {
            return 0;
        }
        $watchPhones = $this->settings->bool(SecurityDevicesSettings::WATCH_PHONES);
        $watched = 0;
        foreach ($devices as $device) {
            if (trim((string) $device->ip_address) === '' || (! $watchPhones && self::isPhoneOrTablet($device))) {
                continue;
            }
            try {
                app(DeviceOfflineAlertController::class)->enable($actor, $device);
                $watched++;
            } catch (Throwable $exception) {
                Log::info('A newly added device was not watched', ['device_id' => $device->id, 'reason' => $exception->getMessage()]);
            }
        }

        return $watched;
    }

    public static function isPhoneOrTablet(Device $device): bool
    {
        return (string) $device->category === 'endpoint'
            && in_array((string) $device->subcategory, ['tablet', 'mobile_device'], true);
    }
}
