<?php

namespace App\Domain\Roadmap\Services;

use App\Domain\Governance\Models\Resolution;
use App\Domain\Roadmap\Events\DecisionRequestCreated;
use App\Domain\Roadmap\Models\DecisionRequest;
use App\Domain\Roadmap\Models\DelegationOfAuthorityRule;
use App\Domain\Roadmap\Models\Initiative;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class RoadmapDecisionService
{
    public function resolveApplicableRule(
        string $scope,
        float $amount,
        ?float $riskScore = null
    ): ?DelegationOfAuthorityRule {
        $rules = DelegationOfAuthorityRule::query()
            ->active()
            ->where('scope', $scope)
            ->get();

        foreach ($rules as $rule) {
            if ($rule->matches($amount, $riskScore)) {
                return $rule;
            }
        }

        return null;
    }

    public function ensureDecisionRequestForInitiative(
        Initiative $initiative,
        ?int $requestedBy,
        string $requestType = 'initiative_approval',
        string $scope = 'initiative_budget'
    ): ?DecisionRequest {
        $amount = $initiative->totalBudgetUpper();
        if ($amount <= 0) {
            return null;
        }

        $riskScore = (float) ($initiative->priority_score ?? 0);
        $rule = $this->resolveApplicableRule($scope, $amount, $riskScore);

        if (! $rule) {
            return null;
        }

        $request = DecisionRequest::query()
            ->where('source_type', Initiative::class)
            ->where('source_id', $initiative->id)
            ->where('request_type', $requestType)
            ->where('status', 'pending')
            ->first();

        if (! $request) {
            $request = DecisionRequest::create([
                'source_type' => Initiative::class,
                'source_id' => $initiative->id,
                'request_type' => $requestType,
                'status' => 'pending',
                'delegation_rule_id' => $rule->id,
                'amount' => $amount,
                'risk_level' => $initiative->priority_band,
                'required_role' => $rule->required_approver_role,
                'requested_by' => $requestedBy,
                'due_date' => now()->addDays(14)->toDateString(),
                'rationale' => $initiative->next_decision ?: 'Approval required based on delegation of authority rules.',
                'recommendation' => 'approve',
            ]);

            event(new DecisionRequestCreated($request));
        } else {
            $request->update([
                'delegation_rule_id' => $rule->id,
                'amount' => $amount,
                'risk_level' => $initiative->priority_band,
                'required_role' => $rule->required_approver_role,
            ]);
        }

        if ($request->governance_resolution_id === null && $this->isBoardLevelRole($rule->required_approver_role)) {
            $resolution = $this->createGovernanceResolution($initiative, $request, $requestedBy);
            if ($resolution) {
                $request->update(['governance_resolution_id' => $resolution->id]);
            }
        }

        return $request;
    }

    public function resolveRequest(DecisionRequest $request, string $status, int $resolvedBy, ?string $notes = null): void
    {
        $request->resolve($status, $resolvedBy, $notes);

        if ($request->governanceResolution) {
            $resolution = $request->governanceResolution;

            if ($status === 'approved' && $resolution->status === 'draft') {
                $resolution->openForVoting(now()->addDays(14));
            }

            if (in_array($status, ['rejected', 'withdrawn'], true) && ! $resolution->isClosed()) {
                $resolution->update([
                    'status' => 'cancelled',
                    'outcome_notes' => $notes,
                ]);
            }
        }
    }

    protected function createGovernanceResolution(Initiative $initiative, DecisionRequest $request, ?int $proposedBy): ?Resolution
    {
        try {
            $proposerId = $proposedBy ?: User::query()->orderBy('id')->value('id');
            if (! $proposerId) {
                return null;
            }

            return Resolution::create([
                'title' => 'Roadmap decision: '.$initiative->code,
                'decision_type' => 'budget_approval',
                'context' => 'Roadmap initiative approval required for '.$initiative->title,
                'options' => [
                    ['key' => 'approve', 'label' => 'Approve'],
                    ['key' => 'defer', 'label' => 'Defer'],
                    ['key' => 'reject', 'label' => 'Reject'],
                ],
                'recommendation' => 'approve',
                'cost_impact' => [
                    'amount' => (float) $request->amount,
                    'currency' => 'NZD',
                ],
                'risk_impact' => [
                    'risk_level' => $request->risk_level,
                    'initiative_id' => $initiative->id,
                ],
                'voting_threshold' => 'simple_majority',
                'status' => 'draft',
                'deadline' => $request->due_date,
                'proposed_by' => $proposerId,
                'proposed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create governance resolution from roadmap decision request', [
                'initiative_id' => $initiative->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function isBoardLevelRole(?string $role): bool
    {
        return in_array($role, [
            'board_chair',
            'board_secretary',
            'board_member',
            'board_observer',
            'board_trustee',
        ], true);
    }
}
