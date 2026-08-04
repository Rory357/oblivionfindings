<?php

namespace App\Domain\SecurityDevices\Credentials\Services;

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Models\Device;
use RuntimeException;

final class CommandCredentialLeaseService
{
    public function __construct(
        private readonly CredentialLeaseProvider $leases,
        private readonly CredentialLeaseLifecycleService $lifecycle,
    ) {}

    public function acquire(CommandExecutionContext $context): CredentialLease
    {
        return $this->acquireFor($context->device, $context->siteId, $context->capability);
    }

    public function acquireFor(Device $device, int $siteId, string $capability): CredentialLease
    {
        $leaseCapability = $this->leaseCapability($capability);
        $reference = $this->referenceFor($device, $siteId, $capability);
        if (! $reference) {
            throw new RuntimeException('A governed command credential is unavailable.');
        }

        return $this->leases->acquire($siteId, $reference->reference_key, [$leaseCapability]);
    }

    public function available(Device $device, int $siteId, string $capability): bool
    {
        return $this->referenceFor($device, $siteId, $capability) !== null;
    }

    public function release(CredentialLease $lease): void
    {
        $this->lifecycle->release($lease);
    }

    private function referenceFor(Device $device, int $siteId, string $capability): ?CredentialReference
    {
        if ($siteId < 1) {
            return null;
        }
        $provider = strtolower(trim((string) $device->provider));
        if ($provider === '') {
            return null;
        }
        $leaseCapability = $this->leaseCapability($capability);

        return CredentialReference::query()
            ->where('site_id', $siteId)
            ->where('provider', $provider)
            ->where('purpose', 'device_management')
            ->where('status', CredentialReferenceStatus::Active->value)
            ->whereNotIn('rotation_status', [
                CredentialRotationStatus::Overdue->value,
                CredentialRotationStatus::Failed->value,
            ])
            ->get()
            ->first(fn (CredentialReference $candidate): bool => in_array(
                $leaseCapability,
                $candidate->capabilities ?? [],
                true,
            ));
    }

    private function leaseCapability(string $capability): string
    {
        return 'command:'.strtolower(trim($capability));
    }
}
