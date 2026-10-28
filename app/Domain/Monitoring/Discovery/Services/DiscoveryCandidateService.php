<?php

namespace App\Domain\Monitoring\Discovery\Services;

use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Data\IdentityMatchResult;
use App\Domain\Monitoring\Discovery\Models\DeviceIdentityEvidence;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

final class DiscoveryCandidateService
{
    public function __construct(
        private readonly DeviceRegistryService $registry,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceFingerprintService $fingerprinter = new DeviceFingerprintService,
    ) {}

    public function record(
        DiscoveryRun $run,
        DiscoveredIdentity $identity,
        IdentityMatchResult $match,
    ): DiscoveryCandidate {
        if (! in_array($run->status, ['queued', 'running', 'partial'], true)) {
            throw new UnexpectedValueException('Discovery run cannot accept candidates.');
        }

        $snapshot = $identity->snapshot();
        $signals = $identity->sanitizedSignals();
        if ($signals !== []) {
            $snapshot['signals'] = $signals;
        }
        $snapshot['identification'] = $this->fingerprinter->identify($identity)->toArray();
        $hash = $identity->evidenceHash();

        $candidate = DiscoveryCandidate::query()->createOrFirst([
            'discovery_run_id' => $run->id,
            'evidence_hash' => $hash,
        ], [
            'candidate_uuid' => (string) Str::orderedUuid(),
            'canonical_device_id' => $match->deviceId,
            'decision' => $match->decision,
            'confidence' => $match->confidence,
            'reasons' => $match->reasons,
            'evidence_snapshot' => $snapshot,
        ]);

        if (! $candidate->wasRecentlyCreated && $signals !== []) {
            $existingSnapshot = is_array($candidate->evidence_snapshot) ? $candidate->evidence_snapshot : [];
            if (($existingSnapshot['signals'] ?? null) !== $signals) {
                $existingSnapshot['signals'] = $signals;
                $existingSnapshot['identification'] = $snapshot['identification'];
                $candidate->forceFill(['evidence_snapshot' => $existingSnapshot])->save();
            }
        }

        return $candidate;
    }

    /** @param array<string, mixed> $attributes */
    public function adopt(DiscoveryCandidate $candidate, int $actorId, array $attributes = []): Device
    {
        return DB::transaction(function () use ($candidate, $actorId, $attributes): Device {
            $locked = $this->lockCandidate($candidate);
            $scope = $locked->run()->with('scope')->firstOrFail()->scope;
            if ($scope === null) {
                throw new UnexpectedValueException('Discovery candidate scope is unavailable.');
            }
            $actor = $this->reviewer($actorId, [
                'securityDevices.integrations.manage',
                'securityDevices.devices.create',
            ]);
            $this->access->assertCanViewSite($actor, (int) $scope->site_id);
            if ($locked->review_action === 'adopted' && $locked->canonical_device_id !== null) {
                return Device::query()->findOrFail($locked->canonical_device_id);
            }
            if ($locked->reviewed_at !== null || ! in_array($locked->decision, ['proposed', 'review'], true)) {
                throw new UnexpectedValueException('Discovery candidate cannot be adopted.');
            }
            $identity = $this->identityFromSnapshot($locked->evidence_snapshot ?? []);
            $reviewedClassification = collect(array_intersect_key($attributes, array_flip([
                'name',
                'domain',
                'category',
                'subcategory',
                'manufacturer',
                'model',
            ])))
                ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                ->map(fn (string $value): string => trim($value))
                ->all();
            $device = $this->registry->registerDiscoveredDevice([
                ...$this->deviceAttributes($identity),
                ...$reviewedClassification,
            ], (int) $scope->site_id, $actorId);
            $this->attachEvidence($device, $identity);
            $this->completeReview($locked, $device, $actorId, 'adopted');
            AuditLogger::logOrFail('monitoring.discovery.candidate.adopted', $device, [
                'actor_id' => $actorId,
                'candidate_id' => $locked->id,
                'site_id' => (int) $scope->site_id,
            ]);

            return $device;
        }, 3);
    }

    public function sameAs(
        DiscoveryCandidate $candidate,
        Device $target,
        int $actorId,
    ): Device {
        return DB::transaction(function () use ($candidate, $target, $actorId): Device {
            $lockedCandidate = $this->lockCandidate($candidate);
            $scope = $lockedCandidate->run()->with('scope')->firstOrFail()->scope;
            if ($scope === null) {
                throw new UnexpectedValueException('Discovery candidate scope is unavailable.');
            }
            $actor = $this->reviewer($actorId, [
                'securityDevices.integrations.manage',
                'securityDevices.devices.update',
            ]);
            $scopeSiteId = (int) $scope->site_id;
            $this->access->assertCanViewSite($actor, $scopeSiteId);
            if ($lockedCandidate->review_action === 'same_as' && $lockedCandidate->canonical_device_id !== null) {
                $resolved = Device::query()->findOrFail($lockedCandidate->canonical_device_id);
                $this->access->assertCanViewDevice($actor, $resolved);

                return $resolved;
            }
            if ($lockedCandidate->reviewed_at !== null || ! in_array($lockedCandidate->decision, ['proposed', 'review'], true)) {
                throw new UnexpectedValueException('Discovery candidate cannot be linked.');
            }
            $lockedTarget = Device::query()->lockForUpdate()->findOrFail($target->id);
            $this->access->assertCanViewDevice($actor, $lockedTarget);
            $targetSiteId = $this->siteResolver->resolve((int) $lockedTarget->id);
            if ($lockedTarget->status === DeviceStatus::Decommissioned || $targetSiteId !== $scopeSiteId) {
                throw new UnexpectedValueException('Select an active device assigned to the same Site.');
            }

            $identity = $this->identityFromSnapshot($lockedCandidate->evidence_snapshot ?? []);
            $snapshot = $identity->snapshot();
            $backfill = [];
            if (($lockedTarget->ip_address === null || trim((string) $lockedTarget->ip_address) === '')
                && is_string($snapshot['addresses'][0] ?? null)
                && filter_var($snapshot['addresses'][0], FILTER_VALIDATE_IP) !== false) {
                $backfill['ip_address'] = $snapshot['addresses'][0];
            }
            if (($lockedTarget->mac_address === null || trim((string) $lockedTarget->mac_address) === '')
                && ($formattedMac = $this->formatMacAddress($snapshot['mac_addresses'][0] ?? null)) !== null) {
                $backfill['mac_address'] = $formattedMac;
            }
            if ($backfill !== []) {
                $lockedTarget->forceFill($backfill)->save();
            }

            $this->attachEvidence($lockedTarget, $identity);
            $this->completeReview($lockedCandidate, $lockedTarget, $actorId, 'same_as');
            AuditLogger::logOrFail('monitoring.discovery.candidate.same_as', $lockedTarget, [
                'actor_id' => $actorId,
                'candidate_id' => $lockedCandidate->id,
                'site_id' => $scopeSiteId,
            ]);

            return $lockedTarget->fresh();
        }, 3);
    }

    public function ignore(DiscoveryCandidate $candidate, int $actorId): DiscoveryCandidate
    {
        return DB::transaction(function () use ($candidate, $actorId): DiscoveryCandidate {
            $lockedCandidate = $this->lockCandidate($candidate);
            $scope = $lockedCandidate->run()->with('scope')->firstOrFail()->scope;
            if ($scope === null) {
                throw new UnexpectedValueException('Discovery candidate scope is unavailable.');
            }
            $actor = $this->reviewer($actorId, [
                'securityDevices.integrations.manage',
            ]);
            $scopeSiteId = (int) $scope->site_id;
            $this->access->assertCanViewSite($actor, $scopeSiteId);
            if ($lockedCandidate->review_action === 'ignored') {
                return $lockedCandidate;
            }
            if ($lockedCandidate->reviewed_at !== null || ! in_array($lockedCandidate->decision, ['proposed', 'review'], true)) {
                throw new UnexpectedValueException('Discovery candidate cannot be ignored.');
            }

            $lockedCandidate->forceFill([
                'reviewed_by_user_id' => $actorId,
                'reviewed_at' => now(),
                'review_action' => 'ignored',
            ])->save();

            AuditLogger::logOrFail('monitoring.discovery.candidate.ignored', $lockedCandidate, [
                'actor_id' => $actorId,
                'candidate_id' => $lockedCandidate->id,
                'site_id' => $scopeSiteId,
            ]);

            return $lockedCandidate->fresh();
        }, 3);
    }

    /** @return Collection<int, Device> */
    public function adoptAll(int $actorId, ?int $siteId = null, ?int $scopeId = null): Collection
    {
        $actor = $this->reviewer($actorId, [
            'securityDevices.integrations.manage',
            'securityDevices.devices.create',
        ]);
        if ($siteId !== null) {
            $this->access->assertCanViewSite($actor, $siteId);
        }

        $accessibleSiteIds = $this->access->accessibleSiteIds($actor);
        $canViewAllSites = $this->access->canViewAllSites($actor);

        $scopeIds = DiscoveryScope::query()
            ->when(! $canViewAllSites, fn (Builder $query) => $accessibleSiteIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('site_id', $accessibleSiteIds))
            ->when($siteId !== null, fn (Builder $query) => $query->where('site_id', $siteId))
            ->when($scopeId !== null, fn (Builder $query) => $query->whereKey($scopeId))
            ->pluck('id');

        if ($scopeIds->isEmpty()) {
            return collect();
        }

        $candidates = DiscoveryCandidate::query()
            ->whereNull('reviewed_at')
            ->where('decision', 'proposed')
            ->whereHas('run', fn (Builder $query): Builder => $query->whereIn('discovery_scope_id', $scopeIds))
            ->orderBy('id')
            ->limit(100)
            ->get();

        $adopted = collect();
        foreach ($candidates as $candidate) {
            $adopted->push($this->adopt($candidate, $actorId, []));
        }

        return $adopted;
    }

    public function merge(
        DiscoveryCandidate $candidate,
        Device $winner,
        Device $loser,
        int $actorId,
    ): Device {
        return DB::transaction(function () use ($candidate, $winner, $loser, $actorId): Device {
            $lockedCandidate = $this->lockCandidate($candidate);
            $scope = $lockedCandidate->run()->with('scope')->firstOrFail()->scope;
            if ($scope === null) {
                throw new UnexpectedValueException('Discovery candidate scope is unavailable.');
            }
            $actor = $this->reviewer($actorId, [
                'securityDevices.integrations.manage',
                'securityDevices.devices.update',
                'securityDevices.devices.delete',
            ]);
            $this->access->assertCanViewSite($actor, (int) $scope->site_id);
            if ($lockedCandidate->review_action === 'merged' && $lockedCandidate->canonical_device_id !== null) {
                $resolved = Device::query()->findOrFail($lockedCandidate->canonical_device_id);
                $this->access->assertCanViewDevice($actor, $resolved);

                return $resolved;
            }
            $lockedWinner = Device::query()->lockForUpdate()->findOrFail($winner->id);
            $lockedLoser = Device::query()->lockForUpdate()->findOrFail($loser->id);
            $this->access->assertCanViewDevice($actor, $lockedWinner);
            $this->access->assertCanViewDevice($actor, $lockedLoser);
            $scopeSiteId = (int) $scope->site_id;
            $winnerSiteId = $this->siteResolver->resolve((int) $lockedWinner->id);
            $loserSiteId = $this->siteResolver->resolve((int) $lockedLoser->id);
            if ($lockedWinner->is($lockedLoser)
                || $winnerSiteId !== $loserSiteId
                || $winnerSiteId !== $scopeSiteId
                || $lockedCandidate->reviewed_at !== null) {
                throw new UnexpectedValueException('Discovery merge requires two visible Devices at one canonical Site.');
            }

            foreach (DeviceIdentityEvidence::query()
                ->where('canonical_device_id', $lockedLoser->id)
                ->lockForUpdate()
                ->get() as $evidence) {
                $duplicate = DeviceIdentityEvidence::query()
                    ->where('canonical_device_id', $lockedWinner->id)
                    ->where('evidence_type', $evidence->evidence_type)
                    ->where('value_hash', $evidence->value_hash)
                    ->where('source', $evidence->source)
                    ->exists();
                if ($duplicate) {
                    $evidence->forceFill(['is_active' => false, 'superseded_at' => now()])->save();

                    continue;
                }
                $evidence->forceFill(['canonical_device_id' => $lockedWinner->id])->save();
            }

            foreach (DeviceAssignment::query()
                ->where('device_id', $lockedLoser->id)
                ->lockForUpdate()
                ->get() as $assignment) {
                if ($assignment->released_at === null
                    && DeviceAssignment::query()
                        ->where('device_id', $lockedWinner->id)
                        ->where('assignable_type', $assignment->assignable_type)
                        ->where('assignable_id', $assignment->assignable_id)
                        ->whereNull('released_at')
                        ->exists()) {
                    $assignment->forceFill([
                        'released_at' => now(),
                        'released_by_user_id' => $actorId,
                    ]);
                }
                $assignment->forceFill(['device_id' => $lockedWinner->id])->save();
            }
            Monitor::query()->where('device_id', $lockedLoser->id)->update(['device_id' => $lockedWinner->id]);

            $lockedLoser->forceFill(['status' => DeviceStatus::Decommissioned->value])->save();
            $lockedLoser->delete();
            $this->completeReview($lockedCandidate, $lockedWinner, $actorId, 'merged');
            AuditLogger::logOrFail('monitoring.discovery.candidate.merged', $lockedWinner, [
                'actor_id' => $actorId,
                'candidate_id' => $lockedCandidate->id,
                'merged_device_id' => $lockedLoser->id,
                'site_id' => $scopeSiteId,
            ]);

            return $lockedWinner;
        }, 3);
    }

    /**
     * @param  list<int>  $evidenceIds
     * @param  list<int>  $monitorIds
     * @param  array<string, mixed>  $attributes
     */
    public function split(
        DiscoveryCandidate $candidate,
        Device $source,
        array $evidenceIds,
        array $monitorIds,
        int $actorId,
        array $attributes,
    ): Device {
        return DB::transaction(function () use (
            $candidate,
            $source,
            $evidenceIds,
            $monitorIds,
            $actorId,
            $attributes,
        ): Device {
            $lockedCandidate = $this->lockCandidate($candidate);
            $scope = $lockedCandidate->run()->with('scope')->firstOrFail()->scope;
            if ($scope === null) {
                throw new UnexpectedValueException('Discovery candidate scope is unavailable.');
            }
            $actor = $this->reviewer($actorId, [
                'securityDevices.integrations.manage',
                'securityDevices.devices.create',
                'securityDevices.devices.update',
            ]);
            $this->access->assertCanViewSite($actor, (int) $scope->site_id);
            if ($lockedCandidate->review_action === 'split' && $lockedCandidate->canonical_device_id !== null) {
                $resolved = Device::query()->findOrFail($lockedCandidate->canonical_device_id);
                $this->access->assertCanViewDevice($actor, $resolved);

                return $resolved;
            }
            $lockedSource = Device::query()->lockForUpdate()->findOrFail($source->id);
            $this->access->assertCanViewDevice($actor, $lockedSource);
            $siteId = $this->siteResolver->resolve((int) $lockedSource->id);
            $scopeSiteId = (int) $scope->site_id;
            $evidenceIds = $this->ids($evidenceIds);
            $monitorIds = $this->ids($monitorIds);
            $evidence = DeviceIdentityEvidence::query()
                ->where('canonical_device_id', $lockedSource->id)
                ->whereKey($evidenceIds)
                ->lockForUpdate()
                ->get();
            $monitors = Monitor::query()
                ->where('device_id', $lockedSource->id)
                ->whereKey($monitorIds)
                ->lockForUpdate()
                ->get();
            if ($lockedCandidate->reviewed_at !== null
                || $siteId !== $scopeSiteId
                || $evidenceIds === []
                || $evidence->count() !== count($evidenceIds)
                || $monitors->count() !== count($monitorIds)
                || Monitor::query()->whereKey($monitorIds)->whereHas('observations')->exists()) {
                throw new UnexpectedValueException('Discovery split selection is invalid or has immutable history.');
            }

            $created = $this->registry->registerDiscoveredDevice($attributes, $siteId, $actorId);
            DeviceIdentityEvidence::query()->whereKey($evidenceIds)->update(['canonical_device_id' => $created->id]);
            Monitor::query()->whereKey($monitorIds)->update(['device_id' => $created->id]);
            $this->completeReview($lockedCandidate, $created, $actorId, 'split');
            AuditLogger::logOrFail('monitoring.discovery.candidate.split', $created, [
                'actor_id' => $actorId,
                'candidate_id' => $lockedCandidate->id,
                'source_device_id' => $lockedSource->id,
                'site_id' => $siteId,
                'evidence_count' => count($evidenceIds),
                'monitor_count' => count($monitorIds),
            ]);

            return $created;
        }, 3);
    }

    private function lockCandidate(DiscoveryCandidate $candidate): DiscoveryCandidate
    {
        return DiscoveryCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
    }

    /** @param list<string> $permissions */
    private function reviewer(int $actorId, array $permissions): User
    {
        $actor = User::query()->whereKey($actorId)->whereNotNull('approved_at')->first();
        if ($actor === null) {
            throw new UnexpectedValueException('Discovery review actor is unavailable.');
        }
        foreach ($permissions as $permission) {
            if (! $actor->canDo($permission)) {
                throw new AuthorizationException('Discovery review is not authorised.');
            }
        }

        return $actor;
    }

    private function completeReview(
        DiscoveryCandidate $candidate,
        Device $device,
        int $actorId,
        string $action,
    ): void {
        $candidate->forceFill([
            'canonical_device_id' => $device->id,
            'reviewed_by_user_id' => $actorId,
            'reviewed_at' => now(),
            'review_action' => $action,
        ])->save();
    }

    private function attachEvidence(Device $device, DiscoveredIdentity $identity): void
    {
        $source = trim((string) ($identity->provider ?? 'discovery')) ?: 'discovery';
        foreach ($identity->evidence() as $item) {
            DeviceIdentityEvidence::record(
                $device,
                $item['type'],
                $item['value'],
                $source,
                $item['weight'],
            );
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function identityFromSnapshot(array $snapshot): DiscoveredIdentity
    {
        return new DiscoveredIdentity(
            provider: is_string($snapshot['provider'] ?? null) ? $snapshot['provider'] : null,
            providerId: is_string($snapshot['provider_id'] ?? null) ? $snapshot['provider_id'] : null,
            serialNumber: is_string($snapshot['serial_number'] ?? null) ? $snapshot['serial_number'] : null,
            hardwareId: is_string($snapshot['hardware_id'] ?? null) ? $snapshot['hardware_id'] : null,
            macAddresses: $this->strings($snapshot['mac_addresses'] ?? []),
            certificateFingerprint: is_string($snapshot['certificate_fingerprint'] ?? null)
                ? $snapshot['certificate_fingerprint']
                : null,
            hostname: is_string($snapshot['hostname'] ?? null) ? $snapshot['hostname'] : null,
            addresses: $this->strings($snapshot['addresses'] ?? []),
            fingerprint: is_string($snapshot['fingerprint'] ?? null) ? $snapshot['fingerprint'] : null,
            signals: is_array($snapshot['signals'] ?? null) ? $snapshot['signals'] : [],
        );
    }

    /** @return array<string, mixed> */
    private function deviceAttributes(DiscoveredIdentity $identity): array
    {
        $snapshot = $identity->snapshot();
        $identification = $this->fingerprinter->identify($identity);
        $provider = is_string($snapshot['provider'] ?? null) ? $snapshot['provider'] : null;
        $providerId = is_string($snapshot['provider_id'] ?? null) ? $snapshot['provider_id'] : null;
        $firstAddress = is_string($snapshot['addresses'][0] ?? null) && trim($snapshot['addresses'][0]) !== ''
            ? trim($snapshot['addresses'][0])
            : null;

        return [
            'name' => $identification->suggestedName,
            'domain' => $identification->domain,
            'category' => $identification->category,
            'subcategory' => $identification->subcategory,
            'manufacturer' => $identification->manufacturer,
            'model' => $identification->model,
            'serial_number' => $snapshot['serial_number'] ?? null,
            'mac_address' => $this->formatMacAddress($snapshot['mac_addresses'][0] ?? null),
            'ip_address' => $firstAddress,
            'provider' => $provider,
            'external_ref' => $providerId === null ? null : ['provider_entity_id' => $providerId],
        ];
    }

    private function formatMacAddress(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $hex = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $raw) ?? '');

        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null;
    }

    /** @param mixed $values @return list<string> */
    private function strings(mixed $values): array
    {
        return is_array($values)
            ? array_values(array_filter($values, fn (mixed $value): bool => is_string($value) && trim($value) !== ''))
            : [];
    }

    /** @param list<int> $values @return list<int> */
    private function ids(array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
