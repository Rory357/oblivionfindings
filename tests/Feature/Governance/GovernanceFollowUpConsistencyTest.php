<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Services\DashboardAggregatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * Follow-ups from the plain-language audit: committee meetings link to their
 * committee's risks and report, the board report counts risks the same way as
 * the register, and "updated" times come from the data.
 */
class GovernanceFollowUpConsistencyTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_committee_meetings_link_to_the_committee_risks_and_report(): void
    {
        $admin = $this->createAdminUser();
        $committee = BoardCommittee::create(['name' => 'Audit and risk committee', 'committee_type' => 'audit_risk', 'is_active' => true]);
        $committeeMeeting = $this->createMeeting($admin, [
            'title' => 'Audit and risk meeting',
            'meeting_type' => 'audit_risk',
            'board_committee_id' => $committee->id,
        ]);
        $boardMeeting = $this->createMeeting($admin, ['title' => 'Board meeting']);

        $this->actingAs($admin)
            ->get("/governance/meetings/{$committeeMeeting->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('committeeOversight.name', 'Audit and risk committee')
                ->where('committeeOversight.risks_href', "/governance/risks/committee/{$committee->id}")
                ->where('committeeOversight.report_href', "/governance/reports/committee/{$committee->id}"));

        $this->actingAs($admin)
            ->get("/governance/meetings/{$boardMeeting->id}")
            ->assertInertia(fn ($page) => $page->where('committeeOversight', null));
    }

    public function test_board_report_counts_current_risks_like_the_register_and_uses_real_update_times(): void
    {
        $admin = $this->createAdminUser();
        $critical = ['likelihood_score' => 5, 'impact_score' => 5, 'control_effectiveness' => 'none'];

        $this->createRisk($admin, ['title' => 'Open critical risk', 'status' => 'active', ...$critical]);
        $this->createRisk($admin, ['title' => 'Accepted critical risk', 'status' => 'accepted', ...$critical]);
        $this->createRisk($admin, ['title' => 'Closed critical risk', 'status' => 'closed', ...$critical]);

        $aggregator = app(DashboardAggregatorService::class);
        $top = $aggregator->getTopRisks();

        $registerCritical = RiskRegisterEntry::query()->current()->severity('critical')->count();
        $this->assertSame($registerCritical, $top['critical']);
        $this->assertSame(2, $top['critical']);
        $this->assertSame(2, $top['count']);

        $freshness = (fn () => $this->getDataFreshness())->call($aggregator);
        $latestRiskChange = RiskRegisterEntry::query()->max('updated_at');
        $this->assertArrayHasKey('risks', $freshness);
        $this->assertSame(
            \Carbon\Carbon::parse($latestRiskChange, 'UTC')->toIso8601String(),
            $freshness['risks'],
        );
    }
}
