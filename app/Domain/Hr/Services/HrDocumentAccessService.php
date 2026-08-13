<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Canonical Site and sensitivity boundary for retained employee documents.
 *
 * Historical documents remain available when their employee profile retains
 * provenance at an accessible Site. New documents can target current approved
 * staff only. Restricted evidence is an additional manage-permission boundary.
 */
class HrDocumentAccessService
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    /** @return Builder<HrDocument> */
    public function applySiteDocumentScope(Builder $query, User $viewer): Builder
    {
        $historicalUserIds = $this->siteAccess->applyHistoricalHrEmployeeStaffScope(
            User::query()->select('users.id'),
            $viewer,
        );
        $visibleProfileIds = HrEmployeeProfile::withTrashed()
            ->select('hr_employee_profiles.id')
            ->whereIn('user_id', $historicalUserIds);

        $query->whereIn(
            $query->qualifyColumn('employee_profile_id'),
            $visibleProfileIds,
        );

        return $query;
    }

    /** @return Builder<HrDocument> */
    public function applyReadableDocumentScope(Builder $query, User $viewer): Builder
    {
        $this->applySiteDocumentScope($query, $viewer);

        if (! $viewer->canDo('hr.documents.manage')) {
            $query->where($query->qualifyColumn('is_restricted'), false);
        }

        return $query;
    }

    /** @return Builder<HrEmployeeProfile> */
    public function applyCurrentProfileScope(Builder $query, User $viewer): Builder
    {
        $currentUserIds = $this->siteAccess->applyHrEmployeeStaffScope(
            User::query()->select('users.id'),
            $viewer,
        );

        return $query->whereIn($query->qualifyColumn('user_id'), $currentUserIds);
    }

    /** @return Builder<HrEmployeeProfile> */
    public function applyHistoricalProfileScope(Builder $query, User $viewer): Builder
    {
        $historicalUserIds = $this->siteAccess->applyHistoricalHrEmployeeStaffScope(
            User::query()->select('users.id'),
            $viewer,
        );

        return $query->whereIn($query->qualifyColumn('user_id'), $historicalUserIds);
    }

    public function readableDocument(User $viewer, HrDocument|int $document): HrDocument
    {
        $documentId = $document instanceof HrDocument ? $document->getKey() : $document;

        return $this->applyReadableDocumentScope(HrDocument::query(), $viewer)
            ->findOrFail($documentId);
    }

    public function siteDocument(User $viewer, HrDocument|int $document): HrDocument
    {
        $documentId = $document instanceof HrDocument ? $document->getKey() : $document;

        return $this->applySiteDocumentScope(HrDocument::query(), $viewer)
            ->findOrFail($documentId);
    }

    public function currentProfile(User $viewer, HrEmployeeProfile|int $profile): HrEmployeeProfile
    {
        $profileId = $profile instanceof HrEmployeeProfile ? $profile->getKey() : $profile;

        return $this->applyCurrentProfileScope(HrEmployeeProfile::query(), $viewer)
            ->findOrFail($profileId);
    }

    public function historicalProfile(User $viewer, HrEmployeeProfile|int $profile): HrEmployeeProfile
    {
        $profileId = $profile instanceof HrEmployeeProfile ? $profile->getKey() : $profile;

        return $this->applyHistoricalProfileScope(HrEmployeeProfile::query(), $viewer)
            ->findOrFail($profileId);
    }

    /**
     * Resolve an exact bulk set. Any missing, inaccessible, duplicated, or
     * malformed identifier conceals the entire operation instead of applying a
     * partial mutation that reveals which records were accepted.
     *
     * @param  array<int, mixed>  $rawIds
     * @return Collection<int, HrDocument>
     */
    public function exactReadableDocuments(User $viewer, array $rawIds): Collection
    {
        $ids = collect($rawIds)
            ->filter(fn (mixed $id) => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id) => (int) $id)
            ->unique()
            ->values();

        abort_unless($ids->count() === count($rawIds), 404);

        $documents = $this->applyReadableDocumentScope(HrDocument::query(), $viewer)
            ->whereIn('id', $ids->all())
            ->get();

        abort_unless($documents->count() === $ids->count(), 404);

        return $documents;
    }
}
