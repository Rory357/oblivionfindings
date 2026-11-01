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
    ) {
        foreach ([$unifiAccess, $sshConfigBackup] as $descriptor) {
            $this->byCapability[$descriptor->capability()] = $descriptor;
        }
    }

    public function for(string $capability): ?CollectorCommandDescriptor
    {
        return $this->byCapability[$capability] ?? null;
    }
}
