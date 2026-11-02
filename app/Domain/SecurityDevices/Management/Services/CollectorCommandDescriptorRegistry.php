<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Contracts\CollectorCommandDescriptor;

/**
 * Every job a hub can run for a governed command, keyed by capability (S&D Phase B).
 */
final class CollectorCommandDescriptorRegistry
{
    /** @var array<string, CollectorCommandDescriptor> */
    private array $byCapability = [];

    public function __construct(
        UnifiAccessCollectorCommandDescriptor $unifiAccess,
        NetworkSshConfigBackupCollectorDescriptor $sshConfigBackup,
        CameraSnapshotCollectorDescriptor $cameraSnapshot,
        AlarmArmCollectorDescriptor $alarmArm,
        AlarmDisarmCollectorDescriptor $alarmDisarm,
        // S&D Phase H: keep a door unlocked, and the Site emergency modes, in UniFi Access.
        UnifiAccessKeepUnlockedCollectorDescriptor $keepUnlocked,
        UnifiAccessKeepUnlockedEndCollectorDescriptor $keepUnlockedEnd,
        UnifiAccessLockdownCollectorDescriptor $lockdown,
        UnifiAccessEvacuateCollectorDescriptor $evacuate,
        UnifiAccessEmergencyEndCollectorDescriptor $emergencyEnd,
    ) {
        foreach ([$unifiAccess, $sshConfigBackup, $cameraSnapshot, $alarmArm, $alarmDisarm, $keepUnlocked, $keepUnlockedEnd, $lockdown, $evacuate, $emergencyEnd] as $descriptor) {
            $this->byCapability[$descriptor->capability()] = $descriptor;
        }
    }

    public function for(string $capability): ?CollectorCommandDescriptor
    {
        return $this->byCapability[$capability] ?? null;
    }
}
