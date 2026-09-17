<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Http\Controllers\ReportController;
use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\CommitteeMembership;
use App\Domain\Governance\Services\DashboardAggregatorService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceReportsTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_board_monthly_report_uses_normalized_report_contract(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/governance/reports/board-monthly');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Reports/BoardMonthly')
            ->has('report.headline', 4)
            ->has('report.sections', 3)
            ->has('generatedAt')
            ->where('period.label', ReportController::monthToDateLabel(CarbonImmutable::now('Pacific/Auckland')))
            ->where('report.headline.2.label', 'Critical risks')
            ->where('report.headline.2.href', '/governance/risks?severity=critical')
            ->missing('report.sections.0.cards.0.freshness')
        );
    }

    public function test_board_monthly_says_not_available_when_a_part_fails_to_load(): void
    {
        $admin = $this->createAdminUser();
        $this->partialMock(DashboardAggregatorService::class, fn ($mock) => $mock
            ->shouldReceive('getTopRisks')
            ->andThrow(new \RuntimeException('Risk register offline')));

        $response = $this->actingAs($admin)->get('/governance/reports/board-monthly')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('report.headline.2.value', 'Not available')
            ->where('report.headline.2.tone', 'muted'));

        $riskCard = collect($response->inertiaProps('report.sections'))
            ->flatMap(fn (array $section) => $section['cards'])
            ->firstWhere('key', 'top_risks');

        $this->assertNotNull($riskCard);
        $this->assertSame('unknown', $riskCard['status']);
        $this->assertSame(['Not available'], collect($riskCard['metrics'])->pluck('value')->unique()->values()->all());
    }

    public function test_committee_report_uses_normalized_report_contract(): void
    {
        $admin = $this->createAdminUser();
        $this->createRisk($admin, ['title' => 'Cash flow', 'category' => 'financial']);
        $this->createRisk($admin, ['title' => 'Rostering gaps', 'category' => 'operational']);

        // No finance committee has been set up yet.
        $this->actingAs($admin)->get('/governance/reports/committee/finance')->assertNotFound();

        $committee = BoardCommittee::create([
            'committee_type' => 'finance',
            'name' => 'Finance committee',
            'is_active' => true,
        ]);
        $chair = $this->createUserWithRole('board_member', ['name' => 'Hemi Chair']);
        CommitteeMembership::create([
            'board_committee_id' => $committee->id,
            'board_member_id' => $this->createBoardMember($chair)->id,
            'role' => 'chair',
            'appointed_at' => now()->subMonth()->toDateString(),
            'is_active' => true,
        ]);

        foreach (['/governance/reports/committee/finance', "/governance/reports/committee/{$committee->id}"] as $url) {
            $response = $this->actingAs($admin)->get($url);

            $response->assertOk();
            $response->assertInertia(fn ($page) => $page
                ->component('Governance/Reports/Committee')
                ->where('report.committee.type', 'finance')
                ->where('report.committee.id', $committee->id)
                ->where('report.committee.name', 'Finance committee')
                ->where('report.committee.members.0.name', 'Hemi Chair')
                ->where('report.committee.risk_view_href', "/governance/risks/committee/{$committee->id}")
                ->has('report.headline', 2)
                ->has('report.sections', 1)
                // One shared category map: finance oversees financial risks only.
                ->has('report.risks', 1)
                ->where('report.risks.0.title', 'Cash flow')
                ->has('generatedAt')
            );
        }
    }

    public function test_compliance_status_report_uses_normalized_report_contract(): void
    {
        $admin = $this->createAdminUser();
        $this->createComplianceObligation($admin, ['framework' => 'privacy_act']);

        $response = $this->actingAs($admin)->get('/governance/reports/compliance-status');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Reports/ComplianceStatus')
            ->where('report.summary.total', 1)
            ->has('report.frameworks', 1)
            ->where('report.frameworks.0.title', 'Privacy Act 2020')
        );
    }

    public function test_compliance_status_report_works_status_out_from_the_due_date(): void
    {
        $admin = $this->createAdminUser();
        // Stored as "not due" but the due date has passed — the nightly refresh hasn't run.
        $this->createComplianceObligation($admin, [
            'framework' => 'hswa',
            'obligation_code' => '',
            'obligation_title' => 'Worker engagement review',
            'due_date' => now('Pacific/Auckland')->subDays(2)->toDateString(),
            'status' => 'not_due',
        ]);
        $this->createComplianceObligation($admin, ['framework' => 'hswa', 'obligation_code' => 'HSWA-2']);

        $this->actingAs($admin)
            ->get('/governance/reports/compliance-status')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.summary.overdue', 1)
                ->where('report.summary.on_time', 1)
                ->where('report.frameworks.0.title', 'Health and Safety at Work Act 2015')
                ->where('report.frameworks.0.items.0.title', 'Worker engagement review')
                ->where('report.frameworks.0.items.0.status', 'overdue')
                ->where('report.frameworks.0.items.0.code', null));
    }

    public function test_risk_narrative_names_owners_and_counts_match_the_register(): void
    {
        $owner = $this->createAdminUser(['name' => 'Ana Owner']);
        $this->createRisk($owner, [
            'title' => 'Funding cut',
            'likelihood_score' => 5,
            'impact_score' => 5,
            'control_effectiveness' => 'none',
            'appetite_threshold' => 12,
        ]);
        $this->createRisk($owner, ['title' => 'Closed one', 'status' => 'voided']);

        $this->actingAs($owner)
            ->get('/governance/reports/risk-narrative')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Governance/Reports/RiskNarrative')
                ->has('risks', 1)
                ->where('risks.0.title', 'Funding cut')
                ->where('risks.0.owner', 'Ana Owner')
                ->where('summary.critical', 1)
                ->where('summary.above_limit', 1)
                ->where('summary.current', 1));

        $this->assertSame(
            1,
            $this->actingAs($owner)->get('/governance/risks?above_appetite=1')->inertiaProps('risks.total'),
        );
    }
}
