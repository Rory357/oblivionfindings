<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Resolves the application-wide compliance matrix for a staff member.
 *
 * Legacy storage columns are deliberately ignored. A matrix row applies when
 * the staff member has its role and the row is global (`all`/null) or matches
 * the type of any canonical primary or secondary Site assignment.
 */
class ComplianceRequirementApplicabilityService
{
    /** @return Collection<int, HrComplianceRequirement> */
    public function forUser(User $user, bool $hardStopsOnly = false): Collection
    {
        $user->loadMissing('roles:id,name');

        $requirements = HrComplianceRequirement::query()
            ->where('is_active', true)
            ->when($hardStopsOnly, fn ($query) => $query->where('hard_stop', true))
            ->with('matrixEntries:id,requirement_id,role,site_type')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $siteTypes = $this->siteTypesForUsers(collect([$user]))->get((int) $user->id, collect());

        return $this->applicableFrom($requirements, $user, $siteTypes);
    }

    /**
     * Return exact applicable requirement snapshots for each supplied person.
     * Missing materialised rows are represented as not started and stale rows
     * for requirements that no longer apply are excluded.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, Collection<int, array{requirement:HrComplianceRequirement,status_row:?HrStaffComplianceStatus,status:string}>>
     */
    public function snapshotsForUsers(Collection $users): Collection
    {
        if ($users->isEmpty()) {
            return collect();
        }

        $eloquentUsers = $users instanceof EloquentCollection
            ? $users
            : new EloquentCollection($users->values()->all());
        $eloquentUsers->loadMissing([
            'roles:id,name',
            'complianceStatuses',
        ]);

        $requirements = HrComplianceRequirement::query()
            ->where('is_active', true)
            ->with('matrixEntries:id,requirement_id,role,site_type')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $siteTypes = $this->siteTypesForUsers($eloquentUsers);

        return $eloquentUsers->mapWithKeys(function (User $user) use ($requirements, $siteTypes): array {
            $applicable = $this->applicableFrom(
                $requirements,
                $user,
                $siteTypes->get((int) $user->id, collect()),
            );
            $statuses = $user->complianceStatuses
                ->whereIn('requirement_id', $applicable->pluck('id'))
                ->groupBy('requirement_id');

            $snapshots = $applicable->map(function (HrComplianceRequirement $requirement) use ($statuses): array {
                $statusRow = $this->safestStatusRow($statuses->get($requirement->id, collect()));

                return [
                    'requirement' => $requirement,
                    'status_row' => $statusRow,
                    'status' => $this->effectiveStatus($statusRow, $requirement),
                ];
            })->values();

            return [(int) $user->id => $snapshots];
        });
    }

    /**
     * Build exact per-person summaries. Missing materialised rows are treated as
     * not started, so a newly assigned requirement can never produce a false
     * "fully compliant" result while the evaluator catches up.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, array{total:int,compliant:int,expiring_soon:int,expired:int,not_started:int,hard_stop_failures:int,hard_stop_expiring:int,fully_compliant:bool}>
     */
    public function summariesForUsers(Collection $users): Collection
    {
        if ($users->isEmpty()) {
            return collect();
        }

        return $this->snapshotsForUsers($users)->map(function (Collection $snapshots): array {

            $summary = [
                'total' => $snapshots->count(),
                'compliant' => 0,
                'expiring_soon' => 0,
                'expired' => 0,
                'not_started' => 0,
                'hard_stop_failures' => 0,
                'hard_stop_expiring' => 0,
                'fully_compliant' => false,
            ];

            foreach ($snapshots as $snapshot) {
                $requirement = $snapshot['requirement'];
                $status = $snapshot['status'];
                $summary[$status]++;
                if ($requirement->hard_stop && in_array($status, ['expired', 'not_started'], true)) {
                    $summary['hard_stop_failures']++;
                }
                if ($requirement->hard_stop && $status === 'expiring_soon') {
                    $summary['hard_stop_expiring']++;
                }
            }

            $summary['fully_compliant'] = $summary['total'] > 0
                && $summary['compliant'] === $summary['total'];

            return $summary;
        });
    }

    /**
     * @param  Collection<int, HrComplianceRequirement>  $requirements
     * @return Collection<int, HrComplianceRequirement>
     */
    private function applicableFrom(Collection $requirements, User $user, Collection $siteTypes): Collection
    {
        $roles = $user->roles
            ->pluck('name')
            ->filter()
            ->map(fn ($role) => mb_strtolower(trim((string) $role)))
            ->flip();
        if ($roles->isEmpty()) {
            return collect();
        }

        return $requirements
            ->filter(function (HrComplianceRequirement $requirement) use ($roles, $siteTypes): bool {
                return $requirement->matrixEntries->contains(function ($entry) use ($roles, $siteTypes): bool {
                    $role = mb_strtolower(trim((string) $entry->role));
                    if (! $roles->has($role)) {
                        return false;
                    }

                    $matrixSiteType = mb_strtolower(trim((string) ($entry->site_type ?? '')));

                    return $matrixSiteType === ''
                        || $matrixSiteType === 'all'
                        || $siteTypes->contains($matrixSiteType);
                });
            })
            ->values();
    }

    /**
     * Resolve Site types from the canonical profile membership graph in one
     * batch. This deliberately includes both primary_site_id and every
     * secondary_site_ids entry and ignores legacy partition markers.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, Collection<int, string>>
     */
    private function siteTypesForUsers(Collection $users): Collection
    {
        $userIds = $users->pluck('id')->map(fn ($id) => (int) $id)->filter()->values();
        $profiles = HrEmployeeProfile::query()
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'primary_site_id', 'secondary_site_ids'])
            ->keyBy('user_id');
        $siteIds = $profiles->flatMap(function (HrEmployeeProfile $profile): array {
            return collect([$profile->primary_site_id])
                ->merge(is_array($profile->secondary_site_ids) ? $profile->secondary_site_ids : [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values()
                ->all();
        })->unique()->values();
        $siteTypeById = Site::query()
            ->whereIn('id', $siteIds)
            ->pluck('type', 'id');

        return $users->mapWithKeys(function (User $user) use ($profiles, $siteTypeById): array {
            $profile = $profiles->get((int) $user->id);
            if (! $profile) {
                return [(int) $user->id => collect()];
            }

            $types = collect([$profile->primary_site_id])
                ->merge(is_array($profile->secondary_site_ids) ? $profile->secondary_site_ids : [])
                ->map(fn ($id) => $siteTypeById->get((int) $id))
                ->filter()
                ->map(fn ($type) => mb_strtolower(trim((string) $type)))
                ->filter()
                ->unique()
                ->values();

            return [(int) $user->id => $types];
        });
    }

    /** @param Collection<int, HrStaffComplianceStatus> $rows */
    private function safestStatusRow(Collection $rows): ?HrStaffComplianceStatus
    {
        foreach (['expired', 'not_started', 'expiring_soon', 'compliant'] as $status) {
            $row = $rows->first(fn ($candidate) => $candidate->status === $status);
            if ($row instanceof HrStaffComplianceStatus) {
                return $row;
            }
        }

        return null;
    }

    private function effectiveStatus(
        ?HrStaffComplianceStatus $status,
        HrComplianceRequirement $requirement,
    ): string {
        if (! $status) {
            return 'not_started';
        }

        if (filled($status->exemption_reason) && $status->exempted_at) {
            if ($status->exempted_until === null || $status->exempted_until->isFuture()) {
                return 'compliant';
            }

            return 'expired';
        }

        if (in_array($status->status, ['expired', 'non_compliant'], true)) {
            return 'expired';
        }

        if ($status->status === 'not_started') {
            return 'not_started';
        }

        if ($status->expires_at?->isPast()) {
            return 'expired';
        }

        $reminderDays = $requirement->renewal_reminder_days ?: 30;
        if ($status->expires_at?->isFuture()
            && $status->expires_at->diffInDays(now(), true) <= $reminderDays
        ) {
            return 'expiring_soon';
        }

        return match ($status->status) {
            'compliant', 'expiring_soon', 'expired', 'not_started' => $status->status,
            'non_compliant' => 'expired',
            default => 'not_started',
        };
    }
}
