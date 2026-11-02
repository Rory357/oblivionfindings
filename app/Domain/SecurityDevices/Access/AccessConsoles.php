<?php

namespace App\Domain\SecurityDevices\Access;

use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * S&D Phase H: a Site's UniFi Access console as a device, so Site-wide lockdown and evacuation are
 * governed commands like any other door action (step-up, approval or break-glass, audit, read-back),
 * and the link between UniFi's doors and the door devices Oblivion already has.
 */
final class AccessConsoles
{
    public const string PROVIDER = 'unifi_access';

    public const string SUBCATEGORY = 'access_console';

    /** The console's device, made the first time it's needed. */
    public function device(AccessConsole $console): Device
    {
        return DB::transaction(function () use ($console): Device {
            $existing = self::deviceFor($console);
            if ($existing instanceof Device) {
                if ($existing->ip_address !== $console->address) {
                    $existing->forceFill(['ip_address' => $console->address])->save();
                }

                return $existing;
            }
            // Named for the Site's doors: it's the name typed to confirm a lockdown or evacuation.
            $site = trim((string) \App\Models\Site::query()->whereKey($console->site_id)->value('name'));
            $device = Device::query()->create([
                'name' => mb_substr(($site !== '' ? $site : 'Site').' doors', 0, 191),
                'domain' => 'security',
                'category' => 'access_control',
                'subcategory' => self::SUBCATEGORY,
                'manufacturer' => 'Ubiquiti',
                'model' => 'UniFi Access',
                'provider' => self::PROVIDER,
                'ip_address' => $console->address,
                'status' => 'active',
                'external_ref' => ['provider_resource_kind' => 'console', 'access_console_id' => (int) $console->id],
                'config' => [],
                'last_seen_at' => $console->last_synced_at,
            ]);
            DeviceAssignment::query()->create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => (int) $console->site_id,
                'assignment_type' => 'permanent',
                'assigned_at' => CarbonImmutable::now('UTC'),
            ]);

            return $device;
        });
    }

    public static function deviceFor(AccessConsole $console): ?Device
    {
        return Device::query()
            ->where('provider', self::PROVIDER)
            ->where('subcategory', self::SUBCATEGORY)
            ->where('external_ref->access_console_id', (int) $console->id)
            ->first();
    }

    /** The console a console device stands for. */
    public static function consoleFor(Device $device): ?AccessConsole
    {
        if (strtolower((string) $device->provider) !== self::PROVIDER || $device->subcategory !== self::SUBCATEGORY) {
            return null;
        }
        $id = data_get($device->external_ref ?? [], 'access_console_id');

        return is_int($id) ? AccessConsole::query()->find($id) : null;
    }

    /** The UniFi door a door device is linked to (by the sync). */
    public static function doorFor(Device $device): ?AccessDoor
    {
        return $device->category === 'access_control'
            ? AccessDoor::query()->with('console')->where('device_id', $device->id)->first()
            : null;
    }

    /**
     * Link UniFi's doors to Oblivion's door devices at the same Site (by UniFi door id), and mark
     * the console and linked doors as just seen, so their actions count a fresh observation.
     */
    public function touch(AccessConsole $console, CarbonImmutable $at): void
    {
        $doors = AccessDoor::query()->where('console_id', $console->id)->get();
        foreach ($doors as $door) {
            if ($door->device_id === null) {
                $deviceId = Device::query()
                    ->where('category', 'access_control')
                    ->where(fn ($query) => $query->where('external_ref->provider_door_id', $door->external_id)
                        ->orWhere(fn ($entity) => $entity->where('external_ref->provider_resource_kind', 'door')->where('external_ref->provider_entity_id', $door->external_id)))
                    ->whereHas('assignments', fn ($query) => $query->where('assignable_type', DeviceAssignment::TARGET_SITE)->where('assignable_id', $console->site_id)->whereNull('released_at'))
                    ->value('id');
                if ($deviceId !== null) {
                    $door->forceFill(['device_id' => (int) $deviceId])->save();
                }
            }
        }
        $ids = $doors->pluck('device_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $console = self::deviceFor($console);
        if ($console instanceof Device) {
            $ids[] = (int) $console->id;
        }
        if ($ids !== []) {
            Device::query()->whereIn('id', $ids)->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $at))
                ->update(['last_seen_at' => $at]);
        }
    }
}
