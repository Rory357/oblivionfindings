<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;

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

    /**
     * H&S injury restrictions: the document must be readable by the current viewer AND belong to the injured
     * worker's own HR profile (retained profiles included), inside the caller's current-authorization reads.
     */
    public function assertReadableDocumentForCurrentReads(User $viewer, int $documentId, int $expectedWorkerId, CurrentAuthorizationReads $reads): void
    {
        $reads->assertActive();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $level = $connection->transactionLevel();
        if ($viewer->getConnection() !== $connection || ! $pdo instanceof PDO || ! $pdo->inTransaction()) {
            throw new LogicException('Current document access requires the caller physical transaction connection.');
        }
        abort_unless($documentId > 0 && $expectedWorkerId > 0 && (int) $viewer->getKey() > 0, 404);
        $current = (new AuthorizationEvidenceLockService)->lockForUserWithoutWaiting($viewer, [
            'hr.documents.view', 'hr.documents.manage', ...UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS,
        ]);
        abort_unless($reads->query((new HrCurrentStaffService)->currentUsersQuery())->whereKey($current->id)->exists(), 403);
        abort_unless($current->canDo('hr.documents.view'), 403);

        // Constrain the document to the injured worker before any private hydration.
        $expectedProfiles = HrEmployeeProfile::withTrashed()->select('hr_employee_profiles.id')->where('user_id', $expectedWorkerId);
        $reads->query($expectedProfiles);
        $document = $this->applyReadableDocumentScope(HrDocument::query()->select('hr_documents.id'), $current)
            ->whereKey($documentId)
            ->whereIn('employee_profile_id', $expectedProfiles);
        abort_unless($reads->query($document)->exists(), 404);
        $reads->assertActive();
        if ($connection !== DB::connection() || $connection->getPdo() !== $pdo
            || $connection->transactionLevel() !== $level || ! $pdo->inTransaction()) {
            throw new LogicException('Current document access left its caller physical transaction.');
        }
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
