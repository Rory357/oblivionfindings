<?php

namespace App\Services\HealthSafety;

use App\Models\ModifiedDuty;
use App\Models\ReturnToWorkPlan;
use App\Models\User;
use App\Models\WorkplaceInjury;

/**
 * A worker's own workplace injuries, read-only (Stephan, 30 Oct: "Facts, care and plan" in My HR).
 *
 * Being the injured worker is the whole read rule: no H&S, register or Site permission is needed, and nobody else's
 * injury is reachable. Shown: what happened, dates, lost days, treatment, the ACC claim, the return-to-work plan and
 * modified duties, and who is coordinating. Never shown: the coordinator's and reviewers' free-text notes (injury
 * notes, plan review / clearance / agreement notes, duty notes), uploaded files, capacity assessments, or the linked
 * incident (which can name a client).
 */
final class OwnInjuryReadService
{
    /** @return list<array<string, mixed>> */
    public function forWorker(User $worker): array
    {
        return WorkplaceInjury::query()
            ->where('user_id', $worker->id)
            ->with([
                'site:id,name',
                'returnToWorkPlans' => fn ($plans) => $plans
                    ->where('worker_id', $worker->id)
                    ->orderByDesc('plan_start_date')
                    ->orderByDesc('id'),
                'returnToWorkPlans.manager:id,name',
                'returnToWorkPlans.modifiedDuties' => fn ($duties) => $duties
                    ->where('user_id', $worker->id)
                    ->orderBy('start_date')
                    ->orderBy('id'),
            ])
            ->orderByDesc('injury_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (WorkplaceInjury $injury) => $this->injury($injury))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function injury(WorkplaceInjury $injury): array
    {
        return [
            'id' => (int) $injury->id,
            'reference' => $injury->reference_number,
            'status' => $injury->status,
            'severity' => $injury->severity,
            'injury_type' => $injury->injury_type,
            'body_part' => $injury->body_part_affected,
            'description' => $injury->description,
            'injury_date' => $injury->injury_date?->utc()->toIso8601String(),
            'site' => $injury->site?->name,
            'lost_time_days' => $injury->lost_time_days,
            'expected_return_date' => $injury->expected_return_date?->toDateString(),
            'actual_return_date' => $injury->actual_return_date?->toDateString(),
            'next_review_date' => $injury->next_review_date?->toDateString(),
            'coordinator' => $this->coordinatorName($injury),
            'worksafe_notifiable' => (bool) $injury->worksafe_notifiable,
            'immediate_treatment' => $injury->immediate_treatment,
            'medical_treatment_type' => $injury->medical_treatment_type,
            'acc_claim_lodged' => (bool) $injury->acc_claim_lodged,
            'acc_claim_number' => $injury->acc_claim_number,
            'plans' => $injury->returnToWorkPlans->map(fn (ReturnToWorkPlan $plan) => [
                'id' => (int) $plan->id,
                'status' => $plan->status,
                'start_date' => $plan->plan_start_date?->toDateString(),
                'end_date' => $plan->plan_end_date?->toDateString(),
                'next_review_date' => $plan->next_review_date?->toDateString(),
                'manager' => $plan->manager?->name,
                'goals' => array_values(array_filter((array) ($plan->goals ?? []), fn ($goal) => is_string($goal) && trim($goal) !== '')),
                'stages' => array_values(array_map(fn (array $stage) => [
                    'name' => is_string($stage['name'] ?? null) ? $stage['name'] : null,
                    'start_date' => is_string($stage['start_date'] ?? null) ? $stage['start_date'] : null,
                    'end_date' => is_string($stage['end_date'] ?? null) ? $stage['end_date'] : null,
                    'hours_per_week' => is_numeric($stage['hours_per_week'] ?? null) ? (float) $stage['hours_per_week'] : null,
                    'duties' => is_string($stage['duties_description'] ?? null) ? $stage['duties_description'] : null,
                ], array_filter((array) ($plan->stages ?? []), 'is_array'))),
                'workplace_modifications' => $plan->workplace_modifications,
                'medical_clearance_date' => $plan->medical_clearance_date?->toDateString(),
                'medical_clearance_provider' => $plan->medical_clearance_provider,
                'worker_agreed' => (bool) $plan->worker_agreed,
                'worker_agreed_at' => $plan->worker_agreed_at?->toDateString(),
                'duties' => $plan->modifiedDuties->map(fn (ModifiedDuty $duty) => [
                    'id' => (int) $duty->id,
                    'status' => $duty->status,
                    'start_date' => $duty->start_date?->toDateString(),
                    'end_date' => $duty->end_date?->toDateString(),
                    'description' => $duty->modified_duties_description,
                    'restrictions' => $duty->restrictions,
                    'accommodations' => $duty->accommodations,
                    'hours_per_day' => $duty->hours_per_day === null ? null : (float) $duty->hours_per_day,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function coordinatorName(WorkplaceInjury $injury): ?string
    {
        $snapshot = $injury->coordinator_snapshot;
        if (is_array($snapshot) && is_string($snapshot['name'] ?? null) && $snapshot['name'] !== '') {
            return $snapshot['name'];
        }

        return $injury->coordinator_user_id ? User::query()->whereKey($injury->coordinator_user_id)->value('name') : null;
    }
}
