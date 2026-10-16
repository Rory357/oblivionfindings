<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrKudos;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Whole-team recognition uses canonical departments and complete approved staff membership. */
class HrTeamRecognition
{
    public function __construct(private readonly HrCurrentStaffService $staff) {}

    private function currentAccess(): HrCalendarAccessService
    {
        // Deferred delivery may follow earlier reads in the same worker scope.
        // Reuse the canonical policy with fresh Site evidence, never its old cache.
        return new HrCalendarAccessService(new UserSiteAccessService, $this->staff);
    }

    public function ready(): bool
    {
        return Schema::hasColumn('hr_kudos', 'recipient_type') && Schema::hasColumn('hr_kudos', 'to_department_id') && Schema::hasTable('hr_kudos_recipients');
    }

    /** @return list<array{id:int,name:string,member_count:int}> */
    public function options(User $sender): array
    {
        $sender = $sender->fresh() ?? $sender;
        if (! $this->ready() || ! $this->staff->isCurrent($sender) || ! $sender->canDo('hr.recognition.give')) {
            return [];
        }
        $access = $this->currentAccess();
        $departments = $access->visibleDepartmentsQuery($sender)->orderBy('name')->get(['id', 'name']);
        $memberships = HrEmployeeProfile::query()->whereIn('department_id', $departments->pluck('id'))
            ->whereIn('user_id', $this->staff->currentUsersQuery()->select('users.id'))->get(['user_id', 'department_id'])->groupBy('department_id');
        $visible = $access->visibleCurrentStaffQuery($sender)->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        return $departments->filter(function ($department) use ($memberships, $visible): bool {
            $members = ($memberships[$department->id] ?? collect())->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->all();

            return $members !== [] && array_diff($members, $visible) === [];
        })->map(fn ($department) => ['id' => (int) $department->id, 'name' => $department->name, 'member_count' => $memberships[$department->id]->pluck('user_id')->unique()->count()])->values()->all();
    }

    /** Called inside the recognition write transaction. @return array{department:HrDepartment,members:Collection<int,User>} */
    public function resolve(User $sender, int $departmentId): array
    {
        $sender = $sender->fresh() ?? $sender;
        abort_unless($this->staff->isCurrent($sender) && $sender->canDo('hr.recognition.give'), 403);
        if (! $this->ready()) {
            throw ValidationException::withMessages(['to_department_id' => 'Team recognition is not available yet.']);
        }
        $access = $this->currentAccess();
        $department = $access->visibleDepartmentsQuery($sender)->whereKey($departmentId)->lockForUpdate()->first();
        $members = $department ? $this->staff->currentUsersQuery()
            ->whereHas('hrEmployeeProfile', fn (Builder $profile) => $profile->where('department_id', $departmentId))
            ->orderBy('users.id')->lockForUpdate()->get() : collect();
        $visible = $access->visibleCurrentStaffQuery($sender)->whereKey($members->pluck('id'))->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        if (! $department || $members->isEmpty() || array_diff($members->pluck('id')->all(), $visible) !== []) {
            throw ValidationException::withMessages(['to_department_id' => 'Choose an active team whose whole current membership is available within your approved sites.']);
        }

        return ['department' => $department, 'members' => $members];
    }

    public function visibleDepartmentIds(User $viewer): array
    {
        $viewer = $viewer->fresh() ?? $viewer;

        return $this->staff->isCurrent($viewer) ? $this->currentAccess()->visibleDepartmentsQuery($viewer)->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
    }

    public function canViewDepartment(HrKudos $kudos, User $viewer): bool
    {
        return $kudos->isTeamRecognition() && $kudos->to_user_id === null && $kudos->to_department_id !== null
            && in_array((int) $kudos->to_department_id, $this->visibleDepartmentIds($viewer), true);
    }

    /** Restrict new team records without changing established individual recognition reads. */
    public function applyVisibility(Builder $query, User $viewer): Builder
    {
        if (! $this->ready()) {
            return $query;
        }
        $departmentIds = $this->visibleDepartmentIds($viewer);
        $viewer = $viewer->fresh() ?? $viewer;
        $siteValues = array_map('strval', $this->currentAccess()->accessibleSiteIds($viewer));

        return $query->where(function (Builder $recipients) use ($viewer, $departmentIds, $siteValues): void {
            $recipients->where('recipient_type', 'person')->orWhere(function (Builder $teams) use ($viewer, $departmentIds, $siteValues): void {
                $teams->where('recipient_type', 'team')->whereNull('to_user_id')->whereIn('to_department_id', $departmentIds)
                    ->where(fn (Builder $privacy) => $privacy->where('is_public', true)->orWhere('from_user_id', $viewer->id)->orWhereHas('recipients', fn (Builder $members) => $members->whereKey($viewer->id)))
                    ->whereHas('feedPost', function (Builder $posts) use ($siteValues): void {
                        $posts->where(function (Builder $audience) use ($siteValues): void {
                            $audience->where('target_audience', 'all')->orWhere(fn (Builder $sites) => $sites->where('target_audience', 'site')->whereIn('target_value', $siteValues));
                        });
                    });
            });
        });
    }
}
