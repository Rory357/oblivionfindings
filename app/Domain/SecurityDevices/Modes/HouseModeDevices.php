<?php

namespace App\Domain\SecurityDevices\Modes;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The doors, cameras and alarm panels a house mode may name (S&D Phase F): only devices at the
 * mode's own Site, of the right kind. A person choosing them sees only what they may see; a switch
 * checks again that each one is still at the Site before it reads or changes anything.
 */
final class HouseModeDevices
{
    /** The action setting that holds each kind's devices. */
    public const array KINDS = [
        'doors' => DeviceKind::Door,
        'cameras' => DeviceKind::Camera,
        'panels' => DeviceKind::AlarmPanel,
    ];

    public function __construct(
        private readonly DeviceDirectory $directory,
        private readonly CanonicalDeviceSiteResolver $sites,
    ) {}

    /**
     * What this person may choose at the Site, by setting: doors, cameras, panels.
     *
     * @return array{doors: array<int, string>, cameras: array<int, string>, panels: array<int, string>}
     */
    public function forViewer(User $viewer, int $siteId): array
    {
        return self::fromEntries($this->directory->entries($viewer, $siteId));
    }

    /**
     * The same, from the viewer's devices at the Site already read (the Site page has them).
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array{doors: array<int, string>, cameras: array<int, string>, panels: array<int, string>}
     */
    public static function fromEntries(Collection $entries): array
    {
        $out = ['doors' => [], 'cameras' => [], 'panels' => []];
        $entries
            ->sortBy(fn (DeviceEntry $entry): string => mb_strtolower($entry->name()))
            ->each(function (DeviceEntry $entry) use (&$out): void {
                foreach (self::KINDS as $setting => $kind) {
                    if ($entry->kind === $kind) {
                        $out[$setting][$entry->id()] = $entry->name();
                    }
                }
            });

        return $out;
    }

    /**
     * The devices among these that are still at the Site and of the kind, by id (for a switch, with
     * no person looking).
     *
     * @param  list<int>  $ids
     * @return array<int, Device>
     */
    public function stillAt(int $siteId, string $setting, array $ids): array
    {
        $kind = self::KINDS[$setting] ?? null;
        if ($kind === null || $ids === []) {
            return [];
        }
        $out = [];
        Device::query()->whereKey($ids)->get()->each(function (Device $device) use ($siteId, $kind, &$out): void {
            if (DeviceKind::of($device) !== $kind || in_array($device->status, [DeviceStatus::Decommissioned, DeviceStatus::Lost, DeviceStatus::Quarantined], true)) {
                return;
            }
            try {
                $at = $this->sites->resolveForContext((int) $device->id);
            } catch (Throwable) {
                return;
            }
            if ($at === $siteId) {
                $out[(int) $device->id] = $device;
            }
        });

        return $out;
    }
}
