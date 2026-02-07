<?php

namespace Tests\Support;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\PerformanceReview;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Models\StrategicPlan;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

trait GovernanceTestHelpers
{
    protected function seedGovernance(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->seed(\Database\Seeders\GovernancePermissionsSeeder::class);

        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $adminRole->permissions()->sync(Permission::pluck('id'));
        }
    }

    protected function createAdminUser(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => 'admin',
            'approved_at' => now(),
        ], $overrides));

        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $user->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        return $user;
    }

    protected function createBoardMember(User $user, array $overrides = []): BoardMember
    {
        return BoardMember::create(array_merge([
            'user_id' => $user->id,
            'board_role' => 'member',
            'term_start' => now()->subYear()->toDateString(),
            'term_end' => now()->addYear()->toDateString(),
            'is_independent' => true,
            'is_active' => true,
        ], $overrides));
    }

    protected function createMeeting(User $creator, array $overrides = []): GovernanceMeeting
    {
        return GovernanceMeeting::create(array_merge([
            'meeting_type' => 'full_board',
            'title' => 'Test Meeting',
            'scheduled_at' => now()->addWeek(),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'quorum_required' => 50,
            'created_by' => $creator->id,
        ], $overrides));
    }

    protected function createResolution(User $proposer, array $overrides = []): Resolution
    {
        return Resolution::create(array_merge([
            'resolution_reference' => 'RES-' . strtoupper(Str::random(6)),
            'title' => 'Test Resolution',
            'context' => 'Test resolution context',
            'options' => [],
            'voting_threshold' => 'simple_majority',
            'status' => 'draft',
            'proposed_by' => $proposer->id,
            'proposed_at' => now(),
        ], $overrides));
    }

    protected function createRisk(User $creator, array $overrides = []): RiskRegisterEntry
    {
        $likelihood = 3;
        $impact = 3;
        $inherent = $likelihood * $impact;
        $residual = 5;
        $threshold = 12;

        return RiskRegisterEntry::create(array_merge([
            'risk_reference' => 'R-' . strtoupper(Str::random(6)),
            'category' => 'financial',
            'title' => 'Test Risk',
            'description' => 'Risk description',
            'likelihood_score' => $likelihood,
            'impact_score' => $impact,
            'inherent_score' => $inherent,
            'control_effectiveness' => 'moderate',
            'residual_score' => $residual,
            'appetite_threshold' => $threshold,
            'within_appetite' => $residual <= $threshold,
            'risk_owner_id' => $creator->id,
            'review_frequency' => 'quarterly',
            'next_review_date' => now()->addMonths(3),
            'identified_at' => now()->toDateString(),
            'identified_by' => $creator->id,
        ], $overrides));
    }

    protected function createComplianceObligation(User $owner, array $overrides = []): ComplianceObligation
    {
        $dueDate = now()->addDays(30)->toDateString();

        return ComplianceObligation::create(array_merge([
            'framework' => 'privacy_act',
            'obligation_code' => 'PRIV-001',
            'obligation_title' => 'Test Obligation',
            'description' => 'Compliance obligation description',
            'frequency' => 'annual',
            'due_date' => $dueDate,
            'next_due_date' => $dueDate,
            'reminder_days' => [30, 14, 7],
            'owner_id' => $owner->id,
            'status' => 'not_due',
            'evidence_required' => 'Evidence required',
        ], $overrides));
    }

    protected function createPerformanceReview(User $reviewee, User $creator, array $overrides = []): PerformanceReview
    {
        return PerformanceReview::create(array_merge([
            'reviewee_id' => $reviewee->id,
            'review_cycle' => now()->year . '-Annual',
            'review_type' => 'annual',
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->toDateString(),
            'status' => 'drafting',
            'created_by' => $creator->id,
        ], $overrides));
    }

    protected function createStrategicPlan(User $creator, array $overrides = []): StrategicPlan
    {
        return StrategicPlan::create(array_merge([
            'title' => 'Strategic Plan',
            'planning_horizon' => '3_year',
            'period_start' => now()->toDateString(),
            'period_end' => now()->addYears(3)->toDateString(),
            'vision_statement' => 'Test vision',
            'mission_statement' => 'Test mission',
            'values' => [],
            'status' => 'draft',
            'created_by' => $creator->id,
        ], $overrides));
    }

    protected function createBudget(User $creator, array $overrides = []): Budget
    {
        return Budget::create(array_merge([
            'fiscal_year' => (string) now()->year,
            'title' => 'Test Budget',
            'total_budget' => 100000,
            'status' => 'drafting',
            'created_by' => $creator->id,
        ], $overrides));
    }

    protected function createActionItem(User $creator, User $assignedTo, array $overrides = []): ActionItem
    {
        return ActionItem::create(array_merge([
            'action_reference' => 'ACT-' . strtoupper(Str::random(6)),
            'source_type' => 'meeting',
            'source_id' => 1,
            'description' => 'Action item description',
            'assigned_to' => $assignedTo->id,
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'open',
            'priority' => 'medium',
            'created_by' => $creator->id,
        ], $overrides));
    }
}
