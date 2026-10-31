<?php

namespace Tests\Feature\HealthSafety;

use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\Client;
use App\Models\User;
use App\Services\HealthSafety\BehaviourSupportPlanApprovalService;
use App\Services\HealthSafety\BehaviourSupportPlanService;
use App\Services\HealthSafety\RestraintAccessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

abstract class BehaviourSupportPlanTestCase extends BehaviourSupportPolicyTestCase
{
    protected User $planManager;

    protected User $planReviewer;

    protected User $planSpecialist;

    protected User $planCapturer;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-11T00:00:00Z'));
        $this->client = Client::factory()->for($this->site)->create();
        $this->planManager = $this->staff(['restraints.manage', 'clients.viewAny']);
        $this->planReviewer = $this->staff(['restraints.review', 'clients.viewAny']);
        $this->planSpecialist = $this->staff(['restraints.approve', 'clients.viewAny']);
        $this->planCapturer = $this->staff(['restraints.create', 'clients.viewAny']);
    }

    protected function plans(): BehaviourSupportPlanService
    {
        return app(BehaviourSupportPlanService::class);
    }

    protected function approvals(): BehaviourSupportPlanApprovalService
    {
        return app(BehaviourSupportPlanApprovalService::class);
    }

    protected function access(): RestraintAccessService
    {
        return app(RestraintAccessService::class);
    }

    protected function makePlan(array $changes = [], ?User $actor = null): BehaviourSupportPlan
    {
        $receipt = $this->plans()->create($actor ?? $this->planManager, array_replace(['client_id' => $this->client->id, 'title' => 'Person-centred support', 'triggers' => 'Noise', 'de_escalation_strategies' => 'Offer a quiet space', 'approved_interventions' => 'Documented plan content, not authority', 'prohibited_interventions' => 'Recorded limitations', 'developed_at' => '2026-10-01', 'review_date' => '2026-11-30', 'notes' => 'Private plan narrative', 'idempotency_key' => (string) Str::uuid()], $changes));

        return BehaviourSupportPlan::findOrFail($receipt['plan_id']);
    }

    protected function command(BehaviourSupportPlan $plan, array $payload = [], ?string $key = null): array
    {
        return ['expected_version' => $plan->fresh()->lock_version, 'idempotency_key' => $key ?? (string) Str::uuid(), ...$payload];
    }

    protected function targetPayload(BehaviourSupportPlan $plan): array
    {
        $candidate = BehaviourSupportPlanVersion::findOrFail($plan->fresh()->current_candidate_version_id);

        return ['candidate_version_id' => $candidate->id, 'content_digest' => $candidate->content_digest, 'expected_policy_revision' => $this->policy()->snapshot()['policy_revision']];
    }

    protected function approvalPayload(BehaviourSupportPlan $plan, array $changes = []): array
    {
        return $this->command($plan, array_replace([...$this->targetPayload($plan), 'approval_decision' => 'approve', 'origin' => 'internal_specialist', 'approver_role' => 'Organisation designated specialist', 'approved_at' => '2026-10-10T00:00:00.123456Z', 'effective_from' => '2026-10-10T00:00:00.123456Z', 'effective_to' => null, 'basis' => 'Actual plan-specific decision and authority basis.', 'evidence_references' => [], 'activate_requested' => false], $changes));
    }

    protected function approveAndPublish(BehaviourSupportPlan $plan): array
    {
        $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['activate_requested' => false]));

        return $this->plans()->activate($plan, $this->planManager, $this->command($plan, $this->targetPayload($plan)));
    }

    protected function resolve(BehaviourSupportPlan $plan, string $at = '2026-10-10T02:00:00Z', ?int $version = null): array
    {
        return $this->access()->withCurrentViewer($this->planCapturer, fn ($current, $reads, $sites) => $this->plans()->resolveForEvent($this->client->fresh(), (int) $this->site->id, null, Carbon::parse($at), true, (int) $plan->id, $version, $current, $reads, $sites));
    }
}
