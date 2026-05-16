<?php

namespace App\Services\Sites;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\SiteTypePlanPin;
use DateTimeInterface;

class SiteTypePlanPinStatusService
{
    public function markDevicePinsStale(Device|int $device, string $reason, DateTimeInterface $changedAt): void
    {
        $deviceId = $device instanceof Device ? $device->id : $device;
        $timestampKey = $reason === 'assignment_released' ? 'released_at' : 'replaced_at';
        $timestamp = $changedAt->format(DateTimeInterface::ATOM);

        SiteTypePlanPin::query()
            ->where('kind', SiteTypePlanPin::KIND_DEVICE)
            ->where(function ($query) use ($deviceId) {
                $query->where('device_id', $deviceId)
                    ->orWhere('meta->device_id', $deviceId)
                    ->orWhere('meta->device_id', (string) $deviceId);
            })
            ->get()
            ->each(function (SiteTypePlanPin $pin) use ($reason, $timestampKey, $timestamp) {
                $pin->forceFill([
                    'meta' => array_merge($pin->meta ?? [], [
                        'stale' => true,
                        'stale_reason' => $reason,
                        $timestampKey => $timestamp,
                    ]),
                ])->save();
            });
    }
}
