<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\HrProfilePhotoStorageService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Work-contact projection of canonical current staff; never an HR profile dump. */
class HubDirectory
{
    public function __construct(
        private readonly HubAccess $access,
        private readonly HrCurrentStaffService $staff,
        private readonly UserSiteAccessService $sites,
    ) {}

    private function visibleProfiles(User $viewer): Builder
    {
        $this->access->assertEnter($viewer);
        $ids = (new UserSiteAccessService)->applyStaffScope($this->staff->currentUsersQuery()->select('users.id'), $viewer);

        return HrEmployeeProfile::query()->whereIn('user_id', $ids)
            ->with(['user:id,name', 'primarySite:id,name', 'departmentRelation:id,name']);
    }

    public function people(User $viewer, string $search = ''): array
    {
        $viewer = $viewer->fresh();
        abort_unless($viewer, 403);
        $allowedSites = (new UserSiteAccessService)->accessibleSiteIds($viewer, UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS);

        $profiles = $this->visibleProfiles($viewer)->get();
        $managerIds = $profiles->pluck('id', 'user_id');
        $shared = DB::table('hub_work_profiles')->whereIn('user_id', $profiles->pluck('user_id'))->get()->keyBy('user_id');
        $org = $viewer->canDo('hr.orgchart.view') || $viewer->canDo('hr.employees.viewAny');

        return $profiles
            ->map(fn ($profile) => [...$this->card($profile, $allowedSites), ...$this->actions($profile, $viewer),
                ...$this->sharedFields($profile, $viewer, $shared[$profile->user_id] ?? null),
                'manager_id' => $org ? ($managerIds[$profile->manager_user_id] ?? null) : null,
            ])
            ->filter(fn ($person) => $person['name'] !== '')
            ->filter(fn ($person) => $search === '' || str_contains(mb_strtolower(implode(' ', [$person['name'], $person['position'], $person['department'], $person['site'], $person['shared_profile']['about'], ...$person['shared_profile']['help_with']])), mb_strtolower($search)))
            ->sortBy(fn ($person) => mb_strtolower($person['name']), SORT_NATURAL)->values()->all();
    }

    public function person(User $viewer, int $profileId): array
    {
        $viewer = $viewer->fresh();
        abort_unless($viewer, 403);
        $profile = $this->visibleProfiles($viewer)->whereKey($profileId)->firstOrFail();

        $org = $viewer->canDo('hr.orgchart.view') || $viewer->canDo('hr.employees.viewAny');

        return [
            ...$this->card($profile, (new UserSiteAccessService)->accessibleSiteIds($viewer, UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS)),
            ...$this->actions($profile, $viewer),
            ...$this->sharedFields($profile, $viewer, DB::table('hub_work_profiles')->where('user_id', $profile->user_id)->first()),
            'manager_id' => $org && $profile->manager_user_id ? $this->visibleProfiles($viewer)->where('user_id', $profile->manager_user_id)->value('id') : null,
            'staff_record_url' => $viewer->canDo('hr.employees.viewAny') && (new UserSiteAccessService)->applyHistoricalHrEmployeeProfileScope(HrEmployeeProfile::query(), $viewer)->whereKey($profile->id)->exists() ? '/hr/people/'.$profile->id : null,
        ];
    }

    /** Calendar host IDs are User IDs; reader links use authorised HR work-profile IDs. */
    public function profileUrlForUser(User $viewer, int $userId): ?string
    {
        $viewer = $viewer->fresh();
        if (! $viewer || ! $this->access->canEnter($viewer)) {
            return null;
        }
        $id = $this->visibleProfiles($viewer)->where('user_id', $userId)->value('id');

        return $id ? '/company-hub/people/'.$id : null;
    }

    private function sharedFields(HrEmployeeProfile $profile, User $viewer, ?object $shared): array
    {
        return [
            'can_edit_profile' => (int) $profile->user_id === (int) $viewer->id,
            'shared_profile' => [
                'about' => $shared->about ?? '',
                'help_with' => $shared ? json_decode($shared->help_with, true, flags: JSON_THROW_ON_ERROR) : [],
                'version' => (int) ($shared->version ?? 0),
                'updated_at' => $shared ? Carbon::parse($shared->updated_at)->toISOString() : null,
            ],
        ];
    }

    public function peopleSupportContacts(User $viewer): array
    {
        $viewer = $viewer->fresh();
        abort_unless($viewer, 403);
        $departments = ['hr', 'human resources', 'people & culture', 'people and culture'];
        $allowedSites = (new UserSiteAccessService)->accessibleSiteIds($viewer, UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS);

        return $this->visibleProfiles($viewer)->where(function (Builder $query) use ($departments) {
            $query->whereHas('departmentRelation', fn ($department) => $department->where('is_active', true)->whereIn(DB::raw('LOWER(name)'), $departments))
                ->orWhere(fn ($legacy) => $legacy->whereNull('department_id')->whereIn(DB::raw('LOWER(department)'), $departments));
        })->get()->map(fn ($profile) => $this->card($profile, $allowedSites))
            ->sortBy('name')->values()->all();
    }

    private function card(HrEmployeeProfile $profile, array $allowedSites): array
    {
        return [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'name' => $profile->preferred_name ?: ($profile->user?->name ?? ''),
            'position' => $profile->position_title,
            'department' => $profile->departmentRelation?->name ?? $profile->department,
            'department_id' => $profile->department_id,
            'site' => in_array((int) $profile->primary_site_id, $allowedSites, true) ? $profile->primarySite?->name : null,
            'work_email' => $profile->work_email ?: null,
            'work_phone' => $profile->work_phone ?: null,
            'first_aider' => (bool) $profile->is_first_aider,
            'fire_warden' => (bool) $profile->is_fire_warden,
            'avatar_url' => app(HrProfilePhotoStorageService::class)->readableDisk($profile->profile_photo_path, (int) $profile->id) !== null
                ? route('hr.directory.photo', ['profile' => $profile->id]) : null,
        ];
    }

    private function actions(HrEmployeeProfile $profile, User $viewer): array
    {
        $recognise = $viewer->canDo('hr.recognition.give');

        return ['can_recognise' => $recognise, 'recognition_user_id' => $recognise ? (int) $profile->user_id : null, 'chat_url' => null];
    }
}
