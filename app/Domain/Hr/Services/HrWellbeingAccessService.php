<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrWellbeingCheckin;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Canonical current-staff and Site boundary for sensitive wellbeing records.
 */
class HrWellbeingAccessService
{
    public function __construct(
        private readonly HrPerformanceAccessService $staff,
        private readonly UserSiteAccessService $sites,
    ) {}

    public function currentStaff(User $viewer, User|int $subject): User
    {
        return $this->staff->currentStaff($viewer, $subject);
    }

    /** @return Collection<int, User> */
    public function staffOptions(User $viewer): Collection
    {
        return $this->staff
            ->applyCurrentSubjectScope(User::query(), $viewer, 'id')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return array<int, int> */
    public function visibleSiteIds(User $viewer): array
    {
        return $this->sites->accessibleSiteIds($viewer);
    }

    /** @return Collection<int, Site> */
    public function visibleSites(User $viewer): Collection
    {
        return $this->sites
            ->applySiteScope(Site::query(), $viewer)
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Validate and normalize an audience without revealing inaccessible Sites.
     *
     * @param  array<int, mixed>  $requestedSiteIds
     * @return array<int, int>
     */
    public function validateSurveyAudience(User $viewer, string $audienceType, array $requestedSiteIds): array
    {
        $visible = $this->visibleSiteIds($viewer);
        $all = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($audienceType === 'all') {
            if ($all === [] || array_diff($all, $visible) !== []) {
                throw ValidationException::withMessages([
                    'audience_type' => 'An application-wide survey requires access to every current Site.',
                ]);
            }

            return [];
        }

        $siteIds = collect($requestedSiteIds)
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($siteIds === [] || array_diff($siteIds, $visible) !== []) {
            throw ValidationException::withMessages([
                'audience_site_ids' => 'Choose at least one Site you can access.',
            ]);
        }

        return $siteIds;
    }

    public function surveyForManager(User $viewer, HrEngagementSurvey|int $survey): HrEngagementSurvey
    {
        $surveyId = $survey instanceof HrEngagementSurvey ? $survey->getKey() : $survey;
        $record = HrEngagementSurvey::query()->findOrFail($surveyId);
        abort_unless($this->canManageSurvey($viewer, $record), 404);

        return $record;
    }

    public function canManageSurvey(User $viewer, HrEngagementSurvey $survey): bool
    {
        $visible = $this->visibleSiteIds($viewer);
        if (($survey->audience_type ?: 'all') === 'all') {
            $all = Site::query()
                ->active()
                ->notArchived()
                ->whereNull('archived_at')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            return $all !== [] && array_diff($all, $visible) === [];
        }

        $audience = collect($survey->audience_site_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $audience !== [] && array_diff($audience, $visible) === [];
    }

    /** @return Collection<int, HrEngagementActionPlan> */
    public function visibleActionPlans(User $viewer, bool $canManage): Collection
    {
        return HrEngagementActionPlan::query()
            ->with(['owner:id,name', 'survey:id,title,audience_type,audience_site_ids', 'staff:id,name', 'notes.author:id,name'])
            ->when(! $canManage, fn ($query) => $query->where('owner_user_id', $viewer->id))
            ->orderByDesc('id')
            ->lazy(100)
            ->filter(fn (HrEngagementActionPlan $plan) => $this->canAccessActionPlan($viewer, $plan))
            ->collect();
    }

    public function actionPlan(User $viewer, HrEngagementActionPlan|int $plan): HrEngagementActionPlan
    {
        $planId = $plan instanceof HrEngagementActionPlan ? $plan->getKey() : $plan;
        $record = HrEngagementActionPlan::query()
            ->with(['owner:id,name', 'staff:id,name', 'survey:id,title,audience_type,audience_site_ids'])
            ->findOrFail($planId);
        abort_unless($this->canAccessActionPlan($viewer, $record), 404);

        return $record;
    }

    public function canAccessActionPlan(User $viewer, HrEngagementActionPlan $plan): bool
    {
        try {
            $this->currentStaff($viewer, $viewer);
            if ($plan->owner_user_id === null) {
                return false;
            }
            $this->currentStaff($viewer, (int) $plan->owner_user_id);
            if ($plan->staff_user_id !== null) {
                $this->currentStaff($viewer, (int) $plan->staff_user_id);
            }
            if ($plan->survey_id !== null) {
                $survey = $plan->relationLoaded('survey') ? $plan->survey : $plan->survey()->first();
                if (! $survey || ! $this->canManageSurvey($viewer, $survey)) {
                    return false;
                }
            }
        } catch (ModelNotFoundException) {
            return false;
        }

        return $plan->staff_user_id !== null
            || $plan->survey_id !== null
            || (int) $plan->owner_user_id === (int) $viewer->id
            || $viewer->canDo('hr.performance.manage');
    }

    public function checkin(User $viewer, HrWellbeingCheckin|int $checkin): HrWellbeingCheckin
    {
        $checkinId = $checkin instanceof HrWellbeingCheckin ? $checkin->getKey() : $checkin;

        return $this->staff
            ->applyCurrentSubjectScope(HrWellbeingCheckin::query(), $viewer, 'staff_user_id')
            ->findOrFail($checkinId);
    }
}
