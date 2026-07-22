<?php

namespace App\Services\Sites\Profile;

use App\Models\EmergencyDrill;
use App\Models\FirstAidRecord;
use App\Models\HsRiskAssessment;
use App\Models\PpeInventory;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\SiteInspectionSchedule;
use App\Models\User;

class SiteProfileSafetyPresenter
{
    /** @return array<string, mixed> */
    public function hazards(User $user, Site $site): array
    {
        if (! $this->canView($user)) {
            return $this->locked();
        }

        $items = SiteHazard::query()
            ->where('site_id', $site->id)
            ->whereIn('status', ['open', 'in_progress', 'reopened'])
            ->orderByDesc('created_at')
            ->limit(20)
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

        return [
            'locked' => false,
            'items' => $items,
            'summary' => ['open' => $items->count()],
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
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'reference_number', 'title', 'status', 'risk_level', 'review_due_at'])
            ->map(fn (HsRiskAssessment $assessment) => [
                'id' => $assessment->id,
                'reference' => $assessment->reference_number,
                'title' => $assessment->title,
                'status' => $assessment->status,
                'risk_level' => $assessment->risk_level,
                'review_due_at' => $assessment->review_due_at?->toDateString(),
                'href' => route('health-safety.risk-assessments.show', $assessment),
            ])->values();

        return [
            'locked' => false,
            'items' => $items,
            'summary' => ['total' => $items->count()],
            'href' => route('health-safety.risk-assessments.index', ['site_id' => $site->id]),
        ];
    }

    /** @return array<string, mixed> */
    public function inspections(User $user, Site $site): array
    {
        if (! $this->canView($user)) {
            return $this->locked();
        }

        $items = SiteInspectionSchedule::query()
            ->where('site_id', $site->id)
            ->active()
            ->orderBy('next_due_date')
            ->limit(20)
            ->get(['id', 'title', 'inspection_type', 'frequency', 'next_due_date'])
            ->map(fn (SiteInspectionSchedule $schedule) => [
                'id' => $schedule->id,
                'title' => $schedule->title,
                'type' => $schedule->inspection_type,
                'frequency' => $schedule->frequency,
                'next_due_date' => $schedule->next_due_date?->toDateString(),
                'overdue' => $schedule->next_due_date?->isPast() ?? false,
            ])->values();

        return [
            'locked' => false,
            'items' => $items,
            'summary' => ['active' => $items->count(), 'overdue' => $items->where('overdue', true)->count()],
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
            ->orderByDesc('scheduled_at')
            ->limit(20)
            ->get(['id', 'title', 'drill_type', 'scheduled_at', 'completed_at', 'status', 'outcome'])
            ->map(fn (EmergencyDrill $drill) => [
                'id' => $drill->id,
                'title' => $drill->title,
                'type' => $drill->drill_type,
                'scheduled_at' => $drill->scheduled_at?->toISOString(),
                'completed_at' => $drill->completed_at?->toISOString(),
                'status' => $drill->status,
                'outcome' => $drill->outcome,
                'href' => route('health-safety.drills.show', $drill),
            ])->values();

        return [
            'locked' => false,
            'items' => $items,
            'summary' => ['total' => $items->count()],
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
            ->orderByDesc('treatment_date')
            ->limit(20)
            ->get(['id', 'reference_number', 'treatment_date', 'treated_person_name', 'injury_illness_type', 'treatment_outcome', 'ambulance_called'])
            ->map(fn (FirstAidRecord $record) => [
                'id' => $record->id,
                'reference' => $record->reference_number,
                'treatment_date' => $record->treatment_date?->toISOString(),
                'person' => $record->treated_person_name,
                'injury' => $record->injury_illness_type,
                'outcome' => $record->treatment_outcome,
                'ambulance_called' => (bool) $record->ambulance_called,
                'href' => route('health-safety.first-aid.show', $record),
            ])->values();

        return [
            'locked' => false,
            'items' => $items,
            'summary' => ['recent' => $items->count()],
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
            ->limit(20)
            ->get(['id', 'ppe_type_id', 'brand', 'model', 'condition', 'quantity', 'status', 'expiry_date', 'next_inspection_due'])
            ->map(fn (PpeInventory $item) => [
                'id' => $item->id,
                'name' => $item->ppeType?->name ?? trim((string) $item->brand.' '.(string) $item->model),
                'condition' => $item->condition,
                'quantity' => (int) $item->quantity,
                'status' => $item->status,
                'expiry_date' => $item->expiry_date?->toDateString(),
                'next_inspection_due' => $item->next_inspection_due?->toDateString(),
            ])->values();

        return [
            'locked' => false,
            'items' => $items,
            'summary' => ['items' => $items->count(), 'units' => $items->sum('quantity')],
            'href' => route('health-safety.ppe.index', ['site_id' => $site->id]),
        ];
    }

    /** @return array<string, mixed> */
    public function emergencyPlan(User $user, Site $site): array
    {
        if (! $this->canView($user)) {
            return $this->locked();
        }

        return [
            'locked' => false,
            'summary' => [
                'location' => $site->emergency_plan_location,
                'medication_storage_location' => $site->medication_storage_location,
            ],
            'href' => route('sites.emergency-plan.show', $site),
        ];
    }

    private function canView(User $user): bool
    {
        $user->loadMissing(['roles.permissions', 'permissionOverrides']);

        return $user->canDo('hazards.view');
    }

    /** @return array<string, mixed> */
    private function locked(): array
    {
        return ['locked' => true, 'items' => [], 'summary' => null, 'href' => null];
    }
}
