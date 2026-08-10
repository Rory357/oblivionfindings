<?php

namespace App\Services\Assets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\ClientEmergencyContact;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssetAssignmentService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** @param array<string, mixed> $data */
    public function assign(User $actor, Asset $asset, array $data): AssetAssignment
    {
        return DB::transaction(function () use ($actor, $asset, $data): AssetAssignment {
            $asset = $this->access->assignableAsset($actor, (int) $asset->getKey(), true) ?? abort(404);
            $assetSiteIds = $this->assetSiteIds($asset);
            if ($assetSiteIds === []) {
                throw ValidationException::withMessages([
                    'asset' => 'The Asset has no canonical Site and cannot be assigned.',
                ]);
            }

            $this->assertTarget($actor, (string) $data['assignee_type'], (int) $data['assignee_id'], $assetSiteIds);

            $active = AssetAssignment::query()
                ->where('asset_id', $asset->id)
                ->whereNull('released_at')
                ->lockForUpdate()
                ->first();
            if ($active) {
                throw ValidationException::withMessages([
                    'assignee_id' => 'Release the current assignment before assigning this Asset again.',
                ]);
            }

            $assignedAt = CarbonImmutable::parse($data['assigned_at'] ?? now());
            if ($assignedAt->isFuture()) {
                throw ValidationException::withMessages([
                    'assigned_at' => 'An active Asset assignment cannot start in the future.',
                ]);
            }

            $assignment = AssetAssignment::query()->create([
                'asset_id' => $asset->id,
                'assignee_type' => $data['assignee_type'],
                'assignee_id' => (int) $data['assignee_id'],
                'purpose' => $data['purpose'] ?? null,
                'assigned_at' => $assignedAt,
            ]);

            AuditLogger::logOrFail('assets.assignment.created', $asset, [
                'assignment_id' => $assignment->id,
            ]);

            return $assignment;
        }, 3);
    }

    public function release(User $actor, Asset $asset, AssetAssignment $assignment): AssetAssignment
    {
        return DB::transaction(function () use ($actor, $asset, $assignment): AssetAssignment {
            $asset = $this->access->assignableAsset($actor, (int) $asset->getKey(), true) ?? abort(404);
            $assignment = AssetAssignment::query()
                ->where('asset_id', $asset->id)
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($assignment->released_at === null) {
                $releasedAt = now();
                if ($assignment->assigned_at?->isAfter($releasedAt)) {
                    throw ValidationException::withMessages([
                        'assignment' => 'This assignment starts in the future and cannot be released as current history.',
                    ]);
                }
                $assignment->update(['released_at' => $releasedAt]);
                AuditLogger::logOrFail('assets.assignment.released', $asset, [
                    'assignment_id' => $assignment->id,
                ]);
            }

            return $assignment->fresh();
        }, 3);
    }

    /** @param list<int> $assetSiteIds */
    private function assertTarget(User $actor, string $type, int $id, array $assetSiteIds): void
    {
        $targetSiteId = match ($type) {
            'staff' => $this->staffSiteId($actor, $id, $assetSiteIds),
            'client' => $this->clientSiteId($actor, $id),
            'whanau' => $this->whanauSiteId($actor, $id),
            default => null,
        };

        abort_unless($targetSiteId !== null && in_array($targetSiteId, $assetSiteIds, true), 404);
    }

    /** @param list<int> $assetSiteIds */
    private function staffSiteId(User $actor, int $id, array $assetSiteIds): ?int
    {
        $staff = $this->access->assignableStaffMember($actor, $id, true);
        if (! $staff) {
            return null;
        }

        $profile = HrEmployeeProfile::query()
            ->where('user_id', $staff->id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->lockForUpdate()
            ->first(['primary_site_id', 'secondary_site_ids']);
        $staffSiteIds = collect([$profile?->primary_site_id, ...($profile?->secondary_site_ids ?? [])])
            ->filter(fn ($siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
            ->map(fn ($siteId): int => (int) $siteId)
            ->all();

        return collect($staffSiteIds)->first(fn (int $siteId): bool => in_array($siteId, $assetSiteIds, true));
    }

    private function clientSiteId(User $actor, int $id): ?int
    {
        $client = $this->access->assignableClient($actor, $id, true);

        return $client && is_numeric($client->site_id) ? (int) $client->site_id : null;
    }

    private function whanauSiteId(User $actor, int $id): ?int
    {
        $contact = ClientEmergencyContact::query()->whereKey($id)->lockForUpdate()->first(['id', 'client_id']);
        if (! $contact) {
            return null;
        }

        return $this->clientSiteId($actor, (int) $contact->client_id);
    }

    /** @return list<int> */
    private function assetSiteIds(Asset $asset): array
    {
        $asset->loadMissing('client:id,site_id');
        $direct = is_numeric($asset->site_id) ? (int) $asset->site_id : null;
        $home = is_numeric($asset->home_site_id) ? (int) $asset->home_site_id : null;
        $client = is_numeric($asset->client?->site_id) ? (int) $asset->client->site_id : null;

        if ($direct !== null) {
            return ($home === null || $home === $direct) && ($client === null || $client === $direct)
                ? [$direct]
                : [];
        }
        if ($home !== null) {
            return $client === null || $client === $home ? [$home] : [];
        }

        return $client !== null ? [$client] : [];
    }
}
