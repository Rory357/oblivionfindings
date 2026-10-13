<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CctvNavigation
{
    public function forDevice(User $viewer, Device $device): array
    {
        $none = ['live_href' => null, 'setup_href' => null, 'reference_href' => null];
        if ($device->domain !== 'security' || $device->category !== 'cctv') {
            return $none;
        }
        try {
            return DB::transaction(function () use ($viewer, $device): array {
                $recorder = Device::query()->whereKey($device->id)->lock('for share nowait')->value('subcategory') === 'nvr';
                $actor = app(CctvAuthority::class)->actor((int) $viewer->id, $recorder, ! $recorder);
                [$currentDevice] = app(CctvAuthority::class)->device($actor, (int) $device->id, $recorder);
                if ($recorder) {
                    app(CctvAuthority::class)->actor((int) $viewer->id, true);

                    return ['live_href' => null, 'setup_href' => $currentDevice->provider === 'hikvision' ? '/security-devices/devices/'.$currentDevice->id.'/live/source' : null, 'reference_href' => null] + ($currentDevice->provider === 'hikvision' ? ['setup_kind' => 'recorder'] : []);
                }
                $base = '/security-devices/devices/'.$device->id.'/live';

                return ['live_href' => $actor->canDo('securityDevices.cctv.media.view') ? $base : null, 'setup_href' => $actor->canDo('securityDevices.integrations.manage') && $actor->canDo('securityDevices.devices.update') ? $base.'/source' : null, 'reference_href' => $actor->canDo(CctvRecordingReferences::VIEW) && $currentDevice->status !== DeviceStatus::Decommissioned ? '/security-devices/devices/'.$device->id.'/recording-references' : null];
            });
        } catch (HttpExceptionInterface) {
            return $none;
        }
    }
}
