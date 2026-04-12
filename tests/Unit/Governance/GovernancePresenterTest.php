<?php

namespace Tests\Unit\Governance;

use App\Domain\Governance\Support\GovernancePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernancePresenterTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_dashboard_normalizes_unavailable_metrics_and_role_actions(): void
    {
        $user = $this->createUserWithRole('board_member');
        $this->createBoardMember($user);

        $dashboard = app(GovernancePresenter::class)->dashboard(
            widgets: [
                'decisions_required' => ['count' => 1, 'overdue' => 0, 'items' => [['reference' => 'RES-100', 'title' => 'Approve annual plan']]],
                'roadmap' => [
                    'initiatives' => ['total' => 4, 'in_progress' => 2, 'blocked' => 1, 'top' => [['code' => 'RM-1', 'title' => 'Care platform uplift']]],
                    'decisions_required' => 1,
                ],
                'workforce' => ['overtime_percentage' => 8.5, 'unfilled_shifts' => 2, 'training_compliance' => null, 'status' => 'warning'],
                'financial' => ['budget_utilization' => 42.1, 'variance' => 2.3, 'budget_total' => 100000, 'actual_total' => 42100, 'status' => 'good'],
            ],
            period: [
                'type' => 'month',
                'start' => now()->startOfMonth()->toDateString(),
                'end' => now()->toDateString(),
            ],
            freshness: [],
            workflow: ['summary' => ['total' => 1, 'critical' => 0, 'overdue' => 0], 'actions' => []],
            user: $user,
        );

        $workforceCard = collect($dashboard['cards'])->firstWhere('key', 'workforce');
        $trainingMetric = collect($workforceCard['metrics'])->firstWhere('label', 'Training compliance');

        $this->assertNotNull($workforceCard);
        $this->assertSame('Unavailable', $trainingMetric['value']);
        $this->assertSame('muted', $trainingMetric['tone']);
        $this->assertSame('board_focus', $dashboard['sections'][0]['key']);
        $this->assertTrue(collect($dashboard['role_actions'])->contains(fn (array $action) => $action['href'] === '/governance/interests/mine'));
        $this->assertTrue(collect($dashboard['role_actions'])->contains(fn (array $action) => $action['href'] === '/governance/evaluations'));
    }
}
