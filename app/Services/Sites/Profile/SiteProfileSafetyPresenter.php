<?php

namespace App\Services\Sites\Profile;

use App\Models\EmergencyDrill;
use App\Models\FirstAidRecord;
use App\Models\HsRiskAssessment;
use App\Models\PpeInventory;
use App\Models\SafeWorkProcedure;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\SiteInspectionRecord;
use App\Models\SiteInspectionSchedule;
use App\Models\User;
use App\Services\Sites\SiteEmergencyPlanService;
use App\Services\Sites\SiteTypePlanService;
use App\Support\HealthSafety\RiskAssessmentPresenter;

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

        $procedures = $user->canDo('procedures.view')
            ? SafeWorkProcedure::query()
                ->applicableToSite($site->id)
                ->orderBy('title')
                ->get(['id', 'reference_number', 'title', 'category', 'status', 'review_date'])
                ->map(fn (SafeWorkProcedure $procedure) => [
                    'id' => $procedure->id,
                    'reference_number' => $procedure->reference_number,
                    'title' => $procedure->title,
                    'category' => $procedure->category,
                    'status' => $procedure->status,
                    'review_date' => $procedure->review_date?->toDateString(),
                ])->values()
            : collect();

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
        if (! $this->canView($user)) {
            return $this->locked();
        }

        $items = HsRiskAssessment::query()
            ->forAssessable(Site::class, $site->id)
            ->with(['assessable', 'hsEvent', 'assessedBy'])
            ->withCount('attachments')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (HsRiskAssessment $assessment) => RiskAssessmentPresenter::row($assessment))
            ->values();

        return [
            'locked' => false,
            'assessments' => $items,
            'pickers' => RiskAssessmentPresenter::pickers(),
            'can_manage' => $this->canManage($user, $site),
            'site' => ['id' => $site->id, 'name' => $site->name],
            'href' => route('health-safety.risk-assessments.index', ['site_id' => $site->id]),
        ];
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
        if (! $this->canView($user)) {
            return $this->locked();
        }

        $items = EmergencyDrill::query()
            ->where('site_id', $site->id)
            ->withCount(['findings as open_findings' => fn ($query) => $query->whereNotIn('status', ['resolved', 'accepted'])])
            ->orderByDesc('scheduled_at')
            ->limit(100)
            ->get(['id', 'title', 'drill_type', 'scheduled_at', 'completed_at', 'status', 'outcome', 'total_participants', 'evacuation_time_seconds'])
            ->map(fn (EmergencyDrill $drill) => [
                'id' => $drill->id,
                'title' => $drill->title,
                'type' => $drill->drill_type,
                'scheduled_at' => $drill->scheduled_at?->toISOString(),
                'completed_at' => $drill->completed_at?->toISOString(),
                'drill_status' => $drill->status,
                'outcome' => $drill->outcome,
                'participants' => (int) $drill->total_participants,
                'evacuation_time_seconds' => $drill->evacuation_time_seconds,
                'open_findings' => (int) $drill->open_findings,
                'href' => route('health-safety.drills.show', $drill),
            ])->values();

        return [
            'locked' => false,
            'items' => $items,
            'can_manage' => $this->canManage($user, $site),
            'href' => route('health-safety.drills.index', ['site_id' => $site->id]),
        ];
    }

    /** @return array<string, mixed> */
    public function firstAid(User $user, Site $site): array
    {
        if (! $this->canView($user)) {
            return $this->locked();
        }

        $items = FirstAidRecord::query()
            ->where('site_id', $site->id)
            ->with(['firstAider:id,name'])
            ->withCount(['followups as open_followups_count' => fn ($query) => $query->whereNull('completed_at')])
            ->orderByDesc('treatment_date')
            ->limit(100)
            ->get(['id', 'reference_number', 'treatment_date', 'treated_person_name', 'injury_illness_type', 'treatment_outcome', 'ambulance_called'])
            ->map(fn (FirstAidRecord $record) => [
                'id' => $record->id,
                'reference' => $record->reference_number,
                'treatment_date' => $record->treatment_date?->toISOString(),
                'person' => $record->treated_person_name,
                'injury' => $record->injury_illness_type,
                'outcome' => $record->treatment_outcome,
                'ambulance_called' => (bool) $record->ambulance_called,
                'first_aider' => $record->firstAider?->name,
                'incident_reported' => (bool) $record->incident_reported,
                'related_incident_id' => $record->related_incident_id,
                'open_followups_count' => (int) $record->open_followups_count,
                'href' => route('health-safety.first-aid.show', $record),
            ])->values();

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
        if (! $this->canView($user)) {
            return $this->locked();
        }

        $items = PpeInventory::query()
            ->where('site_id', $site->id)
            ->whereNotIn('status', ['disposed', 'lost'])
            ->with('ppeType:id,name')
            ->orderBy('expiry_date')
            ->limit(100)
            ->get(['id', 'ppe_type_id', 'brand', 'model', 'condition', 'quantity', 'status', 'location', 'expiry_date', 'next_inspection_due'])
            ->map(fn (PpeInventory $item) => [
                'id' => $item->id,
                'name' => $item->ppeType?->name ?? trim((string) $item->brand.' '.(string) $item->model),
                'condition' => $item->condition,
                'quantity' => (int) $item->quantity,
                'status' => $item->status,
                'location' => $item->location,
                'expiry_date' => $item->expiry_date?->toDateString(),
                'next_inspection_due' => $item->next_inspection_due?->toDateString(),
                'expired' => $item->isExpired(),
                'inspection_due' => $item->isInspectionDue(),
            ])->values();

        return [
            'locked' => false,
            'items' => $items,
            'can_manage' => $this->canManage($user, $site),
            'href' => route('health-safety.ppe.index', ['site_id' => $site->id]),
        ];
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
