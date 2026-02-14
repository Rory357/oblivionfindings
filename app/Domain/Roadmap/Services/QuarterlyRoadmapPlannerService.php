<?php

namespace App\Domain\Roadmap\Services;

use App\Domain\Roadmap\Events\QuarterlyPlanPublished;
use App\Domain\Roadmap\Models\Initiative;
use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Domain\Roadmap\Models\QuarterlyRoadmapPlanItem;
use Illuminate\Support\Facades\DB;

class QuarterlyRoadmapPlannerService
{
    public function __construct(
        protected RoadmapScoringService $scoringService,
        protected RoadmapDecisionService $decisionService,
        protected RoadmapChangeLogService $changeLogService,
    ) {}

    public function generateDraft(
        int $fiscalYear,
        int $quarter,
        string $preset,
        ?int $tenantId,
        ?int $generatedBy = null
    ): QuarterlyRoadmapPlan {
        return DB::transaction(function () use ($fiscalYear, $quarter, $preset, $tenantId, $generatedBy) {
            $revisionNo = $this->nextRevisionNo($fiscalYear, $quarter, $tenantId);

            $plan = QuarterlyRoadmapPlan::create([
                'tenant_id' => $tenantId,
                'fiscal_year' => $fiscalYear,
                'quarter' => $quarter,
                'status' => QuarterlyRoadmapPlan::STATUS_DRAFT,
                'preset_profile' => $preset,
                'generated_at' => now(),
                'generated_by' => $generatedBy,
                'revision_no' => $revisionNo,
            ]);

            $initiatives = Initiative::query()
                ->forTenant($tenantId)
                ->whereIn('status', [
                    Initiative::STATUS_PROPOSED,
                    Initiative::STATUS_APPROVED,
                    Initiative::STATUS_IN_PROGRESS,
                    Initiative::STATUS_DEFERRED,
                ])
                ->orderByDesc('priority_score')
                ->get();

            $rank = 1;
            foreach ($initiatives as $initiative) {
                $breakdown = $this->scoringService->score($initiative, $preset, true);

                $decisionRequest = $this->decisionService->ensureDecisionRequestForInitiative(
                    $initiative,
                    $generatedBy,
                    'initiative_approval',
                    'initiative_budget'
                );

                $budget = $initiative->budgets()
                    ->where('fiscal_year', $fiscalYear)
                    ->where('quarter', $quarter)
                    ->first();

                QuarterlyRoadmapPlanItem::create([
                    'tenant_id' => $tenantId,
                    'quarterly_plan_id' => $plan->id,
                    'initiative_id' => $initiative->id,
                    'rank' => $rank,
                    'planned_capex' => $budget?->capex_high ?? $initiative->cost_estimate_high,
                    'planned_opex' => $budget?->opex_high,
                    'planned_outcome' => $initiative->benefit_summary,
                    'decision_required' => $decisionRequest !== null,
                    'decision_type' => $decisionRequest?->request_type,
                    'decision_due_date' => $decisionRequest?->due_date,
                    'status_at_snapshot' => $initiative->status,
                    'score_at_snapshot' => $breakdown['score'],
                    'risk_delta_at_snapshot' => $initiative->riskLinks()->max('risk_delta_expected'),
                ]);

                $rank++;
            }

            $this->changeLogService->log(
                $tenantId,
                QuarterlyRoadmapPlan::class,
                $plan->id,
                'plan.generated',
                [
                    'fiscal_year' => $fiscalYear,
                    'quarter' => $quarter,
                    'preset' => $preset,
                    'revision_no' => $revisionNo,
                ],
                'Quarterly draft generated from prioritised initiatives.',
                $generatedBy,
            );

            return $plan->load('items.initiative');
        });
    }

    public function submitForManagerReview(QuarterlyRoadmapPlan $plan, int $userId): QuarterlyRoadmapPlan
    {
        if ($plan->status !== QuarterlyRoadmapPlan::STATUS_DRAFT) {
            throw new \RuntimeException('Only draft plans can be submitted for manager review.');
        }

        $plan->update(['status' => QuarterlyRoadmapPlan::STATUS_MANAGER_REVIEW]);

        $this->changeLogService->log(
            $plan->tenant_id,
            QuarterlyRoadmapPlan::class,
            $plan->id,
            'plan.manager_review',
            ['status' => QuarterlyRoadmapPlan::STATUS_MANAGER_REVIEW],
            null,
            $userId,
        );

        return $plan->fresh('items.initiative');
    }

    public function submitForExecutiveReview(QuarterlyRoadmapPlan $plan, int $userId): QuarterlyRoadmapPlan
    {
        if ($plan->status !== QuarterlyRoadmapPlan::STATUS_MANAGER_REVIEW) {
            throw new \RuntimeException('Plan must be in manager review to submit for executive review.');
        }

        $plan->update(['status' => QuarterlyRoadmapPlan::STATUS_EXEC_REVIEW]);

        $this->changeLogService->log(
            $plan->tenant_id,
            QuarterlyRoadmapPlan::class,
            $plan->id,
            'plan.exec_review',
            ['status' => QuarterlyRoadmapPlan::STATUS_EXEC_REVIEW],
            null,
            $userId,
        );

        return $plan->fresh('items.initiative');
    }

    public function approve(QuarterlyRoadmapPlan $plan, int $userId): QuarterlyRoadmapPlan
    {
        if (! in_array($plan->status, [QuarterlyRoadmapPlan::STATUS_MANAGER_REVIEW, QuarterlyRoadmapPlan::STATUS_EXEC_REVIEW], true)) {
            throw new \RuntimeException('Plan must be in review before approval.');
        }

        $plan->update([
            'status' => QuarterlyRoadmapPlan::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => $userId,
        ]);

        $this->changeLogService->log(
            $plan->tenant_id,
            QuarterlyRoadmapPlan::class,
            $plan->id,
            'plan.approved',
            ['status' => QuarterlyRoadmapPlan::STATUS_APPROVED],
            null,
            $userId,
        );

        return $plan->fresh('items.initiative');
    }

    public function publish(QuarterlyRoadmapPlan $plan, int $userId): QuarterlyRoadmapPlan
    {
        if ($plan->isPublished()) {
            throw new \RuntimeException('Published plans are immutable. Create a new revision instead.');
        }

        if ($plan->status !== QuarterlyRoadmapPlan::STATUS_APPROVED) {
            throw new \RuntimeException('Only approved plans can be published.');
        }

        $payload = $this->buildSnapshotPayload($plan->fresh('items.initiative.riskLinks', 'items.initiative.assurancePlans'));
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));

        $plan->update([
            'status' => QuarterlyRoadmapPlan::STATUS_PUBLISHED,
            'published_at' => now(),
            'published_by' => $userId,
            'snapshot_payload' => $payload,
            'snapshot_hash' => $hash,
        ]);

        event(new QuarterlyPlanPublished($plan->fresh()));

        $this->changeLogService->log(
            $plan->tenant_id,
            QuarterlyRoadmapPlan::class,
            $plan->id,
            'plan.published',
            ['snapshot_hash' => $hash],
            'Plan published as immutable snapshot.',
            $userId,
        );

        return $plan->fresh('items.initiative');
    }

    public function createRevisionFromPublished(
        QuarterlyRoadmapPlan $publishedPlan,
        int $userId,
        ?string $changeSummary = null
    ): QuarterlyRoadmapPlan {
        if (! $publishedPlan->isPublished()) {
            throw new \RuntimeException('Only published plans can be revised.');
        }

        return DB::transaction(function () use ($publishedPlan, $userId, $changeSummary) {
            $revisionNo = $this->nextRevisionNo($publishedPlan->fiscal_year, $publishedPlan->quarter, $publishedPlan->tenant_id);

            $newPlan = QuarterlyRoadmapPlan::create([
                'tenant_id' => $publishedPlan->tenant_id,
                'fiscal_year' => $publishedPlan->fiscal_year,
                'quarter' => $publishedPlan->quarter,
                'status' => QuarterlyRoadmapPlan::STATUS_DRAFT,
                'preset_profile' => $publishedPlan->preset_profile,
                'generated_at' => now(),
                'generated_by' => $userId,
                'revision_no' => $revisionNo,
                'change_summary' => $changeSummary,
                'source_filters' => $publishedPlan->source_filters,
            ]);

            foreach ($publishedPlan->items as $item) {
                QuarterlyRoadmapPlanItem::create([
                    'tenant_id' => $publishedPlan->tenant_id,
                    'quarterly_plan_id' => $newPlan->id,
                    'initiative_id' => $item->initiative_id,
                    'rank' => $item->rank,
                    'planned_capex' => $item->planned_capex,
                    'planned_opex' => $item->planned_opex,
                    'planned_outcome' => $item->planned_outcome,
                    'decision_required' => $item->decision_required,
                    'decision_type' => $item->decision_type,
                    'decision_due_date' => $item->decision_due_date,
                    'status_at_snapshot' => $item->status_at_snapshot,
                    'score_at_snapshot' => $item->score_at_snapshot,
                    'risk_delta_at_snapshot' => $item->risk_delta_at_snapshot,
                    'notes' => $item->notes,
                ]);
            }

            $this->changeLogService->log(
                $publishedPlan->tenant_id,
                QuarterlyRoadmapPlan::class,
                $newPlan->id,
                'plan.revision_created',
                [
                    'from_plan_id' => $publishedPlan->id,
                    'new_revision' => $revisionNo,
                ],
                $changeSummary,
                $userId,
            );

            return $newPlan->fresh('items.initiative');
        });
    }

    protected function nextRevisionNo(int $fiscalYear, int $quarter, ?int $tenantId): int
    {
        $current = QuarterlyRoadmapPlan::query()
            ->forTenant($tenantId)
            ->where('fiscal_year', $fiscalYear)
            ->where('quarter', $quarter)
            ->max('revision_no');

        return ((int) $current) + 1;
    }

    protected function buildSnapshotPayload(QuarterlyRoadmapPlan $plan): array
    {
        return [
            'plan' => [
                'id' => $plan->id,
                'fiscal_year' => $plan->fiscal_year,
                'quarter' => $plan->quarter,
                'status' => $plan->status,
                'preset_profile' => $plan->preset_profile,
                'revision_no' => $plan->revision_no,
                'published_at' => now()->toIso8601String(),
            ],
            'items' => $plan->items->map(function (QuarterlyRoadmapPlanItem $item) {
                return [
                    'rank' => $item->rank,
                    'initiative_id' => $item->initiative_id,
                    'initiative_code' => $item->initiative?->code,
                    'initiative_title' => $item->initiative?->title,
                    'status' => $item->initiative?->status,
                    'score' => (float) ($item->score_at_snapshot ?? 0),
                    'planned_capex' => (float) ($item->planned_capex ?? 0),
                    'planned_opex' => (float) ($item->planned_opex ?? 0),
                    'decision_required' => (bool) $item->decision_required,
                    'decision_type' => $item->decision_type,
                    'decision_due_date' => $item->decision_due_date?->toDateString(),
                    'risk_links' => $item->initiative?->riskLinks->map(fn ($link) => [
                        'risk_id' => $link->risk_register_entry_id,
                        'risk_delta_expected' => (float) ($link->risk_delta_expected ?? 0),
                    ])->values()->all(),
                    'assurance' => $item->initiative?->assurancePlans->map(fn ($evidence) => [
                        'control_name' => $evidence->control_name,
                        'evidence_type' => $evidence->evidence_type,
                        'verify_due_date' => $evidence->verify_due_date?->toDateString(),
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }
}
