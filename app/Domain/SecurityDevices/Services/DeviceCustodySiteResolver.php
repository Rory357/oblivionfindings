<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteRoom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use UnexpectedValueException;

/**
 * Resolves the one operational Site that has custody of an assignment target.
 *
 * The resolved value is persisted on DeviceAssignment.custody_site_id. Current
 * access additionally re-checks the live target against that snapshot; history
 * uses the snapshot so a later Client, staff, room, or Asset move cannot rewrite
 * the Site that had custody when the assignment was made.
 */
final class DeviceCustodySiteResolver
{
    public function resolve(string $targetType, int $targetId, bool $lockForUpdate = false): int
    {
        if (! in_array($targetType, DeviceAssignment::VALID_TARGETS, true) || $targetId < 1) {
            throw new UnexpectedValueException('Device assignment target is invalid.');
        }

        $siteId = match ($targetType) {
            DeviceAssignment::TARGET_SITE => $this->siteTarget($targetId, $lockForUpdate),
            DeviceAssignment::TARGET_ROOM => $this->roomTarget($targetId, $lockForUpdate),
            DeviceAssignment::TARGET_CLIENT => $this->clientTarget($targetId, $lockForUpdate),
            DeviceAssignment::TARGET_STAFF => $this->staffTarget($targetId, $lockForUpdate),
            DeviceAssignment::TARGET_VEHICLE => $this->assetTarget($targetId, $lockForUpdate),
        };

        $site = $this->maybeLock(Site::query()->whereKey($siteId), $lockForUpdate)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->first(['id']);

        if (! $site) {
            throw new UnexpectedValueException('Device assignment custody Site is unavailable.');
        }

        return (int) $site->id;
    }

    public function tryResolve(string $targetType, int $targetId): ?int
    {
        try {
            return $this->resolve($targetType, $targetId);
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    public function assignmentMatchesCurrentTarget(DeviceAssignment $assignment): bool
    {
        if (! is_numeric($assignment->custody_site_id) || (int) $assignment->custody_site_id < 1) {
            return false;
        }

        return $this->tryResolve(
            (string) $assignment->assignable_type,
            (int) $assignment->assignable_id,
        ) === (int) $assignment->custody_site_id;
    }

    private function siteTarget(int $targetId, bool $lockForUpdate): int
    {
        $site = $this->maybeLock(Site::query()->whereKey($targetId), $lockForUpdate)->first(['id']);

        return $site ? (int) $site->id : throw new UnexpectedValueException('Device assignment Site was not found.');
    }

    private function roomTarget(int $targetId, bool $lockForUpdate): int
    {
        $room = $this->maybeLock(SiteRoom::query()->whereKey($targetId), $lockForUpdate)->first(['site_id']);

        return $this->positiveId($room?->site_id, 'Device assignment room has no canonical Site.');
    }

    private function clientTarget(int $targetId, bool $lockForUpdate): int
    {
        $client = $this->maybeLock(
            Client::query()->whereKey($targetId)->where('status', 'active'),
            $lockForUpdate,
        )->first(['site_id']);

        return $this->positiveId($client?->site_id, 'Device assignment Client has no active canonical Site.');
    }

    private function staffTarget(int $targetId, bool $lockForUpdate): int
    {
        $profile = $this->maybeLock(
            HrEmployeeProfile::query()
                ->where('user_id', $targetId)
                ->where('is_active', true)
                ->where(function (Builder $dates): void {
                    $dates->whereNull('start_date')->orWhereDate('start_date', '<=', today());
                })
                ->where(function (Builder $dates): void {
                    $dates->whereNull('end_date')->orWhereDate('end_date', '>=', today());
                }),
            $lockForUpdate,
        )->first(['primary_site_id']);

        return $this->positiveId($profile?->primary_site_id, 'Device assignment staff member has no current primary Site.');
    }

    private function assetTarget(int $targetId, bool $lockForUpdate): int
    {
        $asset = $this->maybeLock(
            Asset::query()
                ->whereKey($targetId)
                ->where('status', 'active')
                ->where(function (Builder $vehicle): void {
                    $vehicle->whereRaw('LOWER(category) = ?', ['vehicle'])
                        ->orWhereHas('categoryRef', fn (Builder $category): Builder => $category
                            ->whereRaw('LOWER(slug) = ?', ['vehicle']));
                }),
            $lockForUpdate,
        )->first(['site_id', 'home_site_id', 'client_id']);

        if (! $asset) {
            throw new UnexpectedValueException('Device assignment vehicle is unavailable.');
        }

        $siteIds = collect([$asset->site_id, $asset->home_site_id]);
        if ($asset->client_id !== null) {
            $client = $this->maybeLock(
                Client::query()->whereKey($asset->client_id)->where('status', 'active'),
                $lockForUpdate,
            )->first(['site_id']);
            if (! $client) {
                throw new UnexpectedValueException('Device assignment vehicle Client is unavailable.');
            }
            $siteIds->push($client->site_id);
        }

        return $this->oneSite($siteIds, 'Device assignment vehicle does not resolve to one canonical Site.');
    }

    /** @param Collection<int, mixed> $candidates */
    private function oneSite(Collection $candidates, string $message): int
    {
        $siteIds = $candidates
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($siteIds->count() !== 1) {
            throw new UnexpectedValueException($message);
        }

        return (int) $siteIds->first();
    }

    private function positiveId(mixed $value, string $message): int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            throw new UnexpectedValueException($message);
        }

        return (int) $value;
    }

    /** @template TModel of Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function maybeLock(Builder $query, bool $lockForUpdate): Builder
    {
        return $lockForUpdate ? $query->lockForUpdate() : $query;
    }
}
