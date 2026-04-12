<?php

namespace Tests\Feature\Governance;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
        );
    }

    public function test_committee_report_uses_normalized_report_contract(): void
    {
        $admin = $this->createAdminUser();
        $this->createRisk($admin, ['category' => 'financial']);

        $response = $this->actingAs($admin)->get('/governance/reports/committee/finance');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Reports/Committee')
            ->where('report.committee.type', 'finance')
            ->has('report.headline', 2)
            ->has('report.sections', 1)
            ->has('report.risks')
            ->has('generatedAt')
        );
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
        );
    }
}
