<?php

namespace App\Domain\SecurityDevices\Automations;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Models\User;
use Throwable;

/**
 * The devices an automation may name (S&D Phase F): only devices at its own Site. A person choosing
 * them sees only what they may see; a run checks again that each one is still at the Site, and of
 * the right kind, before it reads or changes anything.
 */
final class AutomationDevices
{
    /** Each group of devices and the kinds in it. `any` is every kind (a device going offline). */
    public const array GROUPS = [
        'doors' => [DeviceKind::Door],
        'sensors' => [DeviceKind::Sensor],
        'cameras' => [DeviceKind::Camera],
        'panels' => [DeviceKind::AlarmPanel],
    ];

    /** Retired, lost or held devices never take part. */
    private const array GONE = [DeviceStatus::Decommissioned, DeviceStatus::Lost, DeviceStatus::Quarantined];

    public function __construct(
        private readonly DeviceDirectory $directory,
        private readonly CanonicalDeviceSiteResolver $sites,
    ) {}

    /**
     * What this person may choose, by Site and group (`any` holds every device at the Site).
     *
     * @return array<int, array{doors: array<int, string>, sensors: array<int, string>, cameras: array<int, string>, panels: array<int, string>, any: array<int, string>}>
     */
    public function forViewer(User $viewer, ?int $siteId = null): array
    {
        $out = [];
        $this->directory->entries($viewer, $siteId)
            ->filter(fn (DeviceEntry $entry): bool => $entry->siteId !== null)
            ->sortBy(fn (DeviceEntry $entry): string => mb_strtolower($entry->name()))
            ->each(function (DeviceEntry $entry) use (&$out): void {
                $site = (int) $entry->siteId;
                $out[$site] ??= ['doors' => [], 'sensors' => [], 'cameras' => [], 'panels' => [], 'any' => []];
                foreach (self::GROUPS as $group => $kinds) {
                    if (in_array($entry->kind, $kinds, true)) {
                        $out[$site][$group][$entry->id()] = $entry->name();
                    }
                }
                $out[$site]['any'][$entry->id()] = $entry->name().' · '.$entry->kind->label();
            });

        return $out;
    }

    /**
     * The devices among these that are still at the Site, of the group's kind, by id (for a run, with
     * no person looking).
     *
     * @param  list<int>  $ids
     * @return array<int, Device>
     */
    public function stillAt(int $siteId, string $group, array $ids): array
    {
        $kinds = self::GROUPS[$group] ?? null;
        if (($kinds === null && $group !== 'any') || $ids === []) {
            return [];
        }
        $out = [];
        Device::query()->whereKey($ids)->get()->each(function (Device $device) use ($siteId, $kinds, &$out): void {
            if (($kinds !== null && ! in_array(DeviceKind::of($device), $kinds, true)) || in_array($device->status, self::GONE, true)) {
                return;
            }
            if ($this->siteOf($device) === $siteId) {
                $out[(int) $device->id] = $device;
            }
        });

        return $out;
    }

    /** The device's Site, or null when it isn't at one Site now (or is retired). */
    public function siteOf(Device $device): ?int
    {
        if (in_array($device->status, self::GONE, true)) {
            return null;
        }
        try {
            $siteId = $this->sites->resolveForContext((int) $device->id);
        } catch (Throwable) {
            return null;
        }

        return $siteId > 0 ? $siteId : null;
    }
}
