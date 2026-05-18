<?php

namespace App\Services\Queclink;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class LocateNowService
{
    public function __construct(
        private readonly CommandBuilder $commands,
    ) {
    }

    public function queueForDevice(Device $device, User $user): QueclinkPendingCommand
    {
        $queclinkDevice = $this->resolveQueclinkDevice($device);

        if (! $queclinkDevice || ! $queclinkDevice->isPaired()) {
            throw ValidationException::withMessages([
                'tracker' => 'This resident does not have a paired Queclink tracker.',
            ]);
        }

        $built = $this->commands->requestLocation($this->familyFor($queclinkDevice, $device));

        return QueclinkPendingCommand::create([
            'queclink_device_id' => $queclinkDevice->id,
            'imei' => $queclinkDevice->imei,
            'tenant_id' => $queclinkDevice->tenant_id ?? $device->tenant_id,
            'command_word' => $built['command_word'],
            'raw_command' => $built['raw'],
            'serial_number' => $built['serial'],
            'status' => QueclinkPendingCommand::STATUS_QUEUED,
            'created_by_user_id' => $user->id,
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    public function queueForImei(string $imei, User $user): QueclinkPendingCommand
    {
        $queclinkDevice = QueclinkDevice::query()
            ->where('imei', trim($imei))
            ->first();

        if (! $queclinkDevice || ! $queclinkDevice->device) {
            throw ValidationException::withMessages([
                'tracker' => 'This Queclink tracker is not linked to a canonical device.',
            ]);
        }

        return $this->queueForDevice($queclinkDevice->device, $user);
    }

    private function resolveQueclinkDevice(Device $device): ?QueclinkDevice
    {
        return QueclinkDevice::query()
            ->where(function ($query) use ($device): void {
                $query->where('device_id', $device->id);

                foreach (array_filter([$device->imei, $device->device_uid]) as $identifier) {
                    $query->orWhere('imei', $identifier);
                }
            })
            ->latest('id')
            ->first();
    }

    private function familyFor(QueclinkDevice $queclinkDevice, Device $device): string
    {
        $hint = strtolower((string) ($queclinkDevice->model_hint ?: $device->model));

        if (str_contains($hint, 'gl30') || str_contains($hint, 'gl-30')) {
            return CommandBuilder::FAMILY_GL30M;
        }

        if (str_contains($hint, 'gv500')) {
            return CommandBuilder::FAMILY_GV500CG;
        }

        $category = strtolower((string) $device->category);
        if (in_array($category, ['personal_tracker', 'lone_worker_tracker', 'client_tracker'], true)) {
            return CommandBuilder::FAMILY_GL30M;
        }

        return CommandBuilder::FAMILY_GV500CG;
    }
}
