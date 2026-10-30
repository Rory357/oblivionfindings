<?php

namespace App\Domain\SecurityDevices\Drivers\Contracts;

use App\Domain\SecurityDevices\Models\Device;

/**
 * Contract for alarm & access-control panel drivers that expose a public local REST API.
 *
 * High-trust actions (armArea, disarmArea, unlockDoor) MUST only be invoked
 * through permission-checked, confirmation-backed, and audit-logged routes/commands.
 */
interface AlarmPanelDriver extends DeviceDriver
{
    /**
     * @return array{
     *     ok: bool,
     *     reason_code: string,
     *     message: string,
     *     areas: list<array{id: string, name: string, state: string}>,
     *     zones: list<array{id: string, name: string, state: string}>,
     *     doors: list<array{id: string, name: string, state: string}>
     * }
     */
    public function fetchStatus(Device $device): array;

    /**
     * @return array{ok: bool, reason_code: string, message: string}
     */
    public function armArea(Device $device, string $areaId, string $mode = 'away'): array;

    /**
     * @return array{ok: bool, reason_code: string, message: string}
     */
    public function disarmArea(Device $device, string $areaId): array;

    /**
     * @return array{ok: bool, reason_code: string, message: string}
     */
    public function unlockDoor(Device $device, string $doorId): array;
}
