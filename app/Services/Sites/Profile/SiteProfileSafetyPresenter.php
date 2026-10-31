<?php

namespace App\Services\Sites\Profile;

use App\Models\EmergencyDrill;
use App\Models\HsRiskAssessment;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\SiteInspectionRecord;
use App\Models\SiteInspectionSchedule;
use App\Models\User;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\FirstAidSourceProjectionService;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\PpeSiteReadService;
use App\Services\Sites\SiteEmergencyPlanService;
use App\Services\Sites\SiteTypePlanService;
use App\Support\HealthSafety\EmergencyDrillPresenter;
use App\Support\HealthSafety\EmergencyDrillReturn;
use App\Support\HealthSafety\RiskAssessmentPresenter;
use App\Support\HealthSafety\SafeWorkProcedurePresenter;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SiteProfileSafetyPresenter
{
    public function __construct(
        private readonly SiteTypePlanService $plans,
        private readonly SiteEmergencyPlanService $emergencyPlans,
    ) {}

    /** @return array<string, mixed> */
    public function hazards(User $user, Site $site): array
    {
        if (! $this->canView($user)) {
            return $this->locked();
        }

        $items = SiteHazard::query()
            ->where('site_id', $site->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'reference_number', 'description', 'severity', 'risk_rating', 'status', 'due_date', 'review_date'])
            ->map(fn (SiteHazard $hazard) => [
                'id' => $hazard->id,
                'reference' => $hazard->reference_number,
                'description' => $hazard->description,
                'severity' => $hazard->severity,
                'risk_rating' => $hazard->risk_rating,
                'status' => $hazard->status,
                'due_date' => $hazard->due_date?->toDateString(),
                'review_date' => $hazard->review_date?->toDateString(),
                'href' => route('sites.hazards.show', $hazard),
            ])->values();

        $procedures = SafeWorkProcedurePresenter::applicableRows(
            $user, '/sites/'.$site->id.'?tab=hazards', (int) $site->id,
        );

        return [
            'locked' => false,
            'items' => $items,
            'procedures' => $procedures,
            'can_create' => $this->canCreate($user, $site),
            'can_manage' => $this->canManage($user, $site),
            'href' => route('sites.hazards.index', $site),
        ];
    }

    /** @return array<string, mixed> */
    public function riskAssessments(User $user, Site $site): array
    {
        return RiskAssessmentPresenter::materialise($user, null, function ($current, $reads, $context) use ($site) {
            if (! $this->canView($current)) {
                return $this->locked();
            }

            $query = HsRiskAssessment::forAssessable(Site::class, $site->id)
                ->with(['assessable', 'hsEvent', 'assessedBy'])->withCount('attachments');
            $original = RiskAssessmentPresenter::guardEagerQuery($query, $current, $context);
            $items = (clone $original)
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn (HsRiskAssessment $assessment) => RiskAssessmentPresenter::row($assessment, $current, $context, $original))
                ->values()->all();

            return [
                'locked' => false,
                'assessments' => $items,
                'pickers' => RiskAssessmentPresenter::pickerRowsInContext($current, [(int) $site->id], $context),
                'can_manage' => $this->canManage($current, $site),
                'site' => ['id' => $site->id, 'name' => $site->name],
                'href' => route('health-safety.risk-assessments.index', ['site_id' => $site->id]),
            ];

        });
    }

    /** @return array<string, mixed> */
    public function inspections(User $user, Site $site): array
    {
        if (! $this->canView($user)) {
            return $this->locked();
        }

        $schedules = SiteInspectionSchedule::query()
            ->where('site_id', $site->id)
            ->with('assignedTo:id,name')
            ->orderBy('next_due_date')
            ->limit(100)
            ->get(['id', 'title', 'inspection_type', 'frequency', 'next_due_date', 'assigned_to_user_id', 'is_active'])
            ->map(fn (SiteInspectionSchedule $schedule) => [
                'id' => $schedule->id,
                'title' => $schedule->title,
                'type' => $schedule->inspection_type,
                'frequency' => $schedule->frequency,
                'next_due_date' => $schedule->next_due_date?->toDateString(),
                'assigned_to' => $schedule->assignedTo?->name,
                'is_active' => (bool) $schedule->is_active,
                'overdue' => $schedule->is_active && ($schedule->next_due_date?->isPast() ?? false),
            ])->values();

        $records = SiteInspectionRecord::query()
            ->where('site_id', $site->id)
            ->with(['schedule:id,title', 'completedBy:id,name'])
            ->orderByDesc('due_date')
            ->limit(100)
            ->get(['id', 'schedule_id', 'due_date', 'completed_at', 'completed_by_user_id', 'result', 'findings', 'corrective_actions', 'linked_hazard_id'])
            ->map(fn (SiteInspectionRecord $record) => [
                'id' => $record->id,
                'schedule_title' => $record->schedule?->title,
                'due_date' => $record->due_date?->toDateString(),
                'completed_at' => $record->completed_at?->toISOString(),
                'completed_by' => $record->completedBy?->name,
                'result' => $record->result,
                'findings' => $record->findings,
                'corrective_actions' => $record->corrective_actions,
                'linked_hazard_id' => $record->linked_hazard_id,
            ])->values();

        return [
            'locked' => false,
            'schedules' => $schedules,
            'records' => $records,
            'can_manage' => $this->canManage($user, $site),
            'href' => route('sites.inspections.index', $site),
        ];
    }

    /** @return array<string, mixed> */
    public function drills(User $user, Site $site): array
    {
        $access = app(EmergencyDrillAccessService::class);
        try {
            return $access->withCurrentViewer($user, function ($current, $reads, $sites) use ($access, $site) {
                abort_unless($current->canDo('hazards.view'), 403);
                abort_unless(in_array((int) $site->id, $sites, true), 404);
                $records = $reads->query($access->scopeForSites(EmergencyDrill::query(), $sites))->where('site_id', $site->id)->orderByDesc('scheduled_at')->get();
                $presenter = app(EmergencyDrillPresenter::class);
                $origin = '/sites/'.$site->id.'?tab=drills';
                $items = $records->map(function (EmergencyDrill $drill) use ($presenter, $current, $origin) {
                    $row = $presenter->row($drill, $current, $origin);

                    return ['id' => $row['id'], 'title' => $row['title'], 'type' => $row['drill_type'],
                        'scheduled_at' => $row['scheduled_at'], 'completed_at' => $row['completed_at'],
                        'drill_status' => $row['raw_status'], 'outcome' => $row['outcome'],
                        'participants' => $row['total_participants'], 'assigned_count' => $row['participants_count'], 'attended_count' => $row['attended_count'],
                        'evacuation_time_seconds' => $drill->evacuation_time_seconds, 'open_findings' => $row['findings_open'],
                        'href' => $row['href'], 'can' => $row['can']];
                })->values();

                return ['locked' => false, 'items' => $items,
                    'can_manage' => $current->canDo('hazards.manage'),
                    'can_schedule' => $access->canCreate($current),
                    'href' => EmergencyDrillReturn::fromRegister(['site_id' => $site->id])];
            });
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return $this->locked();
        }
    }

    /** @return array<string, mixed> */
    public function firstAid(User $user, Site $site): array
    {
        if (! $this->canView($user)) {
            return $this->locked();
        }

        $items = app(FirstAidSourceProjectionService::class)->forSite($user, $site);

        return [
            'locked' => false,
            'items' => $items,
            'can_manage' => $this->canManage($user, $site),
            'href' => route('health-safety.first-aid.index', ['site_id' => $site->id]),
        ];
    }

    /** @return array<string, mixed> */
    public function ppe(User $user, Site $site): array
    {
        try {
            return app(PpeSiteReadService::class)->profileForSite($user, (int) $site->id);
        } catch (HttpException $denial) {
            if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                throw $denial;
            }

            return $this->locked();
        }
    }

    /** @return array<string, mixed> */
    public function emergencyPlan(User $user, Site $site): array
    {
        if (! $this->canView($user)) {
            return $this->locked();
        }

        $plan = $this->plans->currentPublished($site);
        if (! $plan) {
            return [
                'locked' => false,
                'available' => false,
                'site' => ['id' => $site->id, 'name' => $site->name, 'type' => $site->type],
                'plan_href' => route('sites.plan.show', $site),
            ];
        }

        return $this->emergencyPlans->viewModel($site, $plan) + [
            'locked' => false,
            'available' => true,
            'typePlan' => $this->plans->summaryFor($site),
            'can' => ['update' => $this->canManage($user, $site)],
        ];
    }

    private function canView(User $user): bool
    {
        $user->loadMissing(['roles.permissions', 'permissionOverrides']);

        return $user->canDo('hazards.view');
    }

    private function canCreate(User $user, Site $site): bool
    {
        return ! $site->archived && $user->can('update', $site)
            && ($user->canDo('hazards.create') || $user->canDo('hazards.manage'));
    }

    private function canManage(User $user, Site $site): bool
    {
        return ! $site->archived && $user->can('update', $site) && $user->canDo('hazards.manage');
    }

    /** @return array<string, mixed> */
    private function locked(): array
    {
        return ['locked' => true, 'items' => [], 'summary' => null, 'href' => null];
    }
}
